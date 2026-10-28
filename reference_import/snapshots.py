"""Immutable local reference versions, explicit review and atomic activation."""
import fcntl
import os
from contextlib import contextmanager
import hashlib
import json
import math
from datetime import datetime, timezone
from .decisions import DecisionConflict, ReferenceDecisions
from .importer import HEADERS, SOURCE_URL, ImportFailure


class ReferenceStore(ReferenceDecisions):
    def __init__(self, database, *, owner_ids):
        database=os.path.realpath(str(database))
        os.makedirs(os.path.dirname(database),exist_ok=True)
        super().__init__(database, owner_ids=owner_ids)
        with self._connect() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS reference_snapshots (
                snapshot_id TEXT PRIMARY KEY, payload TEXT NOT NULL,
                reviewed_by TEXT, review_note TEXT, reviewed_at TEXT);
                CREATE TABLE IF NOT EXISTS reference_active (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), snapshot_id TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_activations (
                activation_id INTEGER PRIMARY KEY, snapshot_id TEXT NOT NULL,
                previous_snapshot_id TEXT, actor_id TEXT NOT NULL, activated_at TEXT NOT NULL);''')
        os.chmod(self.database,0o600)

    def stage(self, candidate):
        if (candidate.get('status') != 'staged' or candidate.get('source_url') != SOURCE_URL
                or list(candidate.get('headers', [])) != list(HEADERS)):
            raise ImportFailure('Invalid candidate contract')
        rows = candidate.get('rows')
        if not isinstance(rows, list) or not rows or candidate.get('row_count') != len(rows):
            raise ImportFailure('Empty or partial candidate')
        timestamp = datetime.fromisoformat(candidate['extracted_at'])
        if timestamp.tzinfo is None or timestamp > datetime.now(timezone.utc):
            raise ImportFailure('Invalid extraction timestamp')
        for row in rows:
            if not isinstance(row, list) or len(row) != len(HEADERS):
                raise ImportFailure('Invalid row shape')
            if (not all(isinstance(row[i], str) and row[i].strip() for i in (0, 1, 2))
                    or row[17:20] != ['TOMAN', 'rezvanpakhsh_pdf_ai', SOURCE_URL]
                    or row[20] != candidate['extracted_at']):
                raise ImportFailure('Invalid row identity/provenance')
            for i in (3, 4, 5, 6, 10):
                value = row[i]
                if value == '' and i != 3:
                    continue
                if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or value < 0:
                    raise ImportFailure('Invalid numeric column')
            if row[3] <= 0:
                raise ImportFailure('Invalid package price')
        payload = json.dumps(candidate, ensure_ascii=False, sort_keys=True, allow_nan=False)
        snapshot_id = hashlib.sha256(payload.encode()).hexdigest()
        with self._connect() as db:
            db.execute('INSERT OR IGNORE INTO reference_snapshots VALUES (?, ?, NULL, NULL, NULL)',
                       (snapshot_id, payload))
        return snapshot_id

    def review(self, snapshot_id, *, actor_id, note):
        if actor_id not in self.owner_ids:
            raise PermissionError('Owner review required')
        if not isinstance(note, str) or not note.strip():
            raise ValueError('Output coverage/price review note required')
        with self._connect() as db:
            existing = db.execute('SELECT reviewed_by,review_note FROM reference_snapshots WHERE snapshot_id=?',
                                  (snapshot_id,)).fetchone()
            if existing and existing['reviewed_by']:
                if existing['reviewed_by'] == actor_id and existing['review_note'] == note.strip():
                    return
                raise DecisionConflict('Snapshot was already reviewed')
            result = db.execute('UPDATE reference_snapshots SET reviewed_by=?, review_note=?, reviewed_at=? '
                                'WHERE snapshot_id=? AND reviewed_by IS NULL',
                                (actor_id, note.strip(), self._now(), snapshot_id))
            if result.rowcount != 1:
                raise DecisionConflict('Unknown or already reviewed snapshot')

    def snapshots(self, *, limit=50):
        limit = max(1, min(int(limit), 200))
        with self._connect() as db:
            active_row=db.execute('SELECT snapshot_id FROM reference_active WHERE singleton=1').fetchone()
            active_id=active_row['snapshot_id'] if active_row else None
            rows=db.execute('SELECT * FROM reference_snapshots ORDER BY rowid DESC LIMIT ?', (limit,)).fetchall()
        result=[]
        for row in rows:
            candidate=json.loads(row['payload'])
            preview=[]
            for values in candidate.get('rows',[])[:8]:
                if not isinstance(values,list) or len(values)!=len(HEADERS):
                    continue
                preview.append({'product_name':values[0],'product_code':values[16],
                    'package_price':values[3],'unit_price':values[4],
                    'consumer_unit_price':values[5],'package_count':values[6],
                    'unit_label':values[7],'pdf_row_number':values[15]})
            result.append({'snapshot_id':row['snapshot_id'],'extracted_at':candidate.get('extracted_at'),
                'row_count':candidate.get('row_count'),'pdf_sha256':candidate.get('pdf_sha256'),
                'model':candidate.get('model'),'source_url':candidate.get('source_url'),
                'preview':preview,
                'reviewed_by':row['reviewed_by'],'review_note':row['review_note'],
                'reviewed_at':row['reviewed_at'],'active':row['snapshot_id']==active_id})
        return {'active_snapshot_id':active_id,'snapshots':result}

    def snapshot(self, snapshot_id):
        with self._connect() as db:
            row=db.execute('SELECT payload FROM reference_snapshots WHERE snapshot_id=?',(snapshot_id,)).fetchone()
        return json.loads(row['payload']) if row else None

    @contextmanager
    def activation_lock(self):
        fd = os.open(self.database + '.activation.lock', os.O_CREAT | os.O_RDWR, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX)
            yield
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    def activate(self, snapshot_id, *, actor_id, expected_active_id):
        with self.activation_lock():
            return self._activate_locked(snapshot_id, actor_id=actor_id, expected_active_id=expected_active_id)

    def _activate_locked(self, snapshot_id, *, actor_id, expected_active_id):
        if actor_id not in self.owner_ids:
            raise PermissionError('Owner activation required')
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            active = db.execute('SELECT snapshot_id FROM reference_active WHERE singleton=1').fetchone()
            previous = active['snapshot_id'] if active else None
            if previous != expected_active_id:
                raise DecisionConflict('Active version changed; reload before activation')
            row = db.execute('SELECT * FROM reference_snapshots WHERE snapshot_id=?', (snapshot_id,)).fetchone()
            if not row or not row['reviewed_by']:
                raise DecisionConflict('Snapshot must be reviewed before activation')
            if previous == snapshot_id:
                return
            # No rollback to previously active data without a separate designed recovery policy.
            if db.execute('SELECT 1 FROM reference_activations WHERE snapshot_id=?', (snapshot_id,)).fetchone():
                raise DecisionConflict('Reactivation requires an explicit recovery workflow')
            if previous:
                old = json.loads(db.execute('SELECT payload FROM reference_snapshots WHERE snapshot_id=?',
                                            (previous,)).fetchone()['payload'])
                new = json.loads(row['payload'])
                if datetime.fromisoformat(new['extracted_at']) <= datetime.fromisoformat(old['extracted_at']):
                    raise DecisionConflict('Replacement must be newer than the active snapshot')
            now = self._now()
            db.execute('INSERT OR REPLACE INTO reference_active VALUES (1, ?)', (snapshot_id,))
            db.execute('INSERT INTO reference_activations VALUES (NULL, ?, ?, ?, ?)',
                       (snapshot_id, previous, actor_id, now))
            db.execute('''INSERT OR IGNORE INTO reference_retirements
                SELECT event_id, ?, ? FROM reference_events WHERE snapshot_id IS ?''',
                       (snapshot_id, now, previous))

    def active_snapshot(self):
        with self._connect() as db:
            row = db.execute('''SELECT s.snapshot_id, s.payload FROM reference_active a
                JOIN reference_snapshots s ON s.snapshot_id=a.snapshot_id WHERE a.singleton=1''').fetchone()
        return {**json.loads(row['payload']), 'snapshot_id': row['snapshot_id']} if row else None

    def record_refresh_failure(self, occurrence_id):
        # Capture active identity and failure atomically against activation.
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT snapshot_id FROM reference_active WHERE singleton=1').fetchone()
            snapshot_id = row['snapshot_id'] if row else None
            event_id = 'refresh:' + occurrence_id
            old = db.execute('SELECT event_id FROM reference_events WHERE event_id=?', (event_id,)).fetchone()
            if not old:
                db.execute('INSERT INTO reference_events VALUES (?, ?, ?, ?, NULL, NULL, NULL)',
                           (event_id, 'refresh_failed', snapshot_id, self._now()))
        return event_id
