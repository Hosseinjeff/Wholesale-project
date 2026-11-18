"""Build reference vectors in resumable slices of the WordPress-owned queue."""

from datetime import datetime, timezone
import hashlib
import json
import os

import requests

from catalog.gemini import google_api_key
from catalog.google_quota import QuotaDeferred, configured_router
from .ai_matching import EMBEDDING_POLICY, EmbeddingIndex
from .snapshots import ReferenceStore, has_verified_coverage
from .workflow import reference_use


TASK = 'google_reference_index'
SLICE_SIZE = 32


def _configured_index():
    model = os.getenv('GOOGLE_REFERENCE_EMBEDDING_MODEL', '').strip()
    router = configured_router()
    if (not router or not google_api_key()
            or not any(item.model == model and 'embed' in item.tasks for item in router.models)):
        raise ValueError('Reference embedding is unconfigured')
    return EmbeddingIndex(router, model)


def _eligible_snapshot():
    path = os.getenv('REFERENCE_STORE_PATH') or ''
    if not path:
        return None
    owners = tuple(filter(None, (value.strip()
                   for value in os.getenv('REFERENCE_OWNER_IDS', '').split(','))))
    store = ReferenceStore(path, owner_ids=owners)
    snapshot = store.active_snapshot()
    if not has_verified_coverage(snapshot):
        return None
    state = reference_use(store, snapshot=snapshot, now=datetime.now(timezone.utc))
    return snapshot if state.get('allow_automatic') else None


def _enqueue(queue, *, run_id, snapshot_id, model, offset, total):
    payload = {'task': TASK, 'run_id': run_id, 'snapshot_id': snapshot_id,
               'model': model, 'offset': offset, 'total': total}
    return queue.put('maintenance', f'{TASK}:{run_id}:{snapshot_id}:{offset}', payload)


def status(queue):
    """Read the latest slice receipt and pending time without querying Google."""
    with queue.connect() as db:
        row = db.execute(
            'SELECT id, state, payload, result, available, error FROM jobs '
            'WHERE kind=? AND id LIKE ? ORDER BY rowid DESC LIMIT 1',
            ('maintenance', f'maintenance:{TASK}:%')).fetchone()
    if not row:
        return None
    payload = json.loads(row['payload'])
    result = json.loads(row['result']) if row['result'] else {}
    return {'job_id': row['id'], 'run_id': payload['run_id'], 'model': payload['model'],
            'snapshot_id': payload['snapshot_id'], 'total_rows': payload['total'],
            'covered_rows': result.get('covered_rows', payload['offset']),
            'state': result.get('state', row['state']), 'error': row['error'],
            'retry_at': row['available'] if row['state'] == 'queued' else None}


def start(queue):
    index = _configured_index()
    current = status(queue)
    if current and current['state'] in {'queued', 'running'}:
        return current
    snapshot = _eligible_snapshot()
    if not snapshot:
        raise LookupError('No eligible active reference')
    if (current and current['state'] == 'complete' and current['model'] == index.model
            and current['snapshot_id'] == snapshot['snapshot_id']):
        return current
    # A repeat click returns the current run. A stopped run can be restarted
    # after its prerequisite is restored, reusing all cached vectors.
    previous = current['job_id'] if current and current['state'] != 'complete' else ''
    identity = json.dumps([EMBEDDING_POLICY, index.router.project, index.model,
                           snapshot['snapshot_id'], previous])
    run_id = hashlib.sha256(identity.encode()).hexdigest()[:32]
    _enqueue(queue, run_id=run_id, snapshot_id=snapshot['snapshot_id'],
             model=index.model, offset=0, total=len(snapshot['rows']))
    return status(queue)


def run_slice(queue, payload):
    index = _configured_index()
    if payload['model'] != index.model:
        return {'ok': True, 'state': 'superseded'}
    snapshot = _eligible_snapshot()
    if not snapshot:
        return {'ok': True, 'state': 'reference_unavailable'}
    rows = snapshot['rows']
    snapshot_id = snapshot['snapshot_id']
    offset = payload['offset'] if payload['snapshot_id'] == snapshot_id else 0
    if offset == 0 and payload['snapshot_id'] != snapshot_id:
        _enqueue(queue, run_id=payload['run_id'], snapshot_id=snapshot_id,
                 model=index.model, offset=0, total=len(rows))
        return {'ok': True, 'state': 'snapshot_changed'}
    end = min(len(rows), offset + SLICE_SIZE)
    with requests.Session() as session:
        result = index.index(rows[offset:end], session=session,
                             api_key=google_api_key(), limit=SLICE_SIZE)
    if result['retry_at'] is not None:
        # Saved vectors are reused when WordPress wakes this slice again.
        raise QuotaDeferred(result['retry_at'])
    if end < len(rows):
        _enqueue(queue, run_id=payload['run_id'], snapshot_id=snapshot_id,
                 model=index.model, offset=end, total=len(rows))
    return {'ok': True, 'state': 'complete' if end == len(rows) else 'slice_complete',
            'covered_rows': end, 'embedded': result['embedded']}
