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


if __name__ == '__main__':
    unittest.main()
