import unittest

from catalog.price_scale import apply_price_scale, assess_price_scale
from catalog.extract import OFFER_HEADERS
from catalog.sync import MemoryStore, _records, _row_from_map, import_product_data
from reference_import.importer import HEADERS


def reference(name, category='Canned Food', unit=100000, package=1200000, count=12, code='R'):
    values = {key:'' for key in HEADERS}
    values.update({'Product Name':name,'Clean Product Name':name,'Normalized Key':name.lower(),
        'Reference Package Price':package,'Reference Unit Price':unit,'Package Count':count,
        'Category':category,'Product Code':code})
    return [values[key] for key in HEADERS]


def offer(name='Tuna 12 pack', price=100000, *, status='imported', price_type='unit', count=12):
    return {'Offer ID':'confirmed:'+str(price),'Product Name':name,'Sale Price':price,
        'Status':status,'Price Type':price_type,'Package Count':count,'Channel Username':'@supplier'}


class PriceScaleTests(unittest.TestCase):
    def test_confirmed_history_fallback_is_applied_during_ai_offer_import(self):
        store=MemoryStore();store.ensure('Offers',OFFER_HEADERS)
        previous={'Offer ID':'history:1','Product Name':'Tuna 12 pack','Channel Username':'@supplier',
            'Sale Price':100000,'Price Type':'unit','Status':'imported','Package Count':12,
            'Import Timestamp':'2026-10-26T10:00:00Z'}
        store.append('Offers',[_row_from_map(OFFER_HEADERS,previous)])
        product={'name':'Tuna 12 pack','sale_price':0,'price':0,'actual_price':120000,
            'consumer_price':120000,'package_price':None,'price_type':'needs_review','currency':'IRT',
            'packaging':'12 pack','package_count':12,'min_qty':12,'category':'Canned Food',
            'stock_status':'Available','confidence':.95,'extraction_confidence':.95,
            'needs_review':True,'review_reason':'supplier price role is unclear',
            'detected_price':1200000,'source_price_amount':1200000,'confirmed_price_role':''}
        result=import_product_data(store,{'id':'message-1','batch_id':'batch-1',
            'channel':'telegram','channel_username':'@supplier','content':'Tuna 12 pack, 1200000 toman'},
            prepared_products=[product])
        self.assertTrue(result['success'])
        _,offers=_records(store.read('Offers'))
        imported=next(row for row in offers if row['Offer ID']=='batch-1:message-1:0')
        self.assertEqual(imported['Sale Price'],100000)
        self.assertEqual(imported['Detected Price'],1200000)
        self.assertEqual(imported['Price Scale Method'],'history_confirmed_package')
        self.assertEqual(imported['Historical Median Unit Price'],100000)
        self.assertEqual(imported['Status'],'imported')

    def test_category_fallback_is_audited_on_the_imported_offer(self):
        store=MemoryStore();store.ensure('Offers',OFFER_HEADERS)
        product={'name':'Mystery canned item 12 pack','sale_price':0,'price':0,'actual_price':150000,
            'consumer_price':150000,'price_type':'needs_review','currency':'IRT','packaging':'12 pack',
            'package_count':12,'min_qty':12,'category':'Canned Food','stock_status':'Available',
            'confidence':.95,'extraction_confidence':.95,'needs_review':True,
            'review_reason':'supplier price role is unclear','detected_price':100000,
            'source_price_amount':100000,'confirmed_price_role':''}
        refs=[reference('Unrelated A',code='A'),reference('Unrelated B',code='B'),reference('Unrelated C',code='C')]
        result=import_product_data(store,{'id':'message-2','batch_id':'batch-2',
            'channel':'telegram','channel_username':'@supplier','content':'Mystery canned item'},
            prepared_products=[product],reference_snapshot={'snapshot_id':'snapshot-A','rows':refs})
        self.assertTrue(result['success'])
        _,offers=_records(store.read('Offers'))
        imported=next(row for row in offers if row['Offer ID']=='batch-2:message-2:0')
        self.assertEqual(imported['Sale Price'],100000)
        self.assertEqual(imported['Price Scale Method'],'category_median')
        self.assertEqual(imported['Price Anchor Type'],'reference')
        self.assertEqual(imported['Category Reference Count'],3)

    def test_category_unit_median_requires_three_rows_and_40_percent_match(self):
        product={'name':'Other Canned Food 12 pack','category':'Canned Food','package_count':12,
            'detected_price':100000,'price_type':'needs_review'}
        two=[reference('A'),reference('B',code='R2')]
        self.assertEqual(assess_price_scale(product,{},[],two)['action'],'confirm_price_scale')
        three=two+[reference('C',code='R3')]
        assessment=assess_price_scale(product,{},[],three)
        self.assertEqual(assessment['action'],'category_unit_price')
        self.assertEqual(assessment['suggested_unit_price'],100000)
        self.assertEqual(assessment['category_reference_count'],3)
        apply_price_scale(product,assessment)
        self.assertEqual(product['sale_price'],100000)
        self.assertEqual(product['price_anchor_type'],'reference')

    def test_category_package_median_converts_to_unit_price(self):
        product={'name':'Other Canned Food 12 pack','category':'Canned Food','package_count':12,
            'detected_price':1200000,'price_type':'needs_review','review_reason':'supplier price role is unclear',
            'needs_review':True,'confidence':.95}
        refs=[reference('A',code='1'),reference('B',code='2'),reference('C',code='3')]
        assessment=assess_price_scale(product,{},[],refs)
        self.assertEqual(assessment['action'],'category_package_price')
        apply_price_scale(product,assessment)
        self.assertEqual(product['sale_price'],100000)
        self.assertEqual(product['package_price'],1200000)
        self.assertFalse(product['needs_review'])

    def test_recent_confirmed_history_identifies_package_total(self):
        product={'name':'Tuna 12 pack','category':'Canned Food','package_count':12,
            'detected_price':1200000,'price_type':'needs_review'}
        history=[offer(price=100000)]
        assessment=assess_price_scale(product,{'channel_username':'@supplier'},history)
        self.assertEqual(assessment['action'],'history_package_price')
        self.assertEqual(assessment['historical_median_unit_price'],100000)
        self.assertEqual(assessment['suggested_unit_price'],100000)
        apply_price_scale(product,assessment)
        self.assertEqual(product['sale_price'],100000)
        self.assertEqual(product['price_anchor_type'],'history')

    def test_recent_history_ignores_review_rows_and_flags_unexplained_deviation(self):
        product={'name':'Tuna 12 pack','package_count':12,'detected_price':400000,'price_type':'needs_review'}
        waiting=offer(price=100000,status='needs_review')
        assessment=assess_price_scale(product,{'channel_username':'@supplier'},[waiting])
        self.assertFalse(assessment['certain'])
        self.assertEqual(assessment['history_count'],0)
        consistent_history=[offer(price=100000),offer(price=110000)]
        assessment=assess_price_scale(product,{'channel_username':'@supplier'},consistent_history)
        self.assertEqual(assessment['action'],'confirm_price_scale')
        self.assertFalse(assessment['certain'])

    def test_explicit_price_role_takes_precedence_over_fallback(self):
        product={'name':'Tuna 12 pack','package_count':12,'detected_price':1200000,
            'confirmed_price_role':'package'}
        assessment=assess_price_scale(product,{},[offer(price=100000)],
            [reference('A'),reference('B',code='2'),reference('C',code='3')])
        self.assertEqual(assessment['action'],'explicit_role')


if __name__ == '__main__':
    unittest.main()
