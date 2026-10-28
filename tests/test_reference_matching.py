import unittest
from reference_import.importer import HEADERS
from reference_import.matching import candidate_match,price_role_hint


def row(name='چای',brand='الف',variant='',count=12,unit=40000,package=480000,code='10'):
 r=['']*len(HEADERS);r[0]=r[1]=r[2]=name;r[3]=package;r[4]=unit;r[6]=count;r[11]=variant;r[12]=brand;r[16]=code
 return r

class MatchingTests(unittest.TestCase):
 def test_exact_reference_preserves_supplier_price_and_role_is_only_a_hint(self):
  result=candidate_match({'name':'چای','brand':'الف','package_count':12},[row()])
  self.assertEqual(result['state'],'candidate')
  hint=price_role_hint(460000,result['candidates'][0])
  self.assertEqual(hint['role'],'package');self.assertEqual(hint['supplier_price'],460000)
 def test_brand_pack_and_unverifiable_attribute_conflicts_need_review(self):
  for product in ({'name':'چای','brand':'ب'}, {'name':'چای','package_count':24}, {'name':'چای','variant':'دارچینی'}):
   with self.subTest(product=product):self.assertEqual(candidate_match(product,[row()])['state'],'needs_review')
 def test_ties_and_missing_reference_require_review(self):
  self.assertEqual(candidate_match({'name':'چای'},[row(code='1'),row(code='2')])['reason'],'multiple_candidates')
  self.assertEqual(candidate_match({'name':'قند'},[row()])['reason'],'no_compatible_reference')
  self.assertEqual(candidate_match({'name':'چای'},[['invalid']])['reason'],'invalid_reference_rows')
 def test_ambiguous_or_missing_scale_requires_review(self):
  self.assertEqual(price_role_hint(100000,{'unit_price':40000,'package_price':480000})['role'],'needs_review')
  self.assertEqual(price_role_hint(100,{'unit_price':0,'package_price':100})['role'],'needs_review')

if __name__=='__main__':unittest.main()
