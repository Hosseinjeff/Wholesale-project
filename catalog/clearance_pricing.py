"""Owner-reviewed batch clearance proposals; nothing here changes a sell price."""
from __future__ import annotations

import hashlib
import json
import os
import sqlite3
from contextlib import contextmanager
from datetime import date


def propose_clearance_price(*, purchase_date, production_date, expiry_date, today,
                            purchase_cost, normal_price, market_price,
                            market_source, market_observed_date,
                            near_cost_threshold_toman=None):
    """Calculate a reviewable price using the user-confirmed batch inputs.

    This proposed curve starts when urgency reaches one half. With a production
    date, holding age and product age contribute equally; otherwise purchase age
    is used alone. The suggested price interpolates from the
    lower of the normal basket price and today's comparable market price toward
    purchase cost. It never publishes or suggests a below-cost price.
    """
    try:
        bought=date.fromisoformat(str(purchase_date))
        produced=date.fromisoformat(str(production_date)) if production_date else None
        expires=date.fromisoformat(str(expiry_date))
        current=date.fromisoformat(str(today))
        observed=date.fromisoformat(str(market_observed_date))
    except (TypeError,ValueError):
        return {'state':'needs_review','reason':'invalid_or_missing_date'}
    if bought>current or (produced and (produced>bought or produced>current)) or expires<=current:
        return {'state':'needs_review','reason':'date_order_or_expiry_requires_review'}
    if observed!=current:
        return {'state':'needs_review','reason':'market_price_must_be_observed_today'}
    values=(purchase_cost,normal_price,market_price)
    if any(isinstance(value,bool) or not isinstance(value,int) or value<=0 for value in values):
        return {'state':'needs_review','reason':'positive_integer_toman_prices_required'}
    if not isinstance(market_source,str) or not market_source.strip() or len(market_source)>200:
        return {'state':'needs_review','reason':'market_source_required'}
    if near_cost_threshold_toman is not None and (
            isinstance(near_cost_threshold_toman,bool) or not isinstance(near_cost_threshold_toman,int)
            or near_cost_threshold_toman<=0):
        return {'state':'needs_review','reason':'invalid_near_cost_threshold'}

    held_days=(current-bought).days
    production_age_days=(current-produced).days if produced else None
    remaining=(expires-current).days
    if held_days+remaining<=0 or (production_age_days is not None and production_age_days+remaining<=0):
        return {'state':'needs_review','reason':'invalid_shelf_life'}
    holding_urgency=min(1.0,max(0.0,held_days/(held_days+remaining)))
    shelf_life_urgency=(min(1.0,max(0.0,production_age_days/(production_age_days+remaining)))
                        if production_age_days is not None else holding_urgency)
    # Both dates contribute when production is known: one tracks cash tied up,
    # the other the remaining product life. The weighting is still a proposed
    # model and requires owner confirmation before customer pricing is enabled.
    urgency=(holding_urgency+shelf_life_urgency)/2 if produced else holding_urgency
    result={
        'purchase_date':bought.isoformat(),
        'production_date':produced.isoformat() if produced else None,
        'expiry_date':expires.isoformat(),
        'today':current.isoformat(),
        'age_basis':'purchase_and_production' if produced else 'purchase_date_fallback',
        'days_since_purchase':held_days,
        'days_since_production':production_age_days,
        'holding_urgency':holding_urgency,
        'shelf_life_urgency':shelf_life_urgency,
        'days_until_expiry':remaining,
        'urgency':urgency,
        'purchase_cost_toman':purchase_cost,
        'normal_price_toman':normal_price,
        'market_price_toman':market_price,
        'market_source':market_source.strip(),
        'market_observed_date':observed.isoformat(),
        'near_cost_alert_configured':near_cost_threshold_toman is not None,
        'near_cost_threshold_toman':near_cost_threshold_toman,
        'near_cost_alert':False,
    }
    if urgency<0.5:
        return {**result,'state':'normal_period','suggested_price_toman':None,
                'discount_from_normal_toman':0,'reason':'clearance_urgency_below_proposed_start'}

    starting=min(normal_price,market_price)
    if starting<=purchase_cost:
        alert=(near_cost_threshold_toman is not None
               and starting<=purchase_cost+near_cost_threshold_toman)
        return {**result,'state':'near_cost_review','suggested_price_toman':None,
                'discount_from_normal_toman':0,'near_cost_alert':alert,
                'reason':'normal_or_market_price_is_at_or_below_purchase_cost'}

    suggested=int(purchase_cost+(starting-purchase_cost)*(1.0-urgency))
    # Preserve a visible strict discount against the same normal-period price.
    suggested=max(purchase_cost,min(suggested,normal_price-1))
    alert=(near_cost_threshold_toman is not None
           and suggested<=purchase_cost+near_cost_threshold_toman)
    state=('near_cost_alert' if alert else
           'alert_threshold_required' if near_cost_threshold_toman is None else 'proposed')
    return {**result,'state':state,'suggested_price_toman':suggested,
            'discount_from_normal_toman':normal_price-suggested,
            'near_cost_alert':alert,
            'reason':'owner_review_only; price is not published automatically'}


class ClearanceLedger:
    def __init__(self,path):
        self.path=os.path.realpath(os.fspath(path))
        os.makedirs(os.path.dirname(self.path),exist_ok=True)
        with self._connect() as db:
            db.execute('''CREATE TABLE IF NOT EXISTS clearance_proposals (
                proposal_id TEXT PRIMARY KEY, lot_id TEXT NOT NULL, sku TEXT NOT NULL,
                receipt_hash TEXT NOT NULL, actor_id TEXT NOT NULL, note TEXT NOT NULL,
                inputs TEXT NOT NULL, result TEXT NOT NULL, created_at TEXT NOT NULL)''')
        os.chmod(self.path,0o600)

    @contextmanager
    def _connect(self):
        db=sqlite3.connect(self.path,timeout=15)
        db.row_factory=sqlite3.Row
        try:
            with db:yield db
        finally:db.close()

    def record(self,*,lot_id,sku,receipt_hash,actor_id,note,inputs,result,created_at):
        if not all(isinstance(value,str) and value.strip() for value in
                   (lot_id,sku,receipt_hash,actor_id,note)) or len(note)>1000:
            raise ValueError('lot, receipt, owner and note are required')
        identity={'lot_id':lot_id,'sku':sku,'receipt_hash':receipt_hash,'actor_id':actor_id,
                  'note':note.strip(),'inputs':inputs,'result':result}
        proposal_id=hashlib.sha256(json.dumps(identity,sort_keys=True,ensure_ascii=False,
            separators=(',',':'),allow_nan=False).encode()).hexdigest()
        with self._connect() as db:
            db.execute('INSERT OR IGNORE INTO clearance_proposals VALUES (?,?,?,?,?,?,?,?,?)',
                (proposal_id,lot_id,sku,receipt_hash,actor_id,note.strip(),
                 json.dumps(inputs,sort_keys=True,ensure_ascii=False,allow_nan=False),
                 json.dumps(result,sort_keys=True,ensure_ascii=False,allow_nan=False),created_at))
        return self.get(proposal_id)

    def get(self,proposal_id):
        with self._connect() as db:
            row=db.execute('SELECT * FROM clearance_proposals WHERE proposal_id=?',(proposal_id,)).fetchone()
        return self._decode(row) if row else None

    def recent(self,*,limit=100):
        limit=max(1,min(int(limit),200))
        with self._connect() as db:
            rows=db.execute('SELECT * FROM clearance_proposals ORDER BY created_at DESC,proposal_id LIMIT ?',
                            (limit,)).fetchall()
        return [self._decode(row) for row in rows]

    @staticmethod
    def _decode(row):
        item=dict(row)
        item['inputs']=json.loads(item['inputs'])
        item['result']=json.loads(item['result'])
        return item
