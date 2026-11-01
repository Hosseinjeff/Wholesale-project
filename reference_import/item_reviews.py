"""Audited owner decisions for supplier-offer reference matching.

Supplier offers remain append-only. These decisions are scoped to one offer and
one immutable reference snapshot; only an explicit owner match can affect the
current Products projection or future offers with the same normalized identity.
"""
import hashlib
import json
import os
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timezone

from .matching import product_signature, ranked_candidates


class ItemReviewConflict(ValueError):
    pass


def review_case_id(offer_id, snapshot_id):
    raw = f'{offer_id}\0{snapshot_id}'.encode()
    return 'item:' + hashlib.sha256(raw).hexdigest()


def offer_attributes(offer):
    return {
        'name': offer.get('Product Name') or '',
        'brand': offer.get('Brand') or '',
        'category': offer.get('Category') or '',
        'variation_type': offer.get('Variation Type') or '',
        'package_count': offer.get('Package Count') or '',
        'packaging': offer.get('Packaging') or '',
        'volume': offer.get('Volume') or '',
        'weight': offer.get('Weight') or '',
    }


class ItemReviewLedger:
    def __init__(self, database, *, owner_ids):
        self.database = os.path.realpath(os.fspath(database))
        self.owner_ids = frozenset(str(value) for value in owner_ids)
        if not self.owner_ids:
            raise ValueError('Explicit business-owner identities required')
        with self.connect() as db:
            db.execute('''CREATE TABLE IF NOT EXISTS reference_item_decisions (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT,
                decision_id TEXT NOT NULL UNIQUE,
                case_id TEXT NOT NULL,
                offer_id TEXT NOT NULL,
                snapshot_id TEXT NOT NULL,
                signature TEXT NOT NULL,
                choice TEXT NOT NULL,
                reference_id TEXT NOT NULL,
                price_role TEXT NOT NULL,
                actor_id TEXT NOT NULL,
                note TEXT NOT NULL,
                created_at TEXT NOT NULL,
                supersedes_decision_id TEXT)''')
            db.execute('CREATE INDEX IF NOT EXISTS reference_item_case_idx '
                       'ON reference_item_decisions(case_id, sequence)')
            db.execute('CREATE INDEX IF NOT EXISTS reference_item_signature_idx '
                       'ON reference_item_decisions(snapshot_id, signature, sequence)')
        os.chmod(self.database, 0o600)

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.database, timeout=10)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    @staticmethod
    def _decision_id(values):
        raw = json.dumps(values, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
        return hashlib.sha256(raw.encode()).hexdigest()

    def get(self, case_id):
        with self.connect() as db:
            row = db.execute('SELECT * FROM reference_item_decisions WHERE case_id=? '
                             'ORDER BY sequence DESC LIMIT 1', (case_id,)).fetchone()
        return dict(row) if row else None

    def get_by_decision_id(self, decision_id):
        if not decision_id:
            return None
        with self.connect() as db:
            row = db.execute('SELECT * FROM reference_item_decisions WHERE decision_id=?',
                             (decision_id,)).fetchone()
        return dict(row) if row else None

    def decide(self, *, case_id, offer_id, snapshot_id, signature, choice,
               reference_id, price_role, actor_id, note, expected_decision_id=None):
        if actor_id not in self.owner_ids:
            raise PermissionError('Business-owner decision required')
        if (not all(isinstance(value, str) and value.strip() for value in
                    (case_id, offer_id, snapshot_id, signature, actor_id, note))
                or len(note) > 1000 or len(case_id) > 300 or len(offer_id) > 300
                or len(snapshot_id) > 300 or len(signature) > 128):
            raise ValueError('Review identity and bounded owner note required')
        if choice == 'match':
            if not isinstance(reference_id, str) or not reference_id.strip() or len(reference_id) > 300:
                raise ValueError('Reference identity required')
            if price_role not in {'unit', 'package'}:
                raise ValueError('Owner must select a price role')
        elif choice == 'no_match':
            if reference_id not in ('', None) or price_role != 'unresolved':
                raise ValueError('No-match decisions must remain unresolved')
            reference_id = ''
        else:
            raise ValueError('Unsupported item review choice')
        payload = {'case_id': case_id, 'offer_id': offer_id, 'snapshot_id': snapshot_id,
                   'signature': signature, 'choice': choice, 'reference_id': reference_id or '',
                   'price_role': price_role, 'actor_id': actor_id, 'note': note.strip()}
        decision_id = self._decision_id(payload)
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            existing = db.execute('SELECT * FROM reference_item_decisions WHERE decision_id=?',
                                  (decision_id,)).fetchone()
            if existing:
                return dict(existing)
            latest = db.execute('SELECT * FROM reference_item_decisions WHERE case_id=? '
                                'ORDER BY sequence DESC LIMIT 1', (case_id,)).fetchone()
            latest_id = latest['decision_id'] if latest else None
            if latest_id != expected_decision_id:
                raise ItemReviewConflict('Review changed; reload the current decision')
            if latest and (latest['offer_id'], latest['snapshot_id'], latest['signature']) != (
                    offer_id, snapshot_id, signature):
                raise ItemReviewConflict('Case identity changed')
            now = datetime.now(timezone.utc).isoformat()
            db.execute('''INSERT INTO reference_item_decisions
                (decision_id,case_id,offer_id,snapshot_id,signature,choice,reference_id,
                 price_role,actor_id,note,created_at,supersedes_decision_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)''',
                (decision_id, case_id, offer_id, snapshot_id, signature, choice,
                 reference_id or '', price_role, actor_id, note.strip(), now, latest_id))
            return dict(db.execute('SELECT * FROM reference_item_decisions WHERE decision_id=?',
                                   (decision_id,)).fetchone())

    def overrides(self, snapshot_id):
        """Return only unambiguous, current owner mappings for this snapshot."""
        with self.connect() as db:
            rows = db.execute('''SELECT d.* FROM reference_item_decisions d JOIN (
                SELECT case_id, MAX(sequence) AS sequence FROM reference_item_decisions
                WHERE snapshot_id=? GROUP BY case_id) latest ON latest.sequence=d.sequence''',
                (snapshot_id,)).fetchall()
        grouped = {}
        for row in rows:
            value = {'choice': row['choice'], 'reference_id': row['reference_id'], 'price_role': row['price_role'],
                     'decision_id': row['decision_id']}
            grouped.setdefault(row['signature'], []).append(value)
        result = {}
        for signature, values in grouped.items():
            mappings = {(row['reference_id'], row['price_role']) for row in values}
            if (all(row['choice'] == 'match' for row in values) and len(mappings) == 1):
                result[signature] = values[-1]
        return result


def review_cases(sheet_store, snapshot, ledger, *, include_decided=False):
    """Build owner cases only for current Products rows and the active snapshot."""
    from catalog.sync import _fresh_read, _records
    snapshot_id = str(snapshot.get('snapshot_id') or '')
    if not snapshot_id:
        return []
    _, products = _records(_fresh_read(sheet_store, 'Products'))
    _, offers = _records(_fresh_read(sheet_store, 'Offers'))
    offers_by_id = {str(row.get('Offer ID') or ''): row for row in offers if row.get('Offer ID')}
    cases = []
    for product in products:
        offer_id = str(product.get('Latest Offer ID') or '')
        offer = offers_by_id.get(offer_id)
        if not offer or str(offer.get('Reference Snapshot ID') or '') != snapshot_id:
            continue
        match_state = str(offer.get('Reference Match State') or '')
        role_hint = str(offer.get('Reference Price Role Hint') or '')
        if match_state in {'exact_compatible_identity', 'owner_approved_mapping'} and role_hint in {'unit', 'package'}:
            continue
        case_id = review_case_id(offer_id, snapshot_id)
        decision = ledger.get(case_id)
        if decision and not include_decided:
            continue
        attrs = offer_attributes(offer)
        cases.append({
            'case_id': case_id, 'offer_id': offer_id, 'snapshot_id': snapshot_id,
            'product_name': str(offer.get('Product Name') or ''),
            'channel': str(offer.get('Channel Username') or ''),
            'supplier_price': str(offer.get('Price') or offer.get('Sale Price') or ''),
            'match_state': match_state, 'price_role_hint': role_hint,
            'reason': str(offer.get('Review Reason') or match_state or 'reference_review'),
            'attributes': attrs, 'signature': product_signature(attrs),
            'candidates': ranked_candidates(attrs, snapshot.get('rows') or [], limit=12),
            'decision': decision,
        })
    return cases
