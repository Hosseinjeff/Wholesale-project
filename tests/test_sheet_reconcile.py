import unittest
from catalog.sheet_reconcile import InvalidEdit, field_values_equal, reconcile, reconcile_field, resolve_conflict

class SheetReconciliationTests(unittest.TestCase):
    def test_edit_either_side_and_equivalent_numeric_format(self):
        self.assertEqual(reconcile('۱۲',12,'۲۴').action,'sheet_to_server')
        self.assertEqual(reconcile(12,24,'12').action,'server_to_sheet')
        self.assertEqual(reconcile(12,'۲۴',24).action,'in_sync')

    def test_conflicting_edits_preserve_both_and_require_current_observation(self):
        conflict=reconcile(12,24,36)
        self.assertEqual(conflict.action,'conflict')
        self.assertEqual((conflict.server,conflict.sheet),(24,36))
        self.assertEqual(resolve_conflict(conflict,'sheet',observed_server=24,observed_sheet=36),36)
        with self.assertRaises(InvalidEdit):
            resolve_conflict(conflict,'server',observed_server=25,observed_sheet=36)

    def test_invalid_values_are_held_with_original_values_for_review(self):
        for invalid in (True,0,-1,'abc',float('nan'),12.5):
            with self.subTest(invalid=invalid):
                result=reconcile(12,12,invalid)
                self.assertEqual(result.action,'needs_review')
                self.assertEqual(str(result.sheet),str(invalid))

    def test_numeric_boolean_is_invalid_and_unchanged_invalid_edit_can_be_identified(self):
        self.assertEqual(reconcile_field('Consumer Price',65000,70000,True).action,'needs_review')
        self.assertTrue(field_values_equal('Consumer Price','not a price','not a price'))
        self.assertFalse(field_values_equal('Consumer Price','not a price','another invalid edit'))

if __name__=='__main__':unittest.main()
