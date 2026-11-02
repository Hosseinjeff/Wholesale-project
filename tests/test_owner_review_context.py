"""Durable source context and atomic retry behavior for owner review cards."""
import tempfile
import unittest
from pathlib import Path

from catalog.durable import Handoffs, JobConflict


class OwnerReviewContextTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.queue = Handoffs(str(Path(self.tmp.name) / 'pipeline.sqlite'))

    def add_held_batch(self, hold_id, reason='batch_unverified'):
        self.queue.hold_publication(hold_id, reason)
        self.queue.put('batch', hold_id, {
            'batch_id': hold_id,
            'expected_count': 1,
            'messages': [{
                'id': '7317', 'channel_username': '@supplier',
                'timestamp': '2026-11-02T13:01:00Z',
                'url': 'https://t.me/supplier/7317',
                'content': 'supplier source text', 'chat_id': 'private-chat',
            }],
        })
        with self.queue.connect() as db:
            db.execute("""UPDATE jobs SET state='needs_review',result=? WHERE id=?""",
                       (self.queue.encode({'expected_count':1,'written_count':1,
                           'processed_messages':0,'extraction_status':'error',
                           'extraction_error':'ai_extraction_failed','rollback':True}),
                        'batch:' + hold_id))

    def test_context_returns_source_post_and_safe_processing_reason(self):
        self.add_held_batch('context-1')
        context = self.queue.publication_hold_context('context-1')
        self.assertEqual(context['batch_state'], 'needs_review')
        self.assertEqual(context['processing_error'], 'ai_extraction_failed')
        self.assertTrue(context['rollback'])
        self.assertEqual(context['messages'][0]['message_id'], '7317')
        self.assertEqual(context['messages'][0]['source_url'], 'https://t.me/supplier/7317')
        self.assertEqual(context['messages'][0]['content'], 'supplier source text')
        self.assertNotIn('chat_id', repr(context))

    def test_selected_batch_retry_is_atomic(self):
        self.add_held_batch('batch-a')
        self.add_held_batch('batch-b')
        holds = [
            {'hold_id':'batch-a','expected_reason':'batch_unverified'},
            {'hold_id':'batch-b','expected_reason':'batch_unverified'},
        ]
        resumed = self.queue.resolve_publication_holds(holds, actor_id='wp:7', note='Reviewed')
        self.assertEqual(resumed, ['batch-a','batch-b'])
        self.assertEqual(self.queue.publication_holds(), [])
        self.assertEqual(self.queue.get('batch:batch-a')['state'], 'queued')
        self.assertEqual(self.queue.get('batch:batch-b')['state'], 'queued')
        with self.queue.connect() as db:
            count = db.execute('SELECT COUNT(*) AS count FROM publication_hold_resolutions').fetchone()['count']
        self.assertEqual(count, 2)

    def test_stale_member_prevents_partial_batch_retry(self):
        self.add_held_batch('batch-a')
        self.add_held_batch('batch-b')
        holds = [
            {'hold_id':'batch-a','expected_reason':'batch_unverified'},
            {'hold_id':'batch-b','expected_reason':'wrong'},
        ]
        with self.assertRaises(JobConflict):
            self.queue.resolve_publication_holds(holds, actor_id='wp:7', note='Reviewed')
        self.assertEqual(len(self.queue.publication_holds()), 2)
        self.assertEqual(self.queue.get('batch:batch-a')['state'], 'needs_review')
        with self.queue.connect() as db:
            count = db.execute('SELECT COUNT(*) AS count FROM publication_hold_resolutions').fetchone()['count']
        self.assertEqual(count, 0)


if __name__ == '__main__':
    unittest.main()
