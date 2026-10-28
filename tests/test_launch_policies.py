import unittest
from datetime import datetime,timedelta,timezone
from catalog.launch_policies import supplier_price_state,tehran_delivery

class LaunchPoliciesTests(unittest.TestCase):
    def test_supplier_price_warning_and_block_boundaries(self):
        now=datetime(2026,10,28,tzinfo=timezone.utc)
        for delta,expected in ((timedelta(days=7,seconds=-1),'current'),(timedelta(days=7),'warning'),
                               (timedelta(days=14,seconds=-1),'warning'),(timedelta(days=14),'blocked')):
            with self.subTest(delta=delta):self.assertEqual(supplier_price_state(now-delta,now),expected)

    def test_missing_or_future_verification_never_counts_as_current(self):
        now=datetime(2026,10,28,tzinfo=timezone.utc)
        for last in (None,datetime(2026,10,1),now+timedelta(seconds=1)):
            self.assertEqual(supplier_price_state(last,now),'needs_review')

    def test_tehran_fee_and_free_delivery_boundary(self):
        self.assertEqual(tehran_delivery(0)['delivery_payable'],0)
        self.assertEqual(tehran_delivery(4_999_999)['delivery_payable'],250_000)
        self.assertEqual(tehran_delivery(5_000_000)['delivery_payable'],0)
        self.assertEqual(tehran_delivery(5_000_000)['delivery_discount'],250_000)

    def test_invalid_money_and_config_fail_closed(self):
        for amount in (-1, True, 2.5, '5000000'):
            with self.assertRaises(ValueError):tehran_delivery(amount)
        with self.assertRaises(ValueError):tehran_delivery(1,threshold=0)
        now=datetime.now(timezone.utc)
        with self.assertRaises(ValueError):supplier_price_state(now,now,warning=timedelta(days=14),blocking=timedelta(days=7))

if __name__=='__main__':unittest.main()
