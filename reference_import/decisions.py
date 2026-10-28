"""Local draft of a durable reference exception gate; no network or active catalog writes."""
import sqlite3
import os
import math
from contextlib import contextmanager
from datetime import datetime, timezone


class DecisionConflict(ValueError):
    pass


def snapshot_is_stale(extracted_at, now, *, max_age_seconds):
    """An explicit cutoff is required; None explicitly means no age-based trigger."""
    if extracted_at.tzinfo is None or now.tzinfo is None:
        raise ValueError('Timezone-aware timestamps required')
    if extracted_at > now:
        raise ValueError('Future extraction timestamp requires review')
    if max_age_seconds is None:
        return False
    if isinstance(max_age_seconds, bool) or not math.isfinite(max_age_seconds) or max_age_seconds <= 0:
        raise ValueError('Positive age cutoff required')
    return (now - extracted_at).total_seconds() >= max_age_seconds


class ReferenceDecisions:
    """Each event ID identifies one occurrence; retries MUST reuse that same ID.

    Caller authenticates the actor and passes the verified identity. An allowlist is
    required here as a second guard. UI/channel authentication remains an integration task.
    """

    def __init__(self, database, *, owner_ids):
        self.database = os.path.realpath(str(database))
        self.owner_ids = frozenset(owner_ids)
        if not self.owner_ids:
            raise ValueError('Explicit business-owner identities required')
        with self._connect() as db:
            db.execute('''CREATE TABLE IF NOT EXISTS reference_events (
                event_id TEXT PRIMARY KEY, reason TEXT NOT NULL,
                snapshot_id TEXT, created_at TEXT NOT NULL,
                choice TEXT, actor_id TEXT, decided_at TEXT)''')
            db.execute('''CREATE TABLE IF NOT EXISTS reference_retirements (
                event_id TEXT PRIMARY KEY, replacement_snapshot_id TEXT NOT NULL,
                retired_at TEXT NOT NULL)''')

    @contextmanager
    def _connect(self):
        db = sqlite3.connect(self.database, timeout=10)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    @staticmethod
    def _now():
        return datetime.now(timezone.utc).isoformat()

    def open_event(self, event_id, *, reason, snapshot_id):
        if not event_id or reason not in {'stale', 'refresh_failed', 'missing'}:
            raise ValueError('Stable event ID and supported reason required')
        if snapshot_id is not None and not snapshot_id:
            raise ValueError('Snapshot identity must be nonempty or None')
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            existing = db.execute('SELECT * FROM reference_events WHERE event_id=?',
                                  (event_id,)).fetchone()
            if existing:
                if (existing['reason'], existing['snapshot_id']) != (reason, snapshot_id):
                    raise DecisionConflict('Event ID reused for a different occurrence')
            else:
                db.execute('INSERT INTO reference_events VALUES (?, ?, ?, ?, NULL, NULL, NULL)',
                           (event_id, reason, snapshot_id, self._now()))
        return event_id

    def pending(self):
        """Local work queue. Delivery/notification is independent of event persistence."""
        with self._connect() as db:
            return [dict(row) for row in db.execute(
                '''SELECT * FROM reference_events e WHERE choice IS NULL AND NOT EXISTS
                (SELECT 1 FROM reference_retirements r WHERE r.event_id=e.event_id)
                ORDER BY created_at, event_id''')]

    def choose(self, event_id, *, actor_id, choice, expected_snapshot_id):
        if actor_id not in self.owner_ids:
            raise PermissionError('Business-owner decision required')
        if choice not in {'pause_review', 'use_last_snapshot'}:
            raise ValueError('Unsupported choice')
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            event = db.execute('SELECT * FROM reference_events WHERE event_id=?',
                               (event_id,)).fetchone()
            if not event:
                raise DecisionConflict('Unknown event')
            if db.execute('SELECT 1 FROM reference_retirements WHERE event_id=?', (event_id,)).fetchone():
                raise DecisionConflict('Occurrence retired by replacement snapshot')
            if event['snapshot_id'] != expected_snapshot_id:
                raise DecisionConflict('Decision targets a different snapshot')
            if choice == 'use_last_snapshot' and not event['snapshot_id']:
                raise DecisionConflict('No last successful snapshot exists')
            if event['choice']:
                if (event['choice'], event['actor_id']) == (choice, actor_id):
                    return  # exact retry, no second decision or altered audit timestamp
                raise DecisionConflict('Occurrence already resolved')
            db.execute('UPDATE reference_events SET choice=?, actor_id=?, decided_at=? '
                       'WHERE event_id=? AND choice IS NULL',
                       (choice, actor_id, self._now(), event_id))

    def outcome(self, event_id, *, current_snapshot_id):
        with self._connect() as db:
            event = db.execute('SELECT * FROM reference_events WHERE event_id=?',
                               (event_id,)).fetchone()
        with self._connect() as db:
            retired = db.execute('SELECT 1 FROM reference_retirements WHERE event_id=?', (event_id,)).fetchone()
        if retired:
            return {'state': 'superseded', 'allow_automatic': False, 'warning': None}
        if not event:
            raise DecisionConflict('Unknown event; automatic interpretation must wait')
        if event['snapshot_id'] != current_snapshot_id:
            return {'state': 'superseded', 'allow_automatic': False, 'warning': None}
        choice = event['choice']
        return {'state': choice or 'awaiting_owner',
                'allow_automatic': choice == 'use_last_snapshot',
                'warning': 'Using previous reference snapshot by owner decision'
                           if choice == 'use_last_snapshot' else None}

    def relevant_events(self, snapshot_id):
        """All live events for this snapshot, including owner pauses/permissions."""
        with self._connect() as db:
            return [dict(row) for row in db.execute(
                """SELECT * FROM reference_events e WHERE snapshot_id IS ?
                   AND NOT EXISTS (SELECT 1 FROM reference_retirements r
                                   WHERE r.event_id=e.event_id)
                   ORDER BY created_at, event_id""", (snapshot_id,))]
