import json
import os
import unittest
from unittest.mock import patch

from catalog.gemini import (
    GeminiError,
    ai_extraction_configured,
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


class SequenceSession:
    def __init__(self, texts):
        self.responses = [FakeResponse(text) for text in texts]
        self.calls = []

    def post(self, url, **kwargs):
        self.calls.append((url, kwargs))
        return self.responses.pop(0)


class FailureResponse:
    status_code = 429

    def json(self):
        return {'error': {'message': 'sensitive provider response detail'}}


class FailureSession:
    def post(self, url, **kwargs):
        return FailureResponse()


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
    def test_invalid_json_response_gets_one_constrained_retry(self):
        valid = json.dumps([{
            'name': 'چای', 'sale_price': 10000, 'price_type': 'unit',
            'currency': 'IRT', 'confidence': 0.96,
        }], ensure_ascii=False)
        session = SequenceSession(['not-json', valid])
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'test-key'}, clear=True):
            products = extract_message_products('چای\nهر کیلو: ۱۰/۰۰۰ تومان',
                                                '@supplier', session=session)
        self.assertEqual(len(session.calls), 2)
        self.assertEqual(products[0]['name'], 'چای')
        retry_prompt = session.calls[1][1]['json']['contents'][0]['parts'][0]['text']
        self.assertIn('previous response was not valid JSON', retry_prompt)

    def test_invalid_json_after_retry_has_bounded_diagnostic(self):
        session = SequenceSession(['not-json', 'still-not-json'])
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'test-key'}, clear=True):
            with self.assertRaisesRegex(GeminiError, 'after one retry'):
                extract_message_products('supplier post', '@supplier', session=session)
        self.assertEqual(len(session.calls), 2)

    def test_provider_http_failure_exposes_status_without_response_body(self):
        with patch.dict(os.environ, {'GOOGLE_API_KEY':'test-key'}, clear=True):
            with self.assertRaisesRegex(GeminiError, 'HTTP 429') as raised:
                extract_message_products('supplier post', '@supplier', session=FailureSession())
        self.assertNotIn('sensitive provider response detail', str(raised.exception))

    def test_ai_extraction_requires_key_and_ignores_mode(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertFalse(ai_extraction_configured())
        with patch.dict(os.environ, {
            'EXTRACTION_MODE': 'regex', 'GOOGLE_API_KEY': 'test-key',
        }, clear=True):
            self.assertTrue(ai_extraction_configured())

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

    def test_variant_suffix_uses_base_title_price_context_and_keeps_both_prices(self):
        base = 'نودل لیوانی شف هو،۱۲ عددی'
        title = base + ' سبزیجات'
        content = (
            base + '\n'
            'تعداد در کارتن: ۱۲ عدد\n'
            'قیمت فروش ما: ۷۵/۰۰۰ تومان\n'
            'قیمت مصرف کننده: ۱۳۰/۰۰۰ تومان'
        )
        product = parse_message_products(json.dumps([{
            'name': title, 'variation_type': 'سبزیجات', 'package_count': 12,
            'currency': 'IRT', 'confidence': 0.70, 'needs_review': True,
            'review_reason': 'supplier unit price is missing',
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['variation_type'], 'سبزیجات')
        self.assertEqual(product['sale_price'], 75000)
        self.assertEqual(product['detected_price'], 75000)
        self.assertEqual(product['actual_price'], 130000)
        self.assertEqual(product['price_type'], 'unit')
        self.assertEqual(product['confidence'], 0.95)
        self.assertFalse(product['needs_review'])

    def test_bare_variant_list_keeps_shared_price_evidence_and_expands_products(self):
        base = 'نودل لیوانی شف هو،۱۲ عددی'
        title = base + ' سبزیجات'
        content = (
            base + '\n'
            'گوشت،مرغ،فلفلی،قارچ پنیر،سبزیجات\n'
            'قیمت فروش ما: ۷۵/۰۰۰ تومان\n'
            'قیمت مصرف کننده: ۱۳۰/۰۰۰ تومان'
        )
        products = parse_message_products(json.dumps([{
            'name': title, 'variant': 'سبزیجات', 'package_count': 12,
            'confidence': 0.70, 'needs_review': True,
            'review_reason': 'supplier unit price is missing',
        }], ensure_ascii=False), content, '@supplier')

        self.assertEqual([product['variation_type'] for product in products], [
            'گوشت', 'مرغ', 'فلفلی', 'قارچ پنیر', 'سبزیجات',
        ])
        for product in products:
            with self.subTest(variant=product['variation_type']):
                self.assertEqual(product['name'], base + ' ' + product['variation_type'])
                self.assertEqual(product['sale_price'], 75000)
                self.assertEqual(product['actual_price'], 130000)
                self.assertEqual(product['package_count'], 12)
                self.assertEqual(product['price_type'], 'unit')
                self.assertFalse(product['needs_review'])

    def test_parenthetical_color_variants_expand_and_keep_scoped_consumer_prices(self):
        title = 'کیکس مکس قوطی در ۳ طعم(زرد و آبی و بنفش)'
        content = (
            title + '\nباکس :۲۴عددی\n'
            'بنفش با قیمت مصرف: ۱۴۵/۰۰۰تومن\n'
            'آبی و زرد بدون قیمت مصرف'
        )
        products = parse_message_products(json.dumps([{
            'name': title, 'variation_type': '(زرد و آبی و بنفش) زرد',
            'package_count': 24, 'unit_price': 66250, 'consumer_price': 145000,
            'currency': 'IRT', 'confidence': 0.98,
        }], ensure_ascii=False), content, '@supplier')

        self.assertEqual([row['variation_type'] for row in products], ['زرد', 'آبی', 'بنفش'])
        self.assertEqual([row['name'] for row in products], [
            'کیکس مکس قوطی طعم زرد', 'کیکس مکس قوطی طعم آبی', 'کیکس مکس قوطی طعم بنفش',
        ])
        self.assertEqual([row['consumer_price'] for row in products], [None, None, 145000])
        self.assertEqual([row['package_count'] for row in products], [24, 24, 24])
        self.assertTrue(all(row['needs_review'] for row in products),
                        'the carton count label is not a supplier price')

    def test_variant_specific_supplier_and_consumer_prices_are_not_shared(self):
        title = 'آبمیوه سون ۲۲۰ میلی لیتر'
        content = (
            title + '\nطعم‌ها: انبه،هلو\n'
            'انبه قیمت فروش ما: ۱۸/۵۰۰ تومان\n'
            'هلو قیمت فروش ما: ۲۰/۰۰۰ تومان\n'
            'انبه قیمت مصرف کننده: ۵۰/۰۰۰ تومان\n'
            'هلو قیمت مصرف کننده: ۵۵/۰۰۰ تومان'
        )
        products = parse_message_products(json.dumps([{
            'name': title, 'variation_type': 'طعم انبه', 'unit_price': 18500,
            'consumer_price': 50000, 'package_count': 36,
            'currency': 'IRT', 'confidence': 0.98,
        }], ensure_ascii=False), content, '@supplier')

        self.assertEqual([row['variation_type'] for row in products], ['انبه', 'هلو'])
        self.assertEqual([row['sale_price'] for row in products], [18500, 20000])
        self.assertEqual([row['consumer_price'] for row in products], [50000, 55000])
        self.assertTrue(all(not row['needs_review'] for row in products))

    def test_available_prefix_is_not_part_of_expanded_variant(self):
        title = 'آبمیوه شیشه ای موکوکوکو(تیکه نارگیل)'
        content = (
            title + '\nطعم های موجود : بلوبری،هندوانه،نارگیل،پرتقال،طالبی،توت فرنگی و انبه\n'
            'کارتن ۱۲ عددی\nقیمت فروش ما: ۵۵/۰۰۰ تومان\n'
            'قیمت مصرف کننده: ۱۰۰/۰۰۰ تومان'
        )
        products = parse_message_products(json.dumps([{
            'name': title + ' بلوبری', 'variation_type': 'موجود : بلوبری',
            'unit_price': 55000, 'consumer_price': 100000, 'package_count': 12,
            'currency': 'IRT', 'confidence': 0.98,
        }], ensure_ascii=False), content, '@supplier')

        self.assertEqual([row['variation_type'] for row in products], [
            'بلوبری', 'هندوانه', 'نارگیل', 'پرتقال', 'طالبی', 'توت فرنگی', 'انبه',
        ])
        self.assertTrue(all('موجود' not in row['name'] for row in products))
        self.assertTrue(all(row['sale_price'] == 55000 for row in products))
        self.assertTrue(all(row['consumer_price'] == 100000 for row in products))

    def test_bare_variant_line_expands_even_when_model_omits_variant(self):
        title = 'آبمیوه سون ۲۲۰ میلی لیتر'
        content = (
            title + '\nانبه،هلو\nتعداد در کارتن ۳۶ عدد\n'
            'قیمت فروش ما: ۱۸/۵۰۰ تومان\nقیمت مصرف کننده: ۵۰/۰۰۰ تومان'
        )
        products = parse_message_products(json.dumps([{
            'name': title, 'unit_price': 18500, 'consumer_price': 50000,
            'package_count': 36, 'currency': 'IRT', 'confidence': 0.98,
        }], ensure_ascii=False), content, '@supplier')

        self.assertEqual([row['variation_type'] for row in products], ['انبه', 'هلو'])
        self.assertEqual([row['name'] for row in products], [title + ' انبه', title + ' هلو'])
        self.assertTrue(all(row['sale_price'] == 18500 for row in products))
        self.assertTrue(all(row['consumer_price'] == 50000 for row in products))

    def test_flavor_suffix_with_taam_marker_uses_unique_base_title(self):
        base = 'آبمیوه تتراپک سون،۲۲۰ میلی لیتر'
        title = base + ' طعم هلو'
        content = (
            base + '\n'
            'طعم های: انبه،آناناس،سیب موز،انار،آلبالو،پرتقال و هلو\n'
            'تعداد در کارتن ۳۶ عدد\n'
            'قیمت فروش ما: ۱۸/۵۰۰ تومان\n'
            'قیمت مصرف کننده: ۵۰/۰۰۰ تومان'
        )
        products = parse_message_products(json.dumps([{
            'name': title, 'brand': 'سون', 'variant': 'هلو', 'package_count': 36,
            'unit_price': 18500, 'consumer_price': 50000, 'currency': 'IRT',
            'confidence': 0.97,
        }], ensure_ascii=False), content, '@supplier')
        self.assertEqual(len(products), 7)
        product = next(row for row in products if row['variation_type'] == 'هلو')
        self.assertEqual(product['name'], title)
        self.assertEqual(product['variation_type'], 'هلو')
        self.assertEqual(product['volume'], '220 میل')
        self.assertEqual(product['package_count'], 36)
        self.assertEqual(product['sale_price'], 18500)
        self.assertEqual(product['consumer_price'], 50000)
        self.assertFalse(product['needs_review'])
        self.assertEqual(len({row['name'] for row in products}), 7)

    def test_package_label_with_count_before_colon_preserves_explicit_unit_and_box_prices(self):
        base = 'بلک ولف شیشه ای'
        title = base + ' طعم انار'
        content = (
            base + '\nتعداد در کارتن ۲۴ عدد\n'
            'قیمت هر باکس ۲۴: ۷۴۰٬۰۰۰ تومان\n'
            'دونه ای: ۳۰٬۸۳۳ تومان'
        )
        product = parse_message_products(json.dumps([{
            'name': title, 'variant': 'انار', 'package_count': 24,
            'package_price': 24, 'unit_price': 30833, 'sale_price': 740000,
            'currency': 'IRT', 'confidence': 0.96,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['sale_price'], 30833)
        self.assertEqual(product['detected_price'], 30833)
        self.assertEqual(product['source_price_amount'], 30833)
        self.assertEqual(product['package_price'], 740000)
        self.assertEqual(product['package_count'], 24)
        self.assertFalse(product['needs_review'])

    def test_package_price_comes_from_source_when_model_confuses_box_count_for_amount(self):
        title = 'چای نمونه'
        content = (
            title + '\nدر باکس ۱۲تایی\n'
            'قیمت هر باکس ۱۲تایی: ۵۷۶٫۰۰۰ تومان\n'
            'دونه ای: ۴۸٫۰۰۰ تومان'
        )
        product = parse_message_products(json.dumps([{
            'name': title, 'package_count': 12, 'package_price': 12,
            'unit_price': 48000, 'currency': 'IRT', 'confidence': 0.96,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['sale_price'], 48000)
        self.assertEqual(product['package_price'], 576000)
        self.assertEqual(product['package_count'], 12)
        self.assertFalse(product['needs_review'])

    def test_emoji_prefixed_latin_title_is_a_product_not_an_empty_listing(self):
        title = '🍄🌹🍁🐚: Big bear'
        content = (
            title + '\nدر باکس ۲۴تایی: ۷۴۰٫۰۰۰تومن\n'
            'دونه ای: ۳۰٫۸۳۳تومن\nدر ۵ طعم متفاوت'
        )
        session = FakeSession(json.dumps([{
            'name': title, 'package_count': 24, 'package_price': 24,
            'unit_price': 30833, 'currency': 'IRT', 'confidence': 0.96,
        }], ensure_ascii=False))
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'test-key'}, clear=True):
            products = extract_message_products(content, '@supplier', session=session)
        self.assertEqual(len(products), 1)
        self.assertEqual(products[0]['name'], title)
        self.assertEqual(products[0]['variation_type'], '')
        self.assertEqual(products[0]['package_count'], 24)
        self.assertEqual(products[0]['sale_price'], 30833)
        self.assertEqual(products[0]['package_price'], 740000)
        self.assertFalse(products[0]['needs_review'])
        prompt = session.call[1]['json']['contents'][0]['parts'][0]['text']
        self.assertIn('Emojis are decorative', prompt)
        self.assertIn('Return one product with the exact first line as its name', prompt)

    def test_missing_optional_consumer_price_does_not_keep_confirmed_supplier_price_queued(self):
        title = 'بلک ولف شیشه ای طعم انار'
        content = 'بلک ولف شیشه ای\nدونه ای: ۲۳٬۰۰۰ تومان'
        product = parse_message_products(json.dumps([{
            'name': title, 'variant': 'انار', 'unit_price': 23000,
            'currency': 'IRT', 'confidence': 0.70, 'needs_review': True,
            'review_reason': 'consumer price missing',
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['sale_price'], 23000)
        self.assertIsNone(product['consumer_price'])
        self.assertEqual(product['confidence'], 0.95)
        self.assertFalse(product['needs_review'])
        self.assertEqual(product['review_reason'], '')

    def test_prompt_explains_source_association_and_optional_consumer_price(self):
        title = 'چای نمونه'
        session = FakeSession(json.dumps([{
            'name': title, 'unit_price': 1000, 'currency': 'IRT', 'confidence': 0.96,
        }], ensure_ascii=False))
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'test-key'}, clear=True):
            extract_message_products(title + '\nدونه ای: ۱٬۰۰۰ تومان', '@supplier', session=session)
        prompt = session.call[1]['json']['contents'][0]['parts'][0]['text']
        self.assertIn('Source-line association and reference-catalog matching are two different tasks', prompt)
        self.assertIn('A missing consumer/retail price is optional', prompt)
        self.assertIn('قیمت هر باکس ۲۴: ۷۴۰/۰۰۰ تومان', prompt)
        self.assertIn('محصول نمونه در ۳ طعم', prompt)
        self.assertIn('do not derive or invent a supplier amount', prompt)
        self.assertIn('اتمام موجودی', prompt)

    def test_unit_and_consumer_labels_after_pack_count_are_scoped_to_product(self):
        title = 'تی بگ چای شهرزاد کاوردار'
        content = (
            title + ':\n\n✅درکارتن ۲۴تایی\n\n'
            '✅️دونه ای: ۱۲۰/۰۰۰تومن\n✅قیمت مصرف: ۲۰۰/۰۰۰تومن\n\nآدرس و خرید حضوری'
        )
        product = parse_message_products(json.dumps([{
            'name': title, 'unit_price': 120000, 'consumer_price': 200000,
            'package_count': 24, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['sale_price'], 120000)
        self.assertEqual(product['consumer_price'], 200000)
        self.assertEqual(product['price_type'], 'unit')
        self.assertFalse(product['needs_review'])

    def test_inline_source_package_label_resolves_product_title_and_price_basis(self):
        title = 'محصول نمونه 6ع'
        product = parse_message_products(json.dumps([{
            'name': title, 'package_price': 60000, 'package_count': 6,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + ' | قیمت باکس: ۶۰٬۰۰۰ تومان', '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['sale_price'], 10000)
        self.assertEqual(product['package_price'], 60000)
        self.assertFalse(product['needs_review'])

    def test_unit_and_package_labels_match_their_amounts_and_drop_unlabeled_consumer_field(self):
        title = 'موکو کوکو با تیکه نارگیل'
        content = (
            title + ':\n\n✅در باکس ۲۴تایی\n\n'
            '✅️دونه ای: ۴۱/۰۰۰تومن\n✅قیمت باکس: ۹۸۴/۰۰۰تومن'
        )
        product = parse_message_products(json.dumps([{
            'name': title, 'unit_price': 41000, 'package_price': 984000,
            'consumer_price': 41000, 'package_count': 24,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['sale_price'], 41000)
        self.assertEqual(product['package_price'], 984000)
        self.assertIsNone(product['consumer_price'])
        self.assertFalse(product['needs_review'])

    def test_each_package_label_and_short_consumer_label_are_explicit(self):
        title = 'چای شهرزاد ۴۰۰گرمی'
        content = (
            title + ':\n✅در کارتن ۱۰تایی\n'
            '✅قیمت هر یک بسته: ۶۲۰/۰۰۰تومن\n✅قیمت مصرف: ۹۴۰/۰۰۰تومن'
        )
        product = parse_message_products(json.dumps([{
            'name': title, 'package_price': 620000, 'package_count': 10,
            'consumer_price': 940000, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['sale_price'], 62000)
        self.assertEqual(product['package_price'], 620000)
        self.assertEqual(product['consumer_price'], 940000)
        self.assertFalse(product['needs_review'])

    def test_spelled_out_measurement_in_source_title_corrects_model(self):
        title = 'زعفران یک گرم بهمنان'
        product = parse_message_products(json.dumps([{
            'name': title, 'weight': '500 گرم', 'unit_price': 375000,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + ':\nدونه ای: ۳۷۵/۰۰۰تومن', '@supplier')[0]
        self.assertEqual(product['weight'], '1 گرم')
        self.assertEqual(product['sale_price'], 375000)
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

    def test_same_consumer_amount_on_another_product_is_not_attributed(self):
        first, second = 'محصول الف', 'محصول ب'
        content = (
            first + '\nدونه ای: ۴۰٬۰۰۰ تومان\nقیمت مصرف: ۵۰٬۰۰۰ تومان\n'
            + second + '\nقیمت مصرف: ۴۰٬۰۰۰ تومان'
        )
        products = parse_message_products(json.dumps([
            {'name': first, 'unit_price': 40000, 'consumer_price': 40000,
             'currency': 'IRT', 'confidence': 0.99},
            {'name': second, 'consumer_price': 40000,
             'currency': 'IRT', 'confidence': 0.99},
        ], ensure_ascii=False), content, '@supplier')
        self.assertEqual(products[0]['consumer_price'], 50000)
        self.assertEqual(products[0]['sale_price'], 40000)
        self.assertEqual(products[1]['consumer_price'], 40000)

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

    def test_peach_tetrapak_title_is_structured_without_treating_volume_as_price(self):
        title = 'آبمیوه تتراپک سون،۲۲۰ میلی لیتر طعم هلو'
        product = parse_message_products(json.dumps([{
            'name': title, 'brand': 'سون', 'variant': '', 'packaging': '',
            'sale_price': 220, 'consumer_price': None, 'unit_price': 220,
            'package_price': None, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title, '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['brand'], 'سون')
        self.assertEqual(product['variation_type'], 'هلو')
        self.assertEqual(product['volume'], '220 میل')
        self.assertEqual(product['packaging'], 'تتراپک')
        self.assertEqual(product['sale_price'], 0)
        self.assertEqual(product['detected_price'], 0)
        self.assertTrue(product['needs_review'])
        self.assertIn('supplier unit price is missing', product['review_reason'])

        priced = parse_message_products(json.dumps([{
            'name': title, 'unit_price': 220, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), title + '\nقیمت تکی: ۲۲۰ تومان', '@supplier')[0]
        self.assertEqual(priced['sale_price'], 220)
        self.assertEqual(priced['price_type'], 'unit')
        self.assertFalse(priced['needs_review'])

    def test_full_son_juice_post_recovers_labeled_prices_carton_count_and_flavors(self):
        title = 'آبمیوه تتراپک سون،۲۲۰ میلی لیتر'
        content = '''آبمیوه تتراپک سون،۲۲۰ میلی لیتر

طعم های: انبه ،آناناس،سیب موز،انار،آلبالو،پرتقال و هلو

تعداد در کارتن ۳۶ عدد

قیمت فروش ما : ۱۸/۵۰۰ تومان

قیمت مصرف کننده ۵۰/۰۰۰ تومان

ثبت سفارش از طريق لينك زير

👇👇👇👇👇👇👇👇👇👇👇

پاسخگو ۱👇
[https://wa.me/message/4TDVRFCKRSV3N1](https://wa.me/message/4TDVRFCKRSV3N1)

پاسخگو۲👇
[https://wa.me/message/GJFXKCGPRLGRL1](https://wa.me/message/GJFXKCGPRLGRL1)

آدرس :تهران میدان محمدیه ( اعدام )اول خیابان خیام جنوبی پاساژ برلیان طبقه همکف پلاک۲۱
۰۲۱-۵۵۸۱۵۷۳۲—۰۲۱-۵۵۸۱۵۷۳۳'''
        # Simulate the failure mode: Gemini returns the title but misses all of the
        # obvious labeled values and says it cannot determine the supplier price.
        products = parse_message_products(json.dumps([{
            'name': title, 'brand': 'سون', 'confidence': 0.6,
            'needs_review': True, 'review_reason': 'supplier unit price is missing',
        }], ensure_ascii=False), content, '@supplier')

        self.assertEqual(len(products), 7)
        self.assertEqual([p['variation_type'] for p in products], [
            'انبه', 'آناناس', 'سیب موز', 'انار', 'آلبالو', 'پرتقال', 'هلو',
        ])
        for product in products:
            with self.subTest(variant=product['variation_type']):
                self.assertEqual(product['name'], title + ' طعم ' + product['variation_type'])
                self.assertEqual(product['brand'], 'سون')
                self.assertEqual(product['sale_price'], 18500)
                self.assertEqual(product['consumer_price'], 50000)
                self.assertEqual(product['actual_price'], 50000)
                self.assertEqual(product['price_type'], 'unit')
                self.assertEqual(product['detected_price'], 18500)
                self.assertEqual(product['source_price_amount'], 18500)
                self.assertEqual(product['package_count'], 36)
                self.assertEqual(product['volume'], '220 میل')
                self.assertEqual(product['packaging'], 'تتراپک')
                self.assertFalse(product['needs_review'])
                self.assertGreaterEqual(product['confidence'], 0.85)

        # Re-extraction stages owner-review offers; verify every flavor retains
        # the same recovered prices and carton count in the durable offer shape.
        store = MemoryStore()
        imported = import_product_data(store, {
            'id': 'son-juice-message', 'batch_id': 'son-juice-batch',
            'channel_username': '@supplier', 'content': content,
        }, prepared_products=products, stage_for_review=True)
        _, offers = _records(store.read('Offers'))
        self.assertEqual(imported['products_found'], 7)
        self.assertEqual([offer['Variation Type'] for offer in offers], [
            'انبه', 'آناناس', 'سیب موز', 'انار', 'آلبالو', 'پرتقال', 'هلو',
        ])
        self.assertTrue(all(offer['Sale Price'] == 18500 for offer in offers))
        self.assertTrue(all(offer['Consumer Price'] == 50000 for offer in offers))
        self.assertTrue(all(offer['Package Count'] == 36 for offer in offers))

    def test_per_kilo_labels_and_source_backed_measurements_are_preserved(self):
        examples = (
            ('برنج ایرانی', 'کیسه های ۱۰ کیلوگرمی', 'کیلویی', 450000, '10 کیلوگرم'),
            ('لواشک پذیرایی به بین', 'بسته های یک کیلویی', 'هر کیلو', 250000, '1 کیلوگرم'),
        )
        for title, package_line, label, price, weight in examples:
            with self.subTest(title=title, label=label):
                content = f'{title}\n{package_line}\nقیمت فروش ما: {label} {price:,} تومان'
                product = parse_message_products(json.dumps([{
                    'name': f'{title}\n\n{package_line}', 'unit_price': price,
                    'currency': 'IRT', 'confidence': 0.99,
                }], ensure_ascii=False), content, '@supplier')[0]
                self.assertEqual(product['name'], title)
                self.assertEqual(product['weight'], weight)
                self.assertEqual(product['sale_price'], price)
                self.assertEqual(product['price_type'], 'unit')
                self.assertFalse(product['needs_review'])

    def test_quality_packaging_and_price_lines_do_not_become_product_name(self):
        title = 'برنج طارم هاشمی مازندارن'
        content = (
            f'{title}\nدرجه کیفی عالی\nکیسه های ۱۰ کیلوگرمی\n'
            'قیمت فروش ما : کیلویی۴۵۰/۰۰۰ تومان'
        )
        product = parse_message_products(json.dumps([{
            'name': content, 'unit_price': 450000, 'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['name'], title)
        self.assertEqual(product['weight'], '10 کیلوگرم')
        self.assertEqual(product['sale_price'], 450000)
        self.assertEqual(product['price_type'], 'unit')
        self.assertFalse(product['needs_review'])

    def test_unlabeled_amount_does_not_gain_per_kilo_basis_from_pack_size(self):
        title = 'برنج ایرانی'
        content = f'{title}\nکیسه های ۱۰ کیلوگرمی\n۴۵۰٬۰۰۰ تومان'
        product = parse_message_products(json.dumps([{
            'name': f'{title}\nکیسه های ۱۰ کیلوگرمی', 'unit_price': 450000,
            'currency': 'IRT', 'confidence': 0.99,
        }], ensure_ascii=False), content, '@supplier')[0]
        self.assertEqual(product['price_type'], 'needs_review')
        self.assertEqual(product['sale_price'], 0)
        self.assertTrue(product['needs_review'])

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
        self.assertIn('`۲۲۰ میلی لیتر` is volume 220 ml, not a price of 220', prompt)
        self.assertIn('`طعم هلو` is variant `هلو`', prompt)
        self.assertIn('`قیمت فروش ما`', prompt)
        self.assertIn('bare comma-separated flavor/model list directly after a base title', prompt)
        self.assertIn('`گوشت،مرغ،فلفلی،قارچ پنیر،سبزیجات`', prompt)
        self.assertIn('one record per flavor with the shared source price', prompt)
        self.assertIn('Apply the same rule to every product family and choice type', prompt)
        self.assertIn('marker such as `موجود:` or', prompt)
        self.assertIn('Preserve different explicit prices as separate offer records', prompt)
        self.assertIn('`تتراپک` is packaging', prompt)
        self.assertIn('do not ask the owner to confirm the flavor or volume', prompt)
        self.assertIn('All source channels are wholesalers', prompt)
        self.assertIn('single unlabeled amount is the supplier sale amount', prompt)
        self.assertIn('one product record per distinct block', prompt)
        self.assertIn('Count all such blocks first and never skip a middle size', prompt)
        self.assertIn('with volumes 1.5 liters, 500 ml, and 250 ml; counts 6, 20, and 24', prompt)
        self.assertIn('matching supplier/consumer prices for each block', prompt)

    def test_distinct_size_price_blocks_keep_each_price_count_and_volume(self):
        base = 'نوشيدنى آلوورا بر پايه عسل'
        source = (
            base + '\n'
            '۱/۵ لیتر تعداد ۶ عددی\n'
            'قیمت فروش ما: ۱۰۵/۰۰۰ تومان\nقیمت مصرف کننده: ۱۵۰/۰۰۰ تومان\n'
            '۵۰۰ میل تعداد ۲۰ عددی\n'
            'قیمت فروش ما: ۶۰/۰۰۰ تومان\nقیمت مصرف کننده: ۹۰/۰۰۰ تومان\n'
            '۲۵۰ میل تعداد ۲۴ عددی\n'
            'قیمت فروش ما: ۵۰/۰۰۰ تومان\nقیمت مصرف کننده: ۷۰/۰۰۰ تومان'
        )
        generated = []
        for volume, count, sale, consumer in (
            ('1.5 لیتر', 6, 105000, 150000),
            ('500 میل', 20, 60000, 90000),
            ('250 میل', 24, 50000, 70000),
        ):
            generated.append({
                'name':base,'volume':volume,'package_count':count,
                'unit_price':sale,'sale_price':sale,'consumer_price':consumer,
                'price_type':'unit','currency':'IRT','confidence':0.99,
            })
        session = FakeSession(json.dumps(generated, ensure_ascii=False))
        with patch.dict(os.environ, {'GOOGLE_API_KEY':'test-key'}, clear=True):
            products = extract_message_products(source,'@supplier',session=session)
        self.assertEqual(len(products),3)
        self.assertEqual([(p['volume'],p['package_count'],p['sale_price'],p['actual_price'])
            for p in products], [
                ('1.5 لیتر',6,105000,150000),
                ('500 میل',20,60000,90000),
                ('250 میل',24,50000,70000),
            ])
        self.assertTrue(all(not product['needs_review'] for product in products))
        prompt = session.call[1]['json']['contents'][0]['parts'][0]['text']
        self.assertIn('never skip a middle size or its prices', prompt)

        mismatched = dict(generated[2], volume='330 میل')
        mismatch_session = FakeSession(json.dumps([mismatched], ensure_ascii=False))
        with patch.dict(os.environ, {'GOOGLE_API_KEY':'test-key'}, clear=True):
            mismatch = extract_message_products(source,'@supplier',session=mismatch_session)[0]
        self.assertEqual(mismatch['volume'],'')
        self.assertTrue(mismatch['needs_review'])
        self.assertIn('not tied to its own labeled source price block', mismatch['review_reason'])

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

    def test_import_preserves_ai_review_reason(self):
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
                result = import_product_data(store, {
                    'id': 'm1', 'batch_id': 'b1', 'content': 'supplier post',
                    'channel_username': '@supplier', 'channel': 'telegram',
                })
        self.assertTrue(result['success'])
        offers = store.read('Offers')
        self.assertEqual(offers[1][offers[0].index('Status')], 'needs_review')
        self.assertIn('model marked product unclear', offers[1][offers[0].index('Review Reason')])

    def test_gemini_error_does_not_fall_back_to_regex(self):
        store = MemoryStore()
        with patch.dict(os.environ, {'EXTRACTION_MODE': 'ai'}, clear=True):
            with patch('catalog.sync.extract_message_products', side_effect=GeminiError('offline')):
                result = import_product_data(store, {
                    'id': 'm2', 'batch_id': 'b2', 'content': 'supplier post',
                    'channel_username': '@supplier', 'channel': 'telegram',
                })
        self.assertFalse(result['success'])
        self.assertEqual(result['error'], 'GeminiError')

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
