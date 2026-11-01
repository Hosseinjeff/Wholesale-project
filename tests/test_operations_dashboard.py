import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from catalog.durable import Handoffs


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


if __name__=='__main__':
    unittest.main()
