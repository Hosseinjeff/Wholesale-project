import base64
import hashlib
import hmac
import json
import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from catalog.durable import Handoffs


def owner_token(secret, actor='wp:7', scope='owner:/pipeline/operations-dashboard', *, expires=None):
    now = int(time.time())
    claims = {'iss': 'molavi-wordpress', 'aud': 'molavi-owner-workflows', 'scope': scope,
              'actor': actor, 'iat': now, 'exp': expires or now + 600}
    payload = base64.urlsafe_b64encode(json.dumps(claims, separators=(',', ':')).encode()).decode().rstrip('=')
    signature = base64.urlsafe_b64encode(hmac.new(secret.encode(), payload.encode(), hashlib.sha256).digest()).decode().rstrip('=')
    return payload + '.' + signature


class OwnerWorkflowBrowserAPITests(unittest.TestCase):
    def setUp(self):
        import app as server
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.db_path = str(Path(self.tmp.name) / 'pipeline.sqlite')
        self.reference_path = str(Path(self.tmp.name) / 'reference.sqlite')
        self.secret = 'synthetic-owner-bridge-token-at-least-32-chars'
        webhook = patch.object(server, 'ensure_telegram_webhook', return_value=None)
        webhook.start()
        self.addCleanup(webhook.stop)

    def test_operations_api_accepts_exact_owner_scope_and_adds_origin_restricted_cors(self):
        import app as server
        queue = Handoffs(self.db_path)
        queue.put('batch', 'owner-review', {'private': 'must not appear'})
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': self.secret}
        origin = 'https://www.molavisale.ir'
        with patch.dict(os.environ, env), patch.object(server, 'handoff_queue', return_value=queue):
            client = server.app.test_client()
            accepted = client.get('/pipeline/operations-dashboard', headers={
                'Authorization': 'Bearer ' + owner_token(self.secret), 'Origin': origin})
            self.assertEqual(accepted.status_code, 200, accepted.get_json())
            self.assertEqual(accepted.headers.get('Access-Control-Allow-Origin'), origin)
            self.assertEqual(accepted.headers.get('Cache-Control'), 'no-store')
            self.assertNotIn('private', accepted.get_data(as_text=True))
            wrong_scope = client.get('/pipeline/operations-dashboard', headers={
                'Authorization': 'Bearer ' + owner_token(self.secret, scope='owner:/reference/item-reviews')})
            self.assertEqual(wrong_scope.status_code, 401)
            denied_origin = client.options('/pipeline/operations-dashboard', headers={
                'Origin': 'https://attacker.example', 'Access-Control-Request-Method': 'POST',
                'Access-Control-Request-Headers': 'authorization,content-type'})
            self.assertIsNone(denied_origin.headers.get('Access-Control-Allow-Origin'))

    def test_ai_confirmation_and_reference_decision_routes_accept_scoped_browser_tokens(self):
        import app as server
        from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS, MESSAGE_HEADERS
        from catalog.sync import MemoryStore
        queue = Handoffs(self.db_path)
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        store.ensure('MessageData', MESSAGE_HEADERS)
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_STORE_PATH': self.reference_path,
               'REFERENCE_OWNER_IDS': 'wp:7', 'REFERENCE_BRIDGE_TOKEN': self.secret}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store):
            client = server.app.test_client()
            ai = client.get('/pipeline/ai-confirmations', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/ai-confirmations')})
            self.assertEqual(ai.status_code, 200, ai.get_json())
            self.assertEqual(ai.get_json()['cases'], [])
            decisions = client.get('/reference/decisions', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/reference/decisions')})
            self.assertEqual(decisions.status_code, 200, decisions.get_json())
            self.assertEqual(decisions.get_json()['events'], [])

    def test_reextraction_start_dispatches_jobs_without_waiting_for_a_new_webhook(self):
        import app as server
        from catalog.extract import MESSAGE_HEADERS, OFFER_HEADERS, PRODUCT_HEADERS
        from catalog.sync import MemoryStore, _row_from_map
        queue = Handoffs(self.db_path)
        store = MemoryStore()
        store.ensure('MessageData', MESSAGE_HEADERS)
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        message = {
            'ID': 'message-1', 'Channel': 'telegram', 'Channel Username': '@supplier',
            'Content': 'چای سبز\nقیمت تکی: ۴۰٬۰۰۰ تومان', 'Batch ID': 'batch-1',
            'AI Status': 'needs_confirmation',
        }
        store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, message)])
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_STORE_PATH': self.reference_path,
               'REFERENCE_OWNER_IDS': 'wp:7', 'REFERENCE_BRIDGE_TOKEN': self.secret,
               'GOOGLE_API_KEY': 'test-api-key'}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store), \
                patch.object(server.threading, 'Thread') as worker:
            response = server.app.test_client().post('/pipeline/ai-confirmations', json={
                'action': 'start_reextraction', 'scope': 'pending', 'expected_count': 1,
            }, headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/ai-confirmations')})
        self.assertEqual(response.status_code, 202, response.get_json())
        self.assertEqual(response.get_json()['queued'], 1)
        worker.assert_called_once()
        self.assertEqual(worker.call_args.kwargs.get('target'), server._drain_reextraction_run)
        worker.return_value.start.assert_called_once()

    def test_open_case_reextraction_queues_each_original_supplier_post_once(self):
        import app as server
        from catalog.ai_confirmations import AIConfirmationLedger
        from catalog.extract import MESSAGE_HEADERS, OFFER_HEADERS, PRODUCT_HEADERS
        from catalog.sync import MemoryStore, _row_from_map
        queue = Handoffs(self.db_path)
        store = MemoryStore()
        store.ensure('MessageData', MESSAGE_HEADERS)
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        message = {
            'ID':'message-1','Channel':'telegram','Channel Username':'@supplier',
            'Content':'Juice mango and peach, price 100000 toman',
            'Timestamp':'2026-11-01T10:00:00Z','URL':'https://t.me/supplier/1',
            'Batch ID':'batch-1','AI Status':'needs_confirmation',
        }
        base_offer = {
            'Product Name':'Juice mango','Channel':'telegram','Channel Username':'@supplier',
            'Sale Price':100000,'Actual Price':150000,'Price Type':'unit','Currency':'IRT',
            'Status':'needs_review','Review Reason':'low confidence','Stock Status':'Unknown',
            'Message ID':'message-1','Batch ID':'batch-1',
            'Message Timestamp':'2026-11-01T10:00:00Z','Source Post URL':'https://t.me/supplier/1',
            'Original Message':message['Content'],
        }
        store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, message)])
        store.append('Offers', [
            _row_from_map(OFFER_HEADERS, {**base_offer,'Offer ID':'batch-1:message-1:0'}),
            _row_from_map(OFFER_HEADERS, {**base_offer,'Offer ID':'batch-1:message-1:1',
                'Product Name':'Juice peach','Variation Type':'Peach'}),
        ])
        env = {'PIPELINE_DB_PATH':self.db_path,'REFERENCE_STORE_PATH':self.reference_path,
               'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':self.secret,
               'GOOGLE_API_KEY':'test-api-key'}
        headers = {'Authorization':'Bearer '+owner_token(self.secret,
            scope='owner:/pipeline/ai-confirmations')}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store), \
                patch.object(server.threading, 'Thread') as worker:
            client=server.app.test_client()
            preview=client.get('/pipeline/ai-confirmations?reextract=preview',headers=headers)
            self.assertEqual(preview.status_code,200,preview.get_json())
            open_cases=preview.get_json()['open_cases']
            self.assertEqual(open_cases['case_count'],2)
            self.assertEqual(open_cases['message_count'],1)
            response=client.post('/pipeline/ai-confirmations',json={
                'action':'start_open_case_reextraction','run_id':'c'*32,
                'expected_case_count':2,'expected_message_count':1,
                'expected_digest':open_cases['digest'],
            },headers=headers)
        self.assertEqual(response.status_code,202,response.get_json())
        self.assertEqual(response.get_json()['queued'],1)
        status=queue.reextraction_status('c'*32)
        self.assertEqual(status['total'],1)
        self.assertEqual(status['scope'],'open_cases')
        self.assertEqual(status['case_count'],2)
        self.assertEqual(status['digest'],open_cases['digest'])
        with queue.connect() as db:
            payload=json.loads(db.execute('SELECT payload FROM jobs WHERE id LIKE ?',
                ('reextract:'+'c'*32+':%',)).fetchone()['payload'])
        self.assertIn(payload['source_offer_id'],
            {'batch-1:message-1:0','batch-1:message-1:1'})
        self.assertEqual(payload['case_count'],2)
        worker.assert_called_once()
        worker.return_value.start.assert_called_once()

    def test_item_review_empty_reference_state_has_complete_response_shape(self):
        import app as server
        queue = Handoffs(self.db_path)
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_STORE_PATH': self.reference_path,
               'REFERENCE_OWNER_IDS': 'wp:7', 'REFERENCE_BRIDGE_TOKEN': self.secret}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue):
            client = server.app.test_client()
            response = client.get('/reference/item-reviews', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/reference/item-reviews')})
        self.assertEqual(response.status_code, 200, response.get_json())
        self.assertEqual(response.get_json()['cases'], [])
        self.assertEqual(response.get_json()['recent_decisions'], [])
        self.assertFalse(response.get_json()['reference_state']['allow_automatic'])

    def test_publication_hold_and_supplier_freshness_accept_only_their_route_scopes(self):
        import app as server
        from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS
        from catalog.sync import MemoryStore
        queue = Handoffs(self.db_path)
        queue.hold_publication('batch-review', 'batch_unverified')
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': self.secret}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store):
            client = server.app.test_client()
            hold = client.get('/pipeline/publication-holds', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/publication-holds')})
            self.assertEqual(hold.status_code, 200, hold.get_json())
            self.assertEqual(hold.get_json()['holds'][0]['hold_id'], 'batch-review')
            wrong = client.get('/pipeline/publication-holds', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/supplier-price-evidence')})
            self.assertEqual(wrong.status_code, 401)
            freshness = client.get('/pipeline/supplier-price-evidence', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/supplier-price-evidence')})
            self.assertEqual(freshness.status_code, 200, freshness.get_json())
            self.assertEqual(freshness.get_json()['offers'], [])

    def test_sheet_conflict_screen_accepts_only_its_browser_scope(self):
        import app as server
        queue = Handoffs(self.db_path)
        queue.record_sheet_conflict({'conflict_id': 'sheet:review-1', 'product_id': 'product-1',
            'product_name': 'Synthetic product', 'channel': '@supplier', 'field': 'Sale Price',
            'source_revision': 'revision-1', 'base': 1, 'server': 2, 'sheet': 3})
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': self.secret}
        origin = 'https://www.molavisale.ir'
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue):
            client = server.app.test_client()
            wrong = client.get('/pipeline/sheet-conflicts', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/operations-dashboard')})
            self.assertEqual(wrong.status_code, 401)
            accepted = client.get('/pipeline/sheet-conflicts', headers={
                'Authorization': 'Bearer ' + owner_token(self.secret, scope='owner:/pipeline/sheet-conflicts'),
                'Origin': origin})
        self.assertEqual(accepted.status_code, 200, accepted.get_json())
        self.assertEqual(accepted.headers.get('Access-Control-Allow-Origin'), origin)
        self.assertEqual(accepted.get_json()['conflicts'][0]['conflict_id'], 'sheet:review-1')
        self.assertFalse(accepted.get_json()['conflicts'][0]['server_owned'])
        self.assertFalse(accepted.get_json()['conflicts'][0]['sheet_preserved'])


if __name__ == '__main__':
    unittest.main()
