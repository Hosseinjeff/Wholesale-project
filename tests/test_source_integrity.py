import unittest
from datetime import datetime, timezone

from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS
from catalog.source_integrity import product_offer_link_issues
from catalog.sync import (
    MemoryStore,
    _offer_fields,
    _product_id_for_offer,
    _row_from_map,
    _verify_offer_ids_written,
    import_product_data,
)


class OfferProductLinkTests(unittest.TestCase):
    def test_offer_creation_uses_the_unique_existing_variant_id(self):
        products = [
            PRODUCT_HEADERS,
            _row_from_map(PRODUCT_HEADERS, {
                'Product ID': 'stable-small', 'Product Name': 'نوشیدنی نمونه',
                'Channel Username': '@supplier', 'Volume': '250 میل',
            }),
            _row_from_map(PRODUCT_HEADERS, {
                'Product ID': 'stable-large', 'Product Name': 'نوشیدنی نمونه',
                'Channel Username': '@supplier', 'Volume': '1.5 لیتر',
            }),
        ]

        product_id = _product_id_for_offer(
            {'name': 'نوشیدنی نمونه', 'volume': '250 میلی لیتر'}, '@supplier', products)
        offer = _offer_fields(
            {'name': 'نوشیدنی نمونه', 'volume': '250 میلی لیتر'},
            {'channel_username': '@supplier'}, 'offer-new', '2026-11-05T00:00:00Z', '',
            product_id=product_id)

        self.assertEqual(product_id, 'stable-small')
        self.assertEqual(offer['Product ID'], 'stable-small')

    def test_import_keeps_legacy_product_id_linked_to_the_new_offer(self):
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        store.append('Products', [_row_from_map(PRODUCT_HEADERS, {
            'Product ID': 'legacy-stable-id', 'Product Name': 'نوشیدنی نمونه',
            'Channel Username': '@supplier', 'Volume': '250 میل',
            'Sale Price': 50000, 'Consumer Price': 80000, 'Status': 'imported',
        })])
        timestamp = datetime.now(timezone.utc).isoformat()

        result = import_product_data(store, {
            'id': 'message-new', 'batch_id': 'batch-new', 'channel': 'telegram',
            'channel_username': '@supplier', 'content': 'نوشیدنی نمونه 250 میل',
            'timestamp': timestamp, 'source_timestamp_basis': 'supplier_channel_update',
            'url': 'https://t.me/supplier/2',
        }, prepared_products=[{
            'name': 'نوشیدنی نمونه', 'volume': '250 میلی لیتر',
            'sale_price': 55000, 'price': 55000, 'consumer_price': 80000,
            'actual_price': 80000, 'price_type': 'unit', 'currency': 'IRT',
            'status': 'imported', 'confidence': 0.99, 'extraction_confidence': 0.99,
        }])

        self.assertTrue(result['success'])
        products = store.read('Products')
        offers = store.read('Offers')
        product_headers = products[0]
        offer_headers = offers[0]
        product = {key: products[1][index] for index, key in enumerate(product_headers)}
        new_offer = next(row for row in offers[1:] if row[offer_headers.index('Offer ID')].startswith('batch-new:'))
        self.assertEqual(product['Product ID'], 'legacy-stable-id')
        self.assertEqual(new_offer[offer_headers.index('Product ID')], 'legacy-stable-id')
        self.assertEqual(product['Latest Offer ID'], new_offer[offer_headers.index('Offer ID')])

    def test_offer_append_receipt_verifies_the_product_foreign_key(self):
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.append('Offers', [_row_from_map(OFFER_HEADERS, {
            'Offer ID': 'offer-1', 'Product ID': 'stable-product',
        })])

        _verify_offer_ids_written(store, ['offer-1'], {'offer-1': 'stable-product'})
        with self.assertRaisesRegex(RuntimeError, 'offer_product_link_unverified'):
            _verify_offer_ids_written(store, ['offer-1'], {'offer-1': 'different-product'})

    def test_latest_offer_link_to_a_different_product_is_reported(self):
        product = {
            'Product ID': 'product-small', 'Latest Offer ID': 'offer-large',
            'Product Name': 'نوشیدنی نمونه', 'Channel Username': '@supplier',
            'Channel': 'telegram', 'Batch ID': 'batch-1', 'Message Timestamp': '1730574000',
            'Source Timestamp Basis': 'supplier_channel_update',
            'Source Post URL': 'https://t.me/supplier/1',
        }
        offer = {
            'Offer ID': 'offer-large', 'Product ID': 'product-large',
            'Product Name': 'نوشیدنی نمونه', 'Channel Username': '@supplier',
            'Channel': 'telegram', 'Batch ID': 'batch-1', 'Message Timestamp': '1730574000',
            'Source Timestamp Basis': 'supplier_channel_update',
            'Source Post URL': 'https://t.me/supplier/1',
        }

        issues = product_offer_link_issues([product], [offer])

        self.assertEqual(issues[0]['codes'], ['product_id_mismatch'])

    def test_legacy_offer_without_product_id_keeps_provenance_fallback(self):
        product = {
            'Product ID': 'legacy-product', 'Latest Offer ID': 'legacy-offer',
            'Product Name': 'کالای نمونه', 'Channel Username': '@supplier',
            'Channel': 'telegram', 'Batch ID': 'batch-1', 'Message Timestamp': '1730574000',
            'Source Timestamp Basis': 'supplier_channel_update',
            'Source Post URL': 'https://t.me/supplier/1',
        }
        offer = {
            'Offer ID': 'legacy-offer', 'Product Name': 'کالای نمونه',
            'Channel Username': '@supplier', 'Channel': 'telegram', 'Batch ID': 'batch-1',
            'Message Timestamp': '1730574000',
            'Source Timestamp Basis': 'supplier_channel_update',
            'Source Post URL': 'https://t.me/supplier/1',
        }

        self.assertEqual(product_offer_link_issues([product], [offer]), [])


if __name__ == '__main__':
    unittest.main()
