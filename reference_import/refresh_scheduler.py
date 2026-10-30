"""Single-process-safe daily PDF/Gemini refresh attempts with durable failure incidents."""
from __future__ import annotations

import fcntl
import os
import sqlite3
import uuid
from contextlib import contextmanager
from datetime import datetime, timezone

from .importer import ImportFailure
from .snapshots import ReferenceStore


class ReferenceRefreshScheduler:
    def __init__(self, database, *, owner_ids, interval_seconds=86400):
        self.database=os.path.realpath(os.fspath(database))
        self.owner_ids=tuple(owner_ids)
        if not self.owner_ids:
            raise ValueError('Reference owner identities required')
        self.interval_seconds=max(3600,min(int(interval_seconds),7*86400))
        self.store=ReferenceStore(self.database,owner_ids=self.owner_ids)
        with self._connect() as db:
            db.execute('''CREATE TABLE IF NOT EXISTS reference_refresh_state (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), last_attempt_at TEXT,
                state TEXT, occurrence_id TEXT, snapshot_id TEXT, error_code TEXT)''')
            db.execute('INSERT OR IGNORE INTO reference_refresh_state(singleton) VALUES (1)')

    @contextmanager
    def _connect(self):
        db=sqlite3.connect(self.database,timeout=15)
        db.row_factory=sqlite3.Row
        try:
            with db: yield db
        finally: db.close()

    @contextmanager
    def _lock(self):
        filename=self.database+'.refresh.lock'
        fd=os.open(filename,os.O_CREAT|os.O_RDWR,0o600)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX)
            yield
        finally:
            fcntl.flock(fd,fcntl.LOCK_UN)
            os.close(fd)

    def state(self):
        with self._connect() as db:
            row=db.execute('SELECT * FROM reference_refresh_state WHERE singleton=1').fetchone()
        return dict(row) if row else None

    def run_if_due(self, refresh=None, *, now=None, force=False):
        now=now or datetime.now(timezone.utc)
        if now.tzinfo is None:
            raise ValueError('Timezone-aware refresh clock required')
        with self._lock():
            previous=self.state() or {}
            last=previous.get('last_attempt_at')
            running_crash=previous.get('state')=='running'
            due=True
            elapsed=self.interval_seconds
            if last and not running_crash:
                try:
                    elapsed=(now-datetime.fromisoformat(last)).total_seconds()
                    due=elapsed>=self.interval_seconds
                except (TypeError,ValueError):
                    elapsed=self.interval_seconds
                    due=True
            if force:
                if previous.get('state') not in (None, 'failed', 'running', 'staged'):
                    due = False
                else:
                    due = True
                    if last and not running_crash:
                        retry_wait = max(0, 60 - int(elapsed))
                        if retry_wait:
                            return {'state':'cooldown','retry_after_seconds':retry_wait}
            if not due:
                return {'state':'not_due','next_check_seconds':max(0,self.interval_seconds-int(elapsed))}
            if running_crash and previous.get('occurrence_id'):
                self.store.record_refresh_failure(previous['occurrence_id'])
            occurrence=now.strftime('%Y%m%dT%H%M%S')+'Z-'+uuid.uuid4().hex
            with self._connect() as db:
                db.execute('''UPDATE reference_refresh_state SET last_attempt_at=?,state='running',
                    occurrence_id=?,snapshot_id=NULL,error_code=NULL WHERE singleton=1''',
                    (now.isoformat(),occurrence))
            try:
                candidate=(refresh or self._refresh_pdf)()
                snapshot_id=self.store.stage(candidate)
                with self._connect() as db:
                    db.execute('''UPDATE reference_refresh_state SET state='staged',snapshot_id=?,error_code=NULL
                        WHERE singleton=1 AND occurrence_id=?''',(snapshot_id,occurrence))
                return {'state':'staged','snapshot_id':snapshot_id,'occurrence_id':occurrence,
                        'row_count':candidate.get('row_count')}
            except Exception as exc:
                self.store.record_refresh_failure(occurrence)
                error_code = getattr(exc, 'code', None) or type(exc).__name__
                if not isinstance(error_code, str) or not error_code.replace('_','').isalnum():
                    error_code = 'refresh_failed'
                error_code = error_code[:80]
                with self._connect() as db:
                    db.execute('''UPDATE reference_refresh_state SET state='failed',error_code=?
                        WHERE singleton=1 AND occurrence_id=?''',(error_code,occurrence))
                return {'state':'failed','occurrence_id':occurrence,'error_code':error_code}

    @staticmethod
    def _refresh_pdf():
        import requests
        from .importer import extract_pdf
        with requests.Session() as session:
            return extract_pdf(session)
