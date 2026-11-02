import os
import tempfile
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
        self.assertNotIn('payload',snapshot['items'][0])
        self.assertNotIn('PRIVATE SOURCE BODY',repr(snapshot))
        self.assertNotIn('PRIVATE CUSTOMER',repr(snapshot))

    def test_owner_route_is_private_no_store_and_payload_free(self):
        import app
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
        self.assertEqual(data['failed_publication_ids'],[])
        self.assertEqual(data['failed_batch_ids'],[])
        self.assertNotIn('PRIVATE SOURCE BODY',response.get_data(as_text=True))

    def test_owner_dashboard_does_not_hide_actionable_rows_after_first_hundred(self):
        import app
        for index in range(125):
            self.queue.put('publish',f'pending-{index}',{'private':'payload'})
        env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
             'REFERENCE_BRIDGE_TOKEN':'synthetic-bridge-token-0123456789'}
        headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
        with patch.dict(os.environ,env):
            response=app.app.test_client().get('/pipeline/operations-dashboard',headers=headers)
        self.assertEqual(response.status_code,200)
        self.assertEqual(len(response.get_json()['operations']['items']),125)

    def test_waiting_publication_jobs_are_actionable_owner_work(self):
        job_id=self.queue.put('publish','blocked',{'sheet_digest':'digest'})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='waiting_publication',error='publication_held_for_review' WHERE id=?",
                       (job_id,))
        snapshot=self.queue.operations_snapshot()
        self.assertEqual(snapshot['by_state']['waiting_publication'],1)
        self.assertEqual(snapshot['attention_count'],1)
        self.assertEqual(snapshot['items'][0]['error'],'publication_held_for_review')

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


if __name__=='__main__':
    unittest.main()
