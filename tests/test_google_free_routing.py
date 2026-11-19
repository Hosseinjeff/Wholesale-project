import json
import os
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import patch
from datetime import datetime
from zoneinfo import ZoneInfo

from catalog.google_quota import (
    FreeGoogleRouter, ModelBudget, QuotaConfigurationError, QuotaDeferred,
    GoogleModelUnavailable, GoogleProviderError, configured_model_budgets, configured_router, pacific_day,
)
from catalog.gemini import extract_message_products
from catalog.durable import Handoffs
from catalog.sync import MemoryStore, _process_batch_products
from reference_import.ai_matching import EmbeddingIndex, ReferenceSuggestions
from reference_import.importer import HEADERS


def budget(model='flash-a', **overrides):
    values = dict(model=model, tasks=['extract', 'match'],
        rpm=100, tpm=1000000, rpd=500, free_tier_verified=True,
        qualified=True)
    values.update(overrides)
    return ModelBudget.parse(values)


def row(code='r1'):
    values = [''] * len(HEADERS)
    values[0] = values[1] = values[2] = 'چای'
    values[3], values[4], values[6], values[16] = 120000, 10000, 12, code
    return values


class Response:
    def __init__(self, data, status=200):
        self.data, self.status_code = data, status

    def json(self):
        return self.data


def generation(value):
    return Response({'candidates': [{'finishReason': 'STOP', 'content': {
        'parts': [{'text': json.dumps(value, ensure_ascii=False)}]}}]})


class Session:
    def __init__(self, *responses):
        self.responses, self.calls = list(responses), []

    def post(self, url, **kwargs):
        self.calls.append((url, kwargs))
        result = self.responses.pop(0)
        if isinstance(result, Exception):
            raise result
        return result


class RoutingFixture(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = str(Path(self.temp.name) / 'quota.sqlite')
        self.now = datetime(2026, 11, 16, 12, tzinfo=ZoneInfo('UTC')).timestamp()
        self.environ = patch.dict(os.environ, {}, clear=True)
        self.environ.start()
        self.addCleanup(self.environ.stop)

    def router(self, *models, project='project-a'):
        return FreeGoogleRouter(self.path, project, models or [budget()], clock=lambda: self.now)


class FreeRoutingTests(RoutingFixture):
    def test_limits_require_positive_verified_qualified_configuration(self):
        for update in ({'rpd': 0}, {'rpm': True}, {'tpm': -1}, {'qualified': False},
                       {'free_tier_verified': False}, {'reserve_rpd': 500}, {'tasks': ['ocr']}):
            value = dict(model='flash', tasks=['extract'], rpm=10, tpm=100, rpd=500,
                         qualified=True, free_tier_verified=True)
            value.update(update)
            with self.subTest(update=update), self.assertRaises(QuotaConfigurationError):
                ModelBudget.parse(value)
        self.assertIsNone(configured_router())
        with patch.dict(os.environ, {'GOOGLE_FREE_ROUTING': '1'}):
            with self.assertRaises(QuotaConfigurationError):
                configured_router()

    def test_catalog_model_configuration_keeps_the_existing_extraction_pool(self):
        base = {'model': 'flash-a', 'tasks': ['extract', 'match'], 'rpm': 10, 'tpm': 50000,
                'rpd': 50, 'qualified': True, 'free_tier_verified': True}
        matching = {**base, 'model': 'gemma-4-26b-a4b-it', 'tasks': ['match'], 'api': 'generate_choice'}
        embedding = {**base, 'model': 'gemini-embedding-2', 'tasks': ['embed'], 'api': 'embed'}
        with patch.dict(os.environ, {'GOOGLE_FREE_ROUTING': '1', 'GOOGLE_FREE_MODELS': json.dumps([base]),
                                     'GOOGLE_FREE_MATCH_MODELS': json.dumps([matching]),
                                     'GOOGLE_FREE_EMBED_MODELS': json.dumps([embedding])}):
            self.assertEqual([item.model for item in configured_model_budgets()],
                             [base['model'], matching['model'], embedding['model']])
            with patch.dict(os.environ, {'GOOGLE_FREE_MATCH_MODELS': json.dumps([base])}):
                with self.assertRaises(QuotaConfigurationError):
                    configured_model_budgets()
            with patch.dict(os.environ, {'GOOGLE_FREE_EMBED_MODELS': json.dumps([matching])}):
                with self.assertRaises(QuotaConfigurationError):
                    configured_model_budgets()

    def test_independent_daily_budgets_are_used_and_survive_restart(self):
        a = ModelBudget('a', ('extract',), 100, 100000, 1, bucket='a')
        b = ModelBudget('b', ('extract',), 100, 100000, 1, bucket='b')
        router = self.router(a, b)
        self.assertEqual(router.reserve('extract', 10)[0].model, 'a')
        self.assertEqual(self.router(a, b).reserve('extract', 10)[0].model, 'b')
        with self.assertRaises(QuotaDeferred) as caught:
            self.router(a, b).reserve('extract', 10)
        self.assertEqual(caught.exception.retry_at, pacific_day(self.now)[1])
        self.assertEqual([r['rpd_remaining'] for r in router.status()], [0, 0])

    def test_large_daily_budget_and_rolling_minute_across_midnight(self):
        model = budget('large-daily', tasks=['match'], rpm=2, rpd=14000)
        router = self.router(model)
        day, midnight = pacific_day(self.now)
        self.now = midnight - 2
        with router.connect() as db:
            db.executemany('INSERT INTO google_quota_requests VALUES (?,?,?,?,?,?,?,?)',
                ((str(i), router.project, model.bucket, model.model, 'match', self.now-120, day, 50)
                 for i in range(model.rpd-1)))
        router.reserve('match', 100)
        with self.assertRaises(QuotaDeferred) as daily:
            router.reserve('match', 100)
        self.assertEqual(daily.exception.retry_at, midnight)
        self.now = midnight + 1
        router.reserve('match', 100)
        report = router.status()[0]
        self.assertEqual((report['rpd_used'], report['rpm_used'], report['tpm_used']), (1, 2, 200))
        with self.assertRaises(QuotaDeferred) as rolling:
            router.reserve('match', 100)
        self.assertAlmostEqual(rolling.exception.retry_at, midnight+58.01)

    def test_shared_aliases_cannot_multiply_a_budget(self):
        a = ModelBudget('alias-a', ('match',), 100, 100000, 1, bucket='shared')
        b = ModelBudget('alias-b', ('match',), 100, 100000, 1, bucket='shared')
        router = self.router(a, b)
        router.reserve('match', 1)
        with self.assertRaises(QuotaDeferred):
            router.reserve('match', 1)
        with self.assertRaises(QuotaConfigurationError):
            self.router(a, ModelBudget('alias-b', ('match',), 100, 100000, 2, bucket='shared'))

    def test_404_falls_through_a_shared_bucket_without_repeatedly_calling_the_missing_alias(self):
        missing = ModelBudget('retired-alias', ('match',), 10, 100000, 5, bucket='shared')
        available = ModelBudget('valid-alias', ('match',), 10, 100000, 5, bucket='shared')
        router = self.router(missing, available)
        session = Session(Response({}, status=404), Response({}), Response({}))
        result = router.request('match', {}, session=session, api_key='synthetic')
        self.assertEqual(result[1], 'valid-alias')
        result = self.router(missing, available).request('match', {}, session=session, api_key='synthetic')
        self.assertEqual(result[1], 'valid-alias')
        self.assertEqual([url.split('/')[-1].split(':')[0] for url, _ in session.calls],
                         ['retired-alias', 'valid-alias', 'valid-alias'])
        self.assertEqual(router.status()[0]['rpd_used'], 3)

    def test_explicit_model_pin_preserves_unavailable_error_without_trying_another_model(self):
        router = self.router(budget('retired'), budget('available'))
        session = Session(Response({}, status=404))
        with self.assertRaises(GoogleModelUnavailable):
            router.request('extract', {}, session=session, api_key='synthetic', model='retired')
        self.assertEqual(len(session.calls), 1)

    def test_retry_accounts_for_the_inaccessible_model_cooldown_when_other_capacity_is_used(self):
        router = self.router(budget('unavailable'), budget('exhausted', rpd=1))
        router.reserve('match', 10, model='exhausted')
        session = Session(Response({}, status=404))
        with self.assertRaises(QuotaDeferred) as caught:
            router.request('match', {}, session=session, api_key='synthetic')
        self.assertEqual(caught.exception.retry_at, self.now + 900)

    def test_dedicated_match_capacity_is_used_before_shared_extraction_capacity(self):
        router = self.router(budget('shared-generative'), budget('match-only', tasks=['match'], rpd=2))
        self.assertEqual(router.reserve('match', 10)[0].model, 'match-only')
        self.assertEqual(router.reserve('extract', 10)[0].model, 'shared-generative')
        self.assertEqual(router.reserve('match', 10)[0].model, 'match-only')
        self.assertEqual(router.reserve('match', 10)[0].model, 'shared-generative')

    def test_different_projects_do_not_share_local_counters(self):
        item = ModelBudget('a', ('match',), 100, 100000, 1, bucket='a')
        self.router(item).reserve('match', 1)
        self.router(item, project='another').reserve('match', 1)

    def test_minute_limit_and_daily_reserve_apply_independently(self):
        router = self.router(ModelBudget('a', ('match',), 1, 100, 2, bucket='a', reserve_rpd=1))
        router.reserve('match', 60, background=True)
        with self.assertRaises(QuotaDeferred) as caught:
            router.reserve('match', 60)
        self.assertAlmostEqual(caught.exception.retry_at, self.now + 60.01)
        self.now += 61
        with self.assertRaises(QuotaDeferred):
            router.reserve('match', 60, background=True)
        router.reserve('match', 60)

    def test_token_limit_even_when_rpm_remains(self):
        router = self.router(ModelBudget('a', ('match',), 100, 100, 500, bucket='a'))
        router.reserve('match', 80)
        with self.assertRaises(QuotaDeferred):
            router.reserve('match', 21)
        with self.assertRaises(QuotaConfigurationError):
            router.reserve('match', 101)

    def test_daily_reset_uses_pacific_and_handles_dst(self):
        zone = ZoneInfo('America/Los_Angeles')
        now = datetime(2026, 11, 1, 0, tzinfo=zone).timestamp()
        self.assertEqual(pacific_day(now)[1] - now, 25 * 3600)
        self.now = pacific_day(self.now)[1] - 1
        router = self.router(ModelBudget('a', ('match',), 100, 100, 1, bucket='a'))
        router.reserve('match', 1)
        self.now += 2
        router.reserve('match', 1)

    def test_concurrent_reservations_cannot_overspend(self):
        router = self.router(ModelBudget('a', ('match',), 100, 100000, 1, bucket='a'))
        results = []
        def attempt():
            try:
                router.reserve('match', 10)
                results.append('reserved')
            except QuotaDeferred:
                results.append('wait')
        threads = [threading.Thread(target=attempt) for _ in range(8)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertEqual(results.count('reserved'), 1)
        self.assertEqual(results.count('wait'), 7)

    def test_429_routes_to_another_bucket_and_daily_block_persists(self):
        router = self.router(budget('a'), budget('b'))
        session = Session(Response({'error': {'details': [{'violations': [{
            'quotaId': 'GenerateRequestsPerDayPerProjectPerModel-FreeTier'}]}]}}, 429), Response({}))
        _, selected = router.request('match', {}, session=session, api_key='private')
        self.assertEqual(selected, 'b')
        self.assertEqual(len(session.calls), 2)
        with self.assertRaises(QuotaDeferred) as caught:
            self.router(budget('a')).reserve('match', 10)
        self.assertEqual(caught.exception.retry_at, pacific_day(self.now)[1])

    def test_unknown_429_uses_cooldown_and_no_busy_retry(self):
        router = self.router()
        session = Session(Response({'error': {'message': 'private detail'}}, 429))
        with self.assertRaises(QuotaDeferred) as caught:
            router.request('match', {}, session=session, api_key='private')
        self.assertEqual(caught.exception.retry_at, self.now+60)
        self.assertEqual(len(session.calls), 1)

    def test_errors_never_expose_provider_body_or_refund_attempt(self):
        router = self.router()
        session = Session(Response({'error': {'message': 'private detail'}}, 400))
        with self.assertRaises(GoogleProviderError) as caught:
            router.request('match', {}, session=session, api_key='private')
        self.assertNotIn('private', str(caught.exception))
        self.assertEqual(router.status()[0]['rpd_used'], 1)
        with self.assertRaises(GoogleProviderError):
            router.request('match', {}, session=Session(RuntimeError('private')), api_key='private')
        self.assertEqual(router.status()[0]['rpd_used'], 2)

    def test_real_prompt_usage_reconciles_token_reservation(self):
        router = self.router()
        router.request('match', {}, session=Session(Response({'usageMetadata': {'promptTokenCount': 25}})), api_key='key')
        self.assertEqual(router.status()[0]['tpm_used'], 25)

    def test_cache_values_are_scalar_text_for_wordpress_transport(self):
        router = self.router()
        value = {'name': 'چای', 'vector': [.1, .2]}
        router.cache('transport-test', value)
        with router.connect() as db:
            stored = db.execute('SELECT value,typeof(value) AS kind FROM google_ai_cache').fetchone()
        self.assertEqual(stored['kind'], 'text')
        self.assertTrue(stored['value'].startswith('zlib:'))
        self.assertEqual(router.cached('transport-test'), value)

    def test_quota_ledger_uses_the_shared_connection_provider(self):
        import sqlite3
        with patch('catalog.google_quota.connect_state', wraps=sqlite3.connect) as provider:
            self.router().reserve('extract', 10)
        self.assertGreaterEqual(provider.call_count, 2)

    def test_wordpress_mode_selects_existing_pipeline_store(self):
        configuration = dict(model='flash', tasks=['extract'], rpm=10, tpm=10000,
                             rpd=500, qualified=True, free_tier_verified=True)
        env = {'GOOGLE_FREE_ROUTING':'1', 'GOOGLE_QUOTA_PROJECT':'project',
               'PIPELINE_DB_PATH':'/tmp/authoritative-pipeline.sqlite',
               'GOOGLE_QUOTA_DB_PATH':'/data/forbidden-extra-ledger.sqlite',
               'GOOGLE_FREE_MODELS':json.dumps([configuration])}
        with patch.dict(os.environ, env), patch('catalog.google_quota.wordpress_state', return_value=True), \
                patch('catalog.google_quota.FreeGoogleRouter') as factory:
            configured_router()
        self.assertEqual(factory.call_args.args[0], env['PIPELINE_DB_PATH'])

    def test_qualified_tasks_and_pinned_models_cannot_be_bypassed(self):
        router = self.router(budget())
        for kwargs in ({'model': 'unlisted'}, {'api': 'embed'}):
            with self.assertRaises(QuotaConfigurationError):
                router.reserve('extract', 10, **kwargs)

    def test_extraction_uses_router_and_switches_models_on_429(self):
        router = self.router(budget('a'), budget('b'))
        session = Session(Response({}, 429), generation([{'name': 'چای', 'unit_price': 10000,
            'sale_price': 10000, 'price_type': 'unit', 'currency': 'IRT', 'confidence': .96}]))
        with patch('catalog.gemini.configured_router', return_value=router):
            result = extract_message_products('چای\nدونه ای: ۱۰/۰۰۰ تومان', '@supplier',
                                              session=session, api_key='key')
        self.assertEqual(result[0]['sale_price'], 10000)
        self.assertIn('/a:', session.calls[0][0])
        self.assertIn('/b:', session.calls[1][0])

    def test_batch_quota_wait_is_not_converted_into_an_ai_failure(self):
        with patch('catalog.sync._fill_message_from_sheet'), patch('catalog.sync._rollback_catalog') as rollback:
            with patch('catalog.sync._extract_supplier_products', side_effect=QuotaDeferred(self.now+3600)):
                with self.assertRaises(QuotaDeferred):
                    _process_batch_products(MemoryStore(), 'batch', [{'content': 'چای', 'id': '1'}])
        rollback.assert_called_once()

    def test_batch_retries_reuse_ai_results_but_explicit_reextraction_does_not(self):
        from catalog.sync import _extract_supplier_products, _extract_supplier_products_for_batch
        router = self.router()
        products = [{'name': 'چای', 'sale_price': 10000}]
        with patch('catalog.google_quota.configured_router', return_value=router):
            with patch('catalog.sync.extract_message_products', return_value=products) as extract:
                self.assertEqual(_extract_supplier_products_for_batch('source', 'channel'), products)
                self.assertEqual(_extract_supplier_products_for_batch('source', 'channel'), products)
                self.assertEqual(extract.call_count, 1)
                _extract_supplier_products('source', 'channel')
                self.assertEqual(extract.call_count, 2)

    def test_quota_wait_releases_only_rolled_back_batch_hold(self):
        import app
        queue = Handoffs(str(Path(self.temp.name) / 'pipeline.sqlite'))
        queue.receive({'id': 'source', 'channel_username': '@supplier', 'content': 'چای'})
        job = queue.batch()
        with patch.object(app, 'USE_SHEETS', True), patch.object(app, 'build_store', return_value=MemoryStore()):
            with patch.object(app, 'finalize_batch', side_effect=QuotaDeferred(self.now+86400)):
                app.run_durable_handoff(queue, job)
        self.assertEqual(queue.get(job)['state'], 'queued')
        self.assertEqual(queue.get(job)['error'], 'google_free_quota_wait')
        self.assertEqual(queue.publication_holds(), [])

    def test_routing_off_fingerprints_extraction_policy_without_quota_settings(self):
        import hashlib
        from catalog import gemini
        values = {'revision': gemini._EXTRACTION_POLICY_REVISION, 'model': gemini.gemini_model(),
            'message_prompt': gemini._MESSAGE_PROMPT, 'message_schema': gemini._MESSAGE_SCHEMA}
        expected = 'gemini-' + hashlib.sha256(json.dumps(values, ensure_ascii=False,
            sort_keys=True, separators=(',', ':')).encode()).hexdigest()[:16]
        self.assertEqual(gemini.extraction_policy_version(), expected)

    def test_durable_quota_wait_survives_restart_and_does_not_spend_failure_retries(self):
        queue = Handoffs(str(Path(self.temp.name) / 'pipeline.sqlite'))
        job_id = queue.put('reextract', 'test', {'id': 'one'})
        retry_at = self.now + 86400
        with queue.connect() as db:
            db.execute('UPDATE jobs SET available=0 WHERE id=?', (job_id,))
        with patch('catalog.durable.time.time', return_value=self.now):
            queue.run_one(finalize=None, publish=None, order_write=None,
                          reextract=lambda payload: (_ for _ in ()).throw(QuotaDeferred(retry_at)))
            receipt = Handoffs(queue.path).get(job_id)
            self.assertEqual(receipt['state'], 'queued')
            self.assertEqual(receipt['available'], retry_at)
            self.assertEqual(receipt['attempts'], 0)
            self.assertFalse(queue.run_one(finalize=None, publish=None, order_write=None))


class ReferenceAITests(RoutingFixture):
    def test_a_pending_old_index_does_not_block_the_new_model(self):
        from reference_import import index_tasks
        queue = Handoffs(str(Path(self.temp.name) / 'pipeline.sqlite'))
        old, new = 'gemini-embedding-001', 'gemini-embedding-2'
        router = self.embed_router((old, new))
        snapshot = {'snapshot_id': 'verified', 'rows': [row('one')]}
        with patch.object(index_tasks, '_eligible_snapshot', return_value=snapshot):
            with patch.object(index_tasks, '_configured_index', return_value=EmbeddingIndex(router, old)):
                first = index_tasks.start(queue)
            with patch.object(index_tasks, '_configured_index', return_value=EmbeddingIndex(router, new)):
                second = index_tasks.start(queue)
                self.assertEqual(second['model'], new)
                self.assertNotEqual(first['job_id'], second['job_id'])
                self.assertEqual(index_tasks.start(queue)['job_id'], second['job_id'])
                self.assertEqual(index_tasks.run_slice(queue, {'model': old})['state'], 'superseded')

    def test_durable_reference_index_resumes_same_slice_after_quota_wait(self):
        from catalog.runtime_host import next_work_at, run_runtime_task
        from reference_import import index_tasks
        queue = Handoffs(str(Path(self.temp.name) / 'pipeline.sqlite'))
        index = EmbeddingIndex(self.embed_router(), 'gemini-embedding-001')
        snapshot = {'snapshot_id': 'verified', 'rows': [row('one'), row('two'), row('three')]}
        snapshot['rows'][1][0] = snapshot['rows'][1][1] = 'چای دوم'
        snapshot['rows'][2][0] = snapshot['rows'][2][1] = 'چای سوم'
        from contextlib import nullcontext
        session = Session(self.embedding(), self.embedding(), self.embedding())
        with patch.object(index_tasks, '_eligible_snapshot', return_value=snapshot), \
                patch.object(index_tasks, '_configured_index', return_value=index), \
                patch.object(index_tasks, 'SLICE_SIZE', 2), \
                patch.object(index_tasks, 'google_api_key', return_value='synthetic'), \
                patch.object(index_tasks.requests, 'Session', return_value=nullcontext(session)):
            start = index_tasks.start(queue)
            self.assertEqual(index_tasks.start(queue)['job_id'], start['job_id'])
            original = index.router.request
            retry_at = time.time() + 60
            with patch.object(index.router, 'request', side_effect=[
                    ({'embedding': {'values': [1] + [0]*767}}, index.model),
                    QuotaDeferred(retry_at)]):
                queue.run_one(finalize=None, publish=None, order_write=None,
                              runtime_task=lambda payload: run_runtime_task(queue, payload))
            waiting = queue.get(start['job_id'])
            self.assertEqual(waiting['attempts'], 0)
            self.assertEqual(next_work_at(queue), retry_at)
            with queue.connect() as db:
                db.execute('UPDATE jobs SET available=0 WHERE id=?', (start['job_id'],))
            with patch.object(index.router, 'request', wraps=original) as provider:
                for _ in range(2):
                    queue.run_one(finalize=None, publish=None, order_write=None,
                                  runtime_task=lambda payload: run_runtime_task(queue, payload))
            self.assertEqual(provider.call_count, 2)
            status = index_tasks.status(queue)
            self.assertEqual((status['state'], status['covered_rows'], status['total_rows']),
                             ('complete', 3, 3))
            self.assertEqual(index_tasks.start(queue)['job_id'], status['job_id'])

    def embed_router(self, models=('gemini-embedding-001',)):
        return self.router(*[ModelBudget(name, ('embed',), 100, 1000000, 1000,
                          api='embed', bucket=name) for name in models])

    def embedding(self, first=1):
        return Response({'embedding': {'values': [first] + [0]*767}})

    def test_prices_do_not_invalidate_product_embeddings(self):
        index = EmbeddingIndex(self.embed_router(), 'gemini-embedding-001')
        session = Session(self.embedding(), self.embedding())
        self.assertEqual(index.index([row()], session=session, api_key='key')['embedded'], 1)
        changed = row()
        changed[3], changed[4] = 240000, 20000
        self.assertEqual(index.index([changed], session=session, api_key='key')['cached'], 1)
        result = index.search({'name': 'چای'}, [changed], session=session, api_key='key')
        self.assertEqual(result[0]['unit_price'], 20000)
        index.search({'name': 'چای'}, [changed], session=session, api_key='key')
        self.assertEqual(len(session.calls), 2)

    def test_different_models_never_share_embedding_vectors(self):
        router = self.embed_router(('gemini-embedding-001', 'gemini-embedding-2'))
        session = Session(self.embedding(), self.embedding())
        for model in ('gemini-embedding-001', 'gemini-embedding-2'):
            self.assertEqual(EmbeddingIndex(router, model).index([row()], session=session, api_key='key')['embedded'], 1)
        self.assertEqual(len(session.calls), 2)
        self.assertIn('taskType', session.calls[0][1]['json'])
        self.assertNotIn('taskType', session.calls[1][1]['json'])

    def test_invalid_embedding_is_not_cached(self):
        index = EmbeddingIndex(self.embed_router(), 'gemini-embedding-001')
        with self.assertRaises(GoogleProviderError):
            index.index([row()], session=Session(Response({'embedding': {'values': [float('nan')]*768}})), api_key='key')
        self.assertEqual(index.index([row()], session=Session(self.embedding()), api_key='key')['embedded'], 1)

    def test_semantic_search_batches_state_reads_and_embeds_query_once(self):
        router = self.embed_router()
        index = EmbeddingIndex(router, 'gemini-embedding-001')
        catalog = []
        for i in range(257):
            reference = row('r' + str(i))
            reference[0] = reference[1] = 'محصول ' + str(i)
            catalog.append(reference)
        index.index([catalog[-1]], session=Session(self.embedding()), api_key='key')
        session = Session(self.embedding())
        with patch.object(router, 'cached_many', wraps=router.cached_many) as lookup:
            matches = index.search({'name': 'محصول'}, catalog, session=session, api_key='key')
        self.assertEqual([item['reference_id'] for item in matches], ['r256'])
        self.assertEqual(len(session.calls), 1)
        self.assertEqual([len(call.args[0]) for call in lookup.call_args_list], [256, 1, 1])

    def test_index_stops_at_daily_quota_and_resumes_from_cache(self):
        item = ModelBudget('gemini-embedding-001', ('embed',), 100, 1000000, 1,
                           api='embed', bucket='embed')
        index = EmbeddingIndex(self.router(item), item.model)
        second = row('r2')
        second[0] = second[1] = 'قند'
        result = index.index([row(), second], session=Session(self.embedding()), api_key='key')
        self.assertEqual(result['embedded'], 1)
        self.assertEqual(result['remaining'], 1)
        self.assertEqual(result['retry_at'], pacific_day(self.now)[1])
        self.now = pacific_day(self.now)[1] + 1
        result = index.index([row(), second], session=Session(self.embedding()), api_key='key')
        self.assertEqual((result['cached'], result['embedded']), (1, 1))

    def test_match_suggestions_are_cached_and_never_approval(self):
        service = ReferenceSuggestions(self.router())
        session = Session(*[generation({'reference_id': 'r1', 'confidence': .95, 'reason': 'same product'})
                            for _ in range(2)])
        result = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='snapshot1', session=session, api_key='key')
        self.assertEqual(result['state'], 'suggestion_only')
        self.assertEqual(result['reference_id'], 'r1')
        service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='snapshot1', session=session, api_key='key')
        self.assertEqual(len(session.calls), 1)
        service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='snapshot2', session=session, api_key='key')
        self.assertEqual(len(session.calls), 2)

    def test_unknown_ids_and_untrusted_structures_are_rejected(self):
        for reference_id in ('invented', ['r1']):
            service = ReferenceSuggestions(self.router())
            session = Session(generation({'reference_id': reference_id, 'confidence': .99, 'reason': 'guess'}))
            with self.subTest(reference_id=reference_id), self.assertRaises(GoogleProviderError):
                service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='s1', session=session, api_key='key')

    def test_decision_model_quota_exhaustion_uses_qualified_generation_pool(self):
        decision = ModelBudget('gemini-decision-1', ('match',), 100, 1000000, 1,
                               api='decide', bucket='decide')
        router = self.router(decision, budget())
        service = ReferenceSuggestions(router)
        session = Session(Response({'answers': {'reference': {'choice': 'c0', 'confidence': .9}}}),
            generation({'reference_id': None, 'confidence': .8, 'reason': 'ambiguous'}))
        first = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='s1', session=session, api_key='key')
        self.assertEqual(first['model'], 'gemini-decision-1')
        second = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='s2', session=session, api_key='key')
        self.assertIsNone(second['reference_id'])
        self.assertEqual(second['state'], 'needs_review')
        self.assertEqual(second['model'], 'flash-a')

    def test_unavailable_decision_endpoint_falls_through_to_generation(self):
        decision = ModelBudget('decision-retired', ('match',), 10, 100000, 10,
                               api='decide', bucket='decision-retired')
        service = ReferenceSuggestions(self.router(decision, budget()))
        session = Session(Response({}, status=404),
                          generation({'reference_id': 'r1', 'confidence': .9, 'reason': 'same product'}))
        result = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='s1', session=session, api_key='synthetic')
        self.assertEqual(result['model'], 'flash-a')
        self.assertEqual(result['reference_id'], 'r1')

    def test_decision_handles_gemma_overload_before_using_extraction_capacity(self):
        decision = budget('gemini-decision-1', tasks=['match'], api='decide')
        gemma = budget('gemma-4-26b-a4b-it', tasks=['match'], api='generate_choice')
        service = ReferenceSuggestions(self.router(budget(), gemma, decision))
        session = Session(Response({}, status=503),
            Response({'answers': {'reference': {'choice': 'c0', 'confidence': .9}}}))
        result = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='s1', session=session, api_key='key')
        self.assertEqual(result['model'], decision.model)
        self.assertEqual(result['reference_id'], 'r1')
        self.assertTrue(session.calls[1][0].endswith('/decisions'))

        session = Session(Response({'answers': {'reference': {'choice': 'invented', 'confidence': .9}}}),
                          generation({'reference_id': 'r1', 'confidence': .9, 'reason': 'same product'}))
        result = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='s2', session=session, api_key='key')
        self.assertEqual(result['model'], 'flash-a')

    def test_constrained_generation_selects_a_catalog_candidate_or_abstains(self):
        model = budget('gemma-4-31b-it', api='generate_choice', tasks=['match'])
        service = ReferenceSuggestions(self.router(model))
        session = Session(generation({'choice': 'A'}), generation({'choice': 'no_match'}))
        match = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='s1', session=session, api_key='key')
        abstain = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id='s2', session=session, api_key='key')
        self.assertEqual((match['reference_id'], match['state'], match['model']), ('r1', 'suggestion_only', model.model))
        self.assertEqual((abstain['reference_id'], abstain['state']), (None, 'needs_review'))
        self.assertIsNone(match['confidence'])
        url, request = session.calls[0]
        self.assertTrue(url.endswith('/gemma-4-31b-it:generateContent'))
        config = request['json']['generationConfig']
        self.assertEqual(config['thinkingConfig'], {'thinkingLevel': 'MINIMAL'})
        self.assertEqual(config['responseJsonSchema']['properties']['choice']['enum'], ['A', 'no_match'])
        with self.assertRaises(QuotaConfigurationError):
            budget('gemma-4-31b-it', api='generate_choice', tasks=['extract'])

    def test_constrained_generation_falls_back_after_invalid_output_or_provider_timeout(self):
        model = budget('gemma-4-31b-it', api='generate_choice', tasks=['match'])
        service = ReferenceSuggestions(self.router(model, budget()))
        for snapshot, failed in [('wrong_choice', generation({'choice': 'invented'})), ('timeout', TimeoutError())]:
            session = Session(failed, generation({'reference_id': 'r1', 'confidence': .9, 'reason': 'same item'}))
            result = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id=snapshot, session=session, api_key='key')
            self.assertEqual((result['reference_id'], result['model']), ('r1', 'flash-a'))
            self.assertEqual(len(session.calls), 2)
            self.now += 31

    def test_catalog_uses_26b_for_short_text_matches_and_31b_for_broader_matches(self):
        small = budget('gemma-4-26b-a4b-it', api='generate_choice', tasks=['match'])
        large = budget('gemma-4-31b-it', api='generate_choice', tasks=['match'])
        service = ReferenceSuggestions(self.router(budget(), large, small))
        session = Session(generation({'choice': 'A'}), generation({'choice': 'no_match'}))
        product = {'name': 'چای ممتاز'}
        routine = service.suggest(product, [row()], snapshot_id='s1', session=session, api_key='key')
        broad = service.suggest(product, [row(f'r{i}') for i in range(1, 6)], snapshot_id='s2',
                                session=session, api_key='key')
        self.assertEqual((routine['reference_id'], routine['model']), ('r1', small.model))
        self.assertEqual((broad['reference_id'], broad['model'], broad['state']), (None, large.model, 'needs_review'))
        self.assertEqual(len(session.calls), 2)

    def test_choice_provider_overload_pauses_only_its_model_and_26b_returns_after_recovery(self):
        small = budget('gemma-4-26b-a4b-it', api='generate_choice', tasks=['match'], bucket='shared')
        large = budget('gemma-4-31b-it', api='generate_choice', tasks=['match'], bucket='shared')
        service = ReferenceSuggestions(self.router(small, large))
        session = Session(Response({}, status=503), generation({'choice': 'A'}),
                          generation({'choice': 'A'}), generation({'choice': 'A'}))
        models = []
        for i, elapsed in enumerate([0, 10, 21]):
            self.now += elapsed
            result = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id=f's{i}',
                                     session=session, api_key='key')
            models.append(result['model'])
        self.assertEqual(models, [large.model, large.model, small.model])
        self.assertEqual(service.router.status()[0]['rpd_used'], 4)

    def test_bad_choice_or_timeout_tries_the_other_gemma_before_gemini(self):
        small = budget('gemma-4-26b-a4b-it', api='generate_choice', tasks=['match'])
        large = budget('gemma-4-31b-it', api='generate_choice', tasks=['match'])
        service = ReferenceSuggestions(self.router(small, large, budget()))
        for i, failed in enumerate([generation({'choice': 'invented'}), TimeoutError()]):
            session = Session(failed, generation({'choice': 'A'}))
            result = service.suggest({'name': 'چای ممتاز'}, [row()], snapshot_id=f's{i}',
                                     session=session, api_key='key')
            self.assertEqual((result['reference_id'], result['model']), ('r1', large.model))
            self.assertIn(small.model, session.calls[0][0])
            self.assertIn(large.model, session.calls[1][0])
            self.now += 31

    def test_exact_match_skips_embedding_and_comparison_calls(self):
        router = self.embed_router()
        service = ReferenceSuggestions(router, embedding_model='gemini-embedding-001')
        session = Session()
        result = service.suggest({'name': 'چای', 'package_count': 12}, [row()],
            snapshot_id='s1', session=session, api_key='key')
        self.assertEqual(result['reference_id'], 'r1')
        self.assertEqual(result['method'], 'code')
        self.assertIsNone(result['confidence'])
        self.assertIsNone(result['model'])
        self.assertEqual(session.calls, [])
        self.assertEqual(router.status()[0]['rpd_used'], 0)

    def test_exact_match_and_ambiguous_shortlist_work_without_google_configuration(self):
        service = ReferenceSuggestions(None)
        exact = service.suggest({'name': 'چای'}, [row()], snapshot_id='s1')
        self.assertEqual(exact['reference_id'], 'r1')
        ambiguous = service.suggest({'name': 'چای'}, [row(), row('r2')], snapshot_id='s1')
        self.assertEqual(ambiguous['state'], 'needs_review')
        self.assertIsNone(ambiguous['reference_id'])
        self.assertEqual(len(ambiguous['candidates']), 2)

    def test_approved_mapping_is_reused_without_ai(self):
        from reference_import.matching import product_signature
        product = {'name': 'نام تامین کننده'}
        overrides = {product_signature(product): {'reference_id': 'r1',
                     'price_role': 'unit', 'decision_id': 'owner-record'}}
        result = ReferenceSuggestions(None).suggest(product, [row()], snapshot_id='s1', overrides=overrides)
        self.assertEqual(result['reference_id'], 'r1')
        self.assertEqual(result['reason'], 'owner_approved_mapping')
        self.assertEqual(result['candidates'][0]['owner_decision_id'], 'owner-record')

    def test_explicit_numeric_conflicts_do_not_spend_ai_quota(self):
        reference = row()
        reference[8], reference[9] = '1 لیتر', '400 گرم'
        router = self.router()
        session = Session()
        for product in ({'name': 'چای', 'package_count': 24},
                        {'name': 'چای', 'volume': '500 ml'},
                        {'name': 'چای', 'weight': '1 kg'}):
            with self.subTest(product=product):
                result = ReferenceSuggestions(router).suggest(product, [reference],
                    snapshot_id='s1', session=session, api_key='key')
                self.assertIsNone(result['reference_id'])
                self.assertEqual(result['reason'], 'explicit_attribute_conflicts')
        self.assertEqual(session.calls, [])

    def test_measurement_conversion_is_code_not_an_ai_comparison(self):
        reference = row()
        reference[8], reference[9] = '1 لیتر', '1 kg'
        result = ReferenceSuggestions(None).suggest({'name': 'چای',
            'volume': '۱۰۰۰ میلی لیتر', 'weight': '1000 گرم'}, [reference], snapshot_id='s1')
        self.assertEqual(result['reference_id'], 'r1')
        self.assertEqual(result['method'], 'code')

    def test_model_cannot_override_explicit_variant_conflict(self):
        reference = row()
        reference[11] = 'هلو'
        session = Session(generation({'reference_id': 'r1', 'confidence': .99, 'reason': 'guess'}))
        result = ReferenceSuggestions(self.router()).suggest({'name': 'چای ممتاز', 'variation_type': 'انبه'},
            [reference], snapshot_id='s1', session=session, api_key='key')
        self.assertEqual(result['state'], 'needs_review')
        self.assertIsNone(result['reference_id'])
        self.assertEqual(result['rejected_suggestion_id'], 'r1')
        self.assertIn('variant', result['conflicts'])

    def test_semantic_search_uses_original_wording_without_relaxing_numeric_constraints(self):
        router = self.router(ModelBudget('gemini-embedding-001', ('embed',), 100, 1000000, 1000,
                            api='embed', bucket='embedding'), budget())
        service = ReferenceSuggestions(router, embedding_model='gemini-embedding-001')
        session = Session(self.embedding(), self.embedding(),
            generation({'reference_id': 'r1', 'confidence': .8, 'reason': 'alias'}))
        wrong_pack = row('wrong-pack')
        wrong_pack[6] = 24
        service.embeddings.index([row()], session=session, api_key='key')
        wording = '🍵 Schwarztee\nลัง 12 ซอง'
        result = service.suggest({'name': 'Schwarztee', 'package_count': 12}, [row(), wrong_pack],
            snapshot_id='s1', source_text=wording, session=session, api_key='key')
        query = session.calls[1][1]['json']['content']['parts'][0]['text']
        self.assertIn('Schwarztee ลัง 12 ซอง', query)
        self.assertEqual([item['reference_id'] for item in result['candidates']], ['r1'])
        self.assertEqual(result['reference_id'], 'r1')
        self.assertEqual(result['method'], 'ai')
        self.assertEqual(result['state'], 'suggestion_only')
        self.assertEqual(len(session.calls), 3)

    def test_embedding_failure_preserves_text_shortlist(self):
        router = self.router(budget())
        service = ReferenceSuggestions(router, embedding_model='gemini-embedding-001')
        session = Session(generation({'reference_id': None, 'confidence': .9, 'reason': 'ambiguous'}))
        with patch.object(service.embeddings, 'search', side_effect=GoogleProviderError('invalid vector')):
            result = service.suggest({'name': 'چای ممتاز'}, [row()],
                snapshot_id='s1', session=session, api_key='key')
        self.assertEqual([item['reference_id'] for item in result['candidates']], ['r1'])
        self.assertEqual(result['state'], 'needs_review')

    def test_embedding_migration_searches_the_compatible_cached_index(self):
        primary, fallback = 'gemini-embedding-2', 'gemini-embedding-001'
        service = ReferenceSuggestions(self.embed_router((primary, fallback)),
                                       embedding_model=primary, fallback_embedding_model=fallback)
        session = Session(*(self.embedding() for _ in range(5)))
        rows, product = [row()], {'name': 'Schwarztee', 'package_count': 12}
        service.fallback_embeddings.index(rows, session=session, api_key='key')
        saved = service.suggest(product, rows, snapshot_id='s1', session=session, api_key='key')
        self.assertEqual(saved['candidates'][0]['reference_id'], 'r1')
        self.assertIn(fallback, session.calls[-1][0])
        service.embeddings.index(rows, session=session, api_key='key')
        service.suggest(product, rows, snapshot_id='s1', session=session, api_key='key')
        self.assertIn(primary, session.calls[-1][0])
        self.assertEqual(len(session.calls), 4)

    def test_semantic_retrieval_cannot_displace_explicit_brand_evidence(self):
        service = ReferenceSuggestions(self.router(), embedding_model='gemini-embedding-001')
        real, unrelated = row(), row('wrong-brand')
        real[0] = real[1] = 'چای برندنو'
        unrelated[0] = unrelated[1] = 'چای برند دیگر'
        session = Session(generation({'reference_id': None, 'confidence': .9, 'reason': 'ambiguous'}))
        with patch.object(service.embeddings, 'search', return_value=[{'reference_id': 'wrong-brand'}]):
            result = service.suggest({'name': 'چای برندنو ممتاز', 'brand': 'برندنو'},
                [real, unrelated], snapshot_id='s1', session=session, api_key='key')
        self.assertEqual([item['reference_id'] for item in result['candidates']], ['r1'])


if __name__ == '__main__':
    unittest.main()
