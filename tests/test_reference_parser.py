"""Targeted differential checks, not a repeat of the existing whole-GAS audit."""
import json
import subprocess
import unittest
from pathlib import Path
from reference_import.importer import ImportFailure, parse_rows


class RecoveredParserTests(unittest.TestCase):
    def original(self, item):
        result = subprocess.run(['node', str(Path(__file__).with_name('reference_parser.cjs'))],
                                input=json.dumps([item]), text=True, capture_output=True,
                                check=True, timeout=5)
        return json.loads(result.stdout)

    def server(self, item):
        item = {**item, 'pdf_row_number': item.get('pdf_row_number', '1')}
        row = parse_rows(json.dumps([item]), 'synthetic-time')[0]
        return dict(package_price=row[3], unit_price=row[4] or 0,
                    consumer_unit_price=row[5] or 0, package_count=row[6] or 0,
                    product_code=row[16])

    def test_canonical_contract_matches_recovered_prices(self):
        for item in (
            dict(raw_name='چای (۴۵ف)', clean_name='چای', package_price='۴۸۰٬۰۰۰',
                 package_count=12, unit_price=40000, product_code='10'),
            dict(raw_name='برنج', package_price=500000, package_count=0, unit_price=0),
            dict(raw_name='قند', package_price=100001, package_count=3, unit_price=0)):
            with self.subTest(item=item):
                self.assertEqual(self.original(item)[0], self.server(item))

    def test_known_intentional_divergences_are_explicit(self):
        single = dict(raw_name='چای', package_price=45000, package_count=1, unit_price=45000)
        self.assertEqual(self.original(single)[0]['unit_price'], 0)
        self.assertEqual(self.server(single)['unit_price'], 45000)
        conflicting = dict(raw_name='چای', package_price=480000, package_count=12, unit_price=5)
        self.assertEqual(self.original(conflicting)[0]['unit_price'], 5)
        with self.assertRaises(ImportFailure):
            self.server(conflicting)
