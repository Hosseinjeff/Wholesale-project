import copy
import os
import tempfile
import unittest
from types import SimpleNamespace
from pathlib import Path
from unittest.mock import patch

from catalog.durable import Handoffs
from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS
from catalog.sync import MemoryStore, _row_from_map
from reference_import.importer import HEADERS
from reference_import.item_reviews import ItemReviewLedger, review_cases
from reference_import.snapshots import ReferenceStore
from reference_import.suggestion_tasks import (attach_receipts, policy_version,
    run_suggestion, task_context, task_key)
from catalog.google_quota import QuotaDeferred


class ReferenceSuggestionTasksTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.env = patch.dict(os.environ, {}, clear=True)
        self.env.start(); self.addCleanup(self.env.stop)
        self.path = str(Path(self.temp.name) / 'reference.sqlite')
        self.queue = Handoffs(str(Path(self.temp.name) / 'pipeline.sqlite'))
        self.reference = ReferenceStore(self.path, owner_ids=['wp:7'])
        self.ledger = ItemReviewLedger(self.path, owner_ids=['wp:7'])
        row = [''] * len(HEADERS)
        row[0] = row[1] = row[2] = 'چای'
        row[6], row[16] = 12, 'R-1'
        self.snapshot = {'snapshot_id': 'snapshot-A', 'rows': [row]}
        snapshot_patch = patch.object(self.reference, 'active_snapshot', return_value=self.snapshot)
        snapshot_patch.start(); self.addCleanup(snapshot_patch.stop)
        self.state = {'allow_automatic': True, 'snapshot_id': 'snapshot-A'}
        for target in ['reference_import.suggestion_tasks.active_reference_use',
                       'reference_import.workflow.active_reference_use']:
            gate = patch(target, return_value=self.state)
            gate.start(); self.addCleanup(gate.stop)
        self.store = MemoryStore()
        self.offer = {'Offer ID': 'offer-1', 'Product Name': 'چای', 'Package Count': 12,
                      'Sale Price': 40000, 'Reference Snapshot ID': 'snapshot-A',
                      'Reference Match State': 'multiple_candidates', 'Status': 'needs_review',
                      'Original Message': 'چای ۱۲ عددی'}
        self.store.ensure('Offers', OFFER_HEADERS)
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, self.offer)])
        self.store.ensure('Products', PRODUCT_HEADERS)
        self.store.append('Products', [_row_from_map(PRODUCT_HEADERS,
            {'Product Name': 'چای', 'Latest Offer ID': 'offer-1', 'Status': 'needs_review'})])
        self.case = review_cases(self.store, self.snapshot, self.ledger)[0]
        self.context = task_context(self.case, 'reference', 'snapshot-A')

    def run_job(self, callback=None):
        job_id = self.queue.put('reference_suggest', task_key(self.context), self.context)
        self.queue.run_one(finalize=None, publish=None, order_write=None, job_id=job_id,
            reference_suggest=callback or (lambda payload: run_suggestion(
                self.queue, payload, self.store, self.reference)))
        return self.queue.get(job_id)

    def test_code_first_worker_and_http_reads_never_call_google_or_save_decisions(self):
        before = copy.deepcopy(self.store.read('Products'))
        with patch('requests.Session.post', side_effect=AssertionError('Google must not run')):
            job = self.run_job()
            self.assertEqual(job['state'], 'done')
            self.assertEqual(job['result']['suggestion']['reference_id'], 'R-1')
            self.assertEqual(job['result']['suggestion']['method'], 'code')
            cases = [dict(self.case)]
            attach_receipts(self.queue, cases, 'reference', 'snapshot-A')
        self.assertEqual(cases[0]['reference_suggestion']['state'], 'done')
        self.assertIsNone(self.ledger.get(self.case['case_id']))
        self.assertEqual(self.store.read('Products'), before)

    def test_snapshot_or_source_changes_discard_work_before_model_call(self):
        for field, value in [('snapshot_id', 'old-snapshot'), ('signature', '0'*64)]:
            context = {**self.context, field: value}
            with patch('reference_import.suggestion_tasks.ReferenceSuggestions') as matcher:
                result = run_suggestion(self.queue, context, self.store, self.reference)
            self.assertEqual(result['state'], 'superseded')
            matcher.assert_not_called()

    def test_source_change_during_inference_discards_result(self):
        def suggest(*args, **kwargs):
            self.assertEqual(kwargs['source_text'], self.offer['Original Message'])
            self.store.write_rows('Offers', 2, [_row_from_map(
                OFFER_HEADERS, {**self.offer, 'Original Message': 'چای ۲۴ عددی'})])
            return {'reference_id': 'R-1'}
        with patch('reference_import.suggestion_tasks.ReferenceSuggestions') as matcher:
            matcher.return_value.suggest.side_effect = suggest
            result = run_suggestion(self.queue, self.context, self.store, self.reference)
        self.assertEqual(result['state'], 'superseded')

    def test_contextual_options_stay_visible_with_a_full_existing_shortlist(self):
        self.run_job(lambda _: {'ok': True, 'suggestion': {'reference_id': None,
            'candidates': [{'reference_id': 'contextual'}]}})
        case = {**self.case, 'candidates': [{'reference_id': str(i)} for i in range(12)]}
        attach_receipts(self.queue, [case], 'reference', 'snapshot-A')
        self.assertEqual(len(case['candidates']), 12)
        self.assertEqual(case['candidates'][0]['reference_id'], 'contextual')

    def test_configuration_and_signature_bound_receipts_never_attach_to_changed_case(self):
        self.run_job()
        changed = {**self.case, 'signature': '0'*64}
        attach_receipts(self.queue, [changed], 'reference', 'snapshot-A')
        self.assertNotIn('reference_suggestion', changed)
        with patch.dict(os.environ, {'GOOGLE_FREE_MODELS': 'changed'}):
            self.assertNotEqual(policy_version(), self.context['policy_version'])
            cases = [dict(self.case)]
            attach_receipts(self.queue, cases, 'reference', 'snapshot-A')
            self.assertNotIn('reference_suggestion', cases[0])

    def test_quota_wait_remains_durable_without_consuming_failure_attempt(self):
        def wait(payload):
            raise QuotaDeferred(2000000000)
        job = self.run_job(wait)
        self.assertEqual(job['state'], 'queued')
        self.assertEqual(job['attempts'], 0)
        self.assertEqual(job['available'], 2000000000)

    def test_extract_only_routing_returns_code_shortlist_without_retrying_matching(self):
        from reference_import.ai_matching import ReferenceSuggestions
        duplicate = list(self.snapshot['rows'][0]); duplicate[16] = 'R-2'
        router = SimpleNamespace(models=[SimpleNamespace(tasks=('extract',))])
        result = ReferenceSuggestions(router).suggest({'name': 'چای', 'package_count': 12},
            [*self.snapshot['rows'], duplicate], snapshot_id='snapshot-A', api_key='synthetic')
        self.assertEqual(result['state'], 'needs_review')
        self.assertEqual(result['method'], 'code')

    def test_ai_case_receipts_require_current_source_and_no_owner_decision(self):
        from catalog.ai_confirmations import AIConfirmationLedger, review_cases as ai_cases
        self.store.write_rows('Offers', 2, [_row_from_map(OFFER_HEADERS,
            {**self.offer, 'Review Reason': 'missing_stock'})])
        ai_ledger = AIConfirmationLedger(self.queue.path)
        case = ai_cases(self.store, ai_ledger)[0]
        context = task_context(case, 'ai', 'snapshot-A')
        result = run_suggestion(self.queue, context, self.store, self.reference)
        self.assertEqual(result['suggestion']['reference_id'], 'R-1')
        ai_ledger.decide(case_id=case['case_id'], offer_id=case['offer_id'],
            signature=case['signature'], choice='not_product', fields={}, actor_id='wp:7', note='Synthetic review')
        with patch('reference_import.suggestion_tasks.ReferenceSuggestions') as matcher:
            ignored = run_suggestion(self.queue, context, self.store, self.reference)
        self.assertEqual(ignored['state'], 'superseded')
        matcher.assert_not_called()

    def test_ai_review_history_ignores_completed_offer_inventory(self):
        from catalog.ai_confirmations import AIConfirmationLedger, review_cases as ai_cases
        self.store.write_rows('Offers', 2, [_row_from_map(OFFER_HEADERS,
            {**self.offer, 'Review Reason': 'missing_stock'})])
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS,
            {**self.offer, 'Offer ID': f'completed-{i}', 'Status': 'imported'}) for i in range(1001)])
        ledger = AIConfirmationLedger(self.queue.path)
        original = ledger.latest_for_cases
        looked_up = []
        def latest(identifiers):
            identifiers = list(identifiers); looked_up.extend(identifiers)
            return original(identifiers)
        with patch.object(ledger, 'latest_for_cases', side_effect=latest):
            cases, _ = ai_cases(self.store, ledger, include_history=True)
        self.assertEqual(len(cases), 1)
        self.assertEqual(looked_up, [cases[0]['case_id']])

    def test_owner_route_queues_idempotently_and_get_only_reads_receipt(self):
        import app as server
        secret = 'synthetic-owner-bridge-token-at-least-32-chars'
        env = {'REFERENCE_STORE_PATH': self.path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': secret, 'PIPELINE_DB_PATH': self.queue.path}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=self.queue), \
                patch.object(server, 'build_store', return_value=self.store), \
                patch('reference_import.snapshots.ReferenceStore', return_value=self.reference), \
                patch.object(server, '_start_pipeline_worker') as worker:
            client = server.app.test_client()
            headers = {'X-Molavi-Reference-Token': secret, 'X-Molavi-Actor': 'wp:7'}
            body = {'action': 'suggest_reference', **{k: v for k, v in self.context.items()
                                                    if k != 'policy_version'}}
            self.assertEqual(client.post('/reference/item-reviews', json=body).status_code, 401)
            first = client.post('/reference/item-reviews', json=body, headers=headers)
            second = client.post('/reference/item-reviews', json=body, headers=headers)
            self.assertEqual(first.status_code, 202, first.get_json())
            self.assertEqual(first.get_json()['job_id'], second.get_json()['job_id'])
            self.assertEqual(sum(call.kwargs.get('force') is True for call in worker.call_args_list), 2)
            worker.reset_mock()
            read = client.get('/reference/item-reviews', headers=headers)
            self.assertEqual(read.status_code, 200, read.get_json())
            self.assertEqual(read.get_json()['cases'][0]['reference_suggestion']['state'], 'queued')
            self.assertFalse(any(call.kwargs.get('force') for call in worker.call_args_list))
            stale = client.post('/reference/item-reviews', headers=headers,
                                json={**body, 'signature': '0'*64})
            self.assertEqual(stale.status_code, 409)
            self.state['allow_automatic'] = False
            blocked = client.post('/reference/item-reviews', json=body, headers=headers)
            self.assertEqual(blocked.status_code, 409)


if __name__ == '__main__':
    unittest.main()
