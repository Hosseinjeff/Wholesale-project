"""Worker polling and durable scheduling for Sheet-only Woo projection changes."""
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from catalog.durable import Handoffs
from catalog.sheet_projection import observe_sheet_projection, sheet_projection_digest
from catalog.sync import MemoryStore


class SheetProjectionTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.queue=Handoffs(Path(self.temp.name)/'pipeline.sqlite')
        self.store=MemoryStore()
        self.store.sheets['Products']=[['Product ID','Product Name'],['p-1','Tuna']]
        self.store.sheets['Shop']=[['Min Margin'],[0.12]]

    def test_sheet_digest_tracks_product_and_shop_edits(self):
        first=sheet_projection_digest(self.store)
        self.store.sheets['Products'][1][1]='Tuna edited'
        self.assertNotEqual(first,sheet_projection_digest(self.store))
        second=sheet_projection_digest(self.store)
        self.store.sheets['Shop'][1][0]=0.15
        self.assertNotEqual(second,sheet_projection_digest(self.store))

    def test_poll_coalesces_pending_edits_and_records_successful_projection(self):
        first=observe_sheet_projection(self.queue,self.store)
        self.assertEqual(observe_sheet_projection(self.queue,self.store),first)
        self.store.sheets['Products'][1][1]='Updated tuna'
        current=sheet_projection_digest(self.store)
        self.assertEqual(observe_sheet_projection(self.queue,self.store),first)
        self.assertEqual(json.loads(self.queue.get(first)['payload'])['sheet_digest'],current)

        def publish(payload):
            self.queue.mark_sheet_projection_published(payload['sheet_digest'])
            return {'ok':True,'pushed':True,'images_left':0}

        self.assertTrue(self.queue.run_one(finalize=lambda p:{},publish=lambda:{},
            publish_with_payload=publish,order_write=lambda p:{'written':True}))
        self.assertEqual(self.queue.get(first)['state'],'done')
        self.assertEqual(self.queue.sheet_projection_state()['published_digest'],current)
        self.assertIsNone(observe_sheet_projection(self.queue,self.store))

    def test_sheet_change_after_poll_creates_a_successor_job_instead_of_publishing_stale_data(self):
        import app
        first=observe_sheet_projection(self.queue,self.store)
        expected=json.loads(self.queue.get(first)['payload'])['sheet_digest']
        self.store.sheets['Products'][1][1]='Changed after poll'
        with patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=self.store), \
                patch.object(app,'guarded_publish_shop',return_value={'ok':True,'pushed':True,'images_left':0}) as publish:
            self.assertTrue(app.run_durable_handoff(self.queue,first))

        publish.assert_not_called()
        self.assertEqual(self.queue.get(first)['state'],'done')
        state=self.queue.sheet_projection_state()
        self.assertEqual(state['observed_digest'],sheet_projection_digest(self.store))
        self.assertNotEqual(state['observed_digest'],expected)
        self.assertNotEqual(state['job_id'],first)
        self.assertEqual(self.queue.get(state['job_id'])['state'],'queued')

    def test_successful_worker_publish_marks_current_sheet_digest(self):
        import app
        digest=sheet_projection_digest(self.store)
        job=self.queue.enqueue_sheet_projection(digest)
        with patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=self.store), \
                patch.object(app,'guarded_publish_shop',return_value={'ok':True,'pushed':True,'images_left':0}) as publish:
            self.assertTrue(app.run_durable_handoff(self.queue,job))

        publish.assert_called_once()
        self.assertEqual(self.queue.get(job)['state'],'done')
        self.assertEqual(self.queue.sheet_projection_state()['published_digest'],digest)


if __name__=='__main__':unittest.main()
