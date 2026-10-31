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
from catalog.extract import quality_check
from catalog.sync import MemoryStore, _process_batch_products, _records, import_product_data


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
        title = 'تن ماهی 24 عددی'
        response = json.dumps([{
            'name': title, 'package_price': 24000, 'package_count': 24,
            'consumer_price': 2000, 'currency': 'TOMAN', 'confidence': 0.97,
        }], ensure_ascii=False)
        session = FakeSession(response)
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'test-key'}, clear=True):
            products = extract_message_products(
                title + '\nقیمت باکس: ۲۴٬۰۰۰ تومان\nقیمت مصرف کننده: ۲٬۰۰۰ تومان',
                '@supplier', session=session)
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
            'name': 'محصول', 'package_price': 50000, 'price_type': 'package',
            'package_count': 6, 'confidence': 0.95,
        }]), 'price 50000', '@supplier')[0]
        self.assertEqual(product['sale_price'], 0)
        self.assertEqual(product['price_type'], 'needs_review')
        self.assertEqual(product['detected_price'], 50000)
        self.assertEqual(product['source_price_amount'], 50000)
        self.assertIsNone(product['package_price'])
        self.assertIsNone(product['consumer_price'])
        self.assertTrue(product['needs_review'])
        self.assertIn('no confirmed unit/package basis', product['review_reason'])

    def test_model_unit_field_without_source_label_stays_in_review(self):
        title = 'محصول 6ع'
        product = parse_message_products(json.dumps([{
            'name': title, 'unit_price': 50000, 'package_count': 6,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title, '@supplier')[0]
        self.assertEqual(product['sale_price'], 0)
        self.assertEqual(product['detected_price'], 50000)
        self.assertEqual(product['price_type'], 'needs_review')
        self.assertTrue(product['needs_review'])
        self.assertIn('no confirmed unit/package basis', product['review_reason'])

    def test_source_unit_label_resolves_model_unit_role(self):
        title = 'محصول نمونه'
        product = parse_message_products(json.dumps([{
            'name': title, 'sale_price': 50000, 'price_type': 'unit',
            'currency': 'IRT', 'confidence': 0.99, 'needs_review': True,
            'review_reason': 'supplier price role is unclear',
        }], ensure_ascii=False), title + '\nقیمت تکی: ۵۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['sale_price'], 50000)
        self.assertEqual(product['price_type'], 'unit')
        self.assertFalse(product['needs_review'])

    def test_single_unlabelled_consumer_field_is_supplier_sale_amount(self):
        title = 'محصول نمونه'
        product = parse_message_products(json.dumps([{
            'name': title, 'consumer_price': 50000,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\n۵۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['detected_price'], 50000)
        self.assertIsNone(product['consumer_price'])
        self.assertEqual(product['sale_price'], 0)
        self.assertEqual(product['price_type'], 'needs_review')
        self.assertTrue(product['needs_review'])

    def test_consumer_label_on_another_product_does_not_change_supplier_amount_role(self):
        title = 'محصول الف'
        content = (
            title + '\n۴۰٬۰۰۰ تومان\n'
            'محصول ب\nقیمت مصرف کننده: ۵۰٬۰۰۰ تومان'
        )
        product = parse_message_products(json.dumps([{
            'name': title, 'consumer_price': 40000,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['detected_price'], 40000)
        self.assertIsNone(product['consumer_price'])
        self.assertEqual(product['price_type'], 'needs_review')
        self.assertTrue(product['needs_review'])

    def test_arabic_digits_are_parsed_and_missing_confidence_requires_review(self):
        title = 'تن ٢٤ عددی'
        product = parse_message_products(json.dumps([{
            'name': title, 'package_price': '٢٤٠٠٠', 'package_count': '٢٤',
        }], ensure_ascii=False), title + '\nقیمت باکس: ٢٤٠٠٠ تومان', '@supplier')[0]
        self.assertEqual(product['sale_price'], 1000)
        self.assertTrue(product['needs_review'])
        self.assertIn('low confidence', product['review_reason'])

    def test_measurements_are_recovered_from_title_and_title_is_kept_exact(self):
        title = 'آبلیمو دست چین1/2ل (304ف)6ع***'
        product = parse_message_products(json.dumps([{
            'name': title, 'package_price': 600000, 'package_count': 6,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\nقیمت باکس: ۶۰۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['volume'], '1.2 لیتر')
        self.assertEqual(product['weight'], '')
        self.assertFalse(product['needs_review'])

    def test_explicit_source_measurement_corrects_model_value_without_review(self):
        title = 'آبلیمو مجید کوچک180گ(138ف)20ع***'
        product = parse_message_products(json.dumps([{
            'name': title, 'weight': '500 گرم',
            'package_price': 600000, 'package_count': 20,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\nقیمت باکس: ۶۰۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['weight'], '180 گرم')
        self.assertEqual(product['volume'], '')
        self.assertFalse(product['needs_review'])

    def test_unique_source_title_discards_model_measurement_absent_from_source(self):
        title = 'آبمیوه نمونه ۲۴۰میل'
        product = parse_message_products(json.dumps([{
            'name': title, 'volume': '500 میلی‌لیتر', 'weight': '200 گرم',
            'unit_price': 50000, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\nقیمت تکی: ۵۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['volume'], '240 میل')
        self.assertEqual(product['weight'], '')
        self.assertFalse(product['needs_review'])

    def test_explicit_piece_count_corrects_model_count_before_unit_price_calculation(self):
        title = 'آبلیمو دست چین1/2ل (304ف)6ع***'
        product = parse_message_products(json.dumps([{
            'name': title, 'volume': '0.5 لیتر', 'package_price': 600000,
            'package_count': 24, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\nقیمت باکس: ۶۰۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['volume'], '1.2 لیتر')
        self.assertEqual(product['package_count'], 6)
        self.assertEqual(product['sale_price'], 100000)
        self.assertFalse(product['needs_review'])

    def test_explicit_piece_count_fills_a_missing_model_count(self):
        title = 'آبلیمو مجید کوچک180گ(138ف)20ع***'
        product = parse_message_products(json.dumps([{
            'name': title, 'package_price': 600000,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\nقیمت باکس: ۶۰۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['package_count'], 20)
        self.assertEqual(product['sale_price'], 30000)
        self.assertFalse(product['needs_review'])

    def test_source_count_survives_offer_and_product_projection(self):
        title = 'آبلیمو دست چین1/2ل (304ف)6ع***'
        generated = parse_message_products(json.dumps([{
            'name': title, 'package_price': 600000, 'package_count': 24,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\nقیمت باکس: ۶۰۰٬۰۰۰ تومان', '@supplier')
        store = MemoryStore()
        with patch.dict(os.environ, {'EXTRACTION_MODE': 'ai'}, clear=True):
            with patch('catalog.sync.extract_message_products', return_value=generated):
                result = import_product_data(store, {
                    'id': 'msg-count', 'batch_id': 'batch-count',
                    'content': title + '\nقیمت باکس: ۶۰۰٬۰۰۰ تومان',
                    'channel_username': '@supplier', 'channel': 'telegram',
                })
        self.assertTrue(result['success'])
        _, offers = _records(store.read('Offers'))
        _, products = _records(store.read('Products'))
        self.assertEqual(offers[0]['Package Count'], 6)
        self.assertEqual(offers[0]['Sale Price'], 100000)
        self.assertEqual(products[0]['Package Count'], 6)
        self.assertEqual(products[0]['Sale Price'], 100000)

    def test_ai_measurement_without_source_title_evidence_stays_in_review(self):
        product = parse_message_products(json.dumps([{
            'name': 'محصول', 'volume': '500 میلی‌لیتر', 'unit_price': 50000,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), 'نام دیگر\nقیمت تکی: ۵۰٬۰۰۰ تومان', '@supplier')[0]
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
        self.assertIn('Missing optional brand, category, description or stock details', prompt)
        self.assertIn('Confidence measures these critical fields only', prompt)
        prompt = ' '.join(prompt.split())
        self.assertIn('All source channels are wholesalers', prompt)
        self.assertIn('single unlabeled amount is the supplier sale amount', prompt)

    def test_normal_zero_or_small_discount_does_not_require_review(self):
        headers = ['Product Name', 'Sale Price']
        for sale, consumer in ((100, 100), (99, 100), (95, 100)):
            with self.subTest(sale=sale, consumer=consumer):
                qa = quality_check({
                    'name': 'محصول نمونه', 'sale_price': sale,
                    'actual_price': consumer, 'confidence': 0.99,
                }, [], headers)
                self.assertFalse(qa['requires_review'])

    def test_sale_price_above_consumer_price_still_requires_review(self):
        qa = quality_check({
            'name': 'محصول نمونه', 'sale_price': 120,
            'actual_price': 100, 'confidence': 0.99,
        }, [], ['Product Name', 'Sale Price'])
        self.assertTrue(qa['requires_review'])
        self.assertIn('Unusual discount', qa['reason'])

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
