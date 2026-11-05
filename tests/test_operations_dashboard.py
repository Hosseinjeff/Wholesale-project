import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from catalog.durable import Handoffs
from catalog.sync import MemoryStore, MESSAGE_HEADERS


class OperationsSnapshotTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path=str(Path(self.tmp.name)/'pipeline.sqlite')
        self.queue=Handoffs(self.path)

    def test_snapshot_lists_actionable_counts_without_private_payloads(self):
        self.queue.put('batch','review-1',{'source_message':'PRIVATE SOURCE BODY'})
        self.queue.put('publish','pending-1',{'customer':'PRIVATE CUSTOMER'})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='needs_review',attempts=3,error='reference_changed' WHERE id='batch:review-1'")
            db.execute("UPDATE jobs SET state='failed',attempts=8,error='RuntimeError' WHERE id='publish:pending-1'")
        snapshot=self.queue.operations_snapshot(now=1_900_000_000)
        self.assertEqual(snapshot['by_state']['needs_review'],1)
        self.assertEqual(snapshot['by_state']['failed'],1)
        self.assertEqual(snapshot['actionable_count'],2)
        self.assertEqual(snapshot['attention_count'],2)
        self.assertEqual({item['id'] for item in snapshot['items']},{'batch:review-1','publish:pending-1'})
        actions={item['id']:item['next_action'] for item in snapshot['items']}
        self.assertEqual(actions['batch:review-1'],'inspect_result_and_decide')
        self.assertEqual(actions['publish:pending-1'],'inspect_failure_and_reconcile')
        self.assertNotIn('payload',snapshot['items'][0])
        self.assertNotIn('PRIVATE SOURCE BODY',repr(snapshot))
        self.assertNotIn('PRIVATE CUSTOMER',repr(snapshot))

    def test_cursor_pages_keep_a_fixed_creation_cutoff_and_reject_bad_tokens(self):
        for index in range(4):
            self.queue.put('publish',f'page-{index}',{'private':'payload'})
        with self.queue.connect() as db:
            for index in range(4):
                db.execute('UPDATE jobs SET created_at=? WHERE id=?',(100+index//2,f'publish:page-{index}'))
        first=self.queue.operations_snapshot(limit=2,now=1000)
        self.assertEqual([item['id'] for item in first['items']],['publish:page-0','publish:page-1'])
        self.assertTrue(first['has_more'])
        later=self.queue.put('publish','later',{'private':'payload'})
        with self.queue.connect() as db:
            db.execute('UPDATE jobs SET created_at=1001 WHERE id=?',(later,))
        second=self.queue.operations_snapshot(limit=2,cursor=first['next_cursor'],now=2000)
        self.assertEqual([item['id'] for item in second['items']],['publish:page-2','publish:page-3'])
        self.assertFalse(second['has_more'])
        self.assertNotIn(later,{item['id'] for item in second['items']})
        with self.assertRaises(ValueError):
            self.queue.operations_snapshot(cursor='not-a-cursor')

    def test_worker_heartbeat_and_job_progress_are_truthful(self):
        worker_id='pipeline-worker:test'
        started=time.time()
        self.queue.start_worker(worker_id,now=started)
        job_id=self.queue.put('order','heartbeat-test',{'order':{'id':'heartbeat-test'}})
        with self.queue.connect() as db:
            db.execute('UPDATE jobs SET available=0 WHERE id=?',(job_id,))
        worked=self.queue.run_one(finalize=lambda _: {},publish=lambda: {},
            order_write=lambda _: {'written':True},worker_id=worker_id)
        self.assertTrue(worked)
        status=self.queue.worker_status(now=started+2)
        self.assertEqual(status['status'],'alive')
        self.assertEqual(status['state'],'idle')
        self.assertIsNone(status['current_job_id'])
        self.assertEqual(status['last_claimed_job_id'],job_id)
        self.assertEqual(status['last_completed_job_id'],job_id)
        self.assertEqual(status['last_error_code'],'')
        self.assertEqual(self.queue.worker_status(now=started+60)['status'],'stale')
        self.queue.heartbeat_worker(worker_id,now=started+301)
        stalled=self.queue.worker_status(now=started+302)
        self.assertEqual(stalled['status'],'alive')
        self.assertEqual(stalled['progress_status'],'stalled')
        self.queue.set_worker_state(worker_id,'stopped')
        self.assertEqual(self.queue.worker_status()['status'],'stopped')

    def test_pending_telegram_account_stores_identity_only_and_uses_revisioned_decisions(self):
        from catalog.durable import JobConflict
        self.assertTrue(self.queue.record_pending_telegram_chat('123456789',now=1000))
        self.assertTrue(self.queue.record_pending_telegram_chat('123456789',now=1001))
        pending=self.queue.pending_telegram_chats()
        self.assertEqual(len(pending),1)
        self.assertEqual(set(pending[0]),{'chat_id','first_seen_at','last_seen_at','seen_count','revision'})
        self.assertEqual(pending[0]['seen_count'],2)
        self.assertEqual(pending[0]['revision'],2)
        with self.queue.connect() as db:
            columns={row['name'] for row in db.execute('PRAGMA table_info(pending_telegram_chats)')}
            self.assertNotIn('message',columns)
            self.assertNotIn('payload',columns)
        result=self.queue.resolve_pending_telegram_chat('123456789',expected_revision=2,
            approve=True,actor_id='wp:7')
        self.assertEqual(result['state'],'approved')
        self.assertFalse(result['already_done'])
        self.assertEqual(self.queue.approved_telegram_operator_ids(),['123456789'])
        self.assertEqual(self.queue.pending_telegram_chats(),[])
        retry=self.queue.resolve_pending_telegram_chat('123456789',expected_revision=2,
            approve=True,actor_id='wp:7')
        self.assertTrue(retry['already_done'])
        with self.assertRaises(JobConflict):
            self.queue.resolve_pending_telegram_chat('123456789',expected_revision=1,
                approve=False,actor_id='wp:7')

    def test_pending_telegram_account_capture_expires_and_is_bounded(self):
        self.queue.record_pending_telegram_chat('123456789',now=1000)
        self.queue.record_pending_telegram_chat('987654321',now=1000+31*86400)
        self.assertEqual([row['chat_id'] for row in self.queue.pending_telegram_chats()],['987654321'])
        for value in range(600000000,600000510):
            self.queue.record_pending_telegram_chat(str(value),now=1000000+value)
        self.assertEqual(len(self.queue.pending_telegram_chats(limit=100)),100)
        with self.queue.connect() as db:
            count=db.execute("SELECT COUNT(*) FROM pending_telegram_chats WHERE state='pending'").fetchone()[0]
        self.assertEqual(count,500)

    def test_owner_route_is_private_no_store_and_payload_free(self):
        import app
        self.queue.record_pending_telegram_chat('55667788',now=1000)
        self.queue.put('batch','review-1',{'source_message':'PRIVATE SOURCE BODY'})
        self.queue.hold_publication('batch-1','batch_unverified')
        self.queue.record_sheet_conflict({'conflict_id':'conflict-1','product_id':'sku-1',
            'product_name':'Synthetic product','channel':'@supplier','field':'Sale Price',
            'source_revision':'revision-1','base':1,'server':2,'sheet':3})
        env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
             'REFERENCE_BRIDGE_TOKEN':'synthetic-bridge-token-0123456789'}
        headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
        with patch.dict(os.environ,env):
            client=app.app.test_client()
            self.assertEqual(client.get('/pipeline/operations-dashboard').status_code,401)
            self.assertEqual(client.get('/pipeline/operations-dashboard',headers={**headers,'X-Molavi-Actor':'wp:8'}).status_code,403)
            response=client.get('/pipeline/operations-dashboard',headers=headers)
        self.assertEqual(response.status_code,200)
        self.assertEqual(response.headers.get('Cache-Control'),'no-store')
        data=response.get_json()['operations']
        self.assertEqual(data['publication_holds'],1)
        self.assertEqual(data['sheet_conflicts'],1)
        self.assertFalse(data['items_truncated'])
        self.assertEqual(data['worker']['status'],'unknown')
        self.assertEqual(data['failed_publication_ids'],[])
        self.assertEqual(data['failed_batch_ids'],[])
        self.assertEqual([item['chat_id'] for item in data['pending_telegram_chats']],['55667788'])
        self.assertNotIn('PRIVATE SOURCE BODY',response.get_data(as_text=True))

    def test_owner_can_approve_pending_telegram_account_with_retry_safe_receipt(self):
        import app
        self.queue.record_pending_telegram_chat('123456789',now=1000)
        env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
             'REFERENCE_BRIDGE_TOKEN':'synthetic-bridge-token-0123456789'}
        headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
        body={'action':'approve','chat_id':'123456789','expected_revision':1}
        with patch.dict(os.environ,env):
            client=app.app.test_client()
            unauth=client.post('/pipeline/telegram-operator-chats',json=body)
            self.assertEqual(unauth.status_code,401)
            response=client.post('/pipeline/telegram-operator-chats',json=body,headers=headers)
            self.assertEqual(response.status_code,200)
            self.assertFalse(response.get_json()['already_done'])
            retry=client.post('/pipeline/telegram-operator-chats',json=body,headers=headers)
            self.assertEqual(retry.status_code,200)
            self.assertTrue(retry.get_json()['already_done'])
            operations=client.get('/pipeline/operations-dashboard',headers=headers).get_json()['operations']
        self.assertEqual(self.queue.approved_telegram_operator_ids(),['123456789'])
        self.assertEqual(operations['pending_telegram_chats'],[])
        with patch.dict(os.environ,{},clear=True):
            self.assertEqual(app._notice_chat_allowlists(self.queue)['telegram'],{'123456789'})
            from catalog.orders import operator_chat_ids
            self.assertEqual(operator_chat_ids(None,self.queue.approved_telegram_operator_ids()),['123456789'])
        queued=[]
        with patch.object(app,'ensure_telegram_webhook'),patch.object(app,'USE_SHEETS',False), \
                patch.object(app,'enqueue_message',side_effect=lambda data:queued.append(data) or True), \
                patch.dict(os.environ,{**env,'TELEGRAM_OPERATOR_CHAT_IDS':'288165176',
                    'TELEGRAM_WEBHOOK_SECRET':'configured-webhook-secret-0123456789'}):
            accepted=app.app.test_client().post('/webhook',json={'message':{'message_id':8,
                'text':'hello','chat':{'id':123456789,'type':'private'},
                'from':{'id':123456789}}},headers={
                    'X-Telegram-Bot-Api-Secret-Token':'configured-webhook-secret-0123456789'})
        self.assertEqual(accepted.get_json()['status'],'success')
        self.assertEqual(queued[0]['chat_id'],123456789)

    def test_owner_dashboard_does_not_hide_actionable_rows_after_first_hundred(self):
        import app
        for index in range(125):
            self.queue.put('publish',f'pending-{index}',{'private':'payload'})
        env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
             'REFERENCE_BRIDGE_TOKEN':'synthetic-bridge-token-0123456789'}
        headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
        with patch.dict(os.environ,env):
            client=app.app.test_client()
            response=client.get('/pipeline/operations-dashboard',headers=headers)
            self.assertEqual(response.status_code,200)
            first=response.get_json()['operations']
            self.assertEqual(len(first['items']),100)
            self.assertTrue(first['items_truncated'])
            second_response=client.get('/pipeline/operations-dashboard',headers=headers,
                query_string={'limit':'100','cursor':first['next_cursor']})
            second=second_response.get_json()['operations']
            self.assertEqual(len(second['items']),25)
            self.assertFalse(second['items_truncated'])
            self.assertEqual(len({item['id'] for item in first['items']+second['items']}),125)
            invalid=client.get('/pipeline/operations-dashboard',headers=headers,query_string={'cursor':'broken'})
            self.assertEqual(invalid.status_code,400)

    def test_waiting_publication_jobs_are_actionable_owner_work(self):
        job_id=self.queue.put('publish','blocked',{'sheet_digest':'digest'})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='waiting_publication',error='publication_held_for_review' WHERE id=?",
                       (job_id,))
        snapshot=self.queue.operations_snapshot()
        self.assertEqual(snapshot['by_state']['waiting_publication'],1)
        self.assertEqual(snapshot['attention_count'],1)
        self.assertEqual(snapshot['items'][0]['error'],'publication_held_for_review')
        self.assertEqual(snapshot['items'][0]['next_action'],'resolve_publication_hold')

    def test_owner_can_reconcile_failed_jobs_only_after_complete_current_sync(self):
        import app
        pub=self.queue.put('publish','old-sync',{'secret_payload':'not returned'})
        batch=self.queue.put('batch','old-batch',{'batch_id':'old-batch','messages':[{'id':'source-1'}],
            'expected_count':1})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='failed',attempts=8,error='RuntimeError' WHERE id=?",(pub,))
            db.execute("UPDATE jobs SET state='failed',attempts=8,error='JobConflict' WHERE id=?",(batch,))
        env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
             'REFERENCE_BRIDGE_TOKEN':'synthetic-bridge-token-0123456789'}
        headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
        with patch.object(app,'USE_SHEETS',True), patch.object(app,'build_store',return_value=MemoryStore()), \
                patch.object(app,'publish_shop',return_value={'ok':True,'pushed':True,'images_left':0,
                    'count':2,'created':0,'updated':2,'hidden':0}), \
                patch('catalog.sheet_projection.sheet_projection_digest',return_value='a'*64), \
                patch.object(app,'_batch_sources_reconciled',return_value=True), patch.dict(os.environ,env):
            client=app.app.test_client()
            response=client.post('/pipeline/reconcile-publication-backlog',headers=headers,json={
                'expected_publish_ids':[pub],'expected_batch_ids':[batch]})
        self.assertEqual(response.status_code,200,response.get_data(as_text=True))
        receipt=response.get_json()
        self.assertEqual(receipt['failed_publications_reconciled'],1)
        self.assertEqual(receipt['batch_jobs_reconciled'],1)
        self.assertEqual(self.queue.get(pub)['state'],'done')
        self.assertTrue(self.queue.get(pub)['result']['superseded_by_catalog_sync'])
        self.assertEqual(self.queue.get(pub)['result']['actor_id'],'wp:7')
        self.assertEqual(self.queue.get(batch)['state'],'done')
        self.assertTrue(self.queue.get(batch)['result']['source_rows_verified'])

    def test_failed_catalog_sync_leaves_all_backlog_jobs_open(self):
        import app
        pub=self.queue.put('publish','old-sync',{'private':'payload'})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='failed',attempts=8,error='RuntimeError' WHERE id=?",(pub,))
        env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
             'REFERENCE_BRIDGE_TOKEN':'synthetic-bridge-token-0123456789'}
        headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
        with patch.object(app,'USE_SHEETS',True), patch.object(app,'build_store',return_value=MemoryStore()), \
                patch.object(app,'publish_shop',return_value={'ok':False,'pushed':False,'reason':'synthetic'}), \
                patch('catalog.sheet_projection.sheet_projection_digest',return_value='a'*64), patch.dict(os.environ,env):
            response=app.app.test_client().post('/pipeline/reconcile-publication-backlog',headers=headers,json={
                'expected_publish_ids':[pub],'expected_batch_ids':[]})
        self.assertEqual(response.status_code,502)
        self.assertEqual(response.get_json()['error'],'catalog_sync_incomplete')
        self.assertEqual(self.queue.get(pub)['state'],'failed')

    def test_backlog_reconciliation_is_owner_scoped_and_cors_enabled(self):
        import app
        env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
             'REFERENCE_BRIDGE_TOKEN':'synthetic-bridge-token-0123456789'}
        headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
        with patch.dict(os.environ,env):
            client=app.app.test_client()
            self.assertEqual(client.post('/pipeline/reconcile-publication-backlog',json={}).status_code,401)
            options=client.options('/pipeline/reconcile-publication-backlog',headers={'Origin':'https://www.molavisale.ir'})
        self.assertEqual(options.status_code,204)
        self.assertEqual(options.headers.get('Access-Control-Allow-Origin'),'https://www.molavisale.ir')

    def test_failed_batch_closes_only_when_source_message_is_fully_recorded(self):
        import app
        source={'id':'source-1','channel_username':'@supplier','content':'private message'}
        job=self.queue.put('batch','verified-batch',{'batch_id':'verified-batch','messages':[source],
            'expected_count':1})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='failed',error='JobConflict' WHERE id=?",(job,))
        store=MemoryStore();store.ensure('MessageData',MESSAGE_HEADERS)
        recorded={'ID':'source-1','Channel Username':'@supplier','Batch ID':'verified-batch',
            'AI Status':'ai_processed','AI Processed At':'2026-11-02T20:00:00Z'}
        store.append('MessageData',[[recorded.get(header,'') for header in MESSAGE_HEADERS]])
        self.assertTrue(app._batch_sources_reconciled(store,self.queue,job))
        store.sheets['MessageData']=[list(MESSAGE_HEADERS)]
        self.assertFalse(app._batch_sources_reconciled(store,self.queue,job))

    def test_empty_extraction_and_owner_rejection_are_terminal_batch_states(self):
        import app
        states=('no_product_candidate','owner_rejected','not_product')
        sources=[{'id':f'source-{index}','channel_username':'@supplier','content':'private source'}
                 for index,_ in enumerate(states)]
        job=self.queue.put('batch','empty-result-batch',{
            'batch_id':'empty-result-batch','messages':sources,'expected_count':len(sources)})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='failed',error='JobConflict' WHERE id=?",(job,))
        store=MemoryStore();store.ensure('MessageData',MESSAGE_HEADERS)
        rows=[]
        for index,state in enumerate(states):
            rows.append({
                'ID':f'source-{index}','Channel Username':'@supplier',
                'Batch ID':'empty-result-batch','AI Status':state,
                'AI Processed At':'2026-11-05T10:00:00Z',
            })
        store.append('MessageData',[[row.get(header,'') for header in MESSAGE_HEADERS] for row in rows])
        self.assertTrue(app._batch_sources_reconciled(store,self.queue,job))


if __name__=='__main__':
    unittest.main()
