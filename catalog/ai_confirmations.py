"""Durable owner decisions for uncertain supplier-product extraction."""
from __future__ import annotations

import hashlib
import json
import os
import sqlite3
import time
from contextlib import contextmanager
from pathlib import Path


class ConfirmationConflict(ValueError):
    pass


class AIConfirmationLedger:
    def __init__(self, path):
        self.path = os.path.realpath(os.fspath(path))
        Path(self.path).parent.mkdir(parents=True, exist_ok=True)
        with self.connect() as db:
            db.execute('''CREATE TABLE IF NOT EXISTS ai_confirmation_decisions (
                decision_id TEXT PRIMARY KEY, case_id TEXT NOT NULL, offer_id TEXT NOT NULL,
                signature TEXT NOT NULL, choice TEXT NOT NULL, fields_json TEXT NOT NULL,
                actor_id TEXT NOT NULL, note TEXT NOT NULL, created_at REAL NOT NULL)''')
            db.execute('''CREATE INDEX IF NOT EXISTS ai_confirmation_case_history
                ON ai_confirmation_decisions(case_id,created_at)''')
        os.chmod(self.path, 0o600)

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path, timeout=15)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    def history(self, case_id=None, limit=100):
        with self.connect() as db:
            if case_id:
                rows = db.execute('SELECT * FROM ai_confirmation_decisions WHERE case_id=? ORDER BY created_at DESC LIMIT ?',
                                  (case_id, limit)).fetchall()
            else:
                rows = db.execute('SELECT * FROM ai_confirmation_decisions ORDER BY created_at DESC LIMIT ?',
                                  (limit,)).fetchall()
        return [self._decode(row) for row in rows]

    def latest(self, case_id):
        rows = self.history(case_id, 1)
        return rows[0] if rows else None

    def decide(self, *, case_id, offer_id, signature, choice, fields, actor_id, note,
               expected_decision_id=None):
        identity = {
            'case_id': case_id, 'offer_id': offer_id, 'signature': signature,
            'choice': choice, 'fields': fields, 'actor_id': actor_id,
            'note': note.strip(), 'expected_decision_id': expected_decision_id,
        }
        decision_id = hashlib.sha256(json.dumps(identity, sort_keys=True, ensure_ascii=False,
                                                  separators=(',', ':'), allow_nan=False).encode()).hexdigest()
        raw_fields = json.dumps(fields, sort_keys=True, ensure_ascii=False, separators=(',', ':'), allow_nan=False)
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            existing = db.execute('SELECT * FROM ai_confirmation_decisions WHERE decision_id=?',
                                  (decision_id,)).fetchone()
            if existing:
                current = db.execute('SELECT decision_id FROM ai_confirmation_decisions WHERE case_id=? ORDER BY created_at DESC LIMIT 1',
                                     (case_id,)).fetchone()
                if not current or current['decision_id'] != decision_id:
                    raise ConfirmationConflict('a newer decision already exists')
                return self._decode(existing)
            current = db.execute('SELECT decision_id FROM ai_confirmation_decisions WHERE case_id=? ORDER BY created_at DESC LIMIT 1',
                                 (case_id,)).fetchone()
            current_id = current['decision_id'] if current else None
            if current_id != expected_decision_id:
                raise ConfirmationConflict('decision changed; refresh the dashboard')
            now = time.time()
            db.execute('''INSERT INTO ai_confirmation_decisions
                (decision_id,case_id,offer_id,signature,choice,fields_json,actor_id,note,created_at)
                VALUES (?,?,?,?,?,?,?,?,?)''',
                (decision_id,case_id,offer_id,signature,choice,raw_fields,actor_id,note.strip(),now))
            row = db.execute('SELECT * FROM ai_confirmation_decisions WHERE decision_id=?',
                             (decision_id,)).fetchone()
        return self._decode(row)

    @staticmethod
    def _decode(row):
        result = dict(row)
        result['fields'] = json.loads(result.pop('fields_json'))
        result['created_at'] = float(result['created_at'])
        return result


def _stable(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':'),
                      default=str).encode()


def review_case_id(offer_id):
    return 'ai:' + hashlib.sha256(str(offer_id).encode()).hexdigest()


def _reference_only(reason):
    reasons = [part.strip().lower() for part in str(reason or '').split(';') if part.strip()]
    return bool(reasons) and all(part.startswith('reference:') for part in reasons)


def _as_case(offer, ledger, *, include_decided=False):
    offer_id = str(offer.get('Offer ID') or '')
    if not offer_id:
        return None
    reason = str(offer.get('Review Reason') or '')
    if str(offer.get('Status') or '').strip().lower() != 'needs_review' or not reason or _reference_only(reason):
        return None
    signature = hashlib.sha256(_stable(offer)).hexdigest()
    case_id = review_case_id(offer_id)
    latest = ledger.latest(case_id)
    if latest and latest['signature'] == signature and not include_decided:
        return None
    stock = str(offer.get('Stock Status') or '').strip().lower()
    if stock in {'available','in stock','instock','موجود'}:
        stock_status = 'Available'
    elif stock in {'out of stock','outofstock','unavailable','sold out','ناموجود'}:
        stock_status = 'Out of Stock'
    else:
        stock_status = 'Unknown'
    return {
        'case_id': case_id, 'offer_id': offer_id, 'signature': signature,
        'product_name': str(offer.get('Product Name') or ''),
        'channel': str(offer.get('Channel Username') or ''),
        'source_channel': str(offer.get('Channel') or ''),
        'batch_id': str(offer.get('Batch ID') or ''),
        'message_id': str(offer.get('Message ID') or ''),
        'message_timestamp': str(offer.get('Message Timestamp') or ''),
        'original_message': str(offer.get('Original Message') or ''),
        'reason': reason,
        'sale_price': offer.get('Sale Price') or '',
        'detected_price': offer.get('Detected Price') or offer.get('Sale Price') or '',
        'actual_price': offer.get('Actual Price') or offer.get('Consumer Price') or '',
        'package_price': offer.get('Package Price') or '',
        'package_count': offer.get('Package Count') or '',
        'min_qty': offer.get('Min Qty') or '',
        'price_type': str(offer.get('Price Type') or ''),
        'currency': str(offer.get('Currency') or ''),
        'packaging': str(offer.get('Packaging') or ''),
        'stock_status': stock_status,
        'reference_match_state': str(offer.get('Reference Match State') or ''),
        'previous_decision_id': latest['decision_id'] if latest else None,
    }


def find_case(store, ledger, case_id, offer_id):
    from catalog.sync import _records, _read_sheet_if_present
    _, offers = _records(_read_sheet_if_present(store, 'Offers'))
    source = next((item for item in offers if str(item.get('Offer ID') or '') == offer_id), None)
    if source is None or review_case_id(offer_id) != case_id:
        return None
    return _as_case(source, ledger, include_decided=True)


def review_cases(store, ledger, *, include_history=False, limit=200):
    from catalog.sync import _records, _read_sheet_if_present
    raw = _read_sheet_if_present(store, 'Offers')
    _, offers = _records(raw)
    cases = []
    all_by_offer = {}
    for offer in offers:
        offer_id = str(offer.get('Offer ID') or '')
        if not offer_id:
            continue
        case_id = review_case_id(offer_id)
        latest = ledger.latest(case_id)
        case = _as_case(offer, ledger)
        if case:
            cases.append(case)
        all_by_offer[offer_id] = (offer, latest)
    cases.sort(key=lambda row: (row['message_timestamp'], row['offer_id']), reverse=True)
    if not include_history:
        return cases[:limit]
    recent = []
    seen_cases = set()
    for decision in ledger.history(limit=limit):
        if decision['case_id'] in seen_cases:
            continue
        seen_cases.add(decision['case_id'])
        pair = all_by_offer.get(decision['offer_id'])
        if pair:
            case = _as_case(pair[0], ledger, include_decided=True)
            if case:
                recent.append({**case, 'decision': decision})
    return cases[:limit], recent[:limit]


def validate_choice(body):
    if not isinstance(body, dict) or set(body) != {
            'case_id','offer_id','signature','choice','fields','note','expected_decision_id'}:
        raise ValueError('invalid_body')
    for key, maximum in (('case_id', 100),('offer_id', 300),('signature', 64),
                         ('choice', 20),('note', 1000)):
        value = body.get(key)
        if not isinstance(value, str) or len(value) > maximum:
            raise ValueError('invalid_fields')
    if not body['note'].strip() or len(body['signature']) != 64:
        raise ValueError('invalid_fields')
    expected = body['expected_decision_id']
    if expected is not None and (not isinstance(expected, str) or len(expected) != 64):
        raise ValueError('invalid_fields')
    fields = body['fields']
    if body['choice'] == 'not_product':
        if fields != {}:
            raise ValueError('invalid_fields')
        return
    required = {'price_role','sale_price','actual_price','package_count','min_qty','stock_status'}
    if body['choice'] != 'confirm' or not isinstance(fields, dict) or set(fields) != required:
        raise ValueError('invalid_fields')
    if fields['price_role'] not in {'unit','package'}:
        raise ValueError('invalid_price_role')
    def integer(value, *, optional=False):
        if optional and value in ('', None):
            return None
        if isinstance(value, bool) or not isinstance(value, int) or value < 0:
            raise ValueError('invalid_price')
        return value
    sale = integer(fields['sale_price'])
    actual = integer(fields['actual_price'], optional=True)
    count = integer(fields['package_count'], optional=True)
    minimum = integer(fields['min_qty'], optional=True)
    if sale <= 0 or (actual is not None and actual <= 0):
        raise ValueError('invalid_price')
    if fields['price_role'] == 'package' and (not count or count <= 0):
        raise ValueError('package_count_required')
    if count is not None and count > 100000 or minimum is not None and minimum > 100000:
        raise ValueError('invalid_quantity')
    if fields['stock_status'] not in {'Available','Out of Stock','Unknown'}:
        raise ValueError('invalid_stock_status')


def apply_decision(store, ledger, case, decision):
    """Append a derived Offer then rebuild Products; never alter supplier source rows."""
    from datetime import datetime, timezone
    from catalog.sync import (
        OFFER_HEADERS, _append_at_next_row, _headers, _offer_fields, _positive_price,
        _read_sheet_if_present, _records, _restore_current_book, _row_from_map,
    )
    source_rows = _read_sheet_if_present(store, 'Offers')
    headers, offers = _records(source_rows)
    source = next((item for item in offers if str(item.get('Offer ID') or '') == case['offer_id']), None)
    if source is None:
        raise ConfirmationConflict('supplier offer no longer exists')
    if hashlib.sha256(_stable(source)).hexdigest() != case['signature']:
        raise ConfirmationConflict('supplier offer changed while confirmation was being applied')
    decision_id = decision['decision_id']
    derived_id = 'owner-confirm:' + decision_id
    if any(str(item.get('Offer ID') or '') == derived_id for item in offers):
        _restore_current_book(store, [(str(source.get('Product Name') or ''),
                                       str(source.get('Channel Username') or ''))])
        return derived_id
    store.ensure('Offers', OFFER_HEADERS)
    headers = _headers(store, 'Offers', OFFER_HEADERS)
    now = datetime.now(timezone.utc).isoformat()
    product = {
        'name': source.get('Product Name') or '', 'currency': 'IRT',
        'packaging': source.get('Packaging') or '', 'volume': source.get('Volume') or '',
        'category': source.get('Category') or '', 'variation_type': source.get('Variation Type') or '',
        'stock_status': source.get('Stock Status') or 'Unknown',
        'description': source.get('Description') or '', 'confidence': 1.0,
        'extraction_confidence': source.get('Extraction Confidence Score') or source.get('Confidence') or 1.0,
        'brand': source.get('Brand') or '', 'weight': source.get('Weight') or '',
        'package_count': source.get('Package Count') or '', 'min_qty': source.get('Min Qty') or '',
        'status': 'not_product', 'price_type': source.get('Price Type') or '',
    }
    reason = ''
    if decision['choice'] == 'confirm':
        fields = decision['fields']
        count = fields['package_count']
        source_price = fields['sale_price']
        if fields['price_role'] == 'package':
            unit_price = (source_price + count // 2) // count
            package_price = source_price
            product['min_qty'] = max(int(fields.get('min_qty') or 0), count)
        else:
            unit_price = source_price
            package_price = _positive_price(source.get('Package Price')) or ''
            product['min_qty'] = int(fields.get('min_qty') or _positive_price(source.get('Min Qty')) or 1)
        product.update({
            'sale_price': unit_price, 'price': unit_price, 'actual_price': fields['actual_price'] or '',
            'consumer_price': fields['actual_price'] or '', 'package_price': package_price,
            'package_count': count or source.get('Package Count') or '',
            'price_type': 'unit', 'stock_status': fields['stock_status'], 'status': 'imported',
        })
        reasons = [part.strip() for part in str(source.get('Review Reason') or '').split(';')
                   if part.strip().lower().startswith('reference:')]
        if reasons:
            reason = '; '.join(reasons)
            product['status'] = 'needs_review'
    imported = _offer_fields(product, {
        'id': source.get('Message ID') or '', 'channel': source.get('Channel') or '',
        'channel_username': source.get('Channel Username') or '',
        'content': source.get('Original Message') or '', 'timestamp': source.get('Message Timestamp') or '',
        'forwarded_by': source.get('Forwarded By') or '', 'batch_id': 'owner-ai-confirmation:' + decision_id,
        'image_file_id': source.get('Image File ID') or '',
        'image_file_unique_id': source.get('Image File Unique ID') or '',
    }, derived_id, now, reason)
    imported.update({
        'Package Price': product.get('package_price') or '',
        'Reference Snapshot ID': source.get('Reference Snapshot ID') or '',
        'Reference Match State': source.get('Reference Match State') or '',
        'Reference Match ID': source.get('Reference Match ID') or '',
        'Reference Price Role Hint': source.get('Reference Price Role Hint') or '',
        'Reference Decision ID': source.get('Reference Decision ID') or '',
        'Reference Usage Warning': source.get('Reference Usage Warning') or '',
        'Derived From Offer ID': case['offer_id'], 'Owner Decision ID': decision_id,
        'Owner Decision Actor': decision['actor_id'], 'Owner Decision At': now,
    })
    _append_at_next_row(store, 'Offers', headers, [_row_from_map(headers, imported)])
    _restore_current_book(store, [(str(product['name']), str(source.get('Channel Username') or ''))])
    return derived_id
