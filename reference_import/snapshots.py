"""Immutable reference versions and atomic, field-aware source refreshes."""
import fcntl
import os
from contextlib import contextmanager
import hashlib
import json
import math
import re
from datetime import datetime, timezone
from .decisions import DecisionConflict, ReferenceDecisions
from .importer import HEADERS, SOURCE_NAME, SOURCE_TYPE, SOURCE_URL, ImportFailure


def has_verified_coverage(candidate):
    if (not isinstance(candidate, dict) or candidate.get('coverage_verified') is not True
            or candidate.get('source_type') != SOURCE_TYPE
            or candidate.get('source_url') != SOURCE_URL):
        return False
    total = candidate.get('source_page_count')
    processed = candidate.get('processed_page_count')
    source_total = candidate.get('source_total')
    rows = candidate.get('rows')
    if (not isinstance(total, int) or isinstance(total, bool) or total < 1
            or not isinstance(processed, int) or isinstance(processed, bool) or processed != total
            or not isinstance(source_total, int) or isinstance(source_total, bool) or source_total < 1
            or not isinstance(rows, list)
            or not isinstance(candidate.get('row_count'), int)
            or isinstance(candidate.get('row_count'), bool)
            or candidate.get('row_count') != len(rows) or source_total != len(rows) or not rows
            or candidate.get('source_currency') not in {'IRR', 'IRT'}):
        return False
    source_ids=candidate.get('source_product_ids')
    if source_ids is not None:
        if (not isinstance(source_ids,list) or len(source_ids)!=len(rows)
                or any(not isinstance(value,str) or not re.fullmatch(r'[1-9][0-9]*',value)
                       for value in source_ids)
                or len(set(source_ids))!=len(source_ids)):
            return False
    try:
        row_values = [row[15] for row in rows]
        if any(not isinstance(value, str) or not re.fullmatch(r'[0-9]{1,6}', value)
               for value in row_values):
            return False
        numbers = [int(value) for value in row_values]
    except (IndexError, TypeError, ValueError):
        return False
    return (numbers == list(range(1, len(numbers) + 1))
            and candidate.get('first_source_row_number') == 1
            and candidate.get('last_source_row_number') == len(numbers))


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
                previous_snapshot_id TEXT, actor_id TEXT NOT NULL, activated_at TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_source_products (
                source_product_id TEXT PRIMARY KEY, product_code TEXT NOT NULL, payload TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_snapshot_source_products (
                snapshot_id TEXT NOT NULL, source_product_id TEXT NOT NULL,
                product_code TEXT NOT NULL, payload TEXT NOT NULL,
                PRIMARY KEY(snapshot_id,source_product_id));
                CREATE TABLE IF NOT EXISTS reference_projection_state (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), snapshot_id TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_reconciliation_state (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), snapshot_id TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_source_check (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), snapshot_id TEXT NOT NULL,
                checked_at TEXT NOT NULL);''')
        os.chmod(self.database,0o600)

    def stage(self, candidate):
        return self._stage_candidate(candidate)['snapshot_id']

    def stage_incremental(self, candidate):
        """Stage only changed source nodes; return a no-op receipt when unchanged."""
        return self._stage_candidate(candidate)

    @staticmethod
    def _source_code(product):
        product_id=product.get('id')
        if isinstance(product_id,bool) or not isinstance(product_id,int) or product_id<1:
            raise ImportFailure('WooCommerce product ID is invalid',code='woocommerce_product_id_invalid')
        sku=product.get('sku')
        if sku is None or isinstance(sku,(dict,list,bool)):
            raise ImportFailure('WooCommerce SKU is invalid',code='woocommerce_sku_invalid')
        return str(sku).strip() or f'woo-{product_id}'

    @staticmethod
    def _node_values(raw):
        return {key: raw.get(key) for key in ('name','prices','sku','categories','attributes')}

    def _incremental_candidate(self, candidate):
        products=candidate.get('_source_products')
        if not isinstance(products,list):
            return candidate, None
        from .importer import _woocommerce_row
        active=self.active_snapshot()
        old_rows=(active or {}).get('rows') or []
        active_ids=(active or {}).get('source_product_ids')
        if (not isinstance(active_ids,list) or len(active_ids)!=len(old_rows)
                or len(set(str(value) for value in active_ids))!=len(active_ids)):
            active_ids=None
        old_by_id={}
        if active_ids:
            old_by_id={str(source_id):row for source_id,row in zip(active_ids,old_rows)}
        else:
            old_by_code={str(row[16] or ''):row for row in old_rows
                         if isinstance(row,list) and len(row)==len(HEADERS)}
            for product in products:
                code=self._source_code(product)
                if code in old_by_code:
                    old_by_id[str(product.get('id'))]=old_by_code[code]
        with self._connect() as db:
            cached_rows=db.execute('SELECT source_product_id,product_code,payload FROM reference_source_products').fetchall()
        old_raw={str(row['source_product_id']):json.loads(row['payload']) for row in cached_rows}
        old_codes={str(row['source_product_id']):str(row['product_code']) for row in cached_rows}

        timestamp=candidate['extracted_at']
        source_ids=[]
        rows=[]
        changed=[]
        added=[]
        seen=set()
        cached_candidate_rows=candidate.get('rows') or []
        groups={
            'name':(0,1,2,4,5,6,7,8,9,10),
            'prices':(3,4),
            'sku':(16,),
            'categories':(13,),
            'attributes':(11,12),
        }
        for index,product in enumerate(products,1):
            source_id=str(product.get('id'))
            if source_id in seen:
                raise ImportFailure('Duplicate WooCommerce product ID',code='woocommerce_duplicate_product_id')
            seen.add(source_id)
            source_ids.append(source_id)
            code=self._source_code(product)
            prior=old_by_id.get(source_id)
            prior_raw=old_raw.get(source_id)
            if (prior is not None
                    and (not isinstance(prior,list) or len(prior)!=len(HEADERS))):
                prior=None
            if prior is None:
                fresh=(cached_candidate_rows[index-1] if len(cached_candidate_rows)==len(products)
                       else _woocommerce_row(product,index,timestamp))
                rows.append(fresh)
                added.append({'source_product_id':source_id,'product_code':code})
                continue

            raw_changed=[]
            if prior_raw is not None:
                before=self._node_values(prior_raw)
                after=self._node_values(product)
                raw_changed=[key for key in ('name','prices','sku','categories','attributes')
                             if before.get(key)!=after.get(key)]
            fresh=None
            if prior_raw is None:
                fresh=(cached_candidate_rows[index-1] if len(cached_candidate_rows)==len(products)
                       else _woocommerce_row(product,index,timestamp))
                raw_changed=[key for key,indices in groups.items()
                             if any(prior[column]!=fresh[column] for column in indices)]
            if raw_changed:
                if fresh is None:
                    fresh=_woocommerce_row(product,index,timestamp)
                merged=list(prior)
                for node in raw_changed:
                    for column in groups[node]:
                        merged[column]=fresh[column]
                merged[20]=timestamp
                old_code=str(prior[16] or old_codes.get(source_id) or '')
                changed.append({'source_product_id':source_id,'old_product_code':old_code,
                                'product_code':code,'nodes':raw_changed,
                                'price_changed':(prior[3]!=merged[3] or prior[4]!=merged[4])})
                rows.append(merged)
            else:
                rows.append(list(prior))
            # This ordinal is coverage metadata, not a product field.
            rows[-1][15]=str(index)

        removed=[]
        if active_ids:
            removed=[{'source_product_id':str(source_id),
                      'product_code':str((old_by_id.get(str(source_id)) or ['']*17)[16] or old_codes.get(str(source_id)) or '')}
                     for source_id in active_ids if str(source_id) not in seen]
        else:
            current_codes={self._source_code(product) for product in products}
            removed=[{'source_product_id':source_id,'product_code':code}
                     for source_id,code in old_codes.items() if code not in current_codes]
            if old_rows and not old_codes:
                previous_codes={str(row[16] or '') for row in old_rows
                                if isinstance(row,list) and len(row)==len(HEADERS)}
                current_codes={self._source_code(product) for product in products}
                removed.extend({'source_product_id':'','product_code':code}
                               for code in previous_codes-current_codes)

        changed_reference_ids=set()
        for item in changed:
            if item['price_changed'] or 'sku' in item.get('nodes',[]):
                changed_reference_ids.update(value for value in
                    (item.get('old_product_code'),item.get('product_code')) if value)
        changed_reference_ids.update(item['product_code'] for item in removed if item.get('product_code'))
        metadata_upgrade=bool(active and active.get('source_type')==SOURCE_TYPE and active_ids is None)
        order_changed=bool(active_ids is not None and [str(value) for value in active_ids]!=source_ids)
        summary={
            'added_count':len(added),'removed_count':len(removed),'updated_count':len(changed),
            'added':added,'removed':removed,'changed':changed,
            'reconcile_reference_ids':sorted(changed_reference_ids),
            'metadata_upgrade':metadata_upgrade,
            'order_changed':order_changed,
            'has_changes':bool(added or removed or changed or metadata_upgrade or order_changed),
        }
        result=dict(candidate)
        result.pop('_source_products',None)
        result['rows']=rows
        result['row_count']=len(rows)
        result['source_total']=len(rows)
        result['last_source_row_number']=len(rows)
        result['coverage_verified']=True
        result['source_product_ids']=source_ids
        result['change_summary']=summary
        result['previous_snapshot_id']=(active or {}).get('snapshot_id')
        result['automatic_update']=bool(active and active.get('source_type')==SOURCE_TYPE and summary['has_changes'])
        result['requires_review']=not result['automatic_update']
        return result, products

    def _stage_candidate(self, candidate):
        candidate,source_products=self._incremental_candidate(candidate)
        if (candidate.get('status') != 'staged' or candidate.get('source_type') != SOURCE_TYPE
                or candidate.get('source_url') != SOURCE_URL
                or list(candidate.get('headers', [])) != list(HEADERS)):
            raise ImportFailure('Invalid candidate contract')
        if not has_verified_coverage(candidate):
            raise ImportFailure('WooCommerce product coverage was not verified',
                                code='extraction_coverage_incomplete')
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
                    or row[17:20] != ['TOMAN', SOURCE_NAME, SOURCE_URL]
                    or not isinstance(row[20],str)):
                raise ImportFailure('Invalid row identity/provenance')
            try:
                row_timestamp=datetime.fromisoformat(row[20])
            except (TypeError,ValueError):
                raise ImportFailure('Invalid row update timestamp') from None
            if row_timestamp.tzinfo is None or row_timestamp>timestamp:
                raise ImportFailure('Invalid row update timestamp')
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
            db.execute('BEGIN IMMEDIATE')
            active=db.execute('SELECT snapshot_id FROM reference_active WHERE singleton=1').fetchone()
            active_id=active['snapshot_id'] if active else None
            if source_products is not None and active_id!=candidate.get('previous_snapshot_id'):
                raise ImportFailure('Active reference changed during source comparison',
                                    code='reference_active_changed')
            summary=candidate.get('change_summary') or {}
            if source_products is not None and active_id and not summary.get('has_changes'):
                self._replace_source_products(db,source_products)
                self._record_source_check(db,active_id,candidate['extracted_at'])
                return {'state':'unchanged','snapshot_id':active_id,'change_summary':summary,
                        'row_count':len(rows)}
            db.execute('INSERT OR IGNORE INTO reference_snapshots VALUES (?, ?, NULL, NULL, NULL)',
                       (snapshot_id, payload))
            if source_products is not None:
                db.execute('DELETE FROM reference_snapshot_source_products WHERE snapshot_id=?',(snapshot_id,))
                for product in source_products:
                    source_id=str(product['id'])
                    product_code=self._source_code(product)
                    db.execute('INSERT INTO reference_snapshot_source_products VALUES (?,?,?,?)',
                        (snapshot_id,source_id,product_code,
                         json.dumps(product,ensure_ascii=False,sort_keys=True,allow_nan=False)))
        return {'state':'staged','snapshot_id':snapshot_id,
                'change_summary':candidate.get('change_summary') or {},'row_count':len(rows),
                'automatic_update':bool(candidate.get('automatic_update'))}

    def _replace_source_products(self, db, products):
        db.execute('DELETE FROM reference_source_products')
        for product in products:
            db.execute('INSERT INTO reference_source_products VALUES (?,?,?)',
                (str(product['id']),self._source_code(product),
                 json.dumps(product,ensure_ascii=False,sort_keys=True,allow_nan=False)))

    @staticmethod
    def _record_source_check(db,snapshot_id,checked_at):
        db.execute('INSERT OR REPLACE INTO reference_source_check VALUES (1,?,?)',
                   (snapshot_id,checked_at))
        # A complete source read resolves prior stale/refresh-failure warnings for
        # this same active version without creating a new product-data version.
        db.execute('''INSERT OR IGNORE INTO reference_retirements
            SELECT event_id, ?, ? FROM reference_events
            WHERE snapshot_id=? AND reason IN ('stale','refresh_failed')''',
            (snapshot_id,checked_at,snapshot_id))

    def review(self, snapshot_id, *, actor_id, note):
        if actor_id not in self.owner_ids:
            raise PermissionError('Owner review required')
        if not isinstance(note, str) or not note.strip():
            raise ValueError('Output coverage/price review note required')
        with self._connect() as db:
            existing = db.execute('SELECT payload,reviewed_by,review_note FROM reference_snapshots WHERE snapshot_id=?',
                                  (snapshot_id,)).fetchone()
            if not existing or not has_verified_coverage(json.loads(existing['payload'])):
                raise DecisionConflict('Snapshot extraction coverage is not verified')
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
            source_check=db.execute('SELECT snapshot_id,checked_at FROM reference_source_check WHERE singleton=1').fetchone()
            rows=db.execute('SELECT * FROM reference_snapshots ORDER BY rowid DESC LIMIT ?', (limit,)).fetchall()
        result=[]
        for row in rows:
            candidate=json.loads(row['payload'])
            change_summary=candidate.get('change_summary') or {}
            compact_summary={key:change_summary.get(key,0) for key in
                ('added_count','updated_count','removed_count','has_changes','metadata_upgrade','order_changed')}
            preview=[self._preview_row(values,
                       (candidate.get('source_product_ids') or [])[index]
                       if index<len(candidate.get('source_product_ids') or []) else '')
                     for index,values in enumerate(candidate.get('rows',[])[:8])
                     if isinstance(values,list) and len(values)==len(HEADERS)]
            result.append({'snapshot_id':row['snapshot_id'],'extracted_at':candidate.get('extracted_at'),
                'row_count':candidate.get('row_count'),'pdf_sha256':candidate.get('pdf_sha256'),
                'source_type':candidate.get('source_type'),
                'automatic_update':bool(candidate.get('automatic_update')),
                'previous_snapshot_id':candidate.get('previous_snapshot_id'),
                'change_summary':compact_summary,
                'source_total':candidate.get('source_total'),
                'source_currency':candidate.get('source_currency'),
                'model':candidate.get('model'),'source_url':candidate.get('source_url'),
                'source_page_count':candidate.get('source_page_count'),
                'processed_page_count':candidate.get('processed_page_count'),
                'first_source_row_number':candidate.get('first_source_row_number'),
                'last_source_row_number':candidate.get('last_source_row_number'),
                'coverage_verified':has_verified_coverage(candidate),
                'preview':preview,'preview_total':candidate.get('row_count',0),
                'reviewed_by':row['reviewed_by'],'review_note':row['review_note'],
                'reviewed_at':row['reviewed_at'],'active':row['snapshot_id']==active_id,
                'last_checked_at':(source_check['checked_at'] if source_check
                    and source_check['snapshot_id']==row['snapshot_id'] else None)})
        return {'active_snapshot_id':active_id,'snapshots':result}

    @staticmethod
    def _preview_row(values,source_product_id=''):
        return {'source_product_id':str(source_product_id or ''),
            'product_name':values[0],'clean_name':values[1],'normalized_key':values[2],
            'package_price':values[3],'unit_price':values[4],
            'consumer_unit_price':values[5],'package_count':values[6],
            'unit_label':values[7],'volume':values[8],'weight':values[9],
            'set_count':values[10],'variant':values[11],'brand':values[12],
            'category':values[13],'raw_name_details':values[14],
            'source_row_number':values[15],'product_code':values[16]}

    def snapshot_rows(self, snapshot_id):
        candidate=self.snapshot(snapshot_id)
        if candidate is None:
            return None
        rows=candidate.get('rows',[])
        source_ids=candidate.get('source_product_ids') or []
        return [self._preview_row(row,source_ids[index] if index<len(source_ids) else '')
                for index,row in enumerate(rows)
                if isinstance(row,list) and len(row)==len(HEADERS)]

    def snapshot(self, snapshot_id):
        with self._connect() as db:
            row=db.execute('SELECT snapshot_id,payload FROM reference_snapshots WHERE snapshot_id=?',(snapshot_id,)).fetchone()
        return {**json.loads(row['payload']),'snapshot_id':row['snapshot_id']} if row else None

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

    def activate_incremental(self, snapshot_id, *, expected_active_id):
        """Activate a complete, validated delta produced from the reference source."""
        with self.activation_lock():
            return self._activate_locked(snapshot_id,actor_id='system:reference-refresh',
                                         expected_active_id=expected_active_id,automatic=True)

    def _activate_locked(self, snapshot_id, *, actor_id, expected_active_id, automatic=False):
        if not automatic and actor_id not in self.owner_ids:
            raise PermissionError('Owner activation required')
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            active = db.execute('SELECT snapshot_id FROM reference_active WHERE singleton=1').fetchone()
            previous = active['snapshot_id'] if active else None
            if previous != expected_active_id:
                raise DecisionConflict('Active version changed; reload before activation')
            row = db.execute('SELECT * FROM reference_snapshots WHERE snapshot_id=?', (snapshot_id,)).fetchone()
            candidate=json.loads(row['payload']) if row else None
            if not row or (not automatic and not row['reviewed_by']):
                raise DecisionConflict('Snapshot must be reviewed before activation')
            if automatic and (candidate.get('automatic_update') is not True
                              or candidate.get('source_type')!=SOURCE_TYPE
                              or not (candidate.get('change_summary') or {}).get('has_changes')):
                raise DecisionConflict('Snapshot is not an eligible incremental update')
            if not has_verified_coverage(candidate):
                raise DecisionConflict('Snapshot extraction coverage is not verified')
            if previous == snapshot_id:
                self._commit_source_products(db,snapshot_id)
                if candidate.get('source_product_ids'):
                    self._record_source_check(db,snapshot_id,candidate['extracted_at'])
                return
            if candidate.get('source_product_ids'):
                pending_count=db.execute('SELECT COUNT(*) FROM reference_snapshot_source_products '
                    'WHERE snapshot_id=?',(snapshot_id,)).fetchone()[0]
                if pending_count!=len(candidate['source_product_ids']):
                    raise DecisionConflict('Raw source records are incomplete')
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
            self._commit_source_products(db,snapshot_id)
            if candidate.get('source_product_ids'):
                self._record_source_check(db,snapshot_id,candidate['extracted_at'])

    @staticmethod
    def _commit_source_products(db,snapshot_id):
        rows=db.execute('SELECT source_product_id,product_code,payload '
                        'FROM reference_snapshot_source_products WHERE snapshot_id=?',(snapshot_id,)).fetchall()
        if not rows:
            return
        db.execute('DELETE FROM reference_source_products')
        db.executemany('INSERT INTO reference_source_products VALUES (?,?,?)',
            [(row['source_product_id'],row['product_code'],row['payload']) for row in rows])
        db.execute('DELETE FROM reference_snapshot_source_products WHERE snapshot_id=?',(snapshot_id,))

    def projection_snapshot_id(self):
        with self._connect() as db:
            row=db.execute('SELECT snapshot_id FROM reference_projection_state WHERE singleton=1').fetchone()
        return row['snapshot_id'] if row else None

    def mark_projected(self,snapshot_id):
        with self._connect() as db:
            db.execute('INSERT OR REPLACE INTO reference_projection_state VALUES (1,?)',(snapshot_id,))

    def reconciliation_snapshot_id(self):
        with self._connect() as db:
            row=db.execute('SELECT snapshot_id FROM reference_reconciliation_state WHERE singleton=1').fetchone()
        return row['snapshot_id'] if row else None

    def mark_reconciled(self,snapshot_id):
        with self._connect() as db:
            db.execute('INSERT OR REPLACE INTO reference_reconciliation_state VALUES (1,?)',(snapshot_id,))

    def active_snapshot(self):
        with self._connect() as db:
            row = db.execute('''SELECT s.snapshot_id, s.payload FROM reference_active a
                JOIN reference_snapshots s ON s.snapshot_id=a.snapshot_id WHERE a.singleton=1''').fetchone()
            check=db.execute('SELECT snapshot_id,checked_at FROM reference_source_check WHERE singleton=1').fetchone()
        if not row:
            return None
        result={**json.loads(row['payload']), 'snapshot_id': row['snapshot_id']}
        if check and check['snapshot_id']==row['snapshot_id']:
            result['last_checked_at']=check['checked_at']
        return result

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
