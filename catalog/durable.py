"""Persistent transport receipts/jobs. Payloads may contain private data: keep DB private."""
import fcntl
import hashlib
import json
import os
import sqlite3
import time
import uuid
from contextlib import contextmanager


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
                result TEXT, error TEXT);
                CREATE TABLE IF NOT EXISTS inbox (
                id TEXT PRIMARY KEY, payload TEXT NOT NULL, batch_id TEXT);
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
                published_digest TEXT NOT NULL DEFAULT '', job_id TEXT);''')
        os.chmod(self.path, 0o600)

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path, timeout=15)
        db.row_factory = sqlite3.Row
        try:
            with db: yield db
        finally: db.close()

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

    def operations_snapshot(self, *, limit=100, now=None):
        """Return owner-safe queue metadata without exposing payloads or results."""
        limit=max(1,min(int(limit),200))
        now=time.time() if now is None else float(now)
        actionable=('queued','running','waiting_reference','failed','needs_review')
        placeholders=','.join('?' for _ in actionable)
        with self.connect() as db:
            grouped=db.execute('SELECT state,COUNT(*) AS count FROM jobs GROUP BY state').fetchall()
            rows=db.execute(f'''SELECT id,kind,state,attempts,available,expires,error FROM jobs
                WHERE state IN ({placeholders}) ORDER BY available,id LIMIT ?''',(*actionable,limit)).fetchall()
        by_state={str(row['state']):int(row['count']) for row in grouped}
        items=[]
        for row in rows:
            item={key:row[key] for key in ('id','kind','state','attempts','available','error')}
            item['lease_expired']=bool(row['state']=='running' and row['expires'] is not None and row['expires']<=now)
            items.append(item)
        return {
            'by_state':by_state,
            'actionable_count':sum(by_state.get(state,0) for state in actionable),
            'attention_count':sum(by_state.get(state,0) for state in ('waiting_reference','failed','needs_review')),
            'items':items,
        }

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
        if choice not in ('server','sheet') or not isinstance(actor_id,str) or not actor_id.strip():
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

    def receive(self, message):
        # Stable source/chat/message/revision identity; re-forward metadata does not create new work.
        identity = {k:message.get(k) for k in ('channel','channel_username','id','content',
                    'image_file_id','media_group_id')}
        if message.get('url'):
            identity['id'] = message['url']  # Original forwarded post survives re-forwarding.
        if not identity['id'] or identity['channel_username'] in (None, '', 'DirectMessage'):
            identity['chat_id'] = message.get('chat_id')
        if not identity['id']:
            raise ValueError('Source message identity required')
        key = hashlib.sha256(self.encode(identity).encode()).hexdigest()
        with self.connect() as db:
            db.execute('INSERT OR IGNORE INTO inbox VALUES (?,?,NULL)', (key,self.encode(message)))
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

    def claim(self, job_id=None):
        now=time.time(); lease=uuid.uuid4().hex
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row=db.execute('''SELECT * FROM jobs WHERE (state IN ('queued','waiting_reference') AND available<=?
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
                publish_with_payload=None):
        job=self.claim(job_id)
        if not job:return False
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
                if not result.get('ok') or not result.get('pushed') or int(result.get('images_left') or 0)>0:
                    raise RuntimeError('publication_incomplete')
            elif job['kind']=='order':
                result=order_write(job['payload'])
                if result.get('written') is not True:raise RuntimeError('order_not_written')
            elif job['kind']=='notice':
                if notify is None:raise RuntimeError('notice_sender_unconfigured')
                result=notify(job['payload'])
                if result.get('delivered') is not True:raise RuntimeError('notice_not_delivered')
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
        return True
