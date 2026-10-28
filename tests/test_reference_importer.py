import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch
from reference_import.importer import ImportFailure, HEADERS, extract_pdf, parse_rows, save_candidate

class ReferenceImportTests(unittest.TestCase):
    def item(self):
        return dict(raw_name='چای (۴۵ف) ۱۲ع', clean_name='چای', package_price='۴۸۰٬۰۰۰',
                    package_count=12, unit_price=40000, product_code='10')

    def rows(self, item):
        return parse_rows(json.dumps([item]), '2026-10-25T00:00:00+00:00')

    def test_schema_and_retail_hint(self):
        row = self.rows(self.item())[0]
        self.assertEqual(len(row), len(HEADERS))
        self.assertEqual(row[3:7], [480000, 40000, 45000, 12])
        self.assertEqual(row[16:19], ['10', 'TOMAN', 'rezvanpakhsh_pdf_ai'])

    def test_unknown_and_single_count(self):
        item = self.item()
        item.update(package_count=0, unit_price=0)
        self.assertEqual(self.rows(item)[0][4], '')
        item.update(package_count=1, unit_price=480000)
        self.assertEqual(self.rows(item)[0][4], 480000)

    def test_invalid_or_conflicting_values_reject_candidate(self):
        for changes in ({'package_price': -5}, {'package_price': 'NaN'},
                        {'package_count': 1.5}, {'package_count': True},
                        {'unit_price': 123}, {'consumer_unit_price': 90000},
                        {'currency': 'RIAL'}, {'package_price': '۴۸۰ هزار'}):
            item = self.item()
            item.update(changes)
            with self.subTest(changes=changes), self.assertRaises(ImportFailure):
                self.rows(item)

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

    def session(self, finish='STOP', pdf=b'%PDF-test'):
        session = MagicMock()
        download = session.get.return_value.__enter__.return_value
        download.status_code = 200
        download.iter_content.return_value = [pdf]
        response = session.post.return_value
        response.status_code = 200
        response.json.return_value = {'candidates': [{'finishReason': finish,
            'content': {'parts': [{'thought': True, 'text': 'ignore'},
                                  {'text': json.dumps([self.item()])}]}}]}
        return session

    def test_stages_only_and_keeps_key_out_of_url(self):
        session = self.session()
        candidate = extract_pdf(session, 'synthetic-key', 'explicit-model')
        self.assertEqual(candidate['status'], 'staged')
        self.assertTrue(candidate['requires_review'])
        self.assertEqual(candidate['row_count'], 1)
        self.assertNotIn('synthetic-key', session.post.call_args.args[0])

    def test_environment_key_and_model_are_used_when_not_passed(self):
        session = self.session()
        with patch.dict(os.environ, {'GOOGLE_API_KEY': 'environment-key',
                                     'AI_MODEL': 'environment-model'}, clear=True):
            candidate = extract_pdf(session)
        self.assertEqual(candidate['model'], 'environment-model')
        self.assertEqual(session.post.call_args.kwargs['headers']['x-goog-api-key'], 'environment-key')
        self.assertNotIn('environment-key', session.post.call_args.args[0])

    def test_partial_output_and_non_pdf_fail(self):
        for session in (self.session(finish='MAX_TOKENS'), self.session(pdf=b'<html>error')):
            with self.assertRaises(ImportFailure):
                extract_pdf(session, 'synthetic-key', 'explicit-model')

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
        session.post.return_value.status_code=400
        session.post.return_value.json.return_value={'error':{
            'status':'INVALID_ARGUMENT','message':'API key not valid: secret-detail',
            'details':[{'reason':'API_KEY_INVALID'}]}}
        with self.assertRaises(ImportFailure) as caught:
            extract_pdf(session,'synthetic-key','explicit-model')
        self.assertEqual(caught.exception.code,'gemini_key_invalid')
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

if __name__ == '__main__':
    unittest.main()
