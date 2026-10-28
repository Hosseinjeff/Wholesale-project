import os
import tempfile
import unittest
from datetime import date, datetime, timedelta
from pathlib import Path
from unittest.mock import patch
from zoneinfo import ZoneInfo

from catalog.clearance_pricing import ClearanceLedger, propose_clearance_price
from catalog.inventory_lots import InventoryLots


def calculate(**changes):
    values={'purchase_date':'2026-03-01','production_date':'2026-02-01',
        'expiry_date':'2026-12-01','today':'2026-10-01','purchase_cost':70_000,
        'normal_price':100_000,'market_price':110_000,'market_source':'Comparable listing',
        'market_observed_date':'2026-10-01','near_cost_threshold_toman':None}
    values.update(changes)
    return propose_clearance_price(**values)


class ClearancePriceTests(unittest.TestCase):
    def test_uses_purchase_and_production_dates_and_becomes_lower_than_normal(self):
        result=calculate()
        self.assertEqual(result['state'],'alert_threshold_required')
        self.assertEqual(result['age_basis'],'purchase_and_production')
        self.assertGreater(result['holding_urgency'],0.5)
        self.assertGreater(result['shelf_life_urgency'],0.5)
        self.assertEqual(result['suggested_price_toman'],76_347)
        self.assertLess(result['suggested_price_toman'],result['normal_price_toman'])
        self.assertGreaterEqual(result['suggested_price_toman'],result['purchase_cost_toman'])
        self.assertFalse(result['near_cost_alert'])

    def test_missing_production_falls_back_to_purchase_date(self):
        result=calculate(production_date=None)
        self.assertEqual(result['age_basis'],'purchase_date_fallback')
        self.assertIsNone(result['days_since_production'])

    def test_normal_period_and_stale_market_hold(self):
        normal=calculate(purchase_date='2026-09-20',production_date=None,
            expiry_date='2027-10-01',today='2026-10-01')
        self.assertEqual(normal['state'],'normal_period')
        self.assertIsNone(normal['suggested_price_toman'])
        stale=calculate(market_observed_date='2026-09-30')
        self.assertEqual(stale['reason'],'market_price_must_be_observed_today')

    def test_near_cost_alert_requires_a_positive_explicit_threshold(self):
        alerted=calculate(near_cost_threshold_toman=10_000)
        self.assertEqual(alerted['state'],'near_cost_alert')
        self.assertTrue(alerted['near_cost_alert'])
        invalid=calculate(near_cost_threshold_toman=0)
        self.assertEqual(invalid['reason'],'invalid_near_cost_threshold')

    def test_price_already_at_or_below_cost_is_review_only(self):
        result=calculate(market_price=60_000,near_cost_threshold_toman=10_000)
        self.assertEqual(result['state'],'near_cost_review')
        self.assertIsNone(result['suggested_price_toman'])
        self.assertTrue(result['near_cost_alert'])

    def test_ledger_is_idempotent_and_private(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'pipeline.sqlite'
            ledger=ClearanceLedger(path)
            values={'lot_id':'batch-a','sku':'tuna','receipt_hash':'a'*64,'actor_id':'wp:7',
                'note':'Compared the same unit and basket.','inputs':{'market_price_toman':110_000},
                'result':calculate(),'created_at':'2026-10-01T12:00:00+03:30'}
            first=ledger.record(**values)
            second=ledger.record(**values)
            self.assertEqual(first['proposal_id'],second['proposal_id'])
            self.assertEqual(len(ledger.recent()),1)
            self.assertEqual(os.stat(path).st_mode & 0o777,0o600)


class ClearanceProposalRouteTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path=str(Path(self.tmp.name)/'pipeline.sqlite')
        self.today=datetime.now(ZoneInfo('Asia/Tehran')).date()
        inventory=InventoryLots(self.path)
        bought=self.today-timedelta(days=180)
        produced=bought-timedelta(days=14)
        expiry=self.today+timedelta(days=90)
        inventory.receive(lot_id='batch-a',sku='sku-a',supplier='Supplier A',
            purchase_date=bought.isoformat(),received_at=bought.isoformat(),
            production_date=produced.isoformat(),expiry_date=expiry.isoformat(),units=24,unit_cost=70_000)
        self.headers={'X-Molavi-Reference-Token':'synthetic-clearance-token-0123456789',
            'X-Molavi-Actor':'wp:7'}
        self.env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
            'REFERENCE_BRIDGE_TOKEN':self.headers['X-Molavi-Reference-Token']}
        self.payload={'lot_id':'batch-a','receipt_hash':inventory.get_lot('batch-a')['receipt_hash'],
            'normal_price_toman':100_000,'market_price_toman':110_000,'market_source':'Synthetic market quote',
            'market_observed_date':self.today.isoformat(),'note':'Same unit and basket checked.'}

    def test_owner_get_post_stale_hash_and_payload_are_private(self):
        import app
        with patch.dict(os.environ,self.env):
            client=app.app.test_client()
            self.assertEqual(client.get('/pipeline/clearance-proposals').status_code,401)
            self.assertEqual(client.get('/pipeline/clearance-proposals',headers={
                **self.headers,'X-Molavi-Actor':'wp:8'}).status_code,403)
            listed=client.get('/pipeline/clearance-proposals',headers=self.headers)
            self.assertEqual(listed.status_code,200,listed.get_json())
            self.assertEqual(listed.headers.get('Cache-Control'),'no-store')
            self.assertEqual(listed.get_json()['lots'][0]['receipt_hash'],self.payload['receipt_hash'])
            stale=client.post('/pipeline/clearance-proposals',headers=self.headers,
                json={**self.payload,'receipt_hash':'b'*64})
            self.assertEqual(stale.status_code,409)
            saved=client.post('/pipeline/clearance-proposals',headers=self.headers,json=self.payload)
            self.assertEqual(saved.status_code,200,saved.get_json())
            self.assertEqual(saved.get_json()['lot_id'],'batch-a')
            self.assertIn(saved.get_json()['result']['state'],{'proposed','alert_threshold_required','near_cost_alert'})
            self.assertEqual(len(client.get('/pipeline/clearance-proposals',headers=self.headers)
                .get_json()['proposals']),1)

    def test_missing_purchase_date_and_stale_market_are_rejected(self):
        import app
        inventory=InventoryLots(self.path)
        inventory.receive(lot_id='legacy',sku='sku-b',supplier='Supplier A',received_at=self.today.isoformat(),
            purchase_date=None,production_date=None,expiry_date=(self.today+timedelta(days=90)).isoformat(),
            units=2,unit_cost=50_000)
        with patch.dict(os.environ,self.env):
            client=app.app.test_client()
            missing=client.post('/pipeline/clearance-proposals',headers=self.headers,json={**self.payload,
                'lot_id':'legacy','receipt_hash':inventory.get_lot('legacy')['receipt_hash']})
            self.assertEqual(missing.status_code,409)
            stale=client.post('/pipeline/clearance-proposals',headers=self.headers,json={**self.payload,
                'market_observed_date':(self.today-timedelta(days=1)).isoformat()})
            self.assertEqual(stale.status_code,200)
            self.assertEqual(stale.get_json()['result']['reason'],'market_price_must_be_observed_today')

    def test_owner_can_complete_only_the_missing_historical_purchase_date(self):
        import app
        inventory=InventoryLots(self.path)
        inventory.receive(lot_id='legacy-date',sku='sku-c',supplier='Supplier A',
            received_at=self.today.isoformat(),purchase_date=None,production_date=None,
            expiry_date=(self.today+timedelta(days=90)).isoformat(),units=2,unit_cost=50_000)
        old_hash=inventory.get_lot('legacy-date')['receipt_hash']
        payload={'lot_id':'legacy-date','receipt_hash':old_hash,
            'purchase_date':(self.today-timedelta(days=3)).isoformat(),
            'note':'Verified from supplier invoice.'}
        with patch.dict(os.environ,self.env):
            client=app.app.test_client()
            denied=client.post('/pipeline/stock-lots/purchase-date',json=payload)
            self.assertEqual(denied.status_code,401)
            saved=client.post('/pipeline/stock-lots/purchase-date',headers=self.headers,json=payload)
        self.assertEqual(saved.status_code,200,saved.get_json())
        self.assertEqual(saved.headers.get('Cache-Control'),'no-store')
        self.assertNotEqual(saved.get_json()['receipt_hash'],old_hash)
        self.assertEqual(inventory.get_lot('legacy-date')['purchase_date'],payload['purchase_date'])


if __name__=='__main__':
    unittest.main()
