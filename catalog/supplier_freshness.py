"""Durable, revision-bound supplier price verification for offers in Products."""
import hashlib
import json
import os
import sqlite3
from datetime import datetime, timezone
from contextlib import contextmanager
from decimal import Decimal, InvalidOperation
from .launch_policies import supplier_price_state


def _utc(value):
    if not isinstance(value, datetime) or value.tzinfo is None or value.utcoffset() is None:
        raise ValueError('timezone-aware timestamp required')
    return value.astimezone(timezone.utc)


class PriceFreshness:
    def __init__(self, path):
        self.path=str(path)
        with self._connect() as db:
            db.execute('''CREATE TABLE IF NOT EXISTS supplier_price_evidence (
                offer_id TEXT PRIMARY KEY, source_revision TEXT NOT NULL,
                verified_at TEXT NOT NULL, method TEXT NOT NULL, verified_by TEXT)''')
            db.execute('''CREATE TABLE IF NOT EXISTS supplier_price_evidence_history (
                id INTEGER PRIMARY KEY, offer_id TEXT NOT NULL, source_revision TEXT NOT NULL,
                verified_at TEXT NOT NULL, method TEXT NOT NULL, verified_by TEXT,
                note TEXT NOT NULL DEFAULT '')''')
            columns={row['name'] for row in db.execute('PRAGMA table_info(supplier_price_evidence_history)')}
            if 'note' not in columns:
                db.execute("ALTER TABLE supplier_price_evidence_history ADD COLUMN note TEXT NOT NULL DEFAULT ''")
        os.chmod(self.path,0o600)

    @contextmanager
    def _connect(self):
        db=sqlite3.connect(self.path,timeout=15);db.row_factory=sqlite3.Row
        try:
            with db:yield db
        finally:db.close()

    def observe_source(self, *, offer_id, source_revision, source_timestamp, now):
        """An original source timestamp is evidence; polling the same revision is not."""
        if not offer_id or not source_revision:raise ValueError('offer and source revision required')
        timestamp=_utc(source_timestamp);current=_utc(now)
        if timestamp>current:raise ValueError('future source timestamp')
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            old=db.execute('SELECT * FROM supplier_price_evidence WHERE offer_id=?',(offer_id,)).fetchone()
            if old and (old['source_revision']==source_revision or timestamp<=datetime.fromisoformat(old['verified_at'])):
                return False
            db.execute('''INSERT INTO supplier_price_evidence VALUES (?,?,?,'source',NULL)
                ON CONFLICT(offer_id) DO UPDATE SET source_revision=excluded.source_revision,
                verified_at=excluded.verified_at,method=excluded.method,verified_by=NULL''',
                (offer_id,source_revision,timestamp.isoformat()))
            db.execute("INSERT INTO supplier_price_evidence_history (offer_id,source_revision,verified_at,method,verified_by,note) VALUES (?,?,?,'source',NULL,'')",
                       (offer_id,source_revision,timestamp.isoformat()))
            return True

    def verify_manually(self, *, offer_id, source_revision, actor_id, note, now):
        """Caller must authenticate an authorized actor and actually verify the offer."""
        if not offer_id or not source_revision or not actor_id or not isinstance(note,str) or not note.strip():
            raise ValueError('offer, current revision, actor and verification note required')
        if len(note)>1000:raise ValueError('verification note exceeds length limit')
        timestamp=_utc(now)
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            old=db.execute('SELECT * FROM supplier_price_evidence WHERE offer_id=?',(offer_id,)).fetchone()
            if old and timestamp<datetime.fromisoformat(old['verified_at']):raise ValueError('verification time regressed')
            db.execute('''INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by)
                VALUES (?,?,?,'manual',?) ON CONFLICT(offer_id) DO UPDATE SET source_revision=excluded.source_revision,
                verified_at=excluded.verified_at,method='manual',verified_by=excluded.verified_by''',
                (offer_id,source_revision,timestamp.isoformat(),actor_id))
            db.execute("INSERT INTO supplier_price_evidence_history (offer_id,source_revision,verified_at,method,verified_by,note) VALUES (?,?,?,'manual',?,?)",
                       (offer_id,source_revision,timestamp.isoformat(),actor_id,note.strip()))

    def state(self, offer_id, *, now, expected_revision):
        with self._connect() as db:
            row=db.execute('SELECT * FROM supplier_price_evidence WHERE offer_id=?',(offer_id,)).fetchone()
        if not row:return {'state':'needs_review','verified_at':None}
        if not expected_revision or expected_revision != row['source_revision']:
            return {'state':'needs_review','verified_at':row['verified_at']}
        return {'state':supplier_price_state(datetime.fromisoformat(row['verified_at']),now),
                'verified_at':row['verified_at'],'method':row['method']}


def source_price_revision(record):
    """Revision changes when the supplier offer identity or its quoted cost changes."""
    offer_id=str(record.get('Latest Offer ID') or '').strip()
    if not offer_id:
        product_id=str(record.get('Product ID') or '').strip()
        offer_id='sheet:'+product_id if product_id else ''
    raw_sale=str(record.get('Sale Price') or '').strip().replace(',','').replace('٬','')
    raw_sale=raw_sale.translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789'))
    if not offer_id or not raw_sale:
        return ''
    try:
        number=Decimal(raw_sale)
        if not number.is_finite() or number<=0:return ''
        sale=str(int(number)) if number==number.to_integral_value() else format(number.normalize(),'f')
    except InvalidOperation:
        return ''
    material=json.dumps([offer_id,sale,str(record.get('Currency') or '').strip()],ensure_ascii=False,separators=(',',':'))
    return hashlib.sha256(material.encode()).hexdigest()


def source_datetime(value):
    """Parse Telegram epoch or timezone-aware ISO evidence without guessing a zone."""
    try:
        if isinstance(value,(int,float)) and not isinstance(value,bool):
            return datetime.fromtimestamp(value,tz=timezone.utc)
        raw=str(value or '').strip()
        if raw.isdigit():
            return datetime.fromtimestamp(int(raw),tz=timezone.utc)
        parsed=datetime.fromisoformat(raw.replace('Z','+00:00'))
        if parsed.tzinfo is None or parsed.utcoffset() is None:
            return None
        return parsed.astimezone(timezone.utc)
    except (OverflowError,OSError,TypeError,ValueError):
        return None


def current_supplier_offers(store, ledger, *, now):
    """Observe immutable source evidence and evaluate every current Products row."""
    rows=store.read('Products') or []
    if len(rows)<2 or not isinstance(rows[0],list):
        return []
    headers=[str(value or '') for value in rows[0]]
    offers=[]
    for raw in rows[1:]:
        record={header:raw[index] if index<len(raw) else '' for index,header in enumerate(headers)}
        product_id=str(record.get('Product ID') or '').strip()
        if not product_id:
            continue
        revision=source_price_revision(record)
        stamp=source_datetime(record.get('Message Timestamp'))
        invalid_future=stamp is not None and stamp>_utc(now)
        if revision and stamp:
            try:
                ledger.observe_source(offer_id=product_id,source_revision=revision,source_timestamp=stamp,now=now)
            except ValueError:
                pass
        state=ledger.state(product_id,now=now,expected_revision=revision)
        if invalid_future:
            state={**state,'state':'needs_review'}
        offers.append({
            'product_id':product_id,'name':str(record.get('Product Name') or ''),
            'channel':str(record.get('Channel Username') or ''),'sale_price':record.get('Sale Price') or '',
            'source_revision':revision,'source_timestamp':stamp.isoformat() if stamp else None,
            **state,
        })
    return offers
