import os
import unittest
from unittest.mock import patch

from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS
from catalog.sync import MemoryStore, _row_from_map


class PublicCatalogIntegrityTests(unittest.TestCase):
    def setUp(self):
        self.store = MemoryStore()
        timestamp = 1_794_000_000
        source_url = 'https://t.me/supplier/1'
        good = {
            'Product ID':'valid-product', 'Product Name':'چای', 'Channel Username':'@supplier',
            'Channel':'telegram', 'Sale Price':40000, 'Consumer Price':50000,
            'Currency':'IRT', 'Latest Offer ID':'offer-valid', 'Message Timestamp':timestamp,
            'Source Timestamp Basis':'supplier_channel_update', 'Source Post URL':source_url,
            'Status':'imported', 'Stock Status':'Unknown',
        }
        broken = {
            **good, 'Product ID':'orphan-product', 'Product Name':'نوشیدنی بدون مدرک',
            'Latest Offer ID':'offer-missing', 'Source Post URL':'https://t.me/supplier/2',
        }
        self.store.sheets['Products'] = [PRODUCT_HEADERS,
            _row_from_map(PRODUCT_HEADERS, good), _row_from_map(PRODUCT_HEADERS, broken)]
        offer = {
            'Offer ID':'offer-valid', 'Product Name':'چای', 'Channel Username':'@supplier',
            'Channel':'telegram', 'Message Timestamp':timestamp,
            'Source Timestamp Basis':'supplier_channel_update', 'Source Post URL':source_url,
        }
        self.store.sheets['Offers'] = [OFFER_HEADERS, _row_from_map(OFFER_HEADERS, offer)]

    def test_listing_and_cart_both_exclude_rows_with_broken_latest_offer_links(self):
        import app
        with patch.dict(os.environ, {'PIPELINE_DB_PATH':''}), \
             patch.object(app, 'USE_SHEETS', True), \
             patch.object(app, 'build_store', return_value=self.store):
            client = app.app.test_client()
            listing = client.get('/api/shop').get_json()['products']
            self.assertEqual([item['product_id'] for item in listing], ['valid-product'])

            quote = client.post('/api/cart', json={'lines':[
                {'product_id':'valid-product','qty':1},
                {'product_id':'orphan-product','qty':1},
            ]}).get_json()
            self.assertEqual([line['product_id'] for line in quote['lines']], ['valid-product'])
            self.assertEqual(quote['missing'], ['orphan-product'])


if __name__ == '__main__':
    unittest.main()
