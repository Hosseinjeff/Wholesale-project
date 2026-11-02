import unittest

from catalog.extract import MESSAGE_HEADERS, OFFER_HEADERS, PRODUCT_HEADERS
from catalog.reconciliation import build_reconciliation_report
from catalog.sync import MemoryStore, _row_from_map


class FakeQueue:
    def __init__(self, receipt):
        self.receipt = receipt

    def reconciliation_receipts(self, *, limit=200):
        return {
            'receipts': [self.receipt],
            'publication_by_decision': {},
            'total_receipts': 1,
            'truncated': False,
        }


class ReconciliationReportTests(unittest.TestCase):
    def setUp(self):
        self.store = MemoryStore()
        self.store.ensure('MessageData', MESSAGE_HEADERS)
        self.store.ensure('Offers', OFFER_HEADERS)
        self.store.ensure('Products', PRODUCT_HEADERS)
        self.source = {
            'ID': 'message-1', 'Channel': 'telegram', 'Channel Username': '@supplier',
            'Content': 'چای سبز\nقیمت تکی: ۴۰٬۰۰۰ تومان',
            'Timestamp': '1730574000', 'Batch ID': 'batch-1',
        }
        self.store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, self.source)])
        self.receipt = {
            'message_id': 'message-1', 'channel': '@supplier',
            'source_timestamp': '1730574000', 'batch_id': 'batch-1',
            'received_at': 1730574000, 'batch_state': 'done', 'batch_attempts': 1,
            'batch_error': '', 'publication_state': 'not_queued',
            'publication_attempts': 0, 'publication_error': '',
            'publication_pushed': False, 'publication_count': 0,
        }

    def test_receipt_joins_current_product_through_latest_offer_id(self):
        offer = {
            'Offer ID': 'offer-1', 'Message ID': 'message-1', 'Batch ID': 'batch-1',
            'Channel Username': '@supplier', 'Message Timestamp': '1730574000',
            'Original Message': self.source['Content'], 'Product Name': 'چای سبز',
            'Status': 'needs_review', 'Price Type': 'unit', 'Sale Price': 40000,
        }
        product = {
            'Product ID': 'product-1', 'Latest Offer ID': 'offer-1',
            'Product Name': 'چای سبز', 'Channel Username': '@supplier',
            'Status': 'needs_review', 'Sale Price': 40000,
        }
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, offer)])
        self.store.append('Products', [_row_from_map(PRODUCT_HEADERS, product)])

        report = build_reconciliation_report(self.store, FakeQueue(self.receipt), site_url='')

        self.assertEqual(report['summary']['products'], 1)
        self.assertEqual(report['receipts'][0]['product_count'], 1)
        self.assertEqual(report['receipts'][0]['product_details'][0]['product_id'], 'product-1')
        self.assertEqual(report['receipts'][0]['product_details'][0]['price_basis'], 'unit')

    def test_owner_confirmed_derived_offer_links_back_to_the_same_product(self):
        original = {
            'Offer ID': 'offer-1', 'Message ID': 'message-1', 'Batch ID': 'batch-1',
            'Channel Username': '@supplier', 'Message Timestamp': '1730574000',
            'Original Message': self.source['Content'], 'Product Name': 'چای سبز',
            'Status': 'needs_review',
        }
        derived = {
            **original, 'Offer ID': 'offer-2', 'Derived From Offer ID': 'offer-1',
            'Owner Decision ID': 'decision-1', 'Price Type': 'unit', 'Status': 'imported',
        }
        product = {
            'Product ID': 'product-1', 'Latest Offer ID': 'offer-2',
            'Product Name': 'چای سبز', 'Channel Username': '@supplier',
            'Status': 'imported', 'Sale Price': 40000,
        }
        self.store.append('Offers', [
            _row_from_map(OFFER_HEADERS, original),
            _row_from_map(OFFER_HEADERS, derived),
        ])
        self.store.append('Products', [_row_from_map(PRODUCT_HEADERS, product)])

        report = build_reconciliation_report(self.store, FakeQueue(self.receipt), site_url='')

        self.assertEqual(report['receipts'][0]['owner_decisions'], 1)
        self.assertEqual(report['receipts'][0]['product_count'], 1)
        self.assertEqual(report['receipts'][0]['product_details'][0]['price_basis'], 'unit')


if __name__ == '__main__':
    unittest.main()
