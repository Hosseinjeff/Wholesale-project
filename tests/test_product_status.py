import tempfile
import unittest
from pathlib import Path

from catalog.product_status import ProductStatusLedger, capture_from_store
from catalog.sync import MemoryStore


class ProductStatusSourceIntegrityTests(unittest.TestCase):
    def test_source_link_break_is_saved_on_the_product_status_card(self):
        store = MemoryStore()
        store.sheets['Products'] = [[
            'Product ID', 'Product Name', 'Latest Offer ID', 'Source Post URL',
            'Channel Username', 'Channel', 'Batch ID', 'Message Timestamp',
        ], [
            'product-1', 'چای نمونه', 'missing-offer', 'https://t.me/supplier/1',
            '@supplier', 'telegram', 'batch-1', '1730574000',
        ]]
        store.sheets['Offers'] = [[
            'Offer ID', 'Product Name', 'Channel Username', 'Channel', 'Batch ID',
            'Message Timestamp', 'Source Post URL',
        ]]
        with tempfile.TemporaryDirectory() as directory:
            database = str(Path(directory) / 'pipeline.sqlite')
            capture = capture_from_store(store, database, reason='test')
            status = ProductStatusLedger(database).list_snapshots()['products'][0]

        self.assertEqual(capture['product_count'], 1)
        self.assertEqual(status['source_integrity_issues'], ['offer_not_found'])
        self.assertTrue(status['source_integrity_update_due'])
        self.assertTrue(status['update_needed'])
        self.assertEqual(status['source_post_url'], 'https://t.me/supplier/1')

    def test_snapshot_schema_version_is_available_for_forcing_a_new_card_capture(self):
        with tempfile.TemporaryDirectory() as directory:
            database = str(Path(directory) / 'pipeline.sqlite')
            ledger = ProductStatusLedger(database)
            ledger.capture([['Product ID', 'Product Name'], ['product-1', 'چای نمونه']],
                           reason='test')
            self.assertEqual(ledger.latest_schema_version(), ledger.STATE_SCHEMA_VERSION)
            with ledger.connect() as db:
                db.execute('UPDATE product_status_snapshots SET schema_version=0')
            self.assertEqual(ledger.latest_schema_version(), 0)


if __name__ == '__main__':
    unittest.main()
