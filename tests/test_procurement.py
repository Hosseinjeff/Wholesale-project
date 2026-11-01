import unittest
from catalog.procurement import plan_purchase

class ProcurementTests(unittest.TestCase):
 def test_combined_demand_fixed_packs_and_surplus(self):
  result=plan_purchase([{'order_id':'one','sku':'tuna','units':4},{'order_id':'two','sku':'tuna','units':2}],
   [{'sku':'tuna','supplier':'supplier','units_per_pack':12,'pack_price_toman':1200000}],{'tuna':2})[0]
  self.assertEqual((result['customer_units'],result['supplier_packs'],result['expected_surplus_units'],result['purchase_cost_toman']),(6,1,8,1200000))
 def test_supplier_minimum_and_pack_multiple_are_separate(self):
  result=plan_purchase([{'order_id':'one','sku':'x','units':2}],
   [{'sku':'x','supplier':'s','units_per_pack':12,'pack_price_toman':100,'minimum_packs':3,'pack_multiple':2}],{})[0]
  self.assertEqual((result['supplier_packs'],result['expected_surplus_units']),(4,46))
 def test_no_purchase_if_owned_stock_covers_demand(self):
  r=plan_purchase([{'order_id':'one','sku':'x','units':6}], [{'sku':'x','supplier':'s','units_per_pack':12,'pack_price_toman':100}],{'x':6})[0]
  self.assertEqual((r['supplier_packs'],r['purchase_cost_toman'],r['expected_surplus_units']),(0,0,0))
 def test_missing_or_competing_offers_do_not_guess(self):
  line=[{'order_id':'one','sku':'x','units':6}]
  with self.assertRaises(ValueError):plan_purchase(line,[],{})
  offer={'sku':'x','supplier':'s','units_per_pack':12,'pack_price_toman':100}
  with self.assertRaises(ValueError):plan_purchase(line,[offer,offer],{})
  with self.assertRaises(ValueError):plan_purchase(line,[{**offer,'units_per_pack':None}],{})

if __name__=='__main__':unittest.main()
