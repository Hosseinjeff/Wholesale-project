"""Local reference eligibility; live ingestion callers are not wired yet."""
from datetime import datetime
from .decisions import snapshot_is_stale

REFERENCE_MAX_AGE_SECONDS = 24 * 60 * 60


def reference_use(gate, *, snapshot, now, refresh_failure_id=None):
    """Always load persisted incidents; supplying a failure ID also records it.

    Caller must persist failures when they occur. A failure never recorded cannot
    be discovered here. Production must read a trusted active snapshot from storage.
    """
    snapshot_id = snapshot['snapshot_id'] if snapshot else None
    event_id = None
    if refresh_failure_id:
        event_id = 'refresh:' + refresh_failure_id
        gate.open_event(event_id, reason='refresh_failed', snapshot_id=snapshot_id)
    if snapshot is None:
        event_id = 'missing-reference'
        gate.open_event(event_id, reason='missing', snapshot_id=None)
    elif snapshot_is_stale(datetime.fromisoformat(snapshot['extracted_at']), now,
                           max_age_seconds=REFERENCE_MAX_AGE_SECONDS):
        event_id = 'stale:' + snapshot_id
        gate.open_event(event_id, reason='stale', snapshot_id=snapshot_id)
    events = gate.relevant_events(snapshot_id)
    blocked = [e for e in events if e['choice'] != 'use_last_snapshot']
    if blocked:
        paused = any(e['choice'] == 'pause_review' for e in blocked)
        return {'state': 'pause_review' if paused else 'awaiting_owner',
                'allow_automatic': False, 'warning': None,
                'event_id': blocked[0]['event_id'], 'snapshot_id': snapshot_id}
    return {'state': 'use_last_snapshot' if events else 'fresh',
            'allow_automatic': snapshot is not None,
            'warning': 'Using previous reference snapshot by owner decision' if events else None,
            'event_id': event_id, 'snapshot_id': snapshot_id}


def active_reference_use(store, *, now):
    """Read the authoritative active version, never a caller-selected candidate."""
    return reference_use(store, snapshot=store.active_snapshot(), now=now)
