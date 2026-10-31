import json
import os
import unittest
from unittest.mock import patch

from catalog.gemini import (
    GeminiError,
    ai_extraction_enabled,
    extract_message_products,
    parse_message_products,
)
from catalog.sync import MemoryStore, _process_batch_products, import_product_data


class FakeResponse:
    status_code = 200

    def __init__(self, text):
        self.text = text

    def json(self):
        return {'candidates': [{'finishReason': 'STOP', 'content': {
            'parts': [{'text': self.text}]}}]}


class FakeSession:
    def __init__(self, text):
        self.response = FakeResponse(text)
        self.call = None

    def post(self, url, **kwargs):
        self.call = (url, kwargs)
        return self.response


class FlakyOffersStore(MemoryStore):
    def __init__(self):
        super().__init__()
        self.failed_once = False

    def append(self, name, rows):
        if name == 'Offers' and not self.failed_once:
            self.failed_once = True
            raise RuntimeError('temporary write failure')
        super().append(name, rows)


class GeminiExtractionTests(unittest.TestCase):
    def test_ai_is_default_and_regex_can_be_selected_explicitly(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertTrue(ai_extraction_enabled())
        with patch.dict(os.environ, {'EXTRACTION_MODE': 'regex'}, clear=True):
            self.assertFalse(ai_extraction_enabled())

    def test_environment_key_and_conservative_price_roles(self):
        response = json.dumps([{
            'name': 'تن ماهی', 'package_price': 24000, 'package_count': 24,
            'consumer_price': 2000, 'currency': 'TOMAN', 'confidence': 0.97,
        }], ensure_ascii=False)
        session = FakeSession(response)
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'test-key'}, clear=True):
            products = extract_message_products('supplier post', '@supplier', session=session)
        product = products[0]
        self.assertEqual(product['sale_price'], 1000)
        self.assertEqual(product['package_price'], 24000)
        self.assertEqual(product['consumer_price'], 2000)
        self.assertEqual(product['currency'], 'IRT')
        self.assertFalse(product['needs_review'])
        url, kwargs = session.call
        self.assertIn('gemini-3.5-flash-lite:generateContent', url)
        self.assertEqual(kwargs['headers']['x-goog-api-key'], 'test-key')
        self.assertNotIn('test-key', url)
        self.assertEqual(kwargs['json']['generationConfig']['responseMimeType'], 'application/json')

    def test_ambiguous_supplier_price_is_held_for_review(self):
        product = parse_message_products(json.dumps([{
            'name': 'محصول', 'sale_price': 50000, 'confidence': 0.95,
        }]), 'price 50000', '@supplier')[0]
        self.assertEqual(product['sale_price'], 0)
        self.assertEqual(product['price_type'], 'needs_review')
        self.assertTrue(product['needs_review'])
        self.assertIn('price role is unclear', product['review_reason'])

    def test_arabic_digits_are_parsed_and_missing_confidence_requires_review(self):
        product = parse_message_products(json.dumps([{
            'name': 'تن', 'package_price': '٢٤٠٠٠', 'package_count': '٢٤',
        }], ensure_ascii=False), 'post', '@supplier')[0]
        self.assertEqual(product['sale_price'], 1000)
        self.assertTrue(product['needs_review'])
        self.assertIn('low confidence', product['review_reason'])

    def test_measurements_are_recovered_from_title_and_title_is_kept_exact(self):
        title = 'آبلیمو دست چین1/2ل (304ف)6ع***'
        product = parse_message_products(json.dumps([{
            'name': title, 'package_price': 600000, 'package_count': 6,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title, '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['volume'], '1.2 لیتر')
        self.assertEqual(product['weight'], '')
        self.assertFalse(product['needs_review'])

    def test_unverified_or_conflicting_ai_measurement_is_replaced_and_flagged(self):
        title = 'آبلیمو مجید کوچک180گ(138ف)20ع***'
        product = parse_message_products(json.dumps([{
            'name': title, 'weight': '500 گرم', 'volume': '1 لیتر',
            'package_price': 600000, 'package_count': 20,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title, '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['weight'], '180 گرم')
        self.assertEqual(product['volume'], '')
        self.assertTrue(product['needs_review'])
        self.assertIn('does not match an explicit measurement', product['review_reason'])

    def test_unique_source_title_restores_size_omitted_by_gemini(self):
        title = 'آبلیمو دست چین1/2ل (304ف)6ع***'
        product = parse_message_products(json.dumps([{
            'name': 'آبلیمو دست چین', 'package_price': 600000,
            'package_count': 6, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\nقیمت باکس: ۶۰۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['volume'], '1.2 لیتر')
        self.assertFalse(product['needs_review'])

    def test_ambiguous_source_titles_are_not_used_to_guess_a_measurement(self):
        content = 'آبلیمو دست چین1/2ل\nآبلیمو دست چین1ل'
        product = parse_message_products(json.dumps([{
            'name': 'آبلیمو دست چین', 'package_price': 600000,
            'package_count': 6, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['name'], 'آبلیمو دست چین')
        self.assertEqual(product['volume'], '')
        self.assertTrue(product['needs_review'])
        self.assertIn('multiple source lines', product['review_reason'])

    def test_api_prompt_specifies_compact_measurement_and_full_title_rules(self):
        session = FakeSession(json.dumps([{
            'name': 'آب 1/2ل', 'package_price': 24000, 'package_count': 6,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False))
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'test-key'}, clear=True):
            extract_message_products('supplier post', '@supplier', session=session)
        prompt = session.call[1]['json']['contents'][0]['parts'][0]['text']
        self.assertIn('exactly as written', prompt.replace('\n', ' '))
        self.assertIn('`گ` means', prompt)
        self.assertIn('`1/2ل` is `1.2 لیتر`', prompt)
        self.assertIn('`ع` labels', prompt)

    def test_bad_output_is_rejected_without_partial_products(self):
        with self.assertRaises(GeminiError):
            parse_message_products('[{"name":"ok"}, null]', 'post', '@supplier')

    def test_enabled_import_preserves_ai_review_reason(self):
        generated = [{
            'name': 'چای', 'sale_price': 10000, 'price': 10000,
            'actual_price': None, 'consumer_price': None, 'price_type': 'unit',
            'currency': 'IRT', 'packaging': '', 'category': 'grocery',
            'variation_type': '', 'description': 'supplier post',
            'stock_status': 'Unknown', 'confidence': 0.96,
            'extraction_confidence': 0.96, 'needs_review': True,
            'review_reason': 'model marked product unclear',
        }]
        store = MemoryStore()
        with patch.dict(os.environ, {'EXTRACTION_MODE': 'ai'}, clear=True):
            with patch('catalog.sync.extract_message_products', return_value=generated):
                with patch('catalog.sync.extract_products') as regex:
                    result = import_product_data(store, {
                        'id': 'm1', 'batch_id': 'b1', 'content': 'supplier post',
                        'channel_username': '@supplier', 'channel': 'telegram',
                    })
        self.assertTrue(result['success'])
        regex.assert_not_called()
        offers = store.read('Offers')
        self.assertEqual(offers[1][offers[0].index('Status')], 'needs_review')
        self.assertIn('model marked product unclear', offers[1][offers[0].index('Review Reason')])

    def test_gemini_error_falls_back_to_existing_regex_path(self):
        regex_product = {
            'name': 'چای', 'sale_price': 10000, 'price': 10000,
            'actual_price': None, 'consumer_price': None, 'confidence': 0.95,
            'extraction_confidence': 0.95, 'stock_status': 'Available',
        }
        store = MemoryStore()
        with patch.dict(os.environ, {'EXTRACTION_MODE': 'ai'}, clear=True):
            with patch('catalog.sync.extract_message_products', side_effect=GeminiError('offline')):
                with patch('catalog.sync.extract_products', return_value=[regex_product]) as regex:
                    result = import_product_data(store, {
                        'id': 'm2', 'batch_id': 'b2', 'content': 'supplier post',
                        'channel_username': '@supplier', 'channel': 'telegram',
                    })
        self.assertTrue(result['success'])
        regex.assert_called_once_with('supplier post', '@supplier')

    def test_message_retry_reuses_extraction_without_second_api_call(self):
        generated = [{
            'name': 'چای', 'sale_price': 10000, 'price': 10000,
            'actual_price': None, 'consumer_price': None, 'price_type': 'unit',
            'currency': 'IRT', 'packaging': '', 'category': 'grocery',
            'variation_type': '', 'description': 'supplier post',
            'stock_status': 'Unknown', 'confidence': 0.96,
            'extraction_confidence': 0.96, 'needs_review': False,
        }]
        store = FlakyOffersStore()
        with patch.dict(os.environ, {'EXTRACTION_MODE': 'ai'}, clear=True):
            with patch('catalog.sync.extract_message_products', return_value=generated) as ai:
                result = _process_batch_products(store, 'b3', [{
                    'id': 'm3', 'content': 'supplier post',
                    'channel_username': '@supplier', 'channel': 'telegram',
                }])
        self.assertEqual(result['status'], 'success')
        ai.assert_called_once_with('supplier post', '@supplier')


if __name__ == '__main__':
    unittest.main()
