"""Exercise the three executor responses consumed by the WordPress runtime."""
import os
import unittest
from unittest.mock import patch

import app
from catalog.google_quota import QuotaDeferred


class WordPressTransportTests(unittest.TestCase):
    def setUp(self):
        token = 'x' * 40
        environment = patch.dict(os.environ, {'REFERENCE_BRIDGE_TOKEN': token, 'REFERENCE_OWNER_IDS': 'wp:7'})
        environment.start()
        self.addCleanup(environment.stop)
        self.client = app.app.test_client()
        self.headers = {'X-Molavi-Actor': 'wp:7', 'X-Molavi-Reference-Token': token}

    def post(self, route, body):
        return self.client.post('/pipeline/' + route, json=body, headers=self.headers)

    def test_verdict_and_extraction_responses(self):
        with patch('catalog.gemini.verdict_generate', return_value={'verdict': 'flag', 'reason': 'Review'}) as verdict:
            result = self.post('verdict', {'prompt': 'sample'})
            verdict.assert_called_once_with('sample', model=None)
            self.assertEqual(result.json, {'ok': True, 'verdict': 'flag', 'reason': 'Review'})
        with patch('catalog.google_quota.configured_router', return_value=None), \
                patch('catalog.gemini.extract_generate', side_effect=['[]', QuotaDeferred(2000)]):
            self.assertEqual(self.post('extract', {'prompt': 'sample'}).json, {'ok': True, 'text': '[]', 'cached': False})
            retry = self.post('extract', {'prompt': 'sample'})
            self.assertEqual(retry.status_code, 200)
            self.assertEqual(retry.json, {'ok': False, 'error': 'extract_deferred', 'retry_at': 2000})

    def test_telegram_post_returns_visible_text_and_original_status(self):
        url = 'https://t.me/sample_channel/15'
        page = '<div class="tgme_widget_message_text">Milk &amp;amp; tea<br>12 units</div>'
        with patch.object(app, 'fetch_telegram_page', side_effect=[(200, page), (404, '')]) as fetch:
            self.assertEqual(self.post('telegram-fetch', {'url': url}).json,
                             {'ok': True, 'status': 200, 'text': 'Milk &amp; tea\n12 units'})
            fetch.assert_called_once_with(url)
            self.assertEqual(self.post('telegram-fetch', {'url': url}).json, {'ok': True, 'status': 404, 'text': None})
