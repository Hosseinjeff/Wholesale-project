import os
from unittest import TestCase
from unittest.mock import Mock, patch

from app import app


class WordPressWorkflowRelayTests(TestCase):
    def setUp(self):
        self.client = app.test_client()
        self.bridge = 'synthetic-bridge-credential-for-tests-only'
        configured = patch.dict(os.environ, {
            'PIPELINE_STATE_HOST': 'wordpress',
            'SHOP_SITE_URL': 'https://staging.molavisale.ir',
            'REFERENCE_BRIDGE_TOKEN': self.bridge,
            'REFERENCE_OWNER_IDS': 'wp:7',
            'SHOP_NOTIFY_SECRET': 'synthetic-shop-secret',
            'PIPELINE_RUNTIME_MODE': 'on_demand',
        })
        configured.start()
        self.addCleanup(configured.stop)
        self.headers = {'X-Molavi-Reference-Token': self.bridge, 'X-Molavi-Actor': 'wp:7'}

    def test_owner_review_preserves_scope_status_and_stays_on_wordpress(self):
        receipt = {'ok': False, 'error': 'stale_case'}
        wp = Mock(status_code=409, headers={'X-Molavi-Executor': 'wordpress'})
        wp.json.return_value = receipt
        with patch('catalog.wordpress_workflows.requests.post', return_value=wp) as relay:
            result = self.client.post('/pipeline/ai-confirmations', json={'choice': 'confirm'}, headers=self.headers)
        self.assertEqual((result.status_code, result.json), (409, receipt))
        self.assertEqual(result.headers['X-Molavi-Executor'], 'wordpress')
        self.assertEqual(relay.call_args.args, ('https://staging.molavisale.ir/wp-json/molavi-workflow/v1/request',))
        self.assertEqual(relay.call_args.kwargs['json']['actor'], 'wp:7')
        self.assertFalse(relay.call_args.kwargs['allow_redirects'])

    def test_relay_does_not_grant_access_or_fall_back_to_legacy_processing(self):
        wp = Mock(status_code=200, headers={})
        wp.json.return_value = {'ok': True}
        with patch('catalog.wordpress_workflows.requests.post', return_value=wp) as relay:
            self.assertEqual(self.client.get('/pipeline/ai-confirmations').status_code, 401)
            self.assertEqual(self.client.post('/shop/order', json={}).status_code, 401)
            self.assertEqual(self.client.post('/desk/refresh').status_code, 410)
            relay.assert_not_called()
            response = self.client.get('/api/shop')
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json['error'], 'wordpress_workflow_unavailable')
