"""Persistent transport receipts/jobs. Payloads may contain private data: keep DB private."""
import base64
import fcntl
import hashlib
import json
import math
import os
import re
import sqlite3
import time
import uuid
from contextlib import contextmanager


def pipeline_payload_retention_days(value=None):
    """Return the bounded age for compacting successfully persisted source payloads."""
    if value is None:
        value = os.getenv('PIPELINE_PAYLOAD_RETENTION_DAYS')
    try:
        days = int(value or '30')
    except (TypeError, ValueError):
        days = 30
    return max(30, min(365, days))


def compact_pipeline_payloads(queue, *, now=None):
    days = pipeline_payload_retention_days()
    result = queue.compact_completed_payloads(older_than_seconds=days * 86400, now=now)
    return days, result


class JobConflict(ValueError):
    pass


class ReferencePending(RuntimeError):
    """A durable batch waits for the owner reference decision."""


class ReferenceChanged(RuntimeError):
    """Snapshot changed after intake began; inspection is required."""


class BatchRecoveryRequired(RuntimeError):
    """An uncertain batch must be reconciled before its idempotent replay."""


class Handoffs:
    def __init__(self, path):
        self.path = os.path.realpath(os.fspath(path))
        with self.connect() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS jobs (
                id TEXT PRIMARY KEY, kind TEXT NOT NULL, payload TEXT NOT NULL,
                state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
                available REAL NOT NULL, lease TEXT, expires REAL,
                result TEXT, error TEXT, created_at REAL, updated_at REAL);
                CREATE TABLE IF NOT EXISTS inbox (
                id TEXT PRIMARY KEY, payload TEXT NOT NULL, batch_id TEXT, received_at REAL);
                CREATE TABLE IF NOT EXISTS publication_holds (
                hold_id TEXT PRIMARY KEY, reason TEXT NOT NULL, created_at REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS publication_hold_resolutions (
                id INTEGER PRIMARY KEY, hold_id TEXT NOT NULL, reason TEXT NOT NULL,
                actor_id TEXT NOT NULL, note TEXT NOT NULL, resolved_at REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS sheet_sync_conflicts (
                conflict_id TEXT PRIMARY KEY, product_id TEXT NOT NULL, product_name TEXT NOT NULL,
                channel TEXT NOT NULL, field TEXT NOT NULL, source_revision TEXT NOT NULL,
                base_value TEXT NOT NULL, server_value TEXT NOT NULL, sheet_value TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT 'open', created_at REAL NOT NULL,
                resolved_at REAL, choice TEXT, actor_id TEXT, note TEXT);
                CREATE TABLE IF NOT EXISTS sheet_projection_state (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), observed_digest TEXT NOT NULL DEFAULT '',
                published_digest TEXT NOT NULL DEFAULT '', job_id TEXT);
                CREATE TABLE IF NOT EXISTS pipeline_workers (
                worker_id TEXT PRIMARY KEY, started_at REAL NOT NULL, heartbeat_at REAL NOT NULL,
                progress_at REAL NOT NULL,
                state TEXT NOT NULL, current_job_id TEXT, last_claimed_job_id TEXT,
                last_claimed_at REAL, last_completed_job_id TEXT, last_completed_at REAL,
                last_error_code TEXT);
                CREATE TABLE IF NOT EXISTS pending_telegram_chats (
                chat_id TEXT PRIMARY KEY, first_seen_at REAL NOT NULL, last_seen_at REAL NOT NULL,
                seen_count INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1,
                state TEXT NOT NULL DEFAULT 'pending', decided_at REAL, decided_by TEXT);''')
            self._ensure_column(db, 'jobs', 'created_at', 'REAL')
            self._ensure_column(db, 'jobs', 'updated_at', 'REAL')
            self._ensure_column(db, 'inbox', 'received_at', 'REAL')
            self._ensure_column(db, 'pipeline_workers', 'progress_at', 'REAL')
            db.executescript('''
                CREATE TRIGGER IF NOT EXISTS jobs_set_timestamps_after_insert
                AFTER INSERT ON jobs
                WHEN NEW.created_at IS NULL OR NEW.updated_at IS NULL
                BEGIN
                    UPDATE jobs SET
                        created_at=COALESCE(NEW.created_at,(julianday('now')-2440587.5)*86400.0),
                        updated_at=COALESCE(NEW.updated_at,(julianday('now')-2440587.5)*86400.0)
                    WHERE id=NEW.id;
                END;
                CREATE TRIGGER IF NOT EXISTS jobs_update_timestamp
                AFTER UPDATE ON jobs
                WHEN NEW.updated_at IS OLD.updated_at
                BEGIN
                    UPDATE jobs SET updated_at=(julianday('now')-2440587.5)*86400.0
                    WHERE id=NEW.id;
                END;
            ''')
        os.chmod(self.path, 0o600)

    @staticmethod
    def _ensure_column(db, table, name, definition):
        columns = {str(row['name']) for row in db.execute(f'PRAGMA table_info({table})')}
        if name not in columns:
            db.execute(f'ALTER TABLE {table} ADD COLUMN {name} {definition}')

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path, timeout=15)
        db.row_factory = sqlite3.Row
        try:
            with db: yield db
        finally: db.close()

    def record_pending_telegram_chat(self, chat_id, *, now=None):
        """Keep only the identity/timing of an unapproved private Telegram sender."""
        value = str(chat_id or '').strip()
        if not re.fullmatch(r'[0-9]{1,20}', value):
            return False
        stamp = float(time.time() if now is None else now)
        with self.connect() as db:
            db.execute("DELETE FROM pending_telegram_chats WHERE state!='approved' AND last_seen_at<?",
                       (stamp - 30 * 86400,))
            db.execute('''INSERT INTO pending_telegram_chats
                (chat_id,first_seen_at,last_seen_at,seen_count,revision,state) VALUES (?,?,?,?,1,'pending')
                ON CONFLICT(chat_id) DO UPDATE SET
                    last_seen_at=excluded.last_seen_at,
                    seen_count=pending_telegram_chats.seen_count+1,
                    revision=pending_telegram_chats.revision+1
                WHERE pending_telegram_chats.state='pending' ''',
                (value, stamp, stamp, 1))
            db.execute('''DELETE FROM pending_telegram_chats WHERE chat_id IN (
                SELECT chat_id FROM pending_telegram_chats
                WHERE state='pending' ORDER BY last_seen_at DESC,chat_id DESC LIMIT -1 OFFSET 500)''')
        return True

    def pending_telegram_chats(self, *, limit=50):
        """Return bounded, owner-only enrollment context without message payloads."""
        try:
            size = max(1, min(int(limit), 100))
        except (TypeError, ValueError, OverflowError):
            size = 50
        with self.connect() as db:
            rows = db.execute('''SELECT chat_id,first_seen_at,last_seen_at,seen_count,revision
                FROM pending_telegram_chats WHERE state='pending'
                ORDER BY last_seen_at DESC,chat_id DESC LIMIT ?''',
                (size,)).fetchall()
        return [dict(row) for row in rows]

    def approved_telegram_operator_ids(self):
        with self.connect() as db:
            rows=db.execute("SELECT chat_id FROM pending_telegram_chats WHERE state='approved' ORDER BY chat_id").fetchall()
        return [str(row['chat_id']) for row in rows]

    def resolve_pending_telegram_chat(self, chat_id, *, expected_revision, approve, actor_id):
        """Record an owner decision against the exact pending-sender revision."""
        value=str(chat_id or '').strip()
        if (not re.fullmatch(r'[0-9]{1,20}',value)
                or type(expected_revision) is not int or expected_revision < 1
                or type(approve) is not bool or not isinstance(actor_id,str) or not actor_id.strip()):
            raise ValueError('valid Telegram account decision required')
        now=time.time()
        state='approved' if approve else 'rejected'
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row=db.execute('SELECT revision,state,decided_by,decided_at FROM pending_telegram_chats WHERE chat_id=?',(value,)).fetchone()
            if (row and row['state']==state and int(row['revision'])==expected_revision
                    and str(row['decided_by'] or '')==actor_id.strip()):
                return {'chat_id':value,'state':state,'revision':expected_revision,
                        'decided_at':row['decided_at'],'already_done':True}
            if not row or row['state']!='pending' or int(row['revision'])!=expected_revision:
                raise JobConflict('pending Telegram account changed')
            db.execute('''UPDATE pending_telegram_chats SET state=?,decided_at=?,decided_by=?
                WHERE chat_id=? AND state='pending' AND revision=?''',
                (state,now,actor_id.strip()[:120],value,expected_revision))
        return {'chat_id':value,'state':state,'revision':expected_revision,'decided_at':now,
                'already_done':False}

    @contextmanager
    def publication_lock(self):
        """Serialize batch writes and every application publication across processes."""
        lockpath = self.path + '.publication.lock'
        fd = os.open(lockpath, os.O_CREAT | os.O_RDWR, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX)
            yield
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    def hold_publication(self, hold_id, reason):
        with self.connect() as db:
            db.execute('''INSERT INTO publication_holds VALUES (?,?,?)
                ON CONFLICT(hold_id) DO UPDATE SET reason=excluded.reason
                WHERE publication_holds.reason='batch_in_progress' ''',
                (hold_id, reason, time.time()))

    def publication_holds(self):
        with self.connect() as db:
            return [dict(r) for r in db.execute('SELECT * FROM publication_holds ORDER BY created_at')]

    def publication_hold_context(self, hold_id):
        """Return bounded, owner-review context for the source batch behind a hold."""
        if not isinstance(hold_id, str) or not hold_id or len(hold_id) > 100:
            return {'messages': []}
        with self.connect() as db:
            row = db.execute('''SELECT id,payload,state,attempts,error,result,created_at,updated_at
                FROM jobs WHERE id=? AND kind='batch' ''', ('batch:' + hold_id,)).fetchone()
        if not row:
            return {'messages': []}
        payload = json.loads(row['payload']) if row['payload'] else {}
        result = json.loads(row['result']) if row['result'] else {}
        if not isinstance(payload, dict):
            payload = {}
        if not isinstance(result, dict):
            result = {}
        source_messages = payload.get('messages')
        if not isinstance(source_messages, list):
            source_messages = []
        messages = []
        for source in source_messages[:100]:
            if not isinstance(source, dict):
                continue
            content = str(source.get('content') or '')
            messages.append({
                'message_id': str(source.get('id') or '')[:100],
                'channel': str(source.get('channel_username') or source.get('channel') or '')[:200],
                'source_timestamp': str(source.get('timestamp') or '')[:100],
                'source_timestamp_basis': str(source.get('source_timestamp_basis') or '')[:80],
                'source_created_at': str(source.get('source_created_at') or '')[:100],
                'source_updated_at': str(source.get('source_update_at') or '')[:100],
                'source_url': str(source.get('url') or '')[:1000],
                'content': content[:1800],
                'content_truncated': len(content) > 1800,
                'has_media': bool(source.get('has_media')),
                'media_type': str(source.get('media_type') or '')[:80],
            })
        return {
            'batch_state': str(row['state'] or ''),
            'batch_attempts': int(row['attempts'] or 0),
            'batch_error': str(row['error'] or '')[:300],
            'created_at': row['created_at'],
            'expected_messages': int(result.get('expected_count') or payload.get('expected_count') or len(messages)),
            'written_messages': int(result.get('written_count') or 0),
            'processed_messages': int(result.get('processed_messages') or 0),
            'extraction_state': str(result.get('extraction_status') or ''),
            'processing_error': str(result.get('extraction_error') or '')[:300],
            'processing_detail': str(result.get('extraction_detail') or '')[:200],
            'rollback': bool(result.get('rollback')),
            'messages': messages,
        }

    @staticmethod
    def _decode_operations_cursor(cursor):
        if cursor is None or cursor == '':
            return None
        if not isinstance(cursor, str) or len(cursor) > 2048:
            raise ValueError('invalid queue cursor')
        try:
            raw=base64.urlsafe_b64decode(cursor + '=' * (-len(cursor) % 4))
            value=json.loads(raw.decode('utf-8'))
            snapshot=float(value['snapshot_time'])
            created=float(value['last_created_at'])
            job_id=value['last_id']
        except (ValueError, TypeError, KeyError, UnicodeDecodeError, OverflowError, RecursionError):
            raise ValueError('invalid queue cursor') from None
        if (not isinstance(value,dict) or value.get('v') != 1 or not math.isfinite(snapshot)
                or not math.isfinite(created) or not isinstance(job_id,str) or not job_id
                or len(job_id)>300):
            raise ValueError('invalid queue cursor')
        return snapshot,created,job_id

    @staticmethod
    def _encode_operations_cursor(snapshot_time, created_at, job_id):
        value={'v':1,'snapshot_time':snapshot_time,'last_created_at':created_at,'last_id':job_id}
        return base64.urlsafe_b64encode(json.dumps(value,separators=(',',':')).encode()).decode().rstrip('=')

    def operations_snapshot(self, *, limit=100, cursor=None, now=None):
        """Return one stable, owner-safe queue page without exposing payloads or results."""
        limit=max(1,min(int(limit),200))
        now=time.time() if now is None else float(now)
        decoded=self._decode_operations_cursor(cursor)
        snapshot_time,after_created,after_id=(decoded if decoded else (now,None,None))
        actionable=('queued','running','waiting_reference','waiting_publication','failed','needs_review')
        placeholders=','.join('?' for _ in actionable)
        created_expr='COALESCE(created_at,updated_at,available,0)'
        where=f"state IN ({placeholders}) AND {created_expr}<=?"
        params=[*actionable,snapshot_time]
        if decoded:
            where += f" AND ({created_expr}>? OR ({created_expr}=? AND id>?))"
            params.extend((after_created,after_created,after_id))
        with self.connect() as db:
            db.execute('BEGIN')
            grouped=db.execute('SELECT state,COUNT(*) AS count FROM jobs GROUP BY state').fetchall()
            rows=db.execute(f'''SELECT id,kind,state,attempts,available,expires,error,created_at,
                    {created_expr} AS cursor_created_at FROM jobs
                WHERE {where} ORDER BY cursor_created_at,id LIMIT ?''',(*params,limit+1)).fetchall()
        by_state={str(row['state']):int(row['count']) for row in grouped}
        has_more=len(rows)>limit
        rows=rows[:limit]
        items=[]
        for row in rows:
            item={key:row[key] for key in ('id','kind','state','attempts','available','error','created_at')}
            item['lease_expired']=bool(row['state']=='running' and row['expires'] is not None and row['expires']<=now)
            item['age_seconds']=max(0,int(now-float(row['cursor_created_at'] or 0)))
            item['next_action']={
                'queued':'automatic_retry',
                'running':'recover_expired_lease' if item['lease_expired'] else 'wait_for_processing',
                'waiting_reference':'resolve_reference_decision',
                'waiting_publication':'resolve_publication_hold',
                'failed':'inspect_failure_and_reconcile',
                'needs_review':'inspect_result_and_decide',
            }.get(str(row['state']),'inspect_receipt')
            items.append(item)
        next_cursor=(self._encode_operations_cursor(snapshot_time,rows[-1]['cursor_created_at'],rows[-1]['id'])
                     if has_more and rows else None)
        return {
            'snapshot_time':snapshot_time,
            'by_state':by_state,
            'actionable_count':sum(by_state.get(state,0) for state in actionable),
            'attention_count':sum(by_state.get(state,0) for state in
                                  ('waiting_reference','waiting_publication','failed','needs_review')),
            'items':items,
            'page_count':len(items),
            'has_more':bool(next_cursor),
            'next_cursor':next_cursor,
        }

    def start_worker(self, worker_id, *, now=None):
        """Register a worker process; heartbeat updates never contain job payloads."""
        if not isinstance(worker_id,str) or not worker_id or len(worker_id)>120:
            raise ValueError('invalid worker id')
        now=time.time() if now is None else float(now)
        with self.connect() as db:
            db.execute('DELETE FROM pipeline_workers WHERE started_at<? AND worker_id!=?',
                       (now-30*86400,worker_id))
            db.execute('''INSERT INTO pipeline_workers
                (worker_id,started_at,heartbeat_at,progress_at,state) VALUES (?,?,?,?,'starting')
                ON CONFLICT(worker_id) DO UPDATE SET started_at=excluded.started_at,
                heartbeat_at=excluded.heartbeat_at,progress_at=excluded.progress_at,
                state='starting',current_job_id=NULL,
                last_claimed_job_id=NULL,last_claimed_at=NULL,last_completed_job_id=NULL,
                last_completed_at=NULL,last_error_code=NULL''',(worker_id,now,now,now))

    def heartbeat_worker(self, worker_id, *, now=None):
        now=time.time() if now is None else float(now)
        with self.connect() as db:
            db.execute("UPDATE pipeline_workers SET heartbeat_at=? WHERE worker_id=? AND state!='stopped'",
                       (now,worker_id))

    def set_worker_state(self, worker_id, state):
        allowed={'starting','idle','polling_sheet','refreshing_reference','stopped'}
        if state not in allowed:
            raise ValueError('invalid worker state')
        now=time.time()
        with self.connect() as db:
            db.execute('''UPDATE pipeline_workers SET state=?,heartbeat_at=?,progress_at=?,
                current_job_id=NULL WHERE worker_id=?''',(state,now,now,worker_id))

    def worker_status(self, *, stale_after_seconds=45, now=None):
        now=time.time() if now is None else float(now)
        with self.connect() as db:
            row=db.execute('''SELECT worker_id,started_at,heartbeat_at,state,current_job_id,
                progress_at,last_claimed_job_id,last_claimed_at,last_completed_job_id,last_completed_at,last_error_code
                FROM pipeline_workers ORDER BY heartbeat_at DESC,started_at DESC LIMIT 1''').fetchone()
        if row is None:
            return {'status':'unknown','heartbeat_age_seconds':None,'progress_status':'unknown',
                    'progress_age_seconds':None}
        age=max(0.0,now-float(row['heartbeat_at']))
        progress_age=max(0.0,now-float(row['progress_at'] or row['started_at']))
        state=str(row['state'])
        status='stopped' if state=='stopped' else ('stale' if age>stale_after_seconds else 'alive')
        progress_status=('stopped' if state=='stopped' else 'stalled' if progress_age>300
                         else 'idle' if state=='idle' else 'active')
        return {**dict(row),'status':status,'heartbeat_age_seconds':round(age,1),
                'progress_status':progress_status,'progress_age_seconds':round(progress_age,1)}

    def _worker_job_started(self, worker_id, job_id):
        if not worker_id:
            return
        now=time.time()
        with self.connect() as db:
            db.execute('''UPDATE pipeline_workers SET state='processing',current_job_id=?,
                last_claimed_job_id=?,last_claimed_at=?,heartbeat_at=?,progress_at=? WHERE worker_id=?''',
                (job_id,job_id,now,now,now,worker_id))

    def _worker_job_finished(self, worker_id, job_id):
        if not worker_id:
            return
        now=time.time()
        with self.connect() as db:
            job=db.execute('SELECT error FROM jobs WHERE id=?',(job_id,)).fetchone()
            error_code=str(job['error'] or '')[:100] if job else ''
            db.execute('''UPDATE pipeline_workers SET state='idle',current_job_id=NULL,
                last_completed_job_id=?,last_completed_at=?,last_error_code=?,heartbeat_at=?,progress_at=?
                WHERE worker_id=?''',(job_id,now,error_code,now,now,worker_id))

    def cancel_unroutable_notice_jobs(self, allowed_chat_ids):
        """Cancel pending private notices whose destination is outside the channel allowlist."""
        allowed = {
            str(channel): {str(chat).strip() for chat in chats if str(chat).strip()}
            for channel, chats in (allowed_chat_ids or {}).items()
        }
        now = time.time()
        cancelled = 0
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            rows = db.execute("""SELECT id,kind,state,payload FROM jobs
                WHERE kind='notice' AND state IN ('queued','failed','waiting_reference','waiting_publication')""").fetchall()
            for row in rows:
                try:
                    payload = json.loads(row['payload']) if row['payload'] else {}
                except (TypeError, ValueError):
                    payload = {}
                channel = str(payload.get('channel') or '') if isinstance(payload, dict) else ''
                chat_id = str(payload.get('chat_id') or '').strip() if isinstance(payload, dict) else ''
                if chat_id and chat_id in allowed.get(channel, set()):
                    continue
                result = self.encode({'delivered': False, 'skipped': 'recipient_not_allowlisted'})
                changed = db.execute("""UPDATE jobs SET state='cancelled',result=?,error=?,lease=NULL,
                    expires=NULL,updated_at=? WHERE id=? AND state=?""",
                    (result, 'recipient_not_allowlisted', now, str(row['id']), str(row['state'])))
                cancelled += changed.rowcount
        return cancelled

    def failed_backlog_snapshot(self):
        """Return failed receipts for owner reconciliation after source/sync verification."""
        with self.connect() as db:
            publications = db.execute(
                "SELECT id FROM jobs WHERE kind='publish' AND state='failed' ORDER BY id"
            ).fetchall()
            batches = db.execute(
                "SELECT id FROM jobs WHERE kind='batch' AND state='failed' ORDER BY id"
            ).fetchall()
        return {
            'publication_ids': [str(row['id']) for row in publications],
            'batch_ids': [str(row['id']) for row in batches],
        }

    def reconcile_failed_backlog(self, publication_ids, batch_ids, *, actor_id, sheet_digest, sync_result):
        """Atomically close exact failed receipts covered by a complete sync and source audit."""
        selections=((publication_ids,'publish:',500,'invalid failed publication selection'),
                    (batch_ids,'batch:',100,'invalid failed batch selection'))
        for job_ids,prefix,limit,error in selections:
            if (not isinstance(job_ids,list) or len(job_ids)>limit
                    or any(not isinstance(item,str) or not item.startswith(prefix) or len(item)>300
                           for item in job_ids)
                    or len(set(job_ids))!=len(job_ids)):
                raise ValueError(error)
        if not isinstance(actor_id, str) or not actor_id.strip() or len(actor_id) > 300:
            raise ValueError('owner actor required')
        try:
            images_left = int(sync_result.get('images_left') or 0) if isinstance(sync_result, dict) else -1
        except (TypeError, ValueError, OverflowError):
            images_left = -1
        if (not isinstance(sheet_digest, str) or not re.fullmatch(r'[a-f0-9]{64}', sheet_digest)
                or not isinstance(sync_result, dict) or sync_result.get('ok') is not True
                or sync_result.get('pushed') is not True or images_left != 0):
            raise ValueError('successful complete catalog sync required')
        if not publication_ids and not batch_ids:
            return {'publication_ids':[],'batch_ids':[]}
        summary = {key: sync_result.get(key) for key in ('count', 'created', 'updated', 'hidden', 'attached', 'images_left')}
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            publication_rows=[]
            if publication_ids:
                marks=','.join('?' for _ in publication_ids)
                publication_rows=db.execute(f"SELECT id,error FROM jobs WHERE id IN ({marks}) AND kind='publish' AND state='failed'",
                    tuple(publication_ids)).fetchall()
            batch_rows=[]
            if batch_ids:
                marks=','.join('?' for _ in batch_ids)
                batch_rows=db.execute(f"SELECT id,error FROM jobs WHERE id IN ({marks}) AND kind='batch' AND state='failed'",
                    tuple(batch_ids)).fetchall()
            if ({str(row['id']) for row in publication_rows} != set(publication_ids)
                    or {str(row['id']) for row in batch_rows} != set(batch_ids)):
                raise JobConflict('failed publication backlog changed during reconciliation')
            reconciled_at = time.time()
            for row in publication_rows:
                receipt = {
                    'ok': True, 'pushed': True, 'superseded_by_catalog_sync': True,
                    'reconciled_at': reconciled_at, 'actor_id': actor_id,
                    'sheet_digest': sheet_digest, 'previous_error': str(row['error'] or ''),
                    'sync': summary,
                }
                db.execute("""UPDATE jobs SET state='done',result=?,error=NULL,lease=NULL,expires=NULL,updated_at=?
                    WHERE id=? AND kind='publish' AND state='failed'""",
                    (self.encode(receipt), reconciled_at, str(row['id'])))
            for row in batch_rows:
                receipt = {
                    'ok': True, 'reconciled_after_catalog_sync': True,
                    'source_rows_verified': True, 'reconciled_at': reconciled_at,
                    'actor_id': actor_id, 'sheet_digest': sheet_digest,
                    'previous_error': str(row['error'] or ''), 'sync': summary,
                }
                db.execute("""UPDATE jobs SET state='done',result=?,error=NULL,lease=NULL,expires=NULL,updated_at=?
                    WHERE id=? AND kind='batch' AND state='failed'""",
                    (self.encode(receipt), reconciled_at, str(row['id'])))
        return {'publication_ids':[str(row['id']) for row in publication_rows],
                'batch_ids':[str(row['id']) for row in batch_rows]}

    def reconciliation_receipts(self, *, limit=200):
        """Return source identifiers and queue outcomes without exposing message text."""
        limit = max(1, min(int(limit), 500))
        with self.connect() as db:
            receipts = db.execute('''SELECT id,payload,batch_id,received_at FROM inbox
                ORDER BY rowid DESC LIMIT ?''', (limit,)).fetchall()
            jobs = db.execute('''SELECT id,kind,state,attempts,error,result,payload,created_at,updated_at
                FROM jobs WHERE kind IN ('batch','publish') ORDER BY rowid DESC''').fetchall()
            total = db.execute('SELECT COUNT(*) AS count FROM inbox').fetchone()['count']

        jobs_by_id = {}
        publication_by_batch = {}
        publication_by_decision = {}
        for row in jobs:
            item = dict(row)
            item['payload'] = json.loads(item['payload']) if item['payload'] else {}
            item['result'] = json.loads(item['result']) if item['result'] else {}
            jobs_by_id[item['id']] = item
            batch_id = str(item['payload'].get('batch_id') or '')
            if item['kind'] == 'publish' and batch_id and batch_id not in publication_by_batch:
                publication_by_batch[batch_id] = item
            decision_id = str(item['payload'].get('decision_id') or '')
            if item['kind'] == 'publish' and decision_id and decision_id not in publication_by_decision:
                publication_by_decision[decision_id] = {
                    'state': item['state'], 'attempts': int(item.get('attempts') or 0),
                    'error': item.get('error') or '', 'created_at': item.get('created_at'),
                    'updated_at': item.get('updated_at'),
                    'pushed': bool(item['result'].get('pushed')),
                    'count': int(item['result'].get('count') or 0),
                }

        items = []
        for row in receipts:
            source = json.loads(row['payload']) if row['payload'] else {}
            batch_job_id = str(row['batch_id'] or '')
            batch_job = jobs_by_id.get(batch_job_id)
            batch_payload = (batch_job or {}).get('payload') or {}
            batch_id = str(batch_payload.get('batch_id') or '')
            publication = publication_by_batch.get(batch_id) or {}
            batch_result = (batch_job or {}).get('result') or {}
            publish_result = publication.get('result') or {}
            items.append({
                'receipt_id': str(row['id']),
                'message_id': str(source.get('id') or ''),
                'channel': str(source.get('channel_username') or source.get('channel') or ''),
                'source_channel': str(source.get('source_channel') or ''),
                'source_timestamp': str(source.get('timestamp') or ''),
                'source_timestamp_basis': str(source.get('source_timestamp_basis') or ''),
                'source_created_at': str(source.get('source_created_at') or ''),
                'source_updated_at': str(source.get('source_update_at') or ''),
                'received_at': row['received_at'],
                'batch_id': batch_id,
                'batch_job_id': batch_job_id,
                'batch_state': (batch_job or {}).get('state') or 'unbatched',
                'batch_attempts': int((batch_job or {}).get('attempts') or 0),
                'batch_error': (batch_job or {}).get('error') or '',
                'batch_created_at': (batch_job or {}).get('created_at'),
                'batch_updated_at': (batch_job or {}).get('updated_at'),
                'expected_messages': int(batch_result.get('expected_count') or batch_payload.get('expected_count') or 0),
                'processed_messages': int(batch_result.get('processed_messages') or 0),
                'extraction_state': str(batch_result.get('extraction_status') or ''),
                'rollback': bool(batch_result.get('rollback')),
                'publication_state': publication.get('state') or 'not_queued',
                'publication_attempts': int(publication.get('attempts') or 0),
                'publication_error': publication.get('error') or '',
                'publication_created_at': publication.get('created_at'),
                'publication_updated_at': publication.get('updated_at'),
                'publication_pushed': bool(publish_result.get('pushed')),
                'publication_count': int(publish_result.get('count') or 0),
            })
        return {'receipts': items, 'total_receipts': int(total), 'truncated': int(total) > len(items),
                'publication_by_decision': publication_by_decision}

    def resolve_publication_hold(self, hold_id, *, expected_reason, actor_id, note, resume_batch=False):
        """Record human reconciliation; optionally authorize the same batch to replay."""
        if not all(isinstance(v,str) and v.strip() for v in (hold_id,expected_reason,actor_id,note)):
            raise ValueError('hold, reason, actor and reconciliation note required')
        if len(note)>1000 or any(len(v)>300 for v in (hold_id,expected_reason,actor_id)):
            raise ValueError('hold resolution exceeds length limit')
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            hold=db.execute('SELECT * FROM publication_holds WHERE hold_id=?',(hold_id,)).fetchone()
            if not hold or hold['reason']!=expected_reason:
                raise JobConflict('hold changed or already resolved')
            if resume_batch:
                job=db.execute('SELECT state FROM jobs WHERE id=?',('batch:'+hold_id,)).fetchone()
                if not job or job['state']!='needs_review':
                    raise JobConflict('batch must reach review state before replay')
                db.execute("UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL WHERE id=?",
                           (time.time(),'batch:'+hold_id))
            db.execute('INSERT INTO publication_hold_resolutions VALUES (NULL,?,?,?,?,?)',
                       (hold_id,expected_reason,actor_id,note,time.time()))
            db.execute('DELETE FROM publication_holds WHERE hold_id=?',(hold_id,))
            if not db.execute('SELECT 1 FROM publication_holds LIMIT 1').fetchone():
                db.execute("""UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL
                    WHERE kind='publish' AND state='waiting_publication'""",(time.time(),))

    def resolve_publication_holds(self, holds, *, actor_id, note):
        """Atomically authorize one replay for a selected set of held batches."""
        if (not isinstance(holds, list) or not holds or len(holds) > 100
                or not isinstance(actor_id, str) or not actor_id.strip()
                or not isinstance(note, str) or not note.strip() or len(note) > 1000):
            raise ValueError('valid batch reviews required')
        prepared=[]
        seen=set()
        for item in holds:
            if (not isinstance(item, dict) or set(item) != {'hold_id','expected_reason'}
                    or not isinstance(item['hold_id'],str) or not item['hold_id']
                    or len(item['hold_id'])>100 or not isinstance(item['expected_reason'],str)
                    or not item['expected_reason'] or len(item['expected_reason'])>300
                    or item['hold_id'] in seen):
                raise ValueError('invalid batch review selection')
            seen.add(item['hold_id'])
            prepared.append(item)
        now=time.time()
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            for item in prepared:
                hold=db.execute('SELECT reason FROM publication_holds WHERE hold_id=?',
                                (item['hold_id'],)).fetchone()
                job=db.execute('SELECT state FROM jobs WHERE id=?',
                               ('batch:'+item['hold_id'],)).fetchone()
                if not hold or hold['reason']!=item['expected_reason']:
                    raise JobConflict('publication hold changed or already resolved')
                if not job or job['state']!='needs_review':
                    raise JobConflict('batch must reach review state before replay')
            for item in prepared:
                db.execute("""UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL
                    WHERE id=? AND state='needs_review'""",(now,'batch:'+item['hold_id']))
                db.execute('INSERT INTO publication_hold_resolutions VALUES (NULL,?,?,?,?,?)',
                           (item['hold_id'],item['expected_reason'],actor_id,note.strip(),now))
                db.execute('DELETE FROM publication_holds WHERE hold_id=?',(item['hold_id'],))
            if not db.execute('SELECT 1 FROM publication_holds LIMIT 1').fetchone():
                db.execute("""UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL
                    WHERE kind='publish' AND state='waiting_publication'""",(now,))
        return [item['hold_id'] for item in prepared]

    def wake_reference_waiters(self):
        """Queue batches parked for reference review after an owner clears the gate."""
        now=time.time()
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            changed=db.execute("""UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL
                WHERE kind='batch' AND state='waiting_reference'""",(now,))
        return int(changed.rowcount)

    def wake_publication_waiters_if_unblocked(self):
        """Queue held publication jobs only after every publication hold is cleared."""
        now=time.time()
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            if db.execute('SELECT 1 FROM publication_holds LIMIT 1').fetchone():
                return 0
            changed=db.execute("""UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL
                WHERE kind='publish' AND state='waiting_publication'""",(now,))
        return int(changed.rowcount)

    def record_sheet_conflict(self, conflict):
        required=('conflict_id','product_id','product_name','channel','field','source_revision')
        if any(not isinstance(conflict.get(key),str) or not conflict[key] for key in required):
            raise ValueError('sheet conflict identity required')
        with self.connect() as db:
            db.execute('''INSERT OR IGNORE INTO sheet_sync_conflicts
                (conflict_id,product_id,product_name,channel,field,source_revision,base_value,server_value,sheet_value,state,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,'open',?)''',
                (conflict['conflict_id'],conflict['product_id'],conflict['product_name'],conflict['channel'],
                 conflict['field'],conflict['source_revision'],self.encode(conflict.get('base')),
                 self.encode(conflict.get('server')),self.encode(conflict.get('sheet')),time.time()))
        return self.get_sheet_conflict(conflict['conflict_id'])

    def sheet_sync_conflicts(self, *, state='open'):
        with self.connect() as db:
            rows=db.execute('SELECT * FROM sheet_sync_conflicts WHERE state=? ORDER BY created_at,conflict_id',(state,)).fetchall()
        return [self._decode_sheet_conflict(dict(row)) for row in rows]

    def supersede_sheet_conflicts(self, product_id, channel, active_conflict_ids):
        """Close obsolete open conflicts after a fresh reconciliation of that product."""
        if not isinstance(product_id,str) or not product_id or not isinstance(channel,str):
            return
        active=set(active_conflict_ids or ())
        with self.connect() as db:
            rows=db.execute('''SELECT conflict_id FROM sheet_sync_conflicts
                WHERE product_id=? AND channel=? AND state='open' ''',(product_id,channel)).fetchall()
            stale=[row['conflict_id'] for row in rows if row['conflict_id'] not in active]
            for conflict_id in stale:
                db.execute('''UPDATE sheet_sync_conflicts SET state='superseded',resolved_at=?,
                    choice='superseded',actor_id='system',note='Values or source revision changed during reconciliation'
                    WHERE conflict_id=? AND state='open' ''',(time.time(),conflict_id))
        return stale

    def get_sheet_conflict(self, conflict_id):
        with self.connect() as db:
            row=db.execute('SELECT * FROM sheet_sync_conflicts WHERE conflict_id=?',(conflict_id,)).fetchone()
        return self._decode_sheet_conflict(dict(row)) if row else None

    def resolve_sheet_conflict(self, conflict_id, *, choice, actor_id, note):
        if choice not in ('server','sheet','preserve') or not isinstance(actor_id,str) or not actor_id.strip():
            raise ValueError('valid conflict choice and actor required')
        if not isinstance(note,str) or not note.strip() or len(note)>1000:
            raise ValueError('conflict resolution note required')
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            updated=db.execute('''UPDATE sheet_sync_conflicts SET state='resolved',resolved_at=?,choice=?,actor_id=?,note=?
                WHERE conflict_id=? AND state='open' ''',
                (time.time(),choice,actor_id,note.strip(),conflict_id))
            if updated.rowcount!=1: raise JobConflict('conflict changed or already resolved')
        return self.get_sheet_conflict(conflict_id)

    def _decode_sheet_conflict(self,row):
        for name in ('base_value','server_value','sheet_value'):
            row[name.removesuffix('_value')]=json.loads(row.pop(name))
        return row

    def enqueue_sheet_projection(self,digest):
        """Keep one pending publication job aligned with the newest observed Sheet."""
        if not isinstance(digest,str) or len(digest)!=64 or any(c not in '0123456789abcdef' for c in digest):
            raise ValueError('invalid Sheet projection digest')
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            state=db.execute('SELECT * FROM sheet_projection_state WHERE singleton=1').fetchone()
            if not state:
                db.execute("INSERT INTO sheet_projection_state(singleton) VALUES (1)")
                state=db.execute('SELECT * FROM sheet_projection_state WHERE singleton=1').fetchone()
            if digest==state['published_digest']:
                job_id=state['job_id']
                job=db.execute('SELECT state FROM jobs WHERE id=?',(job_id,)).fetchone() if job_id else None
                if job and job['state']=='queued':
                    db.execute("UPDATE jobs SET state='done',result=?,lease=NULL,expires=NULL,error=NULL WHERE id=?",
                               (self.encode({'ok':True,'pushed':True,'superseded':True}),job_id))
                db.execute('UPDATE sheet_projection_state SET observed_digest=?,job_id=NULL WHERE singleton=1',(digest,))
                return None
            if digest==state['observed_digest'] and state['job_id']:
                job=db.execute('SELECT state FROM jobs WHERE id=?',(state['job_id'],)).fetchone()
                if job and job['state'] in ('queued','running'):
                    return state['job_id']
            old_job_id=state['job_id']
            old_job=db.execute('SELECT state FROM jobs WHERE id=?',(old_job_id,)).fetchone() if old_job_id else None
            now=time.time()
            payload=self.encode({'sheet_digest':digest})
            if old_job and old_job['state']=='queued':
                job_id=old_job_id
                db.execute('''UPDATE jobs SET payload=?,attempts=0,available=?,lease=NULL,expires=NULL,
                    result=NULL,error=NULL WHERE id=? AND state='queued' ''',(payload,now,job_id))
            else:
                job_id='publish:sheet-change:'+uuid.uuid4().hex
                db.execute('''INSERT INTO jobs (id,kind,payload,state,attempts,available,lease,expires,result,error)
                    VALUES (?,'publish',?,'queued',0,?,NULL,NULL,NULL,NULL)''',(job_id,payload,now))
            db.execute('''UPDATE sheet_projection_state SET observed_digest=?,job_id=? WHERE singleton=1''',
                       (digest,job_id))
            return job_id

    def mark_sheet_projection_published(self,digest):
        if not isinstance(digest,str) or len(digest)!=64 or any(c not in '0123456789abcdef' for c in digest):
            raise ValueError('invalid Sheet projection digest')
        with self.connect() as db:
            db.execute('''INSERT OR IGNORE INTO sheet_projection_state
                (singleton,observed_digest,published_digest,job_id) VALUES (1,?,?,NULL)''',(digest,digest))
            state=db.execute('SELECT observed_digest,job_id FROM sheet_projection_state WHERE singleton=1').fetchone()
            if state['observed_digest']==digest and state['job_id']:
                pending=db.execute('SELECT state,payload FROM jobs WHERE id=?',(state['job_id'],)).fetchone()
                if pending and pending['state']=='queued':
                    payload=json.loads(pending['payload'])
                    if payload.get('sheet_digest')==digest:
                        db.execute("UPDATE jobs SET state='done',result=?,lease=NULL,expires=NULL,error=NULL WHERE id=?",
                                   (self.encode({'ok':True,'pushed':True,'superseded':True}),state['job_id']))
            db.execute('''UPDATE sheet_projection_state SET published_digest=?,
                job_id=CASE WHEN observed_digest=? THEN NULL ELSE job_id END WHERE singleton=1''',
                (digest,digest))

    def sheet_projection_state(self):
        with self.connect() as db:
            row=db.execute('SELECT * FROM sheet_projection_state WHERE singleton=1').fetchone()
        return dict(row) if row else None

    @staticmethod
    def encode(payload):
        return json.dumps(payload, sort_keys=True, ensure_ascii=False, allow_nan=False)

    def put(self, kind, key, payload):
        raw = self.encode(payload)
        job_id = kind + ':' + key
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            old = db.execute('SELECT payload FROM jobs WHERE id=?', (job_id,)).fetchone()
            if old and old['payload'] != raw:
                raise JobConflict('Receipt key reused with different content')
            db.execute('INSERT OR IGNORE INTO jobs (id,kind,payload,state,available) VALUES (?,?,?,\'queued\',?)',
                       (job_id, kind, raw, time.time()))
        return job_id

    def enqueue_reextraction(self, run_id, actor_id, items):
        """Queue one source message per job; extracted offers are staged for owner review."""
        if not isinstance(run_id, str) or not re.fullmatch(r'[a-f0-9]{32}', run_id):
            raise ValueError('invalid re-extraction run id')
        if not isinstance(actor_id, str) or not actor_id.strip():
            raise ValueError('re-extraction owner required')
        prepared=[]
        for item in items:
            if not isinstance(item, dict):
                raise ValueError('invalid re-extraction message')
            message_id=str(item.get('message_id') or '')
            batch_id=str(item.get('batch_id') or '')
            channel_username=str(item.get('channel_username') or '')
            message_key=str(item.get('message_key') or '')
            content_sha256=str(item.get('content_sha256') or '')
            if (not message_id or not message_key
                    or not re.fullmatch(r'[a-f0-9]{64}', message_key)
                    or not re.fullmatch(r'[a-f0-9]{64}', content_sha256)):
                raise ValueError('invalid re-extraction identity')
            payload={**item, 'run_id':run_id, 'actor_id':actor_id}
            job_id='reextract:'+run_id+':'+message_key
            prepared.append((job_id,self.encode(payload)))
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            active=db.execute("SELECT id,payload FROM jobs WHERE kind='reextract' AND state IN ('queued','running') ORDER BY rowid LIMIT 1").fetchone()
            if active:
                active_payload=json.loads(active['payload'])
                raise JobConflict('re-extraction run already active:'+str(active_payload.get('run_id') or ''))
            now=time.time()
            for job_id,raw in prepared:
                old=db.execute('SELECT payload FROM jobs WHERE id=?',(job_id,)).fetchone()
                if old and old['payload']!=raw:
                    raise JobConflict('re-extraction receipt key reused with different content')
                db.execute("INSERT OR IGNORE INTO jobs (id,kind,payload,state,available) VALUES (?,'reextract',?,'queued',?)",
                           (job_id,raw,now))
        return len(prepared)

    def reextraction_status(self, run_id):
        if not isinstance(run_id,str) or not re.fullmatch(r'[a-f0-9]{32}',run_id):
            return None
        prefix='reextract:'+run_id+':'
        with self.connect() as db:
            rows=db.execute('''SELECT id,state,attempts,error,result,payload FROM jobs
                WHERE kind='reextract' AND id LIKE ? ORDER BY id''',(prefix+'%',)).fetchall()
        if not rows:
            return None
        items=[]
        counts={}
        scope='saved_messages'
        case_count=0
        digest=''
        policy_version=''
        for row in rows:
            state=str(row['state'])
            counts[state]=counts.get(state,0)+1
            payload=json.loads(row['payload'])
            scope=str(payload.get('reextraction_scope') or scope)
            case_count+=int(payload.get('case_count') or 0)
            digest=str(payload.get('reextraction_digest') or digest)
            policy_version=str(payload.get('policy_version') or policy_version)
            result=json.loads(row['result']) if row['result'] else None
            items.append({'job_id':row['id'],'state':state,'error':row['error'] or '',
                          'result':result})
        total=len(items)
        complete=(counts.get('done',0)+counts.get('failed',0)+counts.get('needs_review',0)
                  +counts.get('cancelled',0))
        return {'run_id':run_id,'total':total,'completed':complete,'counts':counts,
                'items':items,'scope':scope,'case_count':case_count,'digest':digest,
                'policy_version':policy_version,
                'state':'complete' if complete==total else 'running'}

    def cancel_reextraction(self, run_id, actor_id):
        if not isinstance(run_id,str) or not re.fullmatch(r'[a-f0-9]{32}',run_id):
            raise ValueError('invalid re-extraction run id')
        prefix='reextract:'+run_id+':'
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            rows=db.execute('SELECT id,payload FROM jobs WHERE kind=\'reextract\' AND id LIKE ?',(prefix+'%',)).fetchall()
            if not rows or any(json.loads(row['payload']).get('actor_id')!=actor_id for row in rows):
                raise JobConflict('re-extraction run not found for this owner')
            changed=db.execute("UPDATE jobs SET state='cancelled',result=?,error=NULL,lease=NULL,expires=NULL WHERE kind='reextract' AND id LIKE ? AND state='queued'",
                (self.encode({'status':'cancelled'}),prefix+'%')).rowcount
            running=db.execute("SELECT COUNT(*) AS count FROM jobs WHERE kind='reextract' AND id LIKE ? AND state='running'",(prefix+'%',)).fetchone()['count']
        return {'cancelled':int(changed),'running':int(running)}

    def active_reextraction_run(self):
        with self.connect() as db:
            rows=db.execute("SELECT payload,state FROM jobs WHERE kind='reextract' AND state IN ('queued','running') ORDER BY rowid").fetchall()
        if not rows:
            return None
        payload=json.loads(rows[0]['payload'])
        run_id=str(payload.get('run_id') or '')
        status=self.reextraction_status(run_id) if run_id else None
        if not status:
            return None
        return {key:status[key] for key in
                ('run_id','state','total','completed','counts','scope','case_count','digest',
                 'policy_version')}

    def receive(self, message):
        # Source identity ignores re-forward metadata but includes direct channel edit time.
        identity = {k:message.get(k) for k in ('channel','channel_username','id','content',
                    'image_file_id','media_group_id','source_update_at')}
        if message.get('url'):
            identity['id'] = message['url']  # Original forwarded post survives re-forwarding.
        if not identity['id'] or identity['channel_username'] in (None, '', 'DirectMessage'):
            identity['chat_id'] = message.get('chat_id')
        if not identity['id']:
            raise ValueError('Source message identity required')
        key = hashlib.sha256(self.encode(identity).encode()).hexdigest()
        with self.connect() as db:
            db.execute('INSERT OR IGNORE INTO inbox(id,payload,batch_id,received_at) VALUES (?,?,NULL,?)',
                       (key,self.encode(message),time.time()))
        return key

    def batch(self, limit=100):
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            rows = db.execute('SELECT id,payload FROM inbox WHERE batch_id IS NULL ORDER BY rowid LIMIT ?',
                              (limit,)).fetchall()
            if not rows: return None
            batch_id = uuid.uuid4().hex
            job_id = 'batch:' + batch_id
            payload = dict(batch_id=batch_id, messages=[json.loads(row['payload']) for row in rows],
                           expected_count=len(rows), transmission_complete=True, mode='batch_ingest')
            db.execute('INSERT INTO jobs (id,kind,payload,state,available) VALUES (?,?,?,\'queued\',?)',
                       (job_id,'batch',self.encode(payload),time.time()))
            db.executemany('UPDATE inbox SET batch_id=? WHERE id=?', [(job_id,r['id']) for r in rows])
        return job_id

    def compact_completed_payloads(self, *, older_than_seconds=90 * 86400, now=None):
        """Drop duplicated source text from successful old receipts, keeping audit keys.

        Original supplier messages remain in the Sheets offer/message ledger. Failed,
        waiting, and review-needed jobs are deliberately never compacted.
        """
        retention = int(older_than_seconds)
        if retention < 30 * 86400:
            raise ValueError('completed payload retention must be at least 30 days')
        cutoff = (time.time() if now is None else float(now)) - retention
        jobs_compacted = 0
        inbox_compacted = 0

        def summary(source):
            content = str(source.get('content') or '')
            return {
                'compacted': True,
                'id': str(source.get('id') or ''),
                'channel': str(source.get('channel') or ''),
                'channel_username': str(source.get('channel_username') or ''),
                'timestamp': str(source.get('timestamp') or ''),
                'source_timestamp_basis': str(source.get('source_timestamp_basis') or ''),
                'source_created_at': str(source.get('source_created_at') or ''),
                'source_update_at': str(source.get('source_update_at') or ''),
                'url': str(source.get('url') or ''),
                'has_media': bool(source.get('has_media')),
                'media_type': str(source.get('media_type') or ''),
                'content_length': len(content),
                'content_sha256': hashlib.sha256(content.encode()).hexdigest() if content else '',
            }

        with self.connect() as db:
            old_jobs = db.execute("""SELECT id,payload FROM jobs
                WHERE kind='batch' AND state='done' AND updated_at<? ORDER BY updated_at LIMIT 1000""",
                (cutoff,)).fetchall()
            for row in old_jobs:
                payload = json.loads(row['payload']) if row['payload'] else {}
                if not isinstance(payload, dict) or payload.get('compacted'):
                    continue
                messages = payload.get('messages') if isinstance(payload.get('messages'), list) else []
                compact_payload = {
                    'batch_id': str(payload.get('batch_id') or ''),
                    'expected_count': int(payload.get('expected_count') or len(messages)),
                    'transmission_complete': bool(payload.get('transmission_complete')),
                    'mode': str(payload.get('mode') or 'batch_ingest'),
                    'compacted': True,
                    'messages': [summary(item) for item in messages if isinstance(item, dict)],
                }
                db.execute('UPDATE jobs SET payload=? WHERE id=?',
                           (self.encode(compact_payload), str(row['id'])))
                jobs_compacted += 1
                inbox_rows = db.execute('SELECT id,payload FROM inbox WHERE batch_id=?',
                                        (str(row['id']),)).fetchall()
                for inbox_row in inbox_rows:
                    source = json.loads(inbox_row['payload']) if inbox_row['payload'] else {}
                    if isinstance(source, dict) and not source.get('compacted'):
                        db.execute('UPDATE inbox SET payload=? WHERE id=?',
                                   (self.encode(summary(source)), str(inbox_row['id'])))
                        inbox_compacted += 1
        return {'jobs_compacted': jobs_compacted, 'inbox_compacted': inbox_compacted}

    def claim(self, job_id=None):
        now=time.time(); lease=uuid.uuid4().hex
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row=db.execute('''SELECT * FROM jobs WHERE (state='queued' AND available<=?
                OR state='running' AND expires<?) AND (? IS NULL OR id=?) ORDER BY rowid LIMIT 1''',
                (now,now,job_id,job_id)).fetchone()
            if not row:return None
            db.execute("UPDATE jobs SET state='running',lease=?,expires=?,attempts=attempts+1 WHERE id=?",
                       (lease,now+900,row['id']))
        return {**dict(row),'payload':json.loads(row['payload']),'lease':lease,'attempts':row['attempts']+1}

    def finish(self, job, result, *, state='done', error=None):
        with self.connect() as db:
            updated=db.execute('''UPDATE jobs SET state=?,result=?,error=?,available=?,lease=NULL,expires=NULL
                WHERE id=? AND state='running' AND lease=?''',
                (state,self.encode(result),error,time.time()+min(300,2**min(job['attempts'],8)),
                 job['id'],job['lease']))
            if updated.rowcount!=1: raise JobConflict('Worker lease no longer owns this receipt')

    def get(self, job_id):
        with self.connect() as db:
            row=db.execute('SELECT * FROM jobs WHERE id=?',(job_id,)).fetchone()
        return {**dict(row),'result':json.loads(row['result']) if row['result'] else None} if row else None

    def run_one(self, *, finalize, publish, order_write, notify=None, job_id=None,
                publish_with_payload=None, reextract=None, worker_id=None):
        job=self.claim(job_id)
        if not job:return False
        self._worker_job_started(worker_id,job['id'])
        try:
            if job['kind']=='batch':
                if any(hold['hold_id']==job['payload'].get('batch_id')
                       for hold in self.publication_holds()):
                    raise BatchRecoveryRequired('batch needs owner reconciliation before retry')
                result=finalize(job['payload'])
                if result.get('status')!='success': raise RuntimeError('ingestion_failed')
                if result.get('extraction_status')!='success' or result.get('rollback'):
                    self.finish(job,result,state='needs_review'); return True
                # Persist follow-up BEFORE completing extraction; exact replay uses same key.
                self.put('publish',job['id'],{'batch_id':job['payload']['batch_id']})
            elif job['kind']=='publish':
                result=publish_with_payload(job['payload']) if publish_with_payload else publish()
                if result.get('reason')=='publication_held_for_review':
                    self.finish(job,result,state='waiting_publication',error='publication_held_for_review')
                    return True
                if not result.get('ok') or not result.get('pushed') or int(result.get('images_left') or 0)>0:
                    details={'ok':False}
                    for key in ('status','error','row','reason','images_left'):
                        value=result.get(key)
                        if isinstance(value,(str,int,float)) and not isinstance(value,bool):
                            details[key]=value
                    error=re.sub(r'[^A-Za-z0-9_. -]+','',
                        str(result.get('error') or result.get('reason') or 'publication_incomplete'))[:100]
                    self.finish(job,details,state='failed' if job['attempts']>=8 else 'queued',error=error or 'publication_incomplete')
                    return True
            elif job['kind']=='order':
                result=order_write(job['payload'])
                if result.get('written') is not True:raise RuntimeError('order_not_written')
            elif job['kind']=='notice':
                if notify is None:raise RuntimeError('notice_sender_unconfigured')
                result=notify(job['payload'])
                if result.get('skipped')=='recipient_not_allowlisted':
                    self.finish(job,result,state='cancelled',error='recipient_not_allowlisted')
                    return True
                if result.get('delivered') is not True:raise RuntimeError('notice_not_delivered')
            elif job['kind']=='reextract':
                if reextract is None:raise RuntimeError('re-extraction_worker_unconfigured')
                result=reextract(job['payload'])
                if result.get('ok') is not True:raise RuntimeError('re-extraction_failed')
            else:raise RuntimeError('unknown_handoff')
            self.finish(job,result)
        except ReferencePending:
            self.finish(job,{},state='waiting_reference',error='reference_decision_required')
        except ReferenceChanged:
            self.finish(job,{},state='needs_review',error='reference_changed_during_batch')
        except BatchRecoveryRequired:
            self.finish(job,{},state='needs_review',error='batch_recovery_requires_owner_review')
        except Exception as error:
            self.finish(job,{},state='failed' if job['attempts']>=8 else 'queued',error=type(error).__name__)
        finally:
            self._worker_job_finished(worker_id,job['id'])
        return True
