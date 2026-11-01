"""Local lot ledger and fixed-pack planning for the documented purchasing workflow.

This is not connected to live stock or Sheets. Stored quantities are sellable
units; each receipt's cost/expiry and supplier provenance remain distinct.
"""
import hashlib
import json
import os
import sqlite3
from contextlib import contextmanager
from datetime import date


class InventoryConflict(ValueError):
    pass


def _whole(value, *, allow_zero=False):
    if isinstance(value, bool) or not isinstance(value, int) or value < (0 if allow_zero else 1):
        raise ValueError('quantity must be a valid whole number')
    return value


def plan_supplier_packs(customer_demand, unreserved_owned_stock, pack_size):
    """Plan required complete supplier packs; never change customer quantity."""
    demand = _whole(customer_demand, allow_zero=True)
    stock = _whole(unreserved_owned_stock, allow_zero=True)
    pack = _whole(pack_size)
    used = min(demand, stock)
    shortfall = demand - used
    packs = (shortfall + pack - 1) // pack
    return {'customer_units':demand, 'owned_stock_used':used, 'supplier_packs':packs,
            'supplier_units':packs*pack, 'unallocated_after_fulfillment':packs*pack-shortfall}


class InventoryLots:
    def __init__(self, path):
        self.path=str(path)
        with self._connect() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS stock_lots (
                lot_id TEXT PRIMARY KEY, sku TEXT NOT NULL, supplier TEXT NOT NULL,
                purchase_date TEXT,
                received_at TEXT NOT NULL, production_date TEXT, expiry_date TEXT,
                units INTEGER NOT NULL, unit_cost INTEGER NOT NULL, receipt_hash TEXT NOT NULL,
                state TEXT NOT NULL CHECK(state IN ('ready','review','held')));
                CREATE TABLE IF NOT EXISTS stock_allocations (
                order_id TEXT NOT NULL, sku TEXT NOT NULL, lot_id TEXT NOT NULL,
                units INTEGER NOT NULL, state TEXT NOT NULL CHECK(state IN ('reserved','released','fulfilled')),
                PRIMARY KEY(order_id,sku,lot_id));
                CREATE TABLE IF NOT EXISTS stock_reservation_requests (
                order_id TEXT NOT NULL, sku TEXT NOT NULL, requested INTEGER NOT NULL,
                PRIMARY KEY(order_id,sku));''')
            db.execute('''CREATE TABLE IF NOT EXISTS stock_lot_events (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL UNIQUE,
                lot_id TEXT NOT NULL, actor_id TEXT NOT NULL, action TEXT NOT NULL,
                delta_units INTEGER NOT NULL, reason TEXT NOT NULL, note TEXT NOT NULL,
                created_at TEXT NOT NULL, new_state TEXT,
                expected_revision TEXT NOT NULL DEFAULT '')''')
            event_columns={row['name'] for row in db.execute('PRAGMA table_info(stock_lot_events)')}
            if 'expected_revision' not in event_columns:
                db.execute("ALTER TABLE stock_lot_events ADD COLUMN expected_revision TEXT NOT NULL DEFAULT ''")
            columns={row['name'] for row in db.execute('PRAGMA table_info(stock_lots)')}
            if 'purchase_date' not in columns:
                # Historical receipts did not retain the purchase date. Keep it
                # unknown instead of silently substituting the receipt date.
                db.execute('ALTER TABLE stock_lots ADD COLUMN purchase_date TEXT')
            db.execute('''CREATE TABLE IF NOT EXISTS stock_lot_receipt_audit (
                lot_id TEXT NOT NULL, receipt_hash TEXT NOT NULL, actor_id TEXT NOT NULL,
                note TEXT NOT NULL, created_at TEXT NOT NULL,
                PRIMARY KEY(lot_id,receipt_hash,actor_id));''')
        os.chmod(self.path,0o600)

    @contextmanager
    def _connect(self):
        db=sqlite3.connect(self.path,timeout=15);db.row_factory=sqlite3.Row
        try:
            with db:yield db
        finally:db.close()

    def receive(self, *, lot_id, sku, supplier, received_at, production_date, expiry_date, units, unit_cost,
                purchase_date=None):
        if not all(isinstance(v,str) and v.strip() for v in (lot_id,sku,supplier)):
            raise ValueError('lot, sku and supplier are required')
        _whole(units);_whole(unit_cost,allow_zero=True)
        received=date.fromisoformat(received_at)
        purchase=date.fromisoformat(purchase_date) if purchase_date else None
        production=date.fromisoformat(production_date) if production_date else None
        expiry=date.fromisoformat(expiry_date) if expiry_date else None
        if purchase and purchase>received:raise ValueError('purchase follows receipt')
        if production and purchase and production>purchase:raise ValueError('production follows purchase')
        if production and production>received:raise ValueError('production follows receipt')
        if production and expiry and production>=expiry:raise ValueError('production must precede expiry')
        # Unknown/expired on receipt is retained for human review, not silently sold.
        state='ready' if expiry and expiry>received else 'review'
        fields={'lot_id':lot_id,'sku':sku,'supplier':supplier,'received_at':received_at,
                'production_date':production_date,'expiry_date':expiry_date,'units':units,'unit_cost':unit_cost}
        if purchase_date:
            fields['purchase_date']=purchase_date
        receipt_hash=hashlib.sha256(json.dumps(fields,sort_keys=True).encode()).hexdigest()
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            old=db.execute('SELECT receipt_hash FROM stock_lots WHERE lot_id=?',(lot_id,)).fetchone()
            if old and old['receipt_hash']!=receipt_hash:raise InventoryConflict('lot receipt already exists with different facts')
            if not old:db.execute('''INSERT INTO stock_lots
                (lot_id,sku,supplier,purchase_date,received_at,production_date,expiry_date,
                 units,unit_cost,receipt_hash,state) VALUES (?,?,?,?,?,?,?,?,?,?,?)''',
                (lot_id,sku,supplier,purchase_date,received_at,production_date,expiry_date,
                 units,unit_cost,receipt_hash,state))
        return state

    def list_lots(self, *, today):
        date.fromisoformat(today)
        with self._connect() as db:
            rows = db.execute('''SELECT l.*,
                COALESCE(SUM(CASE WHEN a.state IN ('reserved','fulfilled') THEN a.units ELSE 0 END),0) AS committed
                FROM stock_lots l LEFT JOIN stock_allocations a ON a.lot_id=l.lot_id
                GROUP BY l.lot_id ORDER BY l.expiry_date IS NULL,l.expiry_date,l.received_at,l.lot_id''').fetchall()
            events=db.execute('SELECT * FROM stock_lot_events ORDER BY sequence').fetchall()
        by_lot={}
        for event in events:by_lot.setdefault(event['lot_id'],[]).append(dict(event))
        result=[]
        for row in rows:
            item=dict(row)
            item['committed_units']=item.pop('committed')
            item=self._apply_events(item,by_lot.get(item['lot_id'],[]))
            item['available_units']=max(0,item['units']-item['committed_units']) if (
                item['state']=='ready' and item['received_at']<=today
                and item['expiry_date'] and item['expiry_date']>today
            ) else 0
            result.append(item)
        return result

    def get_lot(self, lot_id):
        with self._connect() as db:
            row=db.execute('SELECT * FROM stock_lots WHERE lot_id=?',(str(lot_id),)).fetchone()
            if not row:return None
            events=db.execute('SELECT * FROM stock_lot_events WHERE lot_id=? ORDER BY sequence',
                (str(lot_id),)).fetchall()
            committed=db.execute("SELECT COALESCE(SUM(units),0) FROM stock_allocations "
                "WHERE lot_id=? AND state IN ('reserved','fulfilled')",(str(lot_id),)).fetchone()[0]
        item=dict(row);item['committed_units']=committed
        return self._apply_events(item,[dict(event) for event in events])

    @staticmethod
    def _apply_events(item,events):
        item['received_units']=item['units']
        item['adjustment_units']=sum(int(event['delta_units']) for event in events)
        item['units']=item['received_units']+item['adjustment_units']
        for event in events:
            if event['new_state'] is not None:item['state']=event['new_state']
        material={'receipt_hash':item['receipt_hash'],'events':[
            [event['event_id'],event['action'],event['delta_units'],event['reason'],event['new_state']]
            for event in events]}
        item['revision']=hashlib.sha256(json.dumps(material,sort_keys=True,
            ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
        return item

    def apply_operator_event(self, *, lot_id, expected_revision, actor_id, action,
                             delta_units, reason, note, created_at, today):
        date.fromisoformat(today)
        allowed={'adjust':{'count_correction','damage','expiry'},
                 'hold':{'quality','record_review','supplier_issue'},
                 'release':{'inspection_passed'}}
        if action not in allowed or reason not in allowed[action]:
            raise ValueError('invalid stock action or reason')
        if (isinstance(delta_units,bool) or not isinstance(delta_units,int)
                or (action=='adjust' and delta_units==0)
                or (action!='adjust' and delta_units!=0)
                or abs(delta_units)>1_000_000):
            raise ValueError('invalid unit adjustment')
        if reason in {'damage','expiry'} and delta_units>=0:
            raise ValueError('damage and expiry adjustments must reduce stock')
        if (not isinstance(actor_id,str) or not actor_id.strip() or not isinstance(note,str)
                or not note.strip() or len(note)>1000):
            raise ValueError('owner and audit note required')
        if not isinstance(expected_revision,str) or len(expected_revision)!=64:
            raise ValueError('expected inventory revision required')
        material={'lot_id':str(lot_id),'expected_revision':expected_revision,
            'actor_id':actor_id,'action':action,'delta_units':delta_units,'reason':reason,
            'note':note.strip()}
        event_id=hashlib.sha256(json.dumps(material,sort_keys=True,ensure_ascii=False,
            separators=(',',':')).encode()).hexdigest()
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row=db.execute('SELECT * FROM stock_lots WHERE lot_id=?',(str(lot_id),)).fetchone()
            if not row:raise InventoryConflict('lot not found')
            existing=db.execute('SELECT * FROM stock_lot_events WHERE event_id=?',(event_id,)).fetchone()
            events=[dict(event) for event in db.execute(
                'SELECT * FROM stock_lot_events WHERE lot_id=? ORDER BY sequence',(str(lot_id),))]
            base=dict(row)
            base['committed_units']=db.execute("SELECT COALESCE(SUM(units),0) FROM stock_allocations "
                "WHERE lot_id=? AND state IN ('reserved','fulfilled')",(str(lot_id),)).fetchone()[0]
            current=self._apply_events(base,events)
            if existing:
                return {'event_id':event_id,'revision':current['revision'],
                    'units':current['units'],'state':current['state'],'action':existing['action'],
                    'previous_revision':existing['expected_revision'],'idempotent':True}
            if current['revision']!=expected_revision:
                raise InventoryConflict('lot changed since the operator reviewed it')
            new_state=None
            if action=='hold':
                if current['state']=='held':raise InventoryConflict('lot is already held')
                reserved=db.execute("SELECT COALESCE(SUM(units),0) FROM stock_allocations "
                    "WHERE lot_id=? AND state='reserved'",(str(lot_id),)).fetchone()[0]
                if reserved:raise InventoryConflict('resolve open order reservations before holding this lot')
                new_state='held'
            elif action=='release':
                if current['state']!='held':raise InventoryConflict('only held lots can be released')
                if not current['expiry_date'] or current['expiry_date']<=today:
                    raise InventoryConflict('unknown or expired lot cannot be released')
                new_state='ready'
            elif current['units']+delta_units<current['committed_units']:
                raise InventoryConflict('adjustment would remove reserved or fulfilled units')
            db.execute('''INSERT INTO stock_lot_events
                (event_id,lot_id,actor_id,action,delta_units,reason,note,created_at,new_state,expected_revision)
                VALUES (?,?,?,?,?,?,?,?,?,?)''',
                (event_id,str(lot_id),actor_id,action,delta_units,reason,note.strip(),created_at,new_state,
                 expected_revision))
            saved_events=events+[{'event_id':event_id,'action':action,'delta_units':delta_units,
                'reason':reason,'new_state':new_state}]
            result=self._apply_events(base,saved_events)
            return {'event_id':event_id,'revision':result['revision'],
                'units':result['units'],'state':result['state'],'action':action,
                'previous_revision':expected_revision,'idempotent':False}

    def recent_events(self, *, limit=100):
        limit=max(1,min(int(limit),200))
        with self._connect() as db:
            return [dict(row) for row in db.execute(
                'SELECT * FROM stock_lot_events ORDER BY sequence DESC LIMIT ?',(limit,))]

    def add_missing_purchase_date(self, *, lot_id, expected_receipt_hash, purchase_date):
        """Correct only a missing historical purchase date, never other receipt facts."""
        bought=date.fromisoformat(purchase_date)
        if bought.isoformat()!=purchase_date:
            raise ValueError('purchase date must use YYYY-MM-DD')
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row=db.execute('SELECT * FROM stock_lots WHERE lot_id=?',(str(lot_id),)).fetchone()
            if not row or row['receipt_hash']!=expected_receipt_hash or row['purchase_date'] is not None:
                raise InventoryConflict('lot changed or already has a purchase date')
            if bought.isoformat()>row['received_at']:
                raise ValueError('purchase follows receipt')
            if row['production_date'] and row['production_date']>purchase_date:
                raise ValueError('production follows purchase')
            legacy_fields={'lot_id':row['lot_id'],'sku':row['sku'],'supplier':row['supplier'],
                'received_at':row['received_at'],'production_date':row['production_date'],
                'expiry_date':row['expiry_date'],'units':row['units'],'unit_cost':row['unit_cost']}
            legacy_hash=hashlib.sha256(json.dumps(legacy_fields,sort_keys=True).encode()).hexdigest()
            if legacy_hash!=expected_receipt_hash:
                raise InventoryConflict('historical receipt facts do not match their saved hash')
            corrected_fields={**legacy_fields,'purchase_date':purchase_date}
            corrected_hash=hashlib.sha256(json.dumps(corrected_fields,sort_keys=True).encode()).hexdigest()
            db.execute('UPDATE stock_lots SET purchase_date=?,receipt_hash=? WHERE lot_id=? AND receipt_hash=?',
                (purchase_date,corrected_hash,str(lot_id),expected_receipt_hash))
        return corrected_hash

    def record_receipt(self, *, lot_id, actor_id, note, created_at):
        lot=self.get_lot(lot_id)
        if not lot or not isinstance(actor_id,str) or not actor_id or not isinstance(note,str) or not note.strip():
            raise ValueError('lot, actor and receipt note are required')
        with self._connect() as db:
            db.execute('INSERT OR IGNORE INTO stock_lot_receipt_audit '
                       '(lot_id,receipt_hash,actor_id,note,created_at) VALUES (?,?,?,?,?)',
                       (lot_id,lot['receipt_hash'],actor_id,note.strip()[:1000],created_at))

    def reserve(self, *, order_id, sku, units, today):
        _whole(units)
        if not order_id or not sku:raise ValueError('order and sku required')
        date.fromisoformat(today)
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            prior=db.execute('SELECT requested FROM stock_reservation_requests WHERE order_id=? AND sku=?',(order_id,sku)).fetchone()
            if prior:
                if prior['requested']!=units:raise InventoryConflict('order quantity changed; explicit revision is required')
                allocated=db.execute("SELECT COALESCE(SUM(units),0) FROM stock_allocations "
                    "WHERE order_id=? AND sku=? AND state IN ('reserved','fulfilled')",(order_id,sku)).fetchone()[0]
                remaining=max(0,units-allocated)
                if remaining==0:
                    return [dict(r) for r in db.execute('SELECT lot_id,units,state FROM stock_allocations WHERE order_id=? AND sku=? ORDER BY lot_id',(order_id,sku))]
            else:
                db.execute('INSERT INTO stock_reservation_requests VALUES (?,?,?)',(order_id,sku,units))
                remaining=units
            rows=db.execute('''SELECT l.lot_id,l.units+COALESCE((SELECT SUM(e.delta_units)
                FROM stock_lot_events e WHERE e.lot_id=l.lot_id AND e.action='adjust'),0)
                -COALESCE(SUM(CASE WHEN a.state IN ('reserved','fulfilled') THEN a.units ELSE 0 END),0) AS available
              FROM stock_lots l LEFT JOIN stock_allocations a ON a.lot_id=l.lot_id
              WHERE l.sku=? AND COALESCE((SELECT e.new_state FROM stock_lot_events e
                WHERE e.lot_id=l.lot_id AND e.new_state IS NOT NULL ORDER BY e.sequence DESC LIMIT 1),l.state)='ready'
                AND l.expiry_date>? AND l.received_at<=? GROUP BY l.lot_id
              HAVING available>0 ORDER BY l.expiry_date,l.received_at,l.lot_id''',(sku,today,today)).fetchall()
            for row in rows:
                count=min(remaining,row['available'])
                if count:
                    db.execute("INSERT INTO stock_allocations VALUES (?,?,?,?,'reserved')",(order_id,sku,row['lot_id'],count))
                    remaining-=count
                if not remaining:break
            return [dict(r) for r in db.execute('SELECT lot_id,units,state FROM stock_allocations WHERE order_id=? AND sku=? ORDER BY lot_id',(order_id,sku))]

    def change_reservation(self, *, order_id, sku, from_state, to_state):
        if (from_state,to_state) not in {('reserved','released'),('reserved','fulfilled')}:
            raise ValueError('invalid transition')
        with self._connect() as db:
            return db.execute('UPDATE stock_allocations SET state=? WHERE order_id=? AND sku=? AND state=?',
                              (to_state,order_id,sku,from_state)).rowcount

    def release_order(self, *, order_id):
        """Release every still-reserved unit after cancellation/refund review."""
        with self._connect() as db:
            units = db.execute("SELECT COALESCE(SUM(units),0) FROM stock_allocations "
                               "WHERE order_id=? AND state='reserved'", (order_id,)).fetchone()[0]
            db.execute("UPDATE stock_allocations SET state='released' "
                       "WHERE order_id=? AND state='reserved'", (order_id,))
            return units
