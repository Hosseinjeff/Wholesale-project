"""Private, append-only snapshots of current product approval and update state."""
from __future__ import annotations

import hashlib
import json
import os
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path


class ProductStatusLedger:
    STATE_SCHEMA_VERSION = 2

    def __init__(self, database):
        self.database = os.path.realpath(os.fspath(database))
        with self.connect() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS product_status_snapshots (
                snapshot_id TEXT PRIMARY KEY, captured_at TEXT NOT NULL, reason TEXT NOT NULL,
                product_count INTEGER NOT NULL, changed_count INTEGER NOT NULL,
                record_digest TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS product_status_records (
                snapshot_id TEXT NOT NULL, product_key TEXT NOT NULL, product_name TEXT NOT NULL,
                state_json TEXT NOT NULL, PRIMARY KEY(snapshot_id, product_key));
                CREATE INDEX IF NOT EXISTS product_status_captured_idx
                ON product_status_snapshots(captured_at DESC);''')
            columns = {row['name'] for row in db.execute('PRAGMA table_info(product_status_snapshots)')}
            if 'schema_version' not in columns:
                db.execute('ALTER TABLE product_status_snapshots '
                           'ADD COLUMN schema_version INTEGER NOT NULL DEFAULT 0')
        os.chmod(self.database, 0o600)

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.database, timeout=15)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    @staticmethod
    def _key(record):
        identity = str(record.get('Product ID') or '').strip()
        if identity:
            return identity
        material = '|'.join(str(record.get(key) or '') for key in
                            ('Product Name', 'Channel Username', 'Latest Offer ID'))
        return hashlib.sha256(material.encode('utf-8')).hexdigest()

    @staticmethod
    def _state(record, supplier_evidence=None, source_integrity_issues=None):
        supplier_evidence = supplier_evidence or {}
        source_integrity_issues = list(source_integrity_issues or [])
        approval = str(record.get('Reference Approval State') or '')
        status = str(record.get('Status') or '')
        reference_due = approval in {'outside_margin', 'reference_missing', 'price_unavailable'}
        update_needed = status.strip().lower() in {'needs_review', 'held', 'blocked'} or reference_due
        if approval == 'approved_within_margin':
            reference_result = 'due_no_update_within_margin'
            reference_update_needed = False
        elif reference_due:
            reference_result = 'update_required'
            reference_update_needed = True
        elif approval:
            reference_result = 'checked'
            reference_update_needed = False
        else:
            reference_result = 'not_checked'
            reference_update_needed = False
        supplier_state = str(supplier_evidence.get('state') or '')
        supplier_due = supplier_state in {'blocked', 'needs_review'}
        source_integrity_due = bool(source_integrity_issues)
        update_needed = update_needed or supplier_due or source_integrity_due
        # Original source text and contact values remain in the sheet, not duplicated
        # in this private status history. The post URL is retained for owner review.
        record_copy = {key: value for key, value in record.items()
                       if key not in {'Original Message', 'Contact Info'}}
        return {
            'status_schema_version': ProductStatusLedger.STATE_SCHEMA_VERSION,
            'product_id': str(record.get('Product ID') or ''),
            'product_name': str(record.get('Product Name') or ''),
            'channel': str(record.get('Channel Username') or ''),
            'status': status,
            'latest_offer_id': str(record.get('Latest Offer ID') or ''),
            'source_post_url': str(record.get('Source Post URL') or ''),
            'reference_snapshot_id': str(record.get('Reference Snapshot ID') or ''),
            'reference_match_id': str(record.get('Reference Match ID') or ''),
            'reference_price_role': str(record.get('Reference Price Role') or ''),
            'reference_approval_state': approval,
            'reference_price_change_percent': record.get('Reference Price Change Percent') or '',
            'reference_price_checked_at': str(record.get('Reference Price Checked At') or ''),
            'reference_review_due': reference_due or approval == 'approved_within_margin',
            'reference_review_result': reference_result,
            'reference_update_needed': reference_update_needed,
            'supplier_price_state': supplier_state,
            'supplier_price_verified_at': str(supplier_evidence.get('verified_at') or ''),
            'supplier_price_update_due': supplier_due,
            'source_integrity_issues': source_integrity_issues,
            'source_integrity_update_due': source_integrity_due,
            'update_needed': bool(update_needed),
            'review_reason': str(record.get('Reference Review Reason') or ''),
            'product_record': record_copy,
        }

    def capture(self, rows, *, reason, supplier_evidence=None, source_integrity=None, now=None):
        if not isinstance(rows, list) or not rows or not isinstance(rows[0], list):
            rows = []
        headers = [str(value or '') for value in (rows[0] if rows else [])]
        safe_evidence = supplier_evidence or {}
        safe_integrity = source_integrity or {}
        records = []
        for raw in rows[1:]:
            record = {header: raw[index] if index < len(raw) else ''
                      for index, header in enumerate(headers)}
            if not record.get('Product Name') and not record.get('Product ID'):
                continue
            offer_id = str(record.get('Latest Offer ID') or '')
            product_id = str(record.get('Product ID') or '').strip()
            state = self._state(record, safe_evidence.get(offer_id), safe_integrity.get(product_id))
            records.append((self._key(record), state['product_name'], state))
        records.sort(key=lambda item: item[0])
        captured_at = (now or datetime.now(timezone.utc)).astimezone(timezone.utc).isoformat()
        states = {key: state for key, _name, state in records}
        digest = hashlib.sha256(json.dumps(states, ensure_ascii=False, sort_keys=True,
            separators=(',', ':'), default=str).encode('utf-8')).hexdigest()
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            latest = db.execute('SELECT snapshot_id FROM product_status_snapshots '
                                'ORDER BY captured_at DESC LIMIT 1').fetchone()
            previous = {}
            if latest:
                previous = {row['product_key']: row['state_json'] for row in db.execute(
                    'SELECT product_key,state_json FROM product_status_records WHERE snapshot_id=?',
                    (latest['snapshot_id'],))}
            changed = sum(1 for key, _name, state in records
                          if previous.get(key) != json.dumps(state, ensure_ascii=False,
                              sort_keys=True, separators=(',', ':'), default=str))
            snapshot_id = hashlib.sha256(
                f'{captured_at}\0{reason}\0{digest}'.encode('utf-8')).hexdigest()
            db.execute('''INSERT OR IGNORE INTO product_status_snapshots
                (snapshot_id,captured_at,reason,product_count,changed_count,record_digest,schema_version)
                VALUES (?,?,?,?,?,?,?)''',
                (snapshot_id, captured_at, str(reason)[:120], len(records), changed, digest,
                 self.STATE_SCHEMA_VERSION))
            db.executemany('''INSERT OR IGNORE INTO product_status_records
                (snapshot_id,product_key,product_name,state_json) VALUES (?,?,?,?)''',
                [(snapshot_id, key, name, json.dumps(state, ensure_ascii=False,
                    sort_keys=True, separators=(',', ':'), default=str))
                 for key, name, state in records])
        return {'snapshot_id': snapshot_id, 'captured_at': captured_at,
                'reason': str(reason), 'product_count': len(records), 'changed_count': changed}

    def list_snapshots(self, *, limit=30, snapshot_id=None):
        limit = max(1, min(int(limit), 100))
        with self.connect() as db:
            snapshots = [dict(row) for row in db.execute(
                'SELECT snapshot_id,captured_at,reason,product_count,changed_count,schema_version '
                'FROM product_status_snapshots ORDER BY captured_at DESC LIMIT ?', (limit,))]
            latest_id = snapshots[0]['snapshot_id'] if snapshots else None
            selected_id = str(snapshot_id or latest_id or '')
            selected = db.execute('SELECT snapshot_id FROM product_status_snapshots WHERE snapshot_id=?',
                                  (selected_id,)).fetchone() if selected_id else None
            if snapshot_id and not selected:
                return {'snapshots': snapshots, 'latest_snapshot_id': latest_id,
                        'selected_snapshot_id': None, 'products': []}
            if not selected:
                selected_id = None
            latest_records = []
            if selected_id:
                for row in db.execute('''SELECT state_json FROM product_status_records
                    WHERE snapshot_id=? ORDER BY product_name''', (selected_id,)):
                    latest_records.append(json.loads(row['state_json']))
        return {'snapshots': snapshots, 'latest_snapshot_id': latest_id,
                'selected_snapshot_id': selected_id,
                'products': latest_records}

    def latest_captured_at(self):
        with self.connect() as db:
            row = db.execute('SELECT captured_at FROM product_status_snapshots '
                             'ORDER BY captured_at DESC LIMIT 1').fetchone()
        return row['captured_at'] if row else None

    def latest_schema_version(self):
        with self.connect() as db:
            row = db.execute('SELECT schema_version FROM product_status_snapshots '
                             'ORDER BY captured_at DESC LIMIT 1').fetchone()
        return int(row['schema_version']) if row else 0


def _current_product_status_inputs(store, database, *, now=None):
    """Read current Products, source integrity, and supplier verification evidence."""
    from catalog.sync import _fresh_read, _records
    from catalog.launch_policies import supplier_price_state
    from catalog.supplier_freshness import source_price_revision

    rows = _fresh_read(store, 'Products')
    _, records = _records(rows)
    offers_rows = _fresh_read(store, 'Offers')
    _, offer_records = _records(offers_rows)
    from catalog.source_integrity import product_offer_link_issues
    source_integrity = {str(item.get('product_id') or ''): item.get('codes') or []
                        for item in product_offer_link_issues(records, offer_records)
                        if item.get('product_id')}
    now = now or datetime.now(timezone.utc)
    evidence_rows = {}
    database_path = Path(os.path.realpath(os.fspath(database)))
    if database_path.exists():
        database_uri = database_path.as_uri() + '?mode=ro'
        try:
            with sqlite3.connect(database_uri, uri=True, timeout=15) as db:
                for row in db.execute('SELECT offer_id,source_revision,verified_at,method '
                                      'FROM supplier_price_evidence'):
                    evidence_rows[str(row[0])] = {
                        'source_revision': str(row[1]), 'verified_at': str(row[2]),
                        'method': str(row[3])}
        except sqlite3.OperationalError as exc:
            if 'no such table' not in str(exc).lower():
                raise
    evidence = {}
    for record in records:
        offer_id = str(record.get('Latest Offer ID') or '').strip()
        if not offer_id:
            continue
        revision = source_price_revision(record)
        saved = evidence_rows.get(offer_id)
        verified_at = saved.get('verified_at') if saved else None
        if not saved or not revision or revision != saved.get('source_revision'):
            state = 'needs_review'
        else:
            try:
                state = supplier_price_state(datetime.fromisoformat(verified_at), now)
            except (TypeError, ValueError):
                state = 'needs_review'
        evidence[offer_id] = {'state':state, 'verified_at':verified_at,
            'method':saved.get('method') if saved else None}
    return rows, evidence, source_integrity, now


def read_current_from_store(store, database, *, now=None):
    """Build a live product status view without creating an audit snapshot."""
    from catalog.sync import _records

    rows, evidence, source_integrity, now = _current_product_status_inputs(
        store, database, now=now)
    _, records = _records(rows)
    products = []
    for record in records:
        if not record.get('Product Name') and not record.get('Product ID'):
            continue
        offer_id = str(record.get('Latest Offer ID') or '')
        product_id = str(record.get('Product ID') or '').strip()
        state = ProductStatusLedger._state(record, evidence.get(offer_id),
            source_integrity.get(product_id))
        state.pop('product_record', None)
        products.append(state)
    products.sort(key=lambda item: (item['product_name'], item['product_id']))
    return {'generated_at':now.astimezone(timezone.utc).isoformat(), 'products':products}


def capture_from_store(store, database, *, reason):
    """Capture the current Products sheet and its supplier freshness evaluation."""
    rows, evidence, source_integrity, now = _current_product_status_inputs(store, database)
    return ProductStatusLedger(database).capture(rows, reason=reason,
        supplier_evidence=evidence, source_integrity=source_integrity, now=now)
