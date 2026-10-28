import tempfile
import unittest
from pathlib import Path

from catalog.inventory_lots import InventoryLots
from catalog.order_fulfillment import PaidOrderPlanner
from catalog.order_operations import OrderOperationConflict, OrderOperations


class OrderOperationsTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'pipeline.sqlite')
        self.inventory = InventoryLots(self.path)
        self.planner = PaidOrderPlanner(self.path)
        self.operations = OrderOperations(self.path)

    def receive(self, lot, sku, units):
        return self.inventory.receive(lot_id=lot, sku=sku, supplier='Supplier A',
            received_at='2026-10-28', production_date='2026-09-01', expiry_date='2027-09-01',
            purchase_date='2026-10-28', units=units, unit_cost=600)

    def order(self, sku='item-a', qty=10, total=10_000):
        return {'id':'order-1','payment_status':'verified','woo_status':'processing','total':total,
            'lines':[{'id':sku,'sku':sku,'supplier_offer_id':'offer-a','name':'Tuna 180g',
                      'qty':qty,'total':total}]}

    def plan(self, order=None, terms=None):
        order = order or self.order()
        return self.planner.plan(order, today='2026-10-28', supplier_terms=terms or {
            'item-a': {'supplier':'Supplier A','source_offer_id':'offer-a','units_per_pack':6,
                       'pack_price_toman':3600,'minimum_packs':1,'pack_multiple':1},
        }, actor_id='wp:7', note='Reviewed pack terms.')

    def apply(self, order, plan, action, details, note='Verified by owner.'):
        return self.operations.apply(order=order,plan=plan,action=action,details=details,
            actor_id='wp:7',note=note,today='2026-10-28',inventory=self.inventory)

    def test_supplier_order_receipt_refreshes_plan_then_packs_and_closes(self):
        order=self.order()
        self.receive('owned-a','item-a',4)
        plan=self.plan(order)
        case=self.operations.ensure(order['id'],plan,today='2026-10-28')
        self.assertEqual(case['state'],'supplier_followup')
        self.apply(order,plan,'assign',{'assigned_to':'owner','next_action':'Call supplier','due_at':''})
        case=self.apply(order,plan,'record_supplier_order',{'sku':'item-a','supplier_reference':'PO-1',
            'packs':1,'purchase_date':'2026-10-28','expected_at':'2026-10-29'})
        self.assertEqual(case['procurement']['item-a']['state'],'ordered')
        self.assertEqual(case['procurement']['item-a']['purchase_date'],'2026-10-28')
        self.receive('arrival-a','item-a',6)
        case=self.apply(order,plan,'mark_supplier_received',{'sku':'item-a','lot_id':'arrival-a','accepted_units':6})
        refreshed=self.planner.get('order-1')
        self.assertEqual(refreshed['state'],'reserved')
        self.assertEqual(refreshed['lines'][0]['shortfall_units'],0)
        self.assertEqual(case['procurement']['item-a']['state'],'received')
        case=self.apply(order,refreshed,'mark_packed',{})
        self.assertEqual(case['packing']['status'],'packed')
        self.apply(order,refreshed,'mark_dispatched',{'tracking_reference':'delivery-1','carrier':'Owner delivery'})
        self.apply(order,refreshed,'mark_delivered',{})
        case=self.apply(order,refreshed,'close',{})
        self.assertEqual(case['state'],'closed')
        with self.inventory._connect() as db:
            states={row['state'] for row in db.execute('SELECT state FROM stock_allocations WHERE order_id=?',('order-1',))}
        self.assertEqual(states,{'fulfilled'})

    def test_supplier_purchase_date_is_validated_and_receipt_must_match(self):
        order=self.order()
        plan=self.plan(order)
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'record_supplier_order',{'sku':'item-a','supplier_reference':'PO-future',
                'packs':1,'purchase_date':'2026-10-29','expected_at':''})
        self.apply(order,plan,'record_supplier_order',{'sku':'item-a','supplier_reference':'PO-date',
            'packs':2,'purchase_date':'2026-10-27','expected_at':''})
        self.receive('arrival-mismatched','item-a',6)
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'mark_supplier_received',{'sku':'item-a',
                'lot_id':'arrival-mismatched','accepted_units':6})

    def test_partial_receipt_requires_recorded_customer_refund_before_pack(self):
        order=self.order()
        self.receive('owned-a','item-a',4)
        plan=self.plan(order)
        self.apply(order,plan,'record_supplier_order',{'sku':'item-a','supplier_reference':'PO-2',
            'packs':1,'purchase_date':'2026-10-28','expected_at':''})
        self.receive('arrival-a','item-a',2)
        self.apply(order,plan,'mark_supplier_received',{'sku':'item-a','lot_id':'arrival-a','accepted_units':2})
        plan=self.planner.get('order-1')
        self.assertEqual(plan['lines'][0]['shortfall_units'],4)
        self.apply(order,plan,'mark_supplier_unavailable',{'sku':'item-a'},
            note='Supplier confirmed the remaining units cannot be supplied.')
        case=self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'partial_refund',
            'refund_amount_toman':4000},note='Customer chose a partial refund for missing units.')
        refund=case['refunds'][0]
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'mark_packed',{})
        case=self.apply(order,plan,'confirm_refund',{'refund_id':refund['refund_id'],
            'amount_toman':4000,'transaction_reference':'refund-1'})
        case=self.apply(order,plan,'mark_packed',{})
        self.assertEqual(case['packing']['status'],'packed')

    def test_substitute_is_proposed_then_customer_approval_reserves_owned_units(self):
        order=self.order(qty=5,total=5000)
        self.receive('alternative-lot','item-b',5)
        plan=self.plan(order,terms={'item-a':{'supplier':'Supplier A','source_offer_id':'offer-a',
            'units_per_pack':6,'pack_price_toman':3600,'minimum_packs':1,'pack_multiple':1}})
        self.operations.ensure(order['id'],plan,today='2026-10-28')
        self.apply(order,plan,'propose_substitution',{'sku':'item-a','replacement_sku':'item-b',
            'replacement_units':5,'proposed_line_total_toman':5000})
        case=self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'replacement',
            'replacement_sku':'item-b','approved_line_total_toman':5000})
        self.assertEqual(case['resolutions']['item-a']['state'],'replacement_accepted')
        self.assertEqual(case['resolutions']['item-a']['settlement_status'],'equal')
        with self.inventory._connect() as db:
            row=db.execute("SELECT units,state FROM stock_allocations WHERE order_id='order-1' AND sku='item-b'").fetchone()
        self.assertEqual((row['units'],row['state']),(5,'reserved'))
        packed=self.apply(order,plan,'mark_packed',{})
        self.assertEqual(packed['packing']['status'],'packed')

    def test_price_difference_requires_customer_consent_and_manual_payment_receipt(self):
        order=self.order(qty=5,total=5000)
        self.receive('alternative-lot','item-b',5)
        plan=self.plan(order,terms={'item-a':{'supplier':'Supplier A','source_offer_id':'offer-a',
            'units_per_pack':6,'pack_price_toman':3600,'minimum_packs':1,'pack_multiple':1}})
        self.operations.ensure(order['id'],plan,today='2026-10-28')
        self.apply(order,plan,'propose_substitution',{'sku':'item-a','replacement_sku':'item-b',
            'replacement_units':5,'proposed_line_total_toman':6000})
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'replacement',
                'replacement_sku':'item-b','approved_line_total_toman':6000})
        case=self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'replacement',
            'replacement_sku':'item-b','approved_line_total_toman':6000,'customer_difference_approved':True})
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'mark_packed',{})
        case=self.apply(order,plan,'confirm_additional_payment',{'sku':'item-a','amount_toman':1000,
            'transaction_reference':'extra-payment-1'})
        self.assertEqual(case['resolutions']['item-a']['settlement_status'],'confirmed')
        self.apply(order,plan,'mark_packed',{})

    def test_idempotent_action_and_close_requires_delivery_and_refunds(self):
        order=self.order(qty=1,total=1000)
        self.receive('owned-a','item-a',1)
        plan=self.plan(order)
        self.operations.ensure(order['id'],plan,today='2026-10-28')
        details={'assigned_to':'owner','next_action':'Prepare','due_at':''}
        first=self.apply(order,plan,'assign',details)
        second=self.apply(order,plan,'assign',details)
        self.assertEqual(first,second)
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'close',{})

    def test_full_cancellation_keeps_an_already_placed_supplier_delivery_as_unreserved_stock(self):
        order=self.order(qty=10,total=10_000)
        self.receive('owned-a','item-a',4)
        plan=self.plan(order,terms={'item-a':{'supplier':'Supplier A','source_offer_id':'offer-a',
            'units_per_pack':6,'pack_price_toman':3600,'minimum_packs':1,'pack_multiple':1}})
        self.apply(order,plan,'record_supplier_order',{'sku':'item-a','supplier_reference':'PO-3',
            'packs':1,'purchase_date':'2026-10-28','expected_at':'2026-10-29'})
        case=self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'full_cancellation',
            'refund_amount_toman':10_000},note='Customer cancelled; full refund required.')
        self.assertEqual(case['state'],'cancelled_refund_pending')
        self.receive('arrival-cancelled-order','item-a',6)
        case=self.apply(order,plan,'mark_supplier_received',{'sku':'item-a',
            'lot_id':'arrival-cancelled-order','accepted_units':6})
        self.assertEqual(case['state'],'cancelled_refund_pending')
        self.assertEqual(case['procurement']['item-a']['state'],'received')
        with self.inventory._connect() as db:
            allocations=db.execute("SELECT COUNT(*) FROM stock_allocations WHERE order_id='order-1' AND state='reserved'").fetchone()[0]
        self.assertEqual(allocations,0)
        refund=case['refunds'][0]
        case=self.apply(order,plan,'confirm_refund',{'refund_id':refund['refund_id'],
            'amount_toman':10_000,'transaction_reference':'full-refund-1'})
        self.assertEqual(case['state'],'cancelled')
        arrival=next(row for row in self.inventory.list_lots(today='2026-10-28')
            if row['lot_id']=='arrival-cancelled-order')
        self.assertEqual(arrival['available_units'],6)

    def test_partial_then_full_refund_cannot_exceed_original_order_total(self):
        order=self.order(qty=10,total=10_000)
        self.receive('owned-a','item-a',4)
        plan=self.plan(order)
        case=self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'partial_refund',
            'refund_amount_toman':3_000},note='Customer chose partial refund for the shortage.')
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'full_cancellation',
                'refund_amount_toman':7_000},note='Attempted cancellation before first refund receipt.')
        refund=case['refunds'][0]
        case=self.apply(order,plan,'confirm_refund',{'refund_id':refund['refund_id'],
            'amount_toman':3_000,'transaction_reference':'partial-refund-2'})
        case=self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'full_cancellation',
            'refund_amount_toman':7_000},note='Customer later cancelled the remainder.')
        self.assertEqual(case['refunds'][-1]['amount_toman'],7_000)
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'full_cancellation',
                'refund_amount_toman':10_000},note='Attempted to refund the order total twice.')

    def test_customer_shortage_choice_waits_until_supplier_marks_an_open_line_unavailable(self):
        order=self.order(qty=6,total=6_000)
        plan=self.plan(order)
        self.apply(order,plan,'record_supplier_order',{'sku':'item-a','supplier_reference':'PO-open',
            'packs':1,'purchase_date':'2026-10-28','expected_at':''})
        with self.assertRaises(OrderOperationConflict):
            self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'partial_refund',
                'refund_amount_toman':6_000},note='Do not refund while supplier order remains open.')
        case=self.apply(order,plan,'mark_supplier_unavailable',{'sku':'item-a'},
            note='Supplier confirmed no delivery is forthcoming.')
        self.assertEqual(case['procurement']['item-a']['supplier_reference'],'PO-open')
        case=self.apply(order,plan,'record_customer_choice',{'sku':'item-a','choice':'partial_refund',
            'refund_amount_toman':6_000},note='Customer chose refund after supplier unavailability.')
        self.assertEqual(case['state'],'refund_pending')


if __name__ == '__main__':
    unittest.main()
