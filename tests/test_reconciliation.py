import unittest
from unittest.mock import patch

from catalog.extract import MESSAGE_HEADERS, OFFER_HEADERS, PRODUCT_HEADERS
from catalog.reconciliation import build_reconciliation_report
from catalog.source_integrity import product_offer_link_issues
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
            'Channel':'telegram','Batch ID':'batch-1','Message Timestamp':'1730574000',
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
            'Channel':'telegram','Batch ID':'batch-1','Message Timestamp':'1730574000',
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

    def test_orphaned_current_product_offer_is_reported_as_source_integrity_issue(self):
        product = {
            'Product ID':'product-orphan','Latest Offer ID':'missing-offer',
            'Product Name':'کالای نمونه','Channel Username':'@supplier',
            'Channel':'telegram','Batch ID':'batch-1','Message Timestamp':'1730574000',
            'Status':'imported','Sale Price':40000,'Consumer Price':65000,
        }
        self.store.append('Products',[_row_from_map(PRODUCT_HEADERS,product)])
        report=build_reconciliation_report(self.store,FakeQueue(self.receipt),site_url='')
        self.assertEqual(report['summary']['products_with_source_integrity_issues'],1)
        self.assertEqual(report['source_integrity']['issues'][0]['codes'],['offer_not_found'])

    def test_duplicate_dimensions_are_reported_as_duplicate_product_identity(self):
        products = [
            {'Product ID':'product-a','Product Name':'نوشیدنی آلوورا','Channel Username':'@supplier',
             'Volume':'250 میل','Package Count':24},
            {'Product ID':'product-b','Product Name':'نوشیدنی آلوورا','Channel Username':'@supplier',
             'Volume':'250 میلی لیتر','Package Count':24},
        ]
        issues = product_offer_link_issues(products, [])
        self.assertTrue(all('duplicate_product_identity' in issue['codes'] for issue in issues))

    def test_catalog_woo_counts_products_outside_the_receipt_window(self):
        product = {
            'Product ID':'product-unjoined','Latest Offer ID':'offer-unjoined',
            'Product Name':'کالای مستقل','Channel Username':'@supplier','Channel':'telegram',
            'Batch ID':'older-batch','Message Timestamp':'1730574000',
            'Source Timestamp Basis':'supplier_channel_update','Source Post URL':'https://t.me/supplier/older',
            'Status':'imported','Sale Price':40000,'Consumer Price':65000,
            'Stock Status':'Unknown',
        }
        offer = {
            'Offer ID':'offer-unjoined','Message ID':'older-message','Batch ID':'older-batch',
            'Product Name':'کالای مستقل','Channel Username':'@supplier','Channel':'telegram',
            'Message Timestamp':'1730574000','Source Timestamp Basis':'supplier_channel_update',
            'Source Post URL':'https://t.me/supplier/older','Status':'imported',
            'Sale Price':40000,'Consumer Price':65000,
        }
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, offer)])
        self.store.append('Products', [_row_from_map(PRODUCT_HEADERS, product)])

        class EmptyWoo:
            state = 'checked'
            products = {}
            checked_at = '2026-11-04T00:00:00+00:00'

        with patch.dict('os.environ', {'PIPELINE_DB_PATH':''}), \
             patch('catalog.reconciliation._woo_catalog', return_value={
                 'state':'checked','products':{},'checked_at':'2026-11-04T00:00:00+00:00'}):
            report = build_reconciliation_report(self.store, FakeQueue(self.receipt), site_url='')

        self.assertEqual(report['receipts'][0]['product_count'], 0)
        self.assertEqual(report['catalog_woo']['products_checked'], 1)
        self.assertEqual(report['catalog_woo']['not_visible'], 1)
        self.assertEqual(report['catalog_woo']['issues'][0]['product_id'], 'product-unjoined')
        self.assertEqual(report['catalog_woo']['issues'][0]['product_name'], 'کالای مستقل')
        self.assertEqual(report['catalog_woo']['issues'][0]['state'], 'not_visible')
        self.assertEqual(report['summary']['woo_missing'], 1)

    def test_catalog_woo_matches_preserved_sku_by_private_supplier_identity(self):
        product_id = '________________905958'
        name = 'کروسان پچ‌ پچ ،'
        offer = {
            'Offer ID':'offer-legacy-sku', 'Message ID':'older-message',
            'Batch ID':'older-batch', 'Product Name':name,
            'Channel Username':'@supplier', 'Channel':'telegram',
            'Message Timestamp':'1730574000', 'Source Timestamp Basis':'supplier_channel_update',
            'Source Post URL':'https://t.me/supplier/older', 'Status':'imported',
            'Sale Price':40000, 'Consumer Price':65000,
        }
        product = {
            'Product ID':product_id, 'Latest Offer ID':'offer-legacy-sku',
            'Product Name':name, 'Channel Username':'@supplier', 'Channel':'telegram',
            'Batch ID':'older-batch', 'Message Timestamp':'1730574000',
            'Source Timestamp Basis':'supplier_channel_update',
            'Source Post URL':'https://t.me/supplier/older', 'Status':'imported',
            'Sale Price':40000, 'Consumer Price':65000,
        }
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, offer)])
        self.store.append('Products', [_row_from_map(PRODUCT_HEADERS, product)])
        legacy_sku = '171e3fb514c3'
        with patch.dict('os.environ', {'PIPELINE_DB_PATH':''}), \
             patch('catalog.reconciliation._woo_catalog', return_value={
                 'state':'checked', 'products':{}, 'checked_at':'2026-11-04T00:00:00+00:00',
                 'identity_records':[{
                     'source_record_id':'', 'source_id':legacy_sku, 'sku':legacy_sku,
                     'name':'کروسان پچ‌ پچ', 'supplier':'@supplier',
                     'catalog_state':'sell', 'published':True, 'purchasable':True,
                 }],
             }):
            report = build_reconciliation_report(self.store, FakeQueue(self.receipt), site_url='')

        catalog = report['catalog_woo']
        self.assertEqual(catalog['not_visible'], 0)
        self.assertEqual(catalog['purchasable'], 1)
        self.assertEqual(catalog['sku_mismatch'], 1)
        self.assertEqual(catalog['issues'][0]['state'], 'sku_mismatch')
        self.assertEqual(catalog['issues'][0]['actual_woo_sku'], legacy_sku)
        self.assertEqual(catalog['issues'][0]['woo_match_method'], 'supplier_name')
        self.assertEqual(report['summary']['woo_missing'], 0)

    def test_woo_reconciliation_uses_publisher_holds_for_missing_customer_prices(self):
        names=['چای ساده','چای با قیمت مصرف‌کننده']
        offers=[];products=[]
        for index,name in enumerate(names,1):
            offer_id=f'offer-{index}'
            offers.append({
                'Offer ID':offer_id,'Message ID':'message-1','Batch ID':'batch-1',
                'Channel Username':'@supplier','Channel':'telegram','Message Timestamp':'1730574000',
                'Original Message':self.source['Content'],'Product Name':name,
                'Status':'imported','Sale Price':40000,
            })
            products.append({
                'Product ID':f'product-{index}','Latest Offer ID':offer_id,
                'Product Name':name,'Channel Username':'@supplier',
                'Channel':'telegram','Batch ID':'batch-1','Message Timestamp':'1730574000',
                'Status':'imported','Sale Price':40000,
                'Consumer Price':65000 if index==2 else '',
            })
        self.store.append('Offers',[_row_from_map(OFFER_HEADERS,row) for row in offers])
        self.store.append('Products',[_row_from_map(PRODUCT_HEADERS,row) for row in products])

        class EmptyWoo:
            status_code=200
            headers={'X-WP-TotalPages':'1'}
            def json(self):return []

        with patch.dict('os.environ',{'PIPELINE_DB_PATH':''}), \
             patch('catalog.reconciliation.requests.get',return_value=EmptyWoo()):
            report=build_reconciliation_report(
                self.store,FakeQueue(self.receipt),site_url='https://shop.example')

        states={item['name']:item['woo_state'] for item in report['receipts'][0]['product_details']}
        self.assertEqual(report['summary']['woo_missing'],1)
        self.assertEqual(states['چای ساده'],'held')
        self.assertEqual(states['چای با قیمت مصرف‌کننده'],'not_visible')
        self.assertEqual(report['validation']['catalog_projection'],'checked')


if __name__ == '__main__':
    unittest.main()
