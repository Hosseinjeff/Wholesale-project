"""Bounded, owner-requested suggestions stored in the existing durable queue.

Reads only attach receipts. Workers revalidate the source and reference version;
neither a request nor a completed suggestion writes an approval or product price.
"""
import hashlib
import json
import os
from datetime import datetime, timezone

from .ai_matching import POLICY, ReferenceSuggestions
from .item_reviews import ItemReviewLedger, review_cases
from .workflow import active_reference_use


def policy_version():
    settings = [POLICY, os.getenv('GOOGLE_FREE_ROUTING', ''),
                os.getenv('GOOGLE_FREE_MODELS', ''),
                os.getenv('GOOGLE_REFERENCE_EMBEDDING_MODEL', '')]
    return hashlib.sha256(json.dumps(settings).encode()).hexdigest()


def task_context(case, kind, snapshot_id):
    return {'case_kind': kind, 'case_id': case['case_id'],
            'offer_id': case['offer_id'], 'signature': case['signature'],
            'snapshot_id': snapshot_id, 'policy_version': policy_version()}


def task_key(context):
    return hashlib.sha256(json.dumps(context, sort_keys=True).encode()).hexdigest()


def attach_receipts(queue, cases, kind, snapshot_id):
    """One bounded bulk lookup; no Google client or quota reservation on reads."""
    keyed = {'reference_suggest:' + task_key(task_context(case, kind, snapshot_id)): case
             for case in cases}
    identifiers = list(keyed)
    for start in range(0, len(identifiers), 300):
        group = identifiers[start:start + 300]
        with queue.connect() as db:
            rows = db.execute('SELECT id,state,result,available FROM jobs WHERE id IN (' +
                              ','.join('?' for _ in group) + ')', group).fetchall()
        for row in rows:
            result = json.loads(row['result']) if row['result'] else {}
            case = keyed[row['id']]
            case['reference_suggestion'] = {
                'state': row['state'], 'retry_at': row['available'] if row['state'] == 'queued' else None,
                'result': result.get('suggestion') if row['state'] == 'done' else None,
            }
            suggestion = result.get('suggestion') if row['state'] == 'done' else None
            if suggestion:
                # Include semantic candidates in the existing explicit chooser.
                field = 'reference_candidates' if kind == 'ai' else 'candidates'
                existing = case.get(field) or []
                by_id = {item['reference_id']: item for item in existing}
                for item in suggestion.get('candidates', []):
                    by_id[item['reference_id']] = {**by_id.get(item['reference_id'], {}), **item}
                order = list(dict.fromkeys([suggestion.get('reference_id'),
                                            *[item['reference_id'] for item in existing], *by_id]))
                case[field] = [by_id[key] for key in order if key in by_id][:12]


def resolve_case(sheet_store, snapshot, ledger, queue, context):
    if context['case_kind'] == 'ai':
        from catalog.ai_confirmations import AIConfirmationLedger, find_case
        ai_ledger = AIConfirmationLedger(queue.path)
        case = find_case(sheet_store, ai_ledger, context['case_id'], context['offer_id'], fresh=True)
        if (not case or case['signature'] != context['signature']
                or (ai_ledger.latest(context['case_id']) or {}).get('signature') == context['signature']):
            return None
        product = {'name': case.get('product_name'), 'brand': case.get('brand'),
                   'category': case.get('category'), 'variant': case.get('variation_type'),
                   'volume': case.get('volume'), 'weight': case.get('weight'),
                   'package_count': case.get('package_count')}
        return product, case.get('original_message') or ''
    if context['case_kind'] != 'reference':
        return None
    cases = review_cases(sheet_store, snapshot, ledger, include_candidates=False)
    case = next((item for item in cases if item['case_id'] == context['case_id']
                 and item['offer_id'] == context['offer_id']
                 and item['signature'] == context['signature'] and not item.get('decision')), None)
    return (case['attributes'], '') if case else None


def eligible_snapshot(reference_store, context):
    state = active_reference_use(reference_store, now=datetime.now(timezone.utc))
    snapshot = reference_store.active_snapshot()
    if (not state.get('allow_automatic') or not snapshot
            or snapshot['snapshot_id'] != state.get('snapshot_id')
            or snapshot['snapshot_id'] != context['snapshot_id']):
        return None
    return snapshot


def run_suggestion(queue, context, sheet_store, reference_store):
    from catalog.google_quota import configured_router
    from catalog.gemini import google_api_key
    snapshot = eligible_snapshot(reference_store, context)
    superseded = {'ok': True, 'state': 'superseded'}
    if not snapshot or context['policy_version'] != policy_version():
        return superseded
    ledger = ItemReviewLedger(reference_store.database, owner_ids=reference_store.owner_ids)
    resolved = resolve_case(sheet_store, snapshot, ledger, queue, context)
    if resolved is None:
        return superseded
    router = configured_router()
    model = os.getenv('GOOGLE_REFERENCE_EMBEDDING_MODEL', '').strip() if router else None
    import requests
    with requests.Session() as session:
        suggestion = ReferenceSuggestions(router, embedding_model=model or None).suggest(
            resolved[0], snapshot['rows'], snapshot_id=snapshot['snapshot_id'],
            source_text=resolved[1], overrides=ledger.overrides(snapshot['snapshot_id']),
            session=session, api_key=google_api_key() if router else '')
    # Discard an in-flight result if its source, owner decision, or reference changed.
    if (not eligible_snapshot(reference_store, context)
            or resolve_case(sheet_store, snapshot, ledger, queue, context) != resolved):
        return superseded
    return {'ok': True, 'suggestion': suggestion}
