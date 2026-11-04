import hashlib
import unittest

from catalog.product_identity import (
    legacy_product_id,
    product_identity_key,
    product_row_id,
    woo_sku,
)
from catalog.sync import _product_id


class ProductIdentityTests(unittest.TestCase):
    def test_same_title_with_distinct_volumes_has_distinct_row_and_woo_identity(self):
        base = {'Product Name': 'نوشیدنی آلوورا بر پایه عسل',
                'Channel Username': '@supplier'}
        small = {**base, 'Volume': '250 میل'}
        large = {**base, 'Volume': '1.5 لیتر'}

        self.assertNotEqual(product_identity_key(small), product_identity_key(large))
        small_id = product_row_id(small)
        large_id = product_row_id(large)
        self.assertNotEqual(small_id, large_id)
        self.assertNotEqual(woo_sku({**small, 'Product ID': small_id}),
                            woo_sku({**large, 'Product ID': large_id}))

    def test_equivalent_measurement_units_share_identity(self):
        first = {'Product Name': 'نوشیدنی آلوورا', 'Channel Username': '@supplier',
                 'Volume': '1.5 لیتر'}
        second = {'Product Name': 'نوشیدنی آلوورا', 'Channel Username': '@supplier',
                  'Volume': '1500 میلی لیتر'}
        self.assertEqual(product_identity_key(first), product_identity_key(second))
        self.assertEqual(product_row_id(first), product_row_id(second))

    def test_variant_and_pack_count_are_identity_dimensions(self):
        base = {'Product Name': 'نودل لیوانی شف هو،۱۲ عددی',
                'Channel Username': '@supplier'}
        flavor = {**base, 'Variation Type': 'سبزیجات'}
        other_count = {'Product Name': 'نودل لیوانی شف هو،۲۴ عددی',
                       'Channel Username': '@supplier', 'Variation Type': 'سبزیجات'}
        self.assertNotEqual(product_row_id(base), product_row_id(flavor))
        self.assertNotEqual(product_row_id(flavor), product_row_id(other_count))

    def test_legacy_product_id_keeps_existing_sku_stable(self):
        name = 'آب سودا'
        channel = '@bonakdarjavan'
        legacy = legacy_product_id(name, channel)
        self.assertEqual(woo_sku({'Product Name': name, 'Channel Username': channel,
                                  'Product ID': legacy}),
                         hashlib.sha256(f'{channel}|{name}'.encode()).hexdigest()[:12])

    def test_unrecognized_existing_row_id_keeps_its_legacy_sku(self):
        name = 'آب سودا'
        channel = '@bonakdarjavan'
        self.assertEqual(woo_sku({'Product Name': name, 'Channel Username': channel,
                                  'Product ID': 'stable-soda'}),
                         hashlib.sha256(f'{channel}|{name}'.encode()).hexdigest()[:12])

    def test_only_canonical_dimension_ids_get_a_distinct_woo_sku(self):
        record = {'Product Name':'آب سودا','Channel Username':'@bonakdarjavan','Volume':'250 میل'}
        record['Product ID'] = product_row_id(record)
        self.assertEqual(woo_sku(record), hashlib.sha256(
            f"product-row|{record['Product ID']}".encode()).hexdigest()[:12])

    def test_legacy_id_keeps_the_previous_persian_title_hash(self):
        name = 'نودل لیوانی شف هو،۱۲ عددی سبزیجات'
        channel = '@supplier'
        self.assertEqual(legacy_product_id(name, channel), _product_id(name, channel))


if __name__ == '__main__':
    unittest.main()
