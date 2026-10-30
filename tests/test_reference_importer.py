import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch
from pypdf import PdfWriter
from reference_import.importer import (ImportFailure, HEADERS, _pdf_page_batches,
    _build_woocommerce_candidate, extract_pdf, extract_woocommerce_store_api,
    parse_rows, save_candidate)

class ReferenceImportTests(unittest.TestCase):
    def item(self):
        return dict(raw_name='چای (۴۵ف) ۱۲ع', clean_name='چای', package_price='۴۸۰٬۰۰۰',
                    package_count=12, unit_price=40000, product_code='10', pdf_row_number='1')

    def rows(self, item):
        return parse_rows(json.dumps([item]), '2026-10-25T00:00:00+00:00')

    def extract(self, session, *args):
        with patch('requests.post',side_effect=session.post):
            return extract_pdf(session,*args)

    def test_schema_and_retail_hint(self):
        row = self.rows(self.item())[0]
        self.assertEqual(len(row), len(HEADERS))
        self.assertEqual(row[3:7], [480000, 40000, 45000, 12])
        self.assertEqual(row[16:19], ['10', 'TOMAN', 'rezvanpakhsh_pdf_ai'])

    def test_title_is_authoritative_for_pack_and_retail_prices(self):
        item=self.item();item.update(package_count=0,unit_price=0,consumer_unit_price=90000)
        row=self.rows(item)[0]
        self.assertEqual(row[5],45000)
        self.assertEqual(row[6],12)
        self.assertEqual(row[7],'عدد')

    def test_unknown_and_single_count(self):
        item = self.item()
        item.update(raw_name='چای (۴۵ف)',package_count=0,unit_price=0)
        self.assertEqual(self.rows(item)[0][4], '')
        item.update(raw_name='چای (۴۵ف) ۱ع',package_count=1, unit_price=480000)
        self.assertEqual(self.rows(item)[0][4], 480000)

    def test_invalid_or_conflicting_values_reject_candidate(self):
        for changes in ({'package_price': -5}, {'package_price': 'NaN'},
                        {'currency': 'RIAL'},
                        {'package_price': '۴۸۰ هزار'}):
            item = self.item()
            item.update(changes)
            with self.subTest(changes=changes), self.assertRaises(ImportFailure):
                self.rows(item)

    def test_ambiguous_title_counts_do_not_change_package_or_unit_price(self):
        item=self.item();item.update(raw_name='تیغ ژیلت 3لبه 2تایی مردانه (12*24)',
            clean_name='تیغ ژیلت',package_count=288,unit_price=1000,
            consumer_unit_price=45000,set_count=99)
        row=self.rows(item)[0]
        self.assertEqual(row[4:8],['','', '', ''])
        self.assertEqual(row[10],2)
        self.assertEqual(row[6],'')

    def test_blade_count_is_preserved_as_a_title_detail_not_set_count(self):
        item=self.item();item.update(raw_name='تیغ ژیلت ۴لبه (۲۰*۱۶)',clean_name='تیغ ژیلت',
            package_count=320,set_count=4)
        row=self.rows(item)[0]
        self.assertEqual(row[10],'')
        self.assertIn('۴لبه',row[14])

    def test_explicit_title_count_and_retail_price_override_model_values(self):
        item=self.item();item.update(package_count=320,unit_price=1,consumer_unit_price=90000)
        row=self.rows(item)[0]
        self.assertEqual(row[4],40000)
        self.assertEqual(row[5],45000)
        self.assertEqual(row[6],12)

    def test_empty_malformed_and_duplicate_extraction(self):
        for text in ('[]', '{}', 'unfinished [', json.dumps([self.item(), self.item()])):
            with self.subTest(text=text), self.assertRaises(ImportFailure):
                parse_rows(text, 'now')

    def test_repeated_product_code_with_distinct_pack_is_not_a_duplicate(self):
        first=self.item();first.update(clean_name='چای',package_count=12,package_price=480000,
            unit_price=40000,pdf_row_number='1')
        second={**first,'raw_name':'چای ۲۴ عددی','package_count':24,'package_price':840000,
            'unit_price':35000,'pdf_row_number':'2'}
        rows=parse_rows(json.dumps([first,second],ensure_ascii=False),'2026-10-25T00:00:00+00:00')
        self.assertEqual(len(rows),2)
        self.assertEqual([row[6] for row in rows],[12,24])

    def test_repeated_identical_product_on_distinct_pdf_rows_is_preserved(self):
        first=self.item();first['pdf_row_number']='1'
        second={**first,'pdf_row_number':'2'}
        rows=parse_rows(json.dumps([first,second],ensure_ascii=False),'2026-10-25T00:00:00+00:00')
        self.assertEqual(len(rows),2)
        self.assertEqual([row[15] for row in rows],['1','2'])

    def pdf_bytes(self,pages=1):
        writer=PdfWriter()
        for _ in range(pages):writer.add_blank_page(width=600,height=800)
        output=__import__('io').BytesIO();writer.write(output)
        return output.getvalue()

    def session(self, finish='STOP', pdf=None, omit_row=None, empty_halves=(),
                duplicate_row=None, duplicate_once=False):
        if pdf is None:
            pdf=self.pdf_bytes()
        session = MagicMock()
        download = session.get.return_value.__enter__.return_value
        download.status_code = 200
        download.iter_content.return_value = [pdf]
        crop_calls={}
        def post(*args, **kwargs):
            prompt=kwargs['json']['contents'][0]['parts'][0]['text']
            instruction=prompt.split('This attachment contains exactly one cropped product table:',1)[1]
            page=int(instruction.split('original PDF page ',1)[1].split('.',1)[0])
            half='left' if instruction.lstrip().startswith('the left half') else 'right'
            crop_key=(page,half);crop_calls[crop_key]=crop_calls.get(crop_key,0)+1
            start=(page-1)*60+(1 if half=='left' else 31)
            products=[] if (page,half) in empty_halves else [dict(raw_name=f'کالای نمونه {number}',clean_name=f'کالای نمونه {number}',
                package_price=10000,package_count=1,unit_price=0,pdf_row_number=str(number),
                product_code=f'P-{number}') for number in range(start,start+30)
                if not (page==1 and half=='left' and number==omit_row)]
            if (products and page==1 and half=='left' and duplicate_row is not None
                    and (not duplicate_once or crop_calls[crop_key]==1)):
                duplicate=next((item for item in products if item['pdf_row_number']==str(duplicate_row)),None)
                if duplicate: products.append(dict(duplicate))
            response=MagicMock();response.status_code=200
            response.json.return_value={'candidates':[{'finishReason':finish,
                'content':{'parts':[{'thought':True,'text':'ignore'},
                                    {'text':json.dumps(products,ensure_ascii=False)}]}}]}
            return response
        session.post.side_effect=post
        return session

    def test_stages_only_and_keeps_key_out_of_url(self):
        session = self.session()
        candidate = self.extract(session, 'synthetic-key', 'explicit-model')
        self.assertEqual(candidate['status'], 'staged')
        self.assertTrue(candidate['requires_review'])
        self.assertEqual(candidate['row_count'], 60)
        self.assertTrue(candidate['coverage_verified'])
        self.assertEqual(candidate['source_page_count'], 1)
        self.assertEqual(candidate['processed_page_count'], 1)
        self.assertNotIn('synthetic-key', session.post.call_args.args[0])
        prompt_text=session.post.call_args.kwargs['json']['contents'][0]['parts'][0]['text']
        self.assertIn('one cropped product table',prompt_text)

    def test_pdf_is_split_into_one_request_per_table_half(self):
        writer=PdfWriter()
        for _ in range(3):writer.add_blank_page(width=600,height=800)
        output=__import__('io').BytesIO();writer.write(output)
        page_count,batches=_pdf_page_batches(output.getvalue())
        self.assertEqual(page_count,3)
        self.assertEqual([(page,half) for page,half,_ in batches],[
            (0,'left'),(0,'right'),(1,'left'),(1,'right'),(2,'left'),(2,'right')])
        for _,_,batch in batches:
            from pypdf import PdfReader
            self.assertEqual(len(PdfReader(__import__('io').BytesIO(batch)).pages),1)

    def test_full_table_half_must_return_every_printed_row(self):
        session=self.session(pdf=self.pdf_bytes(3),omit_row=5)
        with self.assertRaises(ImportFailure) as caught:
            self.extract(session,'synthetic-key','explicit-model')
        self.assertEqual(caught.exception.code,'extraction_batch_row_count_mismatch_page_1_left')

    def test_duplicate_row_failure_identifies_the_page_and_table(self):
        session=self.session(duplicate_row=5)
        with self.assertRaises(ImportFailure) as caught:
            self.extract(session,'synthetic-key','explicit-model')
        self.assertEqual(caught.exception.code,'extraction_duplicate_row_number_5_page_1_left')

    def test_structural_retry_repeats_only_the_malformed_crop(self):
        session=self.session(duplicate_row=5,duplicate_once=True)
        candidate=self.extract(session,'synthetic-key','explicit-model')
        self.assertEqual(candidate['row_count'],60)
        self.assertEqual(session.post.call_count,3)

    def test_blank_final_crop_is_allowed_but_final_page_cannot_be_empty(self):
        candidate=self.extract(self.session(empty_halves={(1,'right')}),'synthetic-key','explicit-model')
        self.assertEqual(candidate['row_count'],30)
        with self.assertRaises(ImportFailure) as caught:
            self.extract(self.session(empty_halves={(1,'left'),(1,'right')}),
                         'synthetic-key','explicit-model')
        self.assertEqual(caught.exception.code,'extraction_final_page_empty_page_1')

    def test_environment_key_and_model_are_used_when_not_passed(self):
        session = self.session()
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'environment-key',
                                     'REFERENCE_GEMINI_MODEL': 'environment-model'}, clear=True):
            candidate = self.extract(session)
        self.assertEqual(candidate['model'], 'environment-model')
        self.assertEqual(session.post.call_args.kwargs['headers']['x-goog-api-key'], 'environment-key')
        self.assertNotIn('environment-key', session.post.call_args.args[0])
        self.assertIn('environment-model', session.post.call_args.args[0])

    def test_default_model_is_flash_lite_35(self):
        session=self.session()
        with patch.dict(os.environ,{'GOOGLE_API_KEY':'environment-key'},clear=True):
            candidate=self.extract(session)
        self.assertEqual(candidate['model'],'gemini-3.5-flash-lite')
        self.assertIn('gemini-3.5-flash-lite',session.post.call_args.args[0])

    def test_partial_output_and_non_pdf_fail(self):
        for session in (self.session(finish='MAX_TOKENS'), self.session(pdf=b'<html>error')):
            with self.assertRaises(ImportFailure):
                self.extract(session, 'synthetic-key', 'explicit-model')

    def test_download_failure_and_oversize_pdf(self):
        session = self.session()
        session.get.return_value.__enter__.return_value.status_code = 500
        with self.assertRaises(ImportFailure):
            extract_pdf(session, 'key', 'model')
        session.post.assert_not_called()
        with self.assertRaises(ImportFailure):
            extract_pdf(self.session(), 'key', 'model', max_pdf_bytes=2)

    def test_gemini_http_failure_exposes_only_safe_error_category(self):
        session=self.session()
        session.post.side_effect=None
        session.post.return_value.status_code=400
        session.post.return_value.json.return_value={'error':{
            'status':'INVALID_ARGUMENT','message':'API key not valid: secret-detail',
            'details':[{'reason':'API_KEY_INVALID'}]}}
        with self.assertRaises(ImportFailure) as caught:
            self.extract(session,'synthetic-key','explicit-model')
        self.assertEqual(caught.exception.code,'gemini_key_invalid_page_1_left')
        self.assertNotIn('secret-detail',str(caught.exception))

    def test_atomic_candidate_does_not_overwrite(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'candidate.json'
            save_candidate({'status': 'staged'}, target)
            original = target.read_bytes()
            with self.assertRaises(ImportFailure):
                save_candidate({'status': 'changed'}, target)
            self.assertEqual(original, target.read_bytes())
            self.assertEqual(len(list(Path(directory).iterdir())), 1)


class WooCommerceReferenceImportTests(unittest.TestCase):
    class Response:
        status_code = 200

        def __init__(self, products, *, total, pages):
            self._products = products
            self.headers = {'X-WP-Total': str(total), 'X-WP-TotalPages': str(pages)}

        def json(self):
            return self._products

    class Session:
        def __init__(self, pages):
            self.pages = pages
            self.calls = []

        def get(self, url, *, params, timeout):
            self.calls.append((url, dict(params), timeout))
            return WooCommerceReferenceImportTests.Response(
                self.pages[params['page']], total=3, pages=2)

    @staticmethod
    def product(product_id, sku, title, price='480000', currency='IRT', **extra):
        product = {'id': product_id, 'sku': sku, 'name': title,
                   'categories': [{'name': 'خوراکی'}], 'attributes': [],
                   'prices': {'price': price, 'currency_code': currency,
                              'currency_minor_unit': 0}}
        product.update(extra)
        return product

    def test_title_fields_are_preserved_and_only_explicit_markers_are_derived(self):
        title = 'چای ممتاز ۱۲ع (۴۵ف) ۲۵۰گرم ۵۰۰میل'
        product = self.product(1, 'TEA-12', title, price='48000000', currency='IRR',
            attributes=[{'name': 'pa_brand', 'terms': [{'name': 'برند الف'}]},
                        {'name': 'طعم', 'value': 'هل'}])
        candidate = _build_woocommerce_candidate([product], 1, 'IRR', '2026-10-30T00:00:00+00:00')
        row = candidate['rows'][0]
        self.assertEqual(len(row), len(HEADERS))
        self.assertEqual(row[0], title)
        self.assertEqual(row[3:8], [4800000, 400000, 45000, 12, 'عدد'])
        self.assertEqual(row[8:14], ['500 میل', '250 گرم', '', 'هل', 'برند الف', 'خوراکی'])
        self.assertEqual(row[15:20], ['1', 'TEA-12', 'TOMAN', 'rezvanpakhsh_woocommerce_store_api',
                                      'https://rezvanpakhsh.com/wp-json/wc/store/v1/products'])
        self.assertEqual(candidate['source_currency'], 'IRR')
        self.assertTrue(candidate['coverage_verified'])

    def test_two_piece_set_is_not_treated_as_a_package_count(self):
        product = self.product(1, 'SET-2', 'آجیل مخلوط ۲تایی')
        row = _build_woocommerce_candidate([product], 1, 'IRT', '2026-10-30T00:00:00+00:00')['rows'][0]
        self.assertEqual(row[6], '')
        self.assertEqual(row[10], 2)
        self.assertEqual(row[4], '')

    def test_pagination_is_complete_and_rows_keep_source_order(self):
        pages = {
            1: [self.product(1, 'SKU-1', 'محصول اول'), self.product(2, 'SKU-2', 'محصول دوم')],
            2: [self.product(3, 'SKU-3', 'محصول سوم')],
        }
        session = self.Session(pages)
        candidate = extract_woocommerce_store_api(session, per_page=2)
        self.assertEqual(candidate['row_count'], 3)
        self.assertEqual(candidate['source_total'], 3)
        self.assertEqual(candidate['source_page_count'], 2)
        self.assertEqual(candidate['processed_page_count'], 2)
        self.assertEqual([row[15] for row in candidate['rows']], ['1', '2', '3'])
        self.assertEqual([call[1]['page'] for call in session.calls], [1, 2])
        self.assertTrue(candidate['coverage_verified'])

    def test_incomplete_page_and_unsupported_currency_fail_closed(self):
        session = self.Session({1: [self.product(1, 'SKU-1', 'محصول اول'),
                                    self.product(2, 'SKU-2', 'محصول دوم')], 2: []})
        with self.assertRaises(ImportFailure) as caught:
            extract_woocommerce_store_api(session, per_page=2)
        self.assertEqual(caught.exception.code, 'woocommerce_page_incomplete_2')
        bad = self.product(1, 'SKU-1', 'محصول اول', currency='USD')
        with self.assertRaises(ImportFailure) as caught:
            _build_woocommerce_candidate([bad], 1, 'USD', '2026-10-30T00:00:00+00:00')
        self.assertEqual(caught.exception.code, 'woocommerce_currency_unsupported')

    def test_mixed_currency_and_duplicate_sku_fail_closed(self):
        first = self.product(1, 'SKU-1', 'محصول اول', currency='IRT')
        second = self.product(2, 'SKU-1', 'محصول دوم', currency='IRR')
        session = self.Session({1: [first, second], 2: [self.product(3, 'SKU-3', 'محصول سوم')]})
        with self.assertRaises(ImportFailure) as caught:
            extract_woocommerce_store_api(session, per_page=2)
        self.assertEqual(caught.exception.code, 'woocommerce_duplicate_sku')

if __name__ == '__main__':
    unittest.main()
