import tempfile, unittest, sqlite3
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from catalog.inventory_lots import InventoryLots,InventoryConflict,plan_supplier_packs

class InventoryLotsTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
  self.ledger=InventoryLots(Path(self.tmp.name)/'stock.db')
 def receive(self,lot,expiry,units=6,cost=70000):
  return self.ledger.receive(lot_id=lot,sku='sample',supplier='supplier',received_at='2026-10-01',production_date=None,expiry_date=expiry,units=units,unit_cost=cost)
 def test_supplier_fixed_pack_shortfall_and_surplus(self):
  self.assertEqual(plan_supplier_packs(6,2,12),{'customer_units':6,'owned_stock_used':2,'supplier_packs':1,'supplier_units':12,'unallocated_after_fulfillment':8})
  self.assertEqual(plan_supplier_packs(6,8,12)['supplier_packs'],0)
 def test_receipt_retry_conflict_and_expiry_review(self):
  self.assertEqual(self.receive('A','2027-01-01'),'ready')
  self.assertEqual(self.receive('A','2027-01-01'),'ready')
  with self.assertRaises(InventoryConflict):self.receive('A','2027-01-01',units=7)
  self.assertEqual(self.receive('unknown',None),'review')
 def test_reserve_earlier_lot_without_selling_unknown_or_expired(self):
  self.receive('later','2027-02-01');self.receive('earlier','2027-01-01');self.receive('unknown',None)
  allocations=self.ledger.reserve(order_id='order-1',sku='sample',units=8,today='2026-12-01')
  self.assertEqual({a['lot_id']:a['units'] for a in allocations},{'earlier':6,'later':2})
  other=self.ledger.reserve(order_id='order-2',sku='sample',units=8,today='2027-01-02')
  self.assertEqual({a['lot_id']:a['units'] for a in other},{'later':4})
 def test_reservation_retry_change_and_cancellation(self):
  self.receive('A','2027-01-01')
  a=self.ledger.reserve(order_id='order-1',sku='sample',units=4,today='2026-12-01')
  self.assertEqual(self.ledger.reserve(order_id='order-1',sku='sample',units=4,today='2026-12-01'),a)
  with self.assertRaises(InventoryConflict):self.ledger.reserve(order_id='order-1',sku='sample',units=5,today='2026-12-01')
  self.ledger.change_reservation(order_id='order-1',sku='sample',from_state='reserved',to_state='released')
  new=self.ledger.reserve(order_id='order-2',sku='sample',units=6,today='2026-12-01')
  self.assertEqual(new[0]['units'],6)
 def test_same_paid_demand_can_be_topped_up_after_supplier_receipt(self):
  self.receive('owned','2027-01-01',units=2)
  first=self.ledger.reserve(order_id='order-1',sku='sample',units=6,today='2026-12-01')
  self.assertEqual(sum(row['units'] for row in first),2)
  self.receive('supplier-arrival','2027-02-01',units=8)
  topped=self.ledger.reserve(order_id='order-1',sku='sample',units=6,today='2026-12-01')
  self.assertEqual(sum(row['units'] for row in topped if row['state']=='reserved'),6)
  self.assertEqual({row['lot_id']:row['units'] for row in topped},{'owned':2,'supplier-arrival':4})
 def test_fulfilled_units_do_not_return_to_stock(self):
  self.receive('A','2027-01-01')
  self.ledger.reserve(order_id='1',sku='sample',units=4,today='2026-12-01')
  self.ledger.change_reservation(order_id='1',sku='sample',from_state='reserved',to_state='fulfilled')
  self.assertEqual(self.ledger.reserve(order_id='2',sku='sample',units=6,today='2026-12-01')[0]['units'],2)

 def test_concurrent_orders_cannot_reserve_same_units(self):
  self.receive('A','2027-01-01')
  def request(order):return self.ledger.reserve(order_id=order,sku='sample',units=6,today='2026-12-01')
  with ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(request,['1','2']))
  self.assertEqual(sum(item['units'] for allocation in results for item in allocation),6)
 def test_future_receipt_is_not_available(self):
  self.receive('A','2027-01-01')
  self.assertEqual(self.ledger.reserve(order_id='1',sku='sample',units=1,today='2026-09-01'),[])

 def test_only_missing_purchase_date_can_be_completed_with_matching_receipt_hash(self):
  self.receive('legacy','2027-01-01')
  old=self.ledger.get_lot('legacy')['receipt_hash']
  corrected=self.ledger.add_missing_purchase_date(lot_id='legacy',expected_receipt_hash=old,
      purchase_date='2026-09-01')
  lot=self.ledger.get_lot('legacy')
  self.assertEqual(lot['purchase_date'],'2026-09-01')
  self.assertNotEqual(corrected,old)
  with self.assertRaises(InventoryConflict):
   self.ledger.add_missing_purchase_date(lot_id='legacy',expected_receipt_hash=old,
       purchase_date='2026-09-02')

 def test_owner_stock_adjustment_is_idempotent_revision_bound_and_respects_reservations(self):
  self.receive('counted','2027-01-01',units=10)
  today='2026-10-01'
  lot=self.ledger.get_lot('counted')
  payload={'lot_id':'counted','expected_revision':lot['revision'],'actor_id':'wp:7',
      'action':'adjust','delta_units':-2,'reason':'count_correction',
      'note':'Counted eight sealed units.','created_at':'2026-10-01T10:00:00+03:30','today':today}
  first=self.ledger.apply_operator_event(**payload)
  retry=self.ledger.apply_operator_event(**payload)
  self.assertEqual(first['units'],8)
  self.assertTrue(retry['idempotent'])
  self.assertEqual(self.ledger.list_lots(today=today)[0]['available_units'],8)
  self.ledger.reserve(order_id='order-1',sku='sample',units=6,today=today)
  fresh=self.ledger.get_lot('counted')
  unsafe={**payload,'expected_revision':fresh['revision'],'delta_units':-3,'reason':'damage',
      'note':'Would remove reserved units.'}
  with self.assertRaises(InventoryConflict):self.ledger.apply_operator_event(**unsafe)

 def test_holding_lot_blocks_allocation_until_audited_release(self):
  self.receive('held','2027-01-01',units=10)
  today='2026-10-01';lot=self.ledger.get_lot('held')
  event={'lot_id':'held','expected_revision':lot['revision'],'actor_id':'wp:7',
      'action':'hold','delta_units':0,'reason':'quality','note':'Check seal integrity.',
      'created_at':'2026-10-01T10:00:00+03:30','today':today}
  self.ledger.apply_operator_event(**event)
  held=self.ledger.get_lot('held')
  self.assertEqual(held['state'],'held')
  self.assertEqual(next(row for row in self.ledger.list_lots(today=today)
      if row['lot_id']=='held')['available_units'],0)
  self.assertEqual(self.ledger.reserve(order_id='order-held',sku='sample',units=4,today=today),[])
  released=self.ledger.apply_operator_event(**{**event,'expected_revision':held['revision'],
      'action':'release','reason':'inspection_passed','note':'Seal passed inspection.'})
  self.assertEqual(released['state'],'ready')
  self.assertEqual(self.ledger.reserve(order_id='order-release',sku='sample',units=4,today=today)[0]['units'],4)

 def test_hold_requires_reservations_resolved(self):
  self.receive('allocated','2027-01-01',units=5)
  today='2026-10-01';lot=self.ledger.get_lot('allocated')
  self.ledger.reserve(order_id='order-1',sku='sample',units=3,today=today)
  with self.assertRaises(InventoryConflict):
   self.ledger.apply_operator_event(lot_id='allocated',expected_revision=lot['revision'],
       actor_id='wp:7',action='hold',delta_units=0,reason='quality',note='Hold the lot.',
       created_at='2026-10-01T10:00:00+03:30',today=today)

 def test_unknown_expiry_hold_cannot_be_released(self):
  self.receive('unknown',None)
  today='2026-10-01';lot=self.ledger.get_lot('unknown')
  held=self.ledger.apply_operator_event(lot_id='unknown',expected_revision=lot['revision'],
      actor_id='wp:7',action='hold',delta_units=0,reason='record_review',note='Find the expiry label.',
      created_at='2026-10-01T10:00:00+03:30',today=today)
  with self.assertRaises(InventoryConflict):
   self.ledger.apply_operator_event(lot_id='unknown',expected_revision=held['revision'],
       actor_id='wp:7',action='release',delta_units=0,reason='inspection_passed',
       note='Review complete.',created_at='2026-10-01T10:01:00+03:30',today=today)

 def test_existing_event_ledger_gains_reviewed_revision_column(self):
  with tempfile.TemporaryDirectory() as directory:
   path=Path(directory)/'stock.db'
   with sqlite3.connect(path) as db:
    db.execute('''CREATE TABLE stock_lot_events (
        sequence INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL UNIQUE,
        lot_id TEXT NOT NULL, actor_id TEXT NOT NULL, action TEXT NOT NULL,
        delta_units INTEGER NOT NULL, reason TEXT NOT NULL, note TEXT NOT NULL,
        created_at TEXT NOT NULL, new_state TEXT)''')
   InventoryLots(path)
   with sqlite3.connect(path) as db:
    columns={row[1] for row in db.execute('PRAGMA table_info(stock_lot_events)')}
   self.assertIn('expected_revision',columns)

if __name__=='__main__':unittest.main()
