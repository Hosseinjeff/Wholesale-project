import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from catalog.inventory_lots import InventoryLots
from catalog.order_fulfillment import OrderPlanConflict, PaidOrderPlanner
from catalog.durable import Handoffs
from catalog.sync import MemoryStore


class PaidOrderPlannerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'pipeline.sqlite')
        self.inventory = InventoryLots(self.path)
        self.planner = PaidOrderPlanner(self.path)

    def order(self, **changes):
        value = {
            'id': 'paid-1', 'woo_status': 'processing', 'payment_status': 'verified',
            'currency': 'IRT', 'total': 1_000_000,
            'lines': [{'id': 'product-1', 'sku': 'sku-1', 'supplier_offer_id': 'offer-1',
                       'name': 'Tuna', 'qty': 30, 'total': 1_000_000}],
        }
        value.update(changes)
        return value

    def receive(self, lot, units, expiry, received='2026-10-01'):
        self.inventory.receive(lot_id=lot, sku='sku-1', supplier='Supplier A', received_at=received,
                               production_date='', expiry_date=expiry, units=units, unit_cost=100_000)

    def terms(self, **changes):
        value = {'supplier': 'Supplier A', 'units_per_pack': 24, 'pack_price_toman': 1_200_000,
                 'minimum_packs': 1, 'pack_multiple': 1, 'source_offer_id': 'offer-1'}
        value.update(changes)
        return {'sku-1': value}

    def test_unpaid_snapshot_does_not_reserve(self):
        result = self.planner.plan(self.order(payment_status='pending'), today='2026-10-28')
        self.assertEqual(result['state'], 'payment_pending')
        paid = self.planner.plan(self.order(), today='2026-10-28')
        self.assertEqual(paid['state'], 'needs_owner_review')
        self.assertEqual(paid['procurement'][0]['state'], 'needs_supplier_terms')

    def test_paid_order_reserves_fefo_owned_stock_once(self):
        self.receive('late-lot', 10, '2027-12-01')
        self.receive('early-lot', 12, '2027-01-01')
        result = self.planner.plan(self.order(), today='2026-10-28')
        self.assertEqual(result['state'], 'needs_owner_review')
        self.assertEqual(result['lines'][0]['owned_units_reserved'], 22)
        self.assertEqual(result['lines'][0]['shortfall_units'], 8)
        self.assertEqual(result['lines'][0]['lots'][0]['lot_id'], 'early-lot')
        self.assertEqual(self.planner.plan(self.order(), today='2026-10-28'), result)
        with self.inventory._connect() as db:
            count = db.execute("SELECT COUNT(*) FROM stock_allocations WHERE state='reserved'").fetchone()[0]
        self.assertEqual(count, 2)

    def test_supplier_pack_proposal_requires_owner_note_and_exact_offer_reference(self):
        self.planner.plan(self.order(), today='2026-10-28')
        with self.assertRaises(ValueError):
            self.planner.plan(self.order(), today='2026-10-28', supplier_terms=self.terms())
        with self.assertRaises(ValueError):
            self.planner.plan(self.order(), today='2026-10-28',
                              supplier_terms=self.terms(source_offer_id='different-offer'),
                              actor_id='wp:1', note='checked')
        result = self.planner.plan(self.order(), today='2026-10-28', supplier_terms=self.terms(),
                                   actor_id='wp:1', note='Checked the exact supplier offer.')
        proposal = result['procurement'][0]
        self.assertEqual(result['state'], 'procurement_proposed')
        self.assertEqual(proposal['supplier_packs'], 2)
        self.assertEqual(proposal['purchase_cost_toman'], 2_400_000)
        self.assertEqual(proposal['expected_surplus_units'], 18)
        self.assertFalse(proposal['purchase_authorized'])
        self.assertFalse(result['purchase_authorized'])

    def test_supplier_terms_merge_per_sku_and_order_edits_conflict(self):
        order = self.order(lines=[
            {'id': 'p1', 'sku': 'sku-1', 'supplier_offer_id': 'offer-1', 'name': 'Tuna', 'qty': 30},
            {'id': 'p2', 'sku': 'sku-2', 'supplier_offer_id': 'offer-2', 'name': 'Beans', 'qty': 5},
        ])
        self.planner.plan(order, today='2026-10-28')
        first = self.terms()
        first['sku-2'] = {**first['sku-1'], 'source_offer_id': 'offer-2'}
        result = self.planner.plan(order, today='2026-10-28', supplier_terms={'sku-1': first['sku-1']},
                                   actor_id='wp:1', note='Reviewed tuna terms.')
        result = self.planner.plan(order, today='2026-10-28', supplier_terms={'sku-2': first['sku-2']},
                                   actor_id='wp:1', note='Reviewed beans terms.')
        self.assertEqual(len(result['procurement']), 2)
        self.assertTrue(all(row['state'] == 'proposed' for row in result['procurement']))
        with self.planner._connect() as db:
            decisions = db.execute('SELECT * FROM paid_order_decisions').fetchall()
        self.assertEqual(len(decisions), 2)
        edited = self.order(total=999_000)
        with self.assertRaises(OrderPlanConflict):
            self.planner.plan(edited, today='2026-10-28')

    def test_cancel_or_refund_releases_only_unfulfilled_reservations(self):
        self.receive('lot-a', 10, '2027-12-01')
        paid = self.order(lines=[{'id': 'p', 'sku': 'sku-1', 'supplier_offer_id': 'offer-1',
                                  'name': 'Tuna', 'qty': 10}])
        self.planner.plan(paid, today='2026-10-28')
        result = self.planner.plan({**paid, 'woo_status': 'refunded', 'payment_status': 'refunded'},
                                   today='2026-10-29')
        self.assertEqual(result['state'], 'released')
        self.assertEqual(result['released_units'], 10)

    def test_durable_paid_order_creates_owner_review_and_authenticated_dashboard_proposal(self):
        import app
        store = MemoryStore()
        order = self.order()
        self.receive('lot-before-order', 12, '2027-09-01')
        headers = {'X-Molavi-Reference-Token': 'test-bridge-token-' * 3,
                   'X-Molavi-Actor': 'wp:123'}
        with patch.object(app, 'USE_SHEETS', True), patch.object(app, 'ensure_telegram_webhook'), \
                patch.object(app, 'build_store', return_value=store), \
                patch.dict('os.environ', {'PIPELINE_DB_PATH': self.path,
                    'REFERENCE_BRIDGE_TOKEN': headers['X-Molavi-Reference-Token'],
                    'REFERENCE_OWNER_IDS': 'wp:123'}):
            queue = Handoffs(self.path)
            receipt = queue.put('order', 'paid-order-receipt', {'order': order, 'notify': False})
            app.run_durable_handoff(queue, receipt)
            self.assertEqual(queue.get(receipt)['state'], 'done')
            client = app.app.test_client()
            listed = client.get('/pipeline/paid-orders', headers=headers)
            self.assertEqual(listed.status_code, 200)
            self.assertEqual(listed.get_json()['orders'][0]['state'], 'needs_owner_review')
            self.assertNotIn('phone', listed.get_json()['orders'][0])
            terms = self.terms()['sku-1']
            saved = client.post('/pipeline/paid-orders', headers=headers, json={
                'order_id': 'paid-1', 'supplier_terms': {'sku-1': terms},
                'note': 'Reviewed the exact source offer and fixed pack size.'})
            self.assertEqual(saved.status_code, 200, saved.get_json())
            self.assertEqual(saved.get_json()['state'], 'procurement_proposed')
            self.assertEqual(len(saved.get_json()['terms_hash']), 64)
            lot_payload = {'lot_id':'receipt-1','sku':'sku-1','supplier':'Supplier A',
                'purchase_date':'2026-10-01','received_at':'2026-10-28',
                'production_date':'2026-09-01','expiry_date':'2027-09-01',
                'units':8,'unit_cost':700_000,'note':'Received and counted sealed cases.'}
            lot_saved = client.post('/pipeline/stock-lots', headers=headers, json=lot_payload)
            self.assertEqual(lot_saved.status_code, 200, lot_saved.get_json())
            self.assertEqual(lot_saved.get_json()['state'], 'ready')
            duplicate = client.post('/pipeline/stock-lots', headers=headers, json=lot_payload)
            self.assertEqual(duplicate.get_json()['receipt_hash'], lot_saved.get_json()['receipt_hash'])
            listed_lots = client.get('/pipeline/stock-lots', headers=headers).get_json()['lots']
            received_lot = next(row for row in listed_lots if row['lot_id'] == 'receipt-1')
            self.assertEqual(received_lot['available_units'], 8)
            with self.planner._connect() as db:
                audit_count = db.execute('SELECT COUNT(*) FROM stock_lot_receipt_audit').fetchone()[0]
            self.assertEqual(audit_count, 1)
            changed = client.post('/pipeline/stock-lots', headers=headers,
                                  json={**lot_payload, 'units':9})
            self.assertEqual(changed.status_code, 409)
            cancelled = {**order, 'woo_status':'cancelled', 'payment_status':'pending'}
            terminal_receipt = queue.put('order', 'cancel-paid-order',
                                         {'order':cancelled,'notify':False})
            app.run_durable_handoff(queue, terminal_receipt)
            released = PaidOrderPlanner(self.path).get('paid-1')
            self.assertEqual(released['state'], 'released')
            self.assertEqual(released['released_units'], 12)
            denied = client.get('/pipeline/paid-orders', headers={**headers, 'X-Molavi-Actor': 'wp:999'})
            self.assertEqual(denied.status_code, 403)

    def test_paid_order_substitution_api_rechecks_owned_stock_and_records_customer_choice(self):
        import app
        from catalog.order_operations import OrderOperations
        store=MemoryStore()
        order={'id':'substitution-order','woo_status':'processing','payment_status':'verified',
            'currency':'IRT','total':3_000,'lines':[{'id':'sku-1','sku':'sku-1',
            'supplier_offer_id':'offer-1','name':'Tuna 180g','qty':3,'total':3_000}]}
        self.inventory.receive(lot_id='owned-alternative',sku='sku-alt',supplier='Supplier B',
            received_at='2026-10-01',production_date='',expiry_date='2027-12-01',units=3,unit_cost=700)
        plan=self.planner.plan(order,today='2026-10-28')
        OrderOperations(self.path).ensure(order['id'],plan,today='2026-10-28')
        headers={'X-Molavi-Reference-Token':'test-bridge-token-'*3,'X-Molavi-Actor':'wp:123'}
        catalog={'products':[{'product_id':'sku-alt','name':'Tuna Premium 180g','category':'canned fish',
            'floor':1_000,'ceiling':1_500}]}
        with patch.object(app,'USE_SHEETS',True), patch.object(app,'ensure_telegram_webhook'), \
                patch.object(app,'build_store',return_value=store), patch.object(app,'shop_catalog',return_value=catalog), \
                patch.dict('os.environ',{'PIPELINE_DB_PATH':self.path,
                    'REFERENCE_BRIDGE_TOKEN':headers['X-Molavi-Reference-Token'],'REFERENCE_OWNER_IDS':'wp:123'}):
            client=app.app.test_client()
            listed=client.get('/pipeline/paid-orders',headers=headers).get_json()['orders'][0]
            self.assertEqual(listed['substitution_candidates']['sku-1'][0]['sku'],'sku-alt')
            rejected=client.post('/pipeline/paid-orders',headers=headers,json={
                'order_id':order['id'],'action':'propose_substitution','details':{'sku':'sku-1',
                    'replacement_sku':'sku-alt','replacement_units':3,'proposed_line_total_toman':5_000},
                'note':'Attempted an out-of-range proposal.'})
            self.assertEqual(rejected.status_code,409)
            offered=client.post('/pipeline/paid-orders',headers=headers,json={
                'order_id':order['id'],'action':'propose_substitution','details':{'sku':'sku-1',
                    'replacement_sku':'sku-alt','replacement_units':3,'proposed_line_total_toman':3_600},
                'note':'Checked current alternative stock and price.'})
            self.assertEqual(offered.status_code,200,offered.get_json())
            self.assertEqual(offered.get_json()['action'],'propose_substitution')
            choice=client.post('/pipeline/paid-orders',headers=headers,json={
                'order_id':order['id'],'action':'record_customer_choice','details':{'sku':'sku-1',
                    'choice':'replacement','replacement_sku':'sku-alt','approved_line_total_toman':3_600,
                    'customer_difference_approved':True},
                'note':'Customer approved the replacement and difference.'})
            self.assertEqual(choice.status_code,200,choice.get_json())
            self.assertEqual(choice.get_json()['workflow']['resolutions']['sku-1']['state'],'replacement_accepted')
            with self.inventory._connect() as db:
                row=db.execute("SELECT units,state FROM stock_allocations WHERE order_id=? AND sku='sku-alt'",
                    (order['id'],)).fetchone()
            self.assertEqual((row['units'],row['state']),(3,'reserved'))


if __name__ == '__main__':
    unittest.main()
