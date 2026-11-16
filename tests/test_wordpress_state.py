import copy
import json
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch
from catalog import state
from catalog.state_transfer import _backup, _hash


def result(columns=(), rows=(), rowcount=-1):
    return {'columns': list(columns), 'rows': list(rows), 'rowcount': rowcount, 'lastrowid': 0}


class WordPressStateTests(unittest.TestCase):
    def setUp(self):
        state._schema_scripts.clear()
        state._schema_columns.clear()
        self.calls = []
        def rpc(route, body):
            self.calls.append(copy.deepcopy(body))
            write = body['steps'] and body['steps'][-1]['sql'].lstrip().startswith('UPDATE')
            return {'ok': True, 'result': result(rowcount=1) if write else result(['id'], [['synthetic']]), 'tokens': ['token'+str(i) for i in range(len(body['steps']))],
                    'cursors': [{'rowcount': 1, 'lastrowid': 0} for _ in body['steps']]}
        self.rpc = patch('catalog.state.state_call', side_effect=rpc)
        self.rpc.start();self.addCleanup(self.rpc.stop)

    def test_read_results_are_returned_without_persisting_read_programs(self):
        with state.Connection('pipeline') as db:
            row = db.execute('SELECT id FROM jobs').fetchone()
            self.assertEqual(row[0], 'synthetic')
            self.assertEqual(row['id'], 'synthetic')
            self.assertEqual(dict(row), {'id': 'synthetic'})
            self.assertEqual(tuple(row), ('synthetic',))
        self.assertEqual([c['mode'] for c in self.calls], ['preview'])

    def test_write_commits_complete_observed_program_once(self):
        with state.Connection('pipeline') as db:
            db.execute('SELECT id FROM jobs')
            db.execute('UPDATE jobs SET state=? WHERE id=?', ('done', 'synthetic'))
        self.assertEqual([c['mode'] for c in self.calls], ['preview', 'commit'])
        commit = self.calls[-1]
        self.assertEqual(len(commit['steps']), 2)
        self.assertIn('expected', commit['steps'][0])
        self.assertEqual(self.calls[0]['id'], commit['id'])
        self.assertEqual(self.calls[0]['observed_at'], commit['observed_at'])

    def test_exception_discards_pending_writes(self):
        with self.assertRaises(ValueError):
            with state.Connection('pipeline') as db:
                db.execute('UPDATE jobs SET state=?', ('done',))
                raise ValueError('synthetic failure')
        self.assertEqual(self.calls, [])

    def test_explicit_begin_remains_inside_the_atomic_program(self):
        with state.Connection('pipeline') as db:
            db.execute('BEGIN IMMEDIATE')
            db.execute('UPDATE jobs SET state=?', ('done',))
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(len(self.calls[-1]['steps']), 1)

    def test_write_count_remains_available_after_transaction_close(self):
        with state.Connection('pipeline') as db:
            cursor = db.execute('UPDATE jobs SET state=?', ('done',))
        self.assertEqual(cursor.rowcount, 1)
        self.assertEqual(len(self.calls), 1)

    def test_write_count_used_for_compare_and_swap_is_validated_before_commit(self):
        with state.Connection('pipeline') as db:
            cursor = db.execute('UPDATE jobs SET state=? WHERE lease=?', ('done', 'lease'))
            self.assertEqual(cursor.rowcount, 1)
        self.assertEqual([c['mode'] for c in self.calls], ['preview', 'commit'])
        self.assertIn('expected', self.calls[-1]['steps'][0])

    def test_schema_cache_contains_no_business_rows_and_avoids_repeated_initialization(self):
        for _ in range(2):
            with state.Connection('pipeline') as db:
                db.executescript('CREATE TABLE IF NOT EXISTS jobs (id TEXT)')
                db.execute('PRAGMA table_info(jobs)').fetchall()
        self.assertEqual(len(self.calls), 2, 'schema setup is cached per worker, not repeated per queue access')
        self.assertEqual(state._schema_columns.keys(), {('pipeline', 'jobs')})

    def test_executemany_materializes_a_bounded_program_not_individual_commits(self):
        with state.Connection('pipeline') as db:
            db.executemany('UPDATE inbox SET batch_id=? WHERE id=?', ((str(i), str(i)) for i in range(3)))
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(len(self.calls[-1]['steps'][0]['params']), 3)

    def test_factory_uses_real_sqlite_without_the_cutover_flag(self):
        with tempfile.TemporaryDirectory() as root, patch.dict(os.environ, {'PIPELINE_STATE_HOST': ''}):
            db = state.connect(str(Path(root)/'local.sqlite3'))
            self.assertIsInstance(db, sqlite3.Connection)
            db.close()

    def test_remote_factory_uses_only_empty_ephemeral_markers(self):
        with tempfile.TemporaryDirectory() as root:
            path = str(Path(root)/'marker.sqlite3')
            with patch.dict(os.environ, {'PIPELINE_STATE_HOST': 'wordpress', 'PIPELINE_DB_PATH': path}):
                self.assertIsInstance(state.connect(path), state.Connection)
                self.assertEqual(Path(path).stat().st_size, 0)
                self.assertEqual(state.store_key(Path(path).as_uri()+'?mode=ro'), 'pipeline')

    def test_remote_factory_refuses_a_persistent_local_replica(self):
        with patch.dict(os.environ, {'PIPELINE_STATE_HOST': 'wordpress', 'PIPELINE_DB_PATH': '/data/pipeline.sqlite3'}):
            with self.assertRaises(sqlite3.OperationalError):
                state.connect('/data/pipeline.sqlite3')

    def test_backup_preserves_active_jobs_and_checksums_without_altering_source(self):
        with tempfile.TemporaryDirectory() as root:
            original, backup = Path(root)/'source.sqlite3', Path(root)/'transfer.sqlite3'
            with sqlite3.connect(original) as db:
                db.execute('CREATE TABLE jobs (id TEXT PRIMARY KEY,state TEXT)')
                db.execute("INSERT INTO jobs VALUES ('held','needs_review')")
            before = _hash(original)
            tables = _backup(original, backup)
            self.assertEqual(tables, {'jobs': 1})
            self.assertEqual(_hash(original), before)
            with sqlite3.connect(backup) as db:
                self.assertEqual(db.execute('SELECT state FROM jobs').fetchone()[0], 'needs_review')

    def test_review_reads_fetch_decisions_in_groups_without_per_offer_calls(self):
        from catalog.ai_confirmations import AIConfirmationLedger, review_cases
        from catalog.extract import OFFER_HEADERS
        from catalog.sync import MemoryStore, _row_from_map
        with tempfile.TemporaryDirectory() as root:
            ledger = AIConfirmationLedger(str(Path(root)/'reviews.sqlite3'))
            store = MemoryStore()
            store.ensure('Offers', OFFER_HEADERS)
            store.append('Offers', [_row_from_map(OFFER_HEADERS, {'Offer ID': 'offer-'+str(i),
                'Product Name': 'Synthetic', 'Status': 'approved'}) for i in range(650)])
            with patch.object(ledger, 'latest', side_effect=AssertionError('per-offer query')), \
                    patch.object(ledger, 'connect', wraps=ledger.connect) as calls:
                self.assertEqual(review_cases(store, ledger), [])
            self.assertEqual(calls.call_count, 4, 'one source receipt read plus three bounded decision groups')


class StateTransportTests(unittest.TestCase):
    def test_lost_commit_response_retries_the_same_identity(self):
        import requests
        response = Mock(status_code=200)
        response.json.return_value = {'ok': True, 'committed': True, 'replayed': True}
        env = {'SHOP_SITE_URL': 'https://wp.invalid', 'REFERENCE_BRIDGE_TOKEN': 's'*40}
        body = {'store': 'pipeline', 'id': 'a'*32, 'mode': 'commit', 'steps': []}
        with patch.dict(os.environ, env), patch('catalog.state.requests.request', side_effect=[requests.Timeout(), response]) as request, patch('catalog.state.time.sleep'):
            self.assertTrue(state.state_call('transaction', body)['replayed'])
        self.assertEqual(request.call_args_list[0].kwargs['data'], request.call_args_list[1].kwargs['data'])

    def test_lost_database_authority_never_falls_back_to_local_writes(self):
        import requests
        env = {'SHOP_SITE_URL': 'https://wp.invalid', 'REFERENCE_BRIDGE_TOKEN': 's'*40}
        with patch.dict(os.environ, env), patch('catalog.state.requests.request', side_effect=requests.Timeout()), patch('catalog.state.time.sleep'):
            with self.assertRaises(sqlite3.OperationalError):
                state.state_call('transaction', {'id': 'a'*32})


if __name__ == '__main__':
    unittest.main()
