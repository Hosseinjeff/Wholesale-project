"""Durable owner decisions for uncertain supplier-product extraction."""
from __future__ import annotations

import hashlib
import json
import os
import re
import sqlite3
import time
import unicodedata
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo
from catalog.media import album_media_bundle
from catalog.stock import normalize_stock_status

_OCR_MEDIA_TYPES = frozenset({'image','photo','document','image_document','document_image'})


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
            db.execute('''CREATE TABLE IF NOT EXISTS ai_message_dismissals (
                message_key TEXT PRIMARY KEY, message_id TEXT NOT NULL, batch_id TEXT NOT NULL,
                channel_username TEXT NOT NULL, source_timestamp TEXT NOT NULL,
                content_sha256 TEXT NOT NULL, media_unique_id TEXT NOT NULL DEFAULT '',
                actor_id TEXT NOT NULL, note TEXT NOT NULL,
                created_at REAL NOT NULL)''')
            columns={str(row['name']) for row in db.execute('PRAGMA table_info(ai_message_dismissals)')}
            if 'media_unique_id' not in columns:
                db.execute("ALTER TABLE ai_message_dismissals ADD COLUMN media_unique_id TEXT NOT NULL DEFAULT ''")
            db.execute('''CREATE TABLE IF NOT EXISTS ai_source_reextractions (
                source_key TEXT PRIMARY KEY, batch_id TEXT NOT NULL, outcome TEXT NOT NULL,
                run_id TEXT NOT NULL, actor_id TEXT NOT NULL, completed_at REAL NOT NULL,
                policy_version TEXT NOT NULL DEFAULT '')''')
            columns={str(row['name']) for row in db.execute('PRAGMA table_info(ai_source_reextractions)')}
            if 'policy_version' not in columns:
                db.execute("ALTER TABLE ai_source_reextractions ADD COLUMN policy_version TEXT NOT NULL DEFAULT ''")
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

    def history_for_cases(self, case_ids, *, per_case=10, total_limit=2000):
        identifiers = list(dict.fromkeys(str(value) for value in case_ids if value))
        if not identifiers:
            return {}
        per_case = max(1, min(int(per_case), 50))
        total_limit = max(per_case, min(int(total_limit), 5000))
        placeholders = ','.join('?' for _ in identifiers)
        with self.connect() as db:
            rows = db.execute(f'''SELECT * FROM ai_confirmation_decisions
                WHERE case_id IN ({placeholders}) ORDER BY created_at DESC LIMIT ?''',
                (*identifiers, total_limit)).fetchall()
        grouped = {}
        for row in rows:
            case_id = str(row['case_id'])
            history = grouped.setdefault(case_id, [])
            if len(history) < per_case:
                history.append(self._decode(row))
        return grouped

    def latest(self, case_id):
        rows = self.history(case_id, 1)
        return rows[0] if rows else None

    def dismissed_message_keys(self):
        with self.connect() as db:
            rows = db.execute('SELECT message_key FROM ai_message_dismissals').fetchall()
        return {str(row['message_key']) for row in rows}

    def message_dismissal(self, message_key):
        with self.connect() as db:
            row=db.execute('SELECT * FROM ai_message_dismissals WHERE message_key=?',
                           (str(message_key or ''),)).fetchone()
        return dict(row) if row else None

    def record_source_reextraction(self, *, source_key, batch_id, outcome, run_id, actor_id,
                                   policy_version=None):
        if policy_version is None:
            from catalog.gemini import extraction_policy_version
            policy_version=extraction_policy_version()
        if (not isinstance(source_key, str) or not re.fullmatch(r'[a-f0-9]{64}', source_key)
                or not isinstance(batch_id, str) or not batch_id.startswith('reextract:')
                or outcome not in {'products', 'no_products'}
                or not isinstance(run_id, str) or not re.fullmatch(r'[a-f0-9]{32}', run_id)
                or not isinstance(actor_id, str) or not actor_id.strip()
                or not isinstance(policy_version,str)
                or not re.fullmatch(r'[a-z0-9][a-z0-9._-]{0,63}',policy_version)):
            raise ValueError('invalid source re-extraction receipt')
        with self.connect() as db:
            db.execute('''INSERT INTO ai_source_reextractions
                (source_key,batch_id,outcome,run_id,actor_id,completed_at,policy_version)
                VALUES (?,?,?,?,?,?,?)
                ON CONFLICT(source_key) DO UPDATE SET batch_id=excluded.batch_id,
                outcome=excluded.outcome,run_id=excluded.run_id,actor_id=excluded.actor_id,
                completed_at=excluded.completed_at,policy_version=excluded.policy_version''',
                (source_key, batch_id, outcome, run_id, actor_id, time.time(),policy_version))

    def source_reextractions(self):
        with self.connect() as db:
            rows = db.execute('SELECT * FROM ai_source_reextractions').fetchall()
        return {str(row['source_key']): dict(row) for row in rows}

    def dismiss_message(self, message, *, actor_id, note=''):
        return self.dismiss_messages([message], actor_id=actor_id, note=note)[0]

    def dismiss_messages(self, messages, *, actor_id, note=''):
        if not isinstance(actor_id,str) or not actor_id.strip():
            raise ValueError('invalid_dismissal_identity')
        if not isinstance(note, str) or len(note) > 1000:
            raise ValueError('invalid_dismissal_note')
        prepared=[]
        for message in messages:
            message_id = str(message.get('message_id') or '').strip()
            batch_id = str(message.get('batch_id') or '')
            channel = str(message.get('channel_username') or '')
            timestamp = str(message.get('timestamp') or '')
            content = str(message.get('content') or '')
            media_identity=str(message.get('media_identity') or message.get('image_file_unique_id')
                or message.get('image_file_id') or '')
            if not message_id or (not content.strip() and not media_identity):
                raise ValueError('invalid_dismissal_identity')
            content_sha256 = hashlib.sha256(content.encode()).hexdigest()
            message_key = reextraction_message_key({
                'message_id': message_id, 'batch_id': batch_id,
                'channel_username': channel, 'timestamp': timestamp, 'content': content,
                'media_identity':media_identity,
            })
            prepared.append((message_key,message_id,batch_id,channel,timestamp,content_sha256,media_identity))
        rows=[]
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            for message_key,message_id,batch_id,channel,timestamp,content_sha256,media_identity in prepared:
                db.execute('''INSERT OR IGNORE INTO ai_message_dismissals
                    (message_key,message_id,batch_id,channel_username,source_timestamp,
                     content_sha256,media_unique_id,actor_id,note,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)''',
                    (message_key,message_id,batch_id,channel,timestamp,content_sha256,media_identity,
                     actor_id,note.strip(),time.time()))
                row=db.execute('SELECT * FROM ai_message_dismissals WHERE message_key=?',
                               (message_key,)).fetchone()
                if not row or (row['message_id'],row['batch_id'],row['channel_username'],
                               row['source_timestamp'],row['content_sha256']) != (
                        message_id,batch_id,channel,timestamp,content_sha256) or str(row['media_unique_id'] or '')!=media_identity:
                    raise ConfirmationConflict('message dismissal identity changed')
                rows.append(dict(row))
        return rows

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


def _historical_reextraction(offer):
    if not str(offer.get('Batch ID') or '').startswith('reextract:'):
        return False
    from catalog.launch_policies import BLOCKING_AGE
    from catalog.supplier_freshness import source_datetime
    stamp = source_datetime(offer.get('Source Post Updated At')
        or offer.get('Message Timestamp') or offer.get('Source Post Created At'))
    now = datetime.now(timezone.utc)
    return stamp is None or stamp > now or now - stamp >= BLOCKING_AGE


def _source_current_check_required(offer):
    """Require a human source check when provenance is untrusted or the quote is stale."""
    from catalog.launch_policies import BLOCKING_AGE
    from catalog.supplier_freshness import source_datetime
    basis = str(offer.get('Source Timestamp Basis') or '').strip()
    if basis not in {'supplier_channel_update', 'supplier_channel_forward'}:
        return True
    stamp = source_datetime(offer.get('Source Post Updated At')
        or offer.get('Message Timestamp') or offer.get('Source Post Created At'))
    now = datetime.now(timezone.utc)
    return stamp is None or stamp > now or now - stamp >= BLOCKING_AGE


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
    stock_status = normalize_stock_status(offer.get('Stock Status'))
    return {
        'case_id': case_id, 'offer_id': offer_id, 'signature': signature,
        'product_name': str(offer.get('Product Name') or ''),
        'channel': str(offer.get('Channel Username') or ''),
        'source_channel': str(offer.get('Channel') or ''),
        'batch_id': str(offer.get('Batch ID') or ''),
        'historical_reextract': _historical_reextraction(offer),
        'source_current_check_required': _source_current_check_required(offer),
        'message_id': str(offer.get('Message ID') or ''),
        'message_timestamp': str(offer.get('Message Timestamp') or ''),
        'source_timestamp_basis': str(offer.get('Source Timestamp Basis') or ''),
        'source_created_at': str(offer.get('Source Post Created At') or ''),
        'source_updated_at': str(offer.get('Source Post Updated At') or ''),
        'source_post_url': str(offer.get('Source Post URL') or ''),
        'original_message': str(offer.get('Original Message') or ''),
        'media_ocr_text': str(offer.get('Media OCR Text') or ''),
        'image_file_id': str(offer.get('Image File ID') or ''),
        'image_file_unique_id': str(offer.get('Image File Unique ID') or ''),
        'brand': str(offer.get('Brand') or ''),
        'category': str(offer.get('Category') or ''),
        'variation_type': str(offer.get('Variation Type') or ''),
        'volume': str(offer.get('Volume') or ''),
        'weight': str(offer.get('Weight') or ''),
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
        'reference_match_id': str(offer.get('Reference Match ID') or ''),
        'reference_price_role_hint': str(offer.get('Reference Price Role Hint') or ''),
        'previous_decision_id': latest['decision_id'] if latest else None,
    }


def _variant_text(value):
    value = unicodedata.normalize('NFKC', str(value or '')).casefold()
    return ' '.join(re.findall(r'\w+', value, flags=re.UNICODE))


def _variant_base_name(case):
    name = _variant_text(case.get('product_name'))
    variant = _variant_text(case.get('variation_type'))
    if variant:
        name = re.sub(r'(?<!\w)' + re.escape(variant) + r'(?!\w)', ' ', name)
    return ' '.join(name.split())


def _annotate_variant_groups(cases):
    """Expose a group only for distinct variants sharing source and exact offer terms."""
    groups = {}
    for case in cases:
        case.pop('variant_group_id', None)
        variant = _variant_text(case.get('variation_type'))
        source = (case.get('channel'), case.get('message_id'), case.get('batch_id'))
        digits = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')
        has_price = any(int(re.sub(r'\D', '', str(case.get(key) or '').translate(digits)) or '0') > 0
                        for key in ('sale_price', 'detected_price', 'package_price'))
        if not variant or not all(source) or not _variant_base_name(case) or not has_price:
            continue
        terms = tuple(case.get(key) for key in (
            'source_channel', 'reason', 'sale_price', 'detected_price', 'actual_price',
            'package_price', 'package_count', 'min_qty', 'price_type', 'currency',
            'packaging', 'volume', 'weight', 'brand', 'category',
            'stock_status', 'historical_reextract',
            'reference_match_state', 'reference_match_id', 'reference_price_role_hint',
            'previous_decision_id',
        ))
        group_key = (source, _variant_base_name(case), terms)
        groups.setdefault(group_key, []).append((case, variant))

    for group_key, members in groups.items():
        variants = {variant for _, variant in members}
        if len(members) < 2 or len(variants) < 2:
            continue
        group_id = hashlib.sha256(_stable(group_key)).hexdigest()
        for case, _ in members:
            case['variant_group_id'] = group_id


def find_case(store, ledger, case_id, offer_id):
    from catalog.sync import _records, _read_sheet_if_present
    _, offers = _records(_read_sheet_if_present(store, 'Offers'))
    source = next((item for item in offers if str(item.get('Offer ID') or '') == offer_id), None)
    if source is None or review_case_id(offer_id) != case_id:
        return None
    source_marker=(str(source.get('Message ID') or ''),str(source.get('Channel Username') or ''))
    if not str(source.get('Batch ID') or '').startswith('reextract:') and any(
            str(item.get('Batch ID') or '').startswith('reextract:')
            and (str(item.get('Message ID') or ''),str(item.get('Channel Username') or ''))==source_marker
            for item in offers):
        return None
    return _as_case(source, ledger, include_decided=True)


def _duplicate_source_offer_key(offer):
    """Group repeat extractions of the same product from one public source post."""
    source_url = str(offer.get('Source Post URL') or '').strip()
    try:
        parsed = urlsplit(source_url)
    except ValueError:
        return None
    host = (parsed.hostname or '').lower()
    path = re.sub(r'^/s(?=/)', '', parsed.path).rstrip('/')
    if (parsed.scheme.lower() != 'https' or host not in {'t.me', 'www.t.me'}
            or not re.fullmatch(r'/[^/]+/\d+', path)):
        return None
    canonical_url = f'https://t.me{path}'
    digits = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')

    def normalized(value):
        return ' '.join(unicodedata.normalize('NFKC', str(value or '')).translate(digits).split()).casefold()

    title = normalized(offer.get('Product Name'))
    original = normalized(offer.get('Original Message'))
    if not title or not original:
        return None
    identity = [
        canonical_url, title, original,
        normalized(offer.get('Channel Username')), normalized(offer.get('Channel')),
        normalized(offer.get('Sale Price')), normalized(offer.get('Detected Price')),
        normalized(offer.get('Actual Price') or offer.get('Consumer Price')),
        normalized(offer.get('Package Price')), normalized(offer.get('Package Count')),
        normalized(offer.get('Min Qty')), normalized(offer.get('Currency')),
        normalized(offer.get('Stock Status')),
    ]
    return hashlib.sha256(_stable(identity)).hexdigest()


def review_cases(store, ledger, *, include_history=False, limit=200, reference_snapshot=None,
                 reference_item_ledger=None):
    from catalog.sync import _records, _read_sheet_if_present
    raw = _read_sheet_if_present(store, 'Offers')
    _, offers = _records(raw)
    cases = []
    reference_rows = (reference_snapshot or {}).get('rows') or []
    active_reference_snapshot_id = str((reference_snapshot or {}).get('snapshot_id') or '')
    reference_lookup = {}
    reference_ranker = None
    if reference_rows:
        from reference_import.matching import RankedCandidateIndex
        reference_ranker = RankedCandidateIndex(reference_rows)
        for row in reference_rows:
            if isinstance(row, (list, tuple)) and len(row) > 16:
                reference_lookup[str(row[16] or row[2] or '')] = str(row[0] or '')
    source_duplicates = {}
    all_by_offer = {}
    source_results = ledger.source_reextractions()
    reextracted_messages={
        (str(offer.get('Message ID') or ''),str(offer.get('Channel Username') or ''))
        for offer in offers if str(offer.get('Batch ID') or '').startswith('reextract:')
    }
    latest_reextract_batch_by_source = {}
    for offer in offers:
        batch_id = str(offer.get('Batch ID') or '')
        if not batch_id.startswith('reextract:'):
            continue
        source_key = reextraction_source_key(
            source_url=offer.get('Source Post URL'), channel=offer.get('Channel Username'),
            message_id=offer.get('Message ID'), timestamp=offer.get('Message Timestamp'),
            content=offer.get('Original Message'))
        latest_reextract_batch_by_source[source_key] = batch_id
    for offer in offers:
        offer_id = str(offer.get('Offer ID') or '')
        if not offer_id:
            continue
        case_id = review_case_id(offer_id)
        latest = ledger.latest(case_id)
        marker=(str(offer.get('Message ID') or ''),str(offer.get('Channel Username') or ''))
        source_key = reextraction_source_key(
            source_url=offer.get('Source Post URL'), channel=offer.get('Channel Username'),
            message_id=offer.get('Message ID'), timestamp=offer.get('Message Timestamp'),
            content=offer.get('Original Message'))
        batch_id = str(offer.get('Batch ID') or '')
        superseded=(marker in reextracted_messages
                    and not batch_id.startswith('reextract:'))
        source_result = source_results.get(source_key) or {}
        latest_source_batch = source_result.get('batch_id') if source_result.get('outcome') == 'products' else None
        if latest_source_batch:
            superseded = superseded or batch_id != latest_source_batch
        elif batch_id.startswith('reextract:'):
            superseded = superseded or batch_id != latest_reextract_batch_by_source.get(source_key)
        elif source_key in latest_reextract_batch_by_source:
            superseded = True
        case = None if superseded else _as_case(offer, ledger, include_decided=True)
        if case:
            case['reference_match_name'] = reference_lookup.get(str(case.get('reference_match_id') or ''), '')
            case['reference_candidates'] = []
            if active_reference_snapshot_id:
                case['reference_snapshot_id'] = active_reference_snapshot_id
            if reference_item_ledger and active_reference_snapshot_id:
                from reference_import.item_reviews import (offer_attributes, product_signature,
                    review_case_id as reference_review_case_id)
                attributes = offer_attributes(offer)
                reference_decision = reference_item_ledger.get(
                    reference_review_case_id(offer_id, active_reference_snapshot_id))
                if (reference_decision
                        and reference_decision.get('snapshot_id') == active_reference_snapshot_id
                        and reference_decision.get('signature') == product_signature(attributes)):
                    reference_id = str(reference_decision.get('reference_id') or '')
                    reference_name = reference_lookup.get(reference_id, reference_id)
                    case['reference_assignment'] = {
                        'decision_id': reference_decision.get('decision_id'),
                        'snapshot_id': active_reference_snapshot_id,
                        'choice': reference_decision.get('choice'),
                        'reference_id': reference_id,
                        'reference_name': reference_name,
                        'price_role': reference_decision.get('price_role'),
                    }
            if reference_ranker:
                case['reference_candidates'] = reference_ranker.ranked_candidates({
                    'name': case.get('product_name'), 'clean_name': case.get('product_name'),
                    'brand': case.get('brand'), 'category': case.get('category'),
                    'variant': case.get('variation_type'), 'volume': case.get('volume'),
                    'weight': case.get('weight'), 'package_count': case.get('package_count'),
                    'supplier_price': case.get('detected_price') or case.get('sale_price'),
                }, limit=12)
            latest_decision = ledger.latest(case['case_id'])
            decided = bool(latest_decision and latest_decision['signature'] == case['signature'])
            duplicate_key = _duplicate_source_offer_key(offer)
            if duplicate_key:
                source_duplicates.setdefault(duplicate_key, []).append((case, decided))
            elif not decided:
                cases.append(case)
        all_by_offer[offer_id] = (offer, latest)
    for duplicates in source_duplicates.values():
        # A single accepted decision covers exact repeated extraction rows from the same
        # public post. Keep the raw source rows untouched and avoid repeated owner cards.
        if any(decided for _, decided in duplicates):
            continue
        pending = [case for case, decided in duplicates if not decided]
        if not pending:
            continue
        case = pending[-1]
        if len(duplicates) > 1:
            case['duplicate_count'] = len(duplicates)
        cases.append(case)
    cases.sort(key=lambda row: (row['message_timestamp'], row['offer_id']), reverse=True)
    _annotate_variant_groups(cases)
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
            source=pair[0]
            marker=(str(source.get('Message ID') or ''),str(source.get('Channel Username') or ''))
            if (marker in reextracted_messages
                    and not str(source.get('Batch ID') or '').startswith('reextract:')):
                continue
            case = _as_case(pair[0], ledger, include_decided=True)
            if case:
                recent.append({**case, 'decision': decision})
    histories = ledger.history_for_cases(
        [item['case_id'] for item in cases] + [item['case_id'] for item in recent], per_case=10)
    _annotate_variant_groups(recent)
    for item in cases:
        item['decision_history'] = histories.get(item['case_id'], [])
    for item in recent:
        item['decision_history'] = histories.get(item['case_id'], [])
    return cases[:limit], recent[:limit]


def reextraction_message_key(message):
    content_sha256 = hashlib.sha256(str(message.get('content') or '').encode()).hexdigest()
    identity = [
        str(message.get('message_id') or ''), str(message.get('batch_id') or ''),
        str(message.get('channel_username') or ''), content_sha256,
        str(message.get('timestamp') or ''),
    ]
    media_unique_id=str(message.get('media_identity') or message.get('image_file_unique_id') or '')
    if media_unique_id:
        identity.append(media_unique_id)
    identity = json.dumps(identity, ensure_ascii=False, separators=(',', ':'))
    return hashlib.sha256(identity.encode()).hexdigest()


def reextraction_source_key(*, source_url, channel, message_id, timestamp, content):
    """Stable identity for one source post, independent of duplicate inbox rows."""
    source_url = str(source_url or '').strip()
    canonical_url = ''
    try:
        parsed = urlsplit(source_url)
        host = (parsed.hostname or '').lower()
        path = re.sub(r'^/s(?=/)', '', parsed.path).rstrip('/')
        if (parsed.scheme.lower() == 'https' and host in {'t.me', 'www.t.me'}
                and re.fullmatch(r'/[^/]+/\d+', path)):
            canonical_url = f'https://t.me{path}'
    except ValueError:
        pass
    content_sha256 = hashlib.sha256(str(content or '').encode()).hexdigest()
    if str(timestamp or '').strip():
        identity = ['supplier_message', str(channel or ''), str(timestamp), content_sha256]
    elif canonical_url:
        identity = ['telegram_post', canonical_url, str(channel or ''), content_sha256]
    else:
        identity = ['saved_message', str(channel or ''), str(message_id or ''), content_sha256]
    return hashlib.sha256(_stable(identity)).hexdigest()


def case_reextraction_message_key(*, source_key, offer_id, signature, content_sha256, timestamp):
    identity = _stable(['case-source', source_key, offer_id, signature, content_sha256, timestamp])
    return hashlib.sha256(identity).hexdigest()


def case_reextraction_inventory(store, ledger):
    """Build a source-deduplicated replay plan for every open extraction case."""
    cases = review_cases(store, ledger, limit=1000)
    groups = {}
    unmatched = []
    for case in cases:
        content = str(case.get('original_message') or '')
        if not content.strip():
            unmatched.append(case['case_id'])
            continue
        source_key = reextraction_source_key(
            source_url=case.get('source_post_url'), channel=case.get('channel'),
            message_id=case.get('message_id'), timestamp=case.get('message_timestamp'),
            content=content)
        group = groups.setdefault(source_key, {'cases': [], 'content': content})
        group['cases'].append(case)

    messages = []
    case_identities = []
    for case in cases:
        case_identities.append((case['case_id'], case['offer_id'], case['signature']))
    for source_key, group in sorted(groups.items()):
        candidates = group['cases']
        # One owner action may cover many variant cards; select the newest source offer
        # only as the immutable place from which to read the original message.
        case = candidates[0]
        content = group['content']
        content_sha256 = hashlib.sha256(content.encode()).hexdigest()
        timestamp = str(case.get('message_timestamp') or '')
        message_key = case_reextraction_message_key(
            source_key=source_key, offer_id=case['offer_id'], signature=case['signature'],
            content_sha256=content_sha256, timestamp=timestamp)
        messages.append({
            'message_id': str(case.get('message_id') or case['offer_id']),
            'batch_id': str(case.get('batch_id') or ''),
            'channel_username': str(case.get('channel') or ''),
            'channel': str(case.get('source_channel') or ''),
            'timestamp': timestamp,
            'content_sha256': content_sha256,
            'message_key': message_key,
            'source_message_id': str(case.get('message_id') or ''),
            'source_batch_id': str(case.get('batch_id') or ''),
            'source_offer_id': str(case['offer_id']),
            'source_offer_signature': str(case['signature']),
            'source_key': source_key,
            'source_post_url': str(case.get('source_post_url') or ''),
            'case_ids': sorted(item['case_id'] for item in candidates),
            'case_count': len(candidates),
        })
    digest = hashlib.sha256(_stable({
        'cases': sorted(case_identities),
        'messages': sorted((item['source_key'], item['source_offer_id'],
                            item['source_offer_signature'], item['content_sha256'])
                           for item in messages),
        'unmatched': sorted(unmatched),
    })).hexdigest()
    return {
        'case_count': len(cases), 'message_count': len(messages),
        'unmatched_case_ids': unmatched, 'digest': digest, 'messages': messages,
    }


def reextraction_inventory(store, ledger=None, *, policy_version=None):
    """List saved source messages safe to re-extract without replaying owner decisions."""
    from catalog.sync import _records
    _, messages = _records(store.read('MessageData'))
    _, offers = _records(store.read('Offers'))
    catalog_linking_available = True
    try:
        _, catalog_products = _records(store.read('Products'))
    except Exception:
        catalog_products = []
        catalog_linking_available = False
    catalog_product_ids = {str(item.get('Product ID') or '').strip()
                            for item in catalog_products
                            if str(item.get('Product ID') or '').strip()}
    linked_products_by_source = {}
    if catalog_linking_available and catalog_product_ids:
        for offer in offers:
            product_id = str(offer.get('Product ID') or '').strip()
            if product_id not in catalog_product_ids:
                continue
            source_key = reextraction_source_key(
                source_url=offer.get('Source Post URL'),
                channel=offer.get('Channel Username'),
                message_id=offer.get('Message ID'),
                timestamp=offer.get('Message Timestamp'),
                content=offer.get('Original Message'),
            )
            linked_products_by_source.setdefault(source_key, set()).add(product_id)
    owner_decisions=set()
    staged=set()
    dismissed_keys=ledger.dismissed_message_keys() if ledger else set()
    for offer in offers:
        marker=(str(offer.get('Message ID') or ''),str(offer.get('Channel Username') or ''))
        if not marker[0]:
            continue
        if str(offer.get('Owner Decision ID') or '').strip():
            owner_decisions.add(marker)
        if (str(offer.get('Batch ID') or '').startswith('reextract:')
                and str(offer.get('Status') or '').strip().lower()=='needs_review'):
            staged.add(marker)
    seen=set()
    candidates=[]
    pending=[]
    media_review=[]
    no_content=0
    excluded_owner=0
    explicitly_rejected=0
    no_product_candidates=0
    already_staged=0
    dismissed_by_owner=0
    unsupported_media=0
    media_without_file=0
    already_reprocessed=0
    reprocessed_sources={key for key,value in (ledger.source_reextractions().items() if ledger else [])
                         if (value.get('policy_version')==policy_version if policy_version is not None
                             else value.get('outcome')=='products')}
    album_rows={}
    blocked_album_keys=set()
    blocked_album_member_keys=set()
    for message in messages:
        message_id=str(message.get('ID') or '').strip()
        batch_id=str(message.get('Batch ID') or '').strip()
        channel=str(message.get('Channel Username') or '').strip()
        media_group_id=str(message.get('Media Group ID') or '').strip()
        if not message_id or not media_group_id:
            continue
        album_key=(channel,media_group_id)
        album_rows.setdefault(album_key,[]).append({
            'message_id':message_id,'batch_id':batch_id,'channel_username':channel,
            'channel':str(message.get('Channel') or ''),
            'timestamp':str(message.get('Timestamp') or ''),
            'source_post_url':str(message.get('URL') or ''),
            'content':str(message.get('Content') or ''),
            'media_group_id':media_group_id,
            'image_file_id':str(message.get('Image File ID') or '').strip(),
            'image_file_unique_id':str(message.get('Image File Unique ID') or '').strip(),
            'media_type':str(message.get('Media Type') or 'image').strip(),
        })
        marker=(message_id,channel)
        ai_status=str(message.get('AI Status') or '').strip().lower()
        file_id=str(message.get('Image File ID') or '').strip()
        image_unique=str(message.get('Image File Unique ID') or '').strip() or file_id
        has_media=(str(message.get('Has Media') or '').strip().lower() in {'true','1','yes'} or bool(file_id))
        media_candidate=has_media and (not str(message.get('Content') or '').strip()
            or ai_status in {'no_product_candidate','not_product'})
        legacy_key=reextraction_message_key({
            'message_id':message_id,'batch_id':batch_id,'channel_username':channel,
            'timestamp':str(message.get('Timestamp') or ''),'content':str(message.get('Content') or ''),
            'media_identity':image_unique if media_candidate else '',
        })
        if ai_status=='owner_rejected' or marker in owner_decisions or legacy_key in dismissed_keys:
            blocked_album_keys.add(album_key)
    for message in messages:
        message_id=str(message.get('ID') or '').strip()
        batch_id=str(message.get('Batch ID') or '').strip()
        channel=str(message.get('Channel Username') or '').strip()
        content=str(message.get('Content') or '')
        if not message_id:
            continue
        image_file_id=str(message.get('Image File ID') or '').strip()
        image_unique_id=str(message.get('Image File Unique ID') or '').strip() or image_file_id
        media_type=str(message.get('Media Type') or 'image').strip()
        media_group_id=str(message.get('Media Group ID') or '').strip()
        has_media=(str(message.get('Has Media') or '').strip().lower() in {'true','1','yes'}
                   or bool(image_file_id))
        media_identity=image_unique_id or (f'group:{media_group_id}' if media_group_id
            else f'type:{media_type}' if has_media else '')
        identity=(message_id,batch_id,channel,content,image_unique_id)
        if identity in seen:
            continue
        seen.add(identity)
        marker=(message_id,channel)
        ai_status=str(message.get('AI Status') or '').strip().lower()
        media_no_product=ai_status in {'no_product_candidate','not_product'}
        media_candidate=has_media and (media_no_product or (not content.strip()
            and ai_status in {'','pending_ai','needs_media_review'}))
        media_review_needed=media_candidate and media_type.lower() in _OCR_MEDIA_TYPES
        unsupported_media_needed=media_candidate and not media_review_needed
        source_key=reextraction_source_key(
            source_url=message.get('URL'),channel=channel,message_id=message_id,
            timestamp=str(message.get('Timestamp') or ''),content=content)
        if source_key in reprocessed_sources and not media_group_id:
            already_reprocessed+=1
            continue
        message_key=reextraction_message_key({
            'message_id':message_id,'batch_id':batch_id,'channel_username':channel,
            'timestamp':str(message.get('Timestamp') or ''),'content':content,
            'media_identity':media_identity if media_candidate else '',
        })
        if message_key in dismissed_keys:
            dismissed_by_owner+=1
            continue
        if ai_status=='owner_rejected':
            excluded_owner+=1
            explicitly_rejected+=1
            continue
        if marker in owner_decisions:
            excluded_owner+=1
            continue
        # A staged result from an older extraction policy is eligible for an explicit
        # current-policy replay. Same-policy results are filtered by their receipt above.
        if marker in staged and policy_version is None:
            already_staged+=1
            continue
        if unsupported_media_needed:
            unsupported_media+=1
            # Preserve caption-bearing messages for text extraction. Empty unsupported
            # media has no recoverable OCR input and should not flood the image queue.
            if not content.strip():
                continue
        if media_review_needed:
            if not content.strip():
                no_content+=1
            if not image_file_id:
                media_without_file+=1
            media_review.append({
                'message_id':message_id,'batch_id':batch_id,'channel_username':channel,
                'channel':str(message.get('Channel') or ''),
                'timestamp':str(message.get('Timestamp') or ''),
                'source_post_url':str(message.get('URL') or ''),
                'content':content,
                'media_type':media_type,
                'media_group_id':media_group_id,
                'image_file_id':image_file_id,'image_file_unique_id':image_unique_id,
                'image_file_ids':[image_file_id] if image_file_id else [],
                'image_file_unique_ids':[image_unique_id] if image_file_id else [],
                'image_count':1 if image_file_id else 0,
                'message_ids':[message_id],
                'group_members':[{'message_id':message_id,'batch_id':batch_id,
                    'channel':str(message.get('Channel') or ''),'channel_username':channel}],
                'media_identity':media_identity,
                'message_key':message_key,
            })
            if media_no_product:
                no_product_candidates+=1
            continue
        item={
            'message_id':message_id,'batch_id':batch_id,'channel_username':channel,
            'channel':str(message.get('Channel') or ''),'timestamp':str(message.get('Timestamp') or ''),
            'content':content,'source_post_url':str(message.get('URL') or ''),
            'media_group_id':media_group_id,
            'ai_status':'no_product_candidate' if ai_status=='not_product' else ai_status,
            'message_key':message_key,'source_key':source_key,
            'catalog_product_ids':sorted(linked_products_by_source.get(source_key, set())),
        }
        candidates.append(item)
        if item['ai_status']=='no_product_candidate':
            no_product_candidates+=1
        if item['ai_status'] in {'','pending_ai','needs_confirmation','needs_review','pending',
                                 'not_product','no_product_candidate'}:
            pending.append(item)
    media_review_candidates=media_review
    media_review=[item for item in media_review_candidates if not item.get('media_group_id')]
    def message_marker(item):
        return (str(item.get('channel_username') or ''),str(item.get('message_id') or ''))
    candidate_message_markers={message_marker(item) for item in candidates}
    pending_message_markers={message_marker(item) for item in pending}
    media_review_album_keys={(str(item.get('channel_username') or ''),str(item.get('media_group_id') or ''))
        for item in media_review_candidates if str(item.get('media_group_id') or '').strip()}
    media_albums=[]
    media_album_message_keys=set()
    for album_key,rows in album_rows.items():
        bundle=album_media_bundle(rows)
        if not bundle or not bundle.get('image_count'):
            continue
        group_members=bundle.get('group_members') or []
        member_markers={message_marker(item) for item in group_members
            if str(item.get('message_id') or '')}
        media_album_message_keys.update(member_markers)
        candidates=[candidate for candidate in candidates
            if message_marker(candidate) not in member_markers]
        pending=[candidate for candidate in pending
            if message_marker(candidate) not in member_markers]
        media_review=[candidate for candidate in media_review
            if message_marker(candidate) not in member_markers]
        blocked=(album_key in blocked_album_keys or any(
            (str(item.get('message_id') or ''),str(item.get('channel_username') or '')) in owner_decisions
            or (policy_version is None and
                (str(item.get('message_id') or ''),str(item.get('channel_username') or '')) in staged)
            for item in group_members))
        message_key=reextraction_message_key({
            'message_id':bundle['message_id'],'batch_id':bundle['batch_id'],
            'channel_username':bundle['channel_username'],'timestamp':bundle['timestamp'],
            'content':bundle['content'],'media_identity':bundle['media_identity'],
        })
        source_key=reextraction_source_key(source_url=bundle['source_post_url'],
            channel=bundle['channel_username'],message_id=bundle['message_id'],
            timestamp=bundle['timestamp'],content=bundle['content'])
        album_product_ids=set(linked_products_by_source.get(source_key,set()))
        for row in rows:
            member_source_key=reextraction_source_key(source_url=row.get('source_post_url'),
                channel=row.get('channel_username'),message_id=row.get('message_id'),
                timestamp=row.get('timestamp'),content=row.get('content'))
            album_product_ids.update(linked_products_by_source.get(member_source_key,set()))
        if message_key in dismissed_keys:
            blocked=True
        if blocked:
            blocked_album_member_keys.update(member_markers)
            continue
        if source_key in reprocessed_sources:
            already_reprocessed+=1
            continue
        first_image=bundle['image_file_ids'][0] if bundle['image_file_ids'] else ''
        first_unique=bundle['image_file_unique_ids'][0] if bundle['image_file_unique_ids'] else ''
        item={**bundle,'image_file_id':first_image,'image_file_unique_id':first_unique,
            'message_key':message_key,'source_key':source_key,'grouped':True,
            'media_ocr':True,'catalog_product_ids':sorted(album_product_ids)}
        # Album rows represent one supplier post for every replay scope. Keep all
        # captions and all photos together instead of exposing per-photo rows.
        in_media_review=album_key in media_review_album_keys
        has_candidate=any(marker in candidate_message_markers for marker in member_markers)
        has_pending=any(marker in pending_message_markers for marker in member_markers)
        if item['missing_image_count']:
            media_review.append(item)
            continue
        media_albums.append(item)
        if in_media_review:
            media_review.append(item)
        if has_candidate or in_media_review:
            item['ai_status']='pending_ai' if has_pending or in_media_review else ''
            candidates.append(item)
            if has_pending or in_media_review:
                pending.append(item)
    return {
        'all_messages':candidates,
        'pending_messages':pending,
        'catalog_linking_available':catalog_linking_available,
        'media_review_messages':media_review,
        'media_albums':media_albums,
        'media_album_message_keys':sorted(media_album_message_keys),
        'blocked_media_album_message_keys':sorted(blocked_album_member_keys),
        'counts':{
            'all_available':len(candidates),'pending':len(pending),'without_text':no_content,
            'media_review':len(media_review),
            'media_review_images':sum(int(item.get('image_count') or 0) for item in media_review),
            'media_review_missing_images':sum(int(item.get('missing_image_count') or 0)
                for item in media_review),
            'media_without_file':media_without_file,'unsupported_media':unsupported_media,
            'owner_decisions_preserved':excluded_owner,'not_product_preserved':explicitly_rejected,
            'no_product_candidates':no_product_candidates,
            'already_staged':already_staged,'already_reprocessed':already_reprocessed,
            'dismissed_by_owner':dismissed_by_owner,
        },
    }


def reextraction_selection(inventory, *, scope='all', channels=None,
                           date_from='', date_to='', group_by='channel'):
    """Filter ordinary eligible messages and return an immutable selection preview."""
    from catalog.supplier_freshness import source_datetime

    if scope not in {'all', 'pending', 'linked'}:
        raise ValueError('invalid re-extraction scope')
    if group_by not in {'channel', 'date'}:
        raise ValueError('invalid re-extraction grouping')
    if channels is None:
        raw_channels = []
    elif isinstance(channels, (list, tuple, set)):
        raw_channels = channels
    else:
        raise ValueError('invalid re-extraction channel filter')
    if len(raw_channels) > 30 or any(not isinstance(value, str) or len(value) > 200
                                     for value in raw_channels):
        raise ValueError('invalid re-extraction channel filter')

    def channel_key(value):
        return str(value or '').strip().lstrip('@').casefold()

    channel_labels = {}
    for item in inventory.get('all_messages') or []:
        value = str(item.get('channel_username') or '').strip()
        key = channel_key(value)
        if not key:
            continue
        channel_labels.setdefault(key, value)
    selected_channel_keys = {channel_key(value) for value in raw_channels if channel_key(value)}
    if not selected_channel_keys.issubset(channel_labels):
        raise ValueError('unknown re-extraction channel')

    def parse_day(value):
        value = str(value or '').strip()
        if not value:
            return None
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', value):
            raise ValueError('invalid re-extraction date')
        try:
            return datetime.strptime(value, '%Y-%m-%d').date()
        except ValueError as exc:
            raise ValueError('invalid re-extraction date') from exc

    first_day = parse_day(date_from)
    last_day = parse_day(date_to)
    if first_day and last_day and first_day > last_day:
        raise ValueError('invalid re-extraction date range')

    all_messages = list(inventory.get('all_messages') or [])
    if scope == 'pending':
        pending_keys = {str(item.get('message_key') or '')
                        for item in inventory.get('pending_messages') or []}
        base = [item for item in all_messages
                if str(item.get('message_key') or '') in pending_keys]
    elif scope == 'linked':
        base = [item for item in all_messages if item.get('catalog_product_ids')]
    else:
        base = all_messages

    selected = []
    date_by_key = {}
    tehran = ZoneInfo('Asia/Tehran')
    for item in base:
        channel = channel_key(item.get('channel_username'))
        if selected_channel_keys and channel not in selected_channel_keys:
            continue
        source_time = source_datetime(item.get('timestamp'))
        source_day = source_time.astimezone(tehran).date() if source_time else None
        if first_day or last_day:
            if source_day is None or (first_day and source_day < first_day) \
                    or (last_day and source_day > last_day):
                continue
        selected.append(item)
        date_by_key[str(item.get('message_key') or '')] = source_day

    channels_key_order = sorted(selected_channel_keys)
    digest_payload = {
        'scope': scope,
        'channels': channels_key_order,
        'date_from': str(date_from or ''),
        'date_to': str(date_to or ''),
        'message_keys': sorted(str(item.get('message_key') or '') for item in selected),
    }
    digest = hashlib.sha256(_stable(digest_payload)).hexdigest()
    groups = {}
    for item in selected:
        if group_by == 'date':
            source_day = date_by_key.get(str(item.get('message_key') or ''))
            key = source_day.isoformat() if source_day else ''
            label = key or 'زمان ثبت نشده'
        else:
            raw_channel = str(item.get('channel_username') or '').strip()
            key = channel_key(raw_channel)
            label = raw_channel or str(item.get('channel') or 'منبع نامشخص')
        group = groups.setdefault(key, {'key': key, 'label': label, 'count': 0})
        group['count'] += 1
    if group_by == 'date':
        ordered_groups = sorted((item for item in groups.values() if item['key']),
                                key=lambda item: item['key'], reverse=True)
        ordered_groups.extend(item for item in groups.values() if not item['key'])
    else:
        ordered_groups = sorted(groups.values(), key=lambda item: (item['key'] == '', item['key']))
    source_channels = {}
    for item in all_messages:
        value = str(item.get('channel_username') or '').strip()
        key = channel_key(value)
        if not key:
            continue
        channel = source_channels.setdefault(key, {'value': channel_labels[key], 'count': 0})
        channel['count'] += 1
    ordered_channels = sorted(source_channels.values(), key=lambda item: channel_key(item['value']))
    return {
        'scope': scope,
        'count': len(selected),
        'digest': digest,
        'channels': channels_key_order,
        'date_from': str(date_from or ''),
        'date_to': str(date_to or ''),
        'group_by': group_by,
        'groups': ordered_groups,
        'source_channels': ordered_channels,
        'messages': selected,
    }


def recent_policy_reextraction_inventory(store, ledger=None, *, policy_version=None, now=None):
    """Build a revision-bound replay plan for eligible supplier posts in the live window."""
    from catalog.gemini import extraction_policy_version
    from catalog.launch_policies import BLOCKING_AGE
    from catalog.supplier_freshness import source_datetime
    from datetime import datetime, timezone

    version=policy_version or extraction_policy_version()
    current=now or datetime.now(timezone.utc)
    if current.tzinfo is None or current.utcoffset() is None:
        raise ValueError('timezone-aware replay preview time required')
    current=current.astimezone(timezone.utc)
    inventory=reextraction_inventory(store,ledger,policy_version=version)
    eligible=[]
    unknown_or_future=0
    outside_window=0
    album_member_keys={tuple(item) for item in inventory.get('media_album_message_keys') or []}
    album_member_keys.update(tuple(item)
        for item in inventory.get('blocked_media_album_message_keys') or [])
    ordinary_messages=[item for item in inventory['all_messages']
        if (str(item.get('channel_username') or ''),str(item.get('message_id') or ''))
            not in album_member_keys]
    single_media=[{**item,'media_ocr':True} for item in inventory['media_review_messages']
        if not item.get('media_group_id') and str(item.get('image_file_id') or '').strip()]
    album_media=[{**item,'media_ocr':True} for item in inventory.get('media_albums',[])
        if item.get('image_file_ids')]
    candidates=[*ordinary_messages,*single_media,*album_media]
    for item in candidates:
        stamp=source_datetime(item.get('timestamp'))
        if stamp is None or stamp>current:
            unknown_or_future+=1
            continue
        if current-stamp>=BLOCKING_AGE:
            outside_window+=1
            continue
        item=dict(item)
        item['source_key']=reextraction_source_key(
            source_url=item.get('source_post_url'),channel=item.get('channel_username'),
            message_id=item.get('message_id'),timestamp=item.get('timestamp'),
            content=item.get('content'))
        eligible.append(item)
    identities=sorted((str(item['source_key']),str(item['message_key']),str(item['message_id']),
                       str(item['batch_id']),str(item['timestamp'])) for item in eligible)
    digest=hashlib.sha256(_stable({
        'policy_version':version,'window_seconds':int(BLOCKING_AGE.total_seconds()),
        'messages':identities,
    })).hexdigest()
    return {
        'policy_version':version,
        'window_days':int(BLOCKING_AGE.total_seconds()/86400),
        'digest':digest,
        'messages':eligible,
        'eligible_count':len(eligible),
        'media_ocr_count':sum(1 for item in eligible if item.get('media_ocr')),
        'media_ocr_image_count':sum(int(item.get('image_count') or 1) for item in eligible
            if item.get('media_ocr')),
        'unknown_or_future_timestamp_count':unknown_or_future,
        'outside_window_count':outside_window,
    }


def validate_choice(body):
    required={'case_id','offer_id','signature','choice','fields','note','expected_decision_id'}
    allowed=required|{'source_current_verified'}
    if not isinstance(body, dict) or not required.issubset(body) or not set(body).issubset(allowed):
        raise ValueError('invalid_body')
    if 'source_current_verified' in body and not isinstance(body['source_current_verified'],bool):
        raise ValueError('invalid_fields')
    for key, maximum in (('case_id', 100),('offer_id', 300),('signature', 64),
                         ('choice', 20),('note', 1000)):
        value = body.get(key)
        if not isinstance(value, str) or len(value) > maximum:
            raise ValueError('invalid_fields')
    if (body['choice'] != 'not_product' and not body['note'].strip()) or len(body['signature']) != 64:
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
        _product_id_for_offer,
        _product_restore_key, _read_sheet_if_present, _records, _restore_current_book, _row_from_map,
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
        _restore_current_book(store, [_product_restore_key(source)])
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
        'url': source.get('Source Post URL') or '',
        'forwarded_by': source.get('Forwarded By') or '', 'batch_id': 'owner-ai-confirmation:' + decision_id,
        'image_file_id': source.get('Image File ID') or '',
        'image_file_unique_id': source.get('Image File Unique ID') or '',
    }, derived_id, now, reason,
        product_id=(str(source.get('Product ID') or '')
            or _product_id_for_offer(product, str(source.get('Channel Username') or ''),
                _read_sheet_if_present(store, 'Products'))))
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
    _restore_current_book(store, [_product_restore_key(
        product, str(source.get('Channel Username') or ''))])
    return derived_id
