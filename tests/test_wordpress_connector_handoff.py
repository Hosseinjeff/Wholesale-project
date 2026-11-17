"""The one-time credential handoff stays between the two configured hosts."""
import hashlib
import hmac
import os
import unittest
from unittest.mock import Mock, patch

from catalog.runtime_host import configure_wordpress_connectors


class ConnectorHandoffTests(unittest.TestCase):
    def setUp(self):
        self.environment = patch.dict(os.environ, {
            'SHOP_SITE_URL': 'https://staging.molavisale.ir',
            'REFERENCE_BRIDGE_TOKEN': 'synthetic-' * 5,
            'GOOGLE_SHEET_ID': 'synthetic-sheet-id',
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)
        self.account = {'type': 'service_account', 'private_key': 'SYNTHETIC KEY'}

    def response(self, **overrides):
        data = {'ok': True, 'configured': ['google_sheets'], 'configuration_digest': 'a' * 64}
        data.update(overrides)
        response = Mock()
        response.json.return_value = data
        return response

    def test_credentials_go_directly_to_the_site_and_never_into_the_receipt(self):
        with patch('catalog.sync.load_service_account_info', return_value=self.account), \
                patch('catalog.runtime_host.requests.post', return_value=self.response(private_key='SYNTHETIC LEAK')) as post:
            receipt = configure_wordpress_connectors()
        self.assertEqual(post.call_args.args[0], 'https://staging.molavisale.ir/wp-json/molavi-workflow/v1/connectors')
        self.assertEqual(post.call_args.kwargs['json']['google_service_account'], self.account)
        expected = hmac.new(('synthetic-' * 5).encode(), b'molavi-connectors-v1', hashlib.sha256).hexdigest()
        self.assertEqual(post.call_args.kwargs['headers']['X-Molavi-Connector-Token'], expected)
        self.assertEqual(set(receipt), {'ok', 'configured', 'configuration_digest'})
        self.assertNotIn('SYNTHETIC', str(receipt))

    def test_unexpected_destination_cannot_receive_credentials(self):
        for site in ['http://molavisale.ir', 'https://example.test', 'https://molavisale.ir/other',
                     'https://molavisale.ir?destination=other', 'https://user@molavisale.ir', 'https://molavisale.ir:8443']:
            with self.subTest(site=site), patch.dict(os.environ, {'SHOP_SITE_URL': site}), \
                    patch('catalog.runtime_host.requests.post') as post:
                with self.assertRaises(ValueError):
                    configure_wordpress_connectors()
                post.assert_not_called()

    def test_an_incomplete_receipt_does_not_report_completion(self):
        for fields in [{'ok': False}, {'configured': []}, {'configuration_digest': ''}]:
            with self.subTest(fields=fields), patch('catalog.sync.load_service_account_info', return_value=self.account), \
                    patch('catalog.runtime_host.requests.post', return_value=self.response(**fields)):
                with self.assertRaises(ValueError):
                    configure_wordpress_connectors()


if __name__ == '__main__':
    unittest.main()
