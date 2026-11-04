import tempfile,unittest
import os
from pathlib import Path
from datetime import datetime,timedelta,timezone
from catalog.supplier_freshness import PriceFreshness, current_supplier_offers, source_price_revision
from catalog.sync import MemoryStore, PRODUCT_HEADERS, OFFER_HEADERS, _restore_current_book, _row_from_map
from catalog.durable import Handoffs
from unittest.mock import patch

class SupplierFreshnessTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
  self.path=Path(self.tmp.name)/'freshness.sqlite';self.ledger=PriceFreshness(self.path)
  self.now=datetime(2026,10,28,tzinfo=timezone.utc)
 def test_old_post_does_not_become_fresh_on_reimport(self):
  post=self.now-timedelta(days=15)
  self.ledger.observe_source(offer_id='A',source_revision='post1',source_timestamp=post,now=self.now)
  self.assertEqual(self.ledger.state('A',now=self.now,expected_revision='post1')['state'],'blocked')
  self.assertFalse(self.ledger.observe_source(offer_id='A',source_revision='post1',source_timestamp=self.now,now=self.now))
  self.assertEqual(PriceFreshness(self.path).state('A',now=self.now,expected_revision='post1')['state'],'blocked')
 def test_new_verified_revision_and_manual_verification(self):
  self.ledger.observe_source(offer_id='A',source_revision='post1',source_timestamp=self.now-timedelta(days=15),now=self.now)
  self.assertTrue(self.ledger.observe_source(offer_id='A',source_revision='post2',source_timestamp=self.now-timedelta(days=8),now=self.now))
  self.assertEqual(self.ledger.state('A',now=self.now,expected_revision='post2')['state'],'warning')
  self.ledger.verify_manually(offer_id='A',source_revision='post2',actor_id='owner',note='supplier confirmed today',now=self.now)
  self.assertEqual(self.ledger.state('A',now=self.now,expected_revision='post2')['state'],'current')
  self.assertFalse(self.ledger.observe_source(offer_id='A',source_revision='old',source_timestamp=self.now-timedelta(days=10),now=self.now))
  self.assertEqual(self.ledger.state('A',now=self.now,expected_revision='old')['state'],'needs_review')
  with self.ledger._connect() as db:
   self.assertEqual(db.execute('SELECT COUNT(*) FROM supplier_price_evidence_history').fetchone()[0],3)
   self.assertEqual(db.execute("SELECT note FROM supplier_price_evidence_history WHERE method='manual'").fetchone()['note'],'supplier confirmed today')
 def test_missing_future_or_unknown_verification_is_not_silently_valid(self):
  self.assertEqual(self.ledger.state('unknown',now=self.now,expected_revision='x')['state'],'needs_review')
  with self.assertRaises(ValueError):self.ledger.observe_source(offer_id='A',source_revision='x',source_timestamp=self.now+timedelta(days=1),now=self.now)
  with self.assertRaises(ValueError):self.ledger.verify_manually(offer_id='unknown',source_revision='x',actor_id='owner',note='',now=self.now)

 def test_sheet_price_edit_invalidates_existing_manual_verification(self):
  product_headers=['Product ID','Product Name','Channel Username','Sale Price','Currency','Latest Offer ID','Message Timestamp','Source Timestamp Basis']
  old_stamp=int((self.now-timedelta(days=15)).timestamp())
  row=['product-1','چای','@supplier',100,'IRR','offer-1',old_stamp,'supplier_channel_update']
  store=MemoryStore();store.sheets['Products']=[product_headers,row]
  offers=current_supplier_offers(store,self.ledger,now=self.now)
  self.assertEqual(offers[0]['state'],'blocked')
  revision=source_price_revision(dict(zip(product_headers,row)))
  self.assertEqual(source_price_revision({**dict(zip(product_headers,row)),'Sale Price':'۴۰۰,۰۰۰'}),source_price_revision({**dict(zip(product_headers,row)),'Sale Price':'400000'}))
  self.ledger.verify_manually(offer_id='product-1',source_revision=revision,actor_id='wp:7',note='supplier confirmed by phone',now=self.now)
  self.assertEqual(current_supplier_offers(store,self.ledger,now=self.now)[0]['state'],'current')
  store.sheets['Products'][1][3]=120
  self.assertEqual(current_supplier_offers(store,self.ledger,now=self.now)[0]['state'],'needs_review')

 def test_owner_submission_timestamp_needs_manual_source_verification(self):
  headers=['Product ID','Product Name','Channel Username','Sale Price','Currency','Latest Offer ID',
   'Message Timestamp','Source Timestamp Basis']
  row=['owner-product','چای','DirectMessage',100,'IRR','owner-offer',int(self.now.timestamp()),'owner_submission']
  store=MemoryStore();store.sheets['Products']=[headers,row]
  current=current_supplier_offers(store,self.ledger,now=self.now)[0]
  self.assertFalse(current['source_timestamp_trusted'])
  self.assertEqual(current['state'],'needs_review')
  self.assertIsNone(current['verified_at'])
  self.ledger.verify_manually(offer_id='owner-product',source_revision=current['source_revision'],
   actor_id='wp:7',note='checked the current supplier post',now=self.now)
  self.assertEqual(current_supplier_offers(store,self.ledger,now=self.now)[0]['state'],'current')

 def test_timestamp_basis_change_invalidates_price_verification(self):
  row={'Product ID':'p1','Sale Price':100,'Currency':'IRR','Latest Offer ID':'o1',
   'Source Timestamp Basis':'supplier_channel_update'}
  original=source_price_revision(row)
  self.assertNotEqual(original,source_price_revision({**row,'Source Timestamp Basis':'owner_submission'}))

 def test_later_reimport_of_older_post_does_not_replace_newer_source_row(self):
  store=MemoryStore();store.ensure('Offers',OFFER_HEADERS);store.ensure('Products',PRODUCT_HEADERS)
  newer_time=int((self.now-timedelta(days=2)).timestamp())
  older_time=int((self.now-timedelta(days=20)).timestamp())
  newer={'Offer ID':'newer:1','Product Name':'Tuna sample','Channel Username':'@supplier',
   'Sale Price':50000,'Consumer Price':70000,'Price':50000,'Actual Price':70000,
   'Currency':'IRT','Stock Status':'Unknown','Status':'imported',
   'Message Timestamp':newer_time,'Import Timestamp':'2026-10-26T10:00:00Z'}
  older={**newer,'Offer ID':'older:1','Sale Price':40000,'Price':40000,
   'Message Timestamp':older_time,'Import Timestamp':'2026-10-28T10:00:00Z'}
  store.append('Offers',[_row_from_map(OFFER_HEADERS,newer),_row_from_map(OFFER_HEADERS,older)])
  _restore_current_book(store,[('Tuna sample','@supplier')])
  rows=store.read('Products');headers=rows[0];current=rows[1]
  self.assertEqual(current[headers.index('Latest Offer ID')],'newer:1')
  self.assertEqual(current[headers.index('Sale Price')],50000)
  self.assertEqual(current[headers.index('Message Timestamp')],newer_time)


class SupplierFreshnessRouteTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
  self.now=datetime.now(timezone.utc)
  self.db=str(Path(self.tmp.name)/'pipeline.sqlite')
  headers=['Product ID','Product Name','Channel Username','Sale Price','Consumer Price','Currency','Latest Offer ID','Message Timestamp','Source Timestamp Basis','Status','Stock Status']
  self.store=MemoryStore()
  self.store.sheets['Products']=[headers,['product-1','چای','@supplier',40000,50000,'IRR','offer-1',int((self.now-timedelta(days=8)).timestamp()),'supplier_channel_update','imported','Available']]
  offer={'Offer ID':'offer-1','Product Name':'چای','Channel Username':'@supplier',
   'Sale Price':40000,'Consumer Price':50000,'Currency':'IRR',
   'Message Timestamp':int((self.now-timedelta(days=8)).timestamp()),
   'Source Timestamp Basis':'supplier_channel_update'}
  self.store.sheets['Offers']=[OFFER_HEADERS,_row_from_map(OFFER_HEADERS,offer)]
  self.env={'PIPELINE_DB_PATH':self.db,'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':'synthetic-reference-token-1234567890'}
  from catalog.supplier_freshness import source_price_revision
  self.revision=source_price_revision(dict(zip(headers,self.store.sheets['Products'][1])))

 def test_dashboard_warning_manual_verification_and_catalog_release(self):
  import app
  with patch.dict(os.environ,self.env),patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=self.store),patch.object(app,'ensure_telegram_webhook'):
   client=app.app.test_client();headers={'X-Molavi-Reference-Token':self.env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
   self.assertEqual(client.get('/pipeline/supplier-price-evidence',headers={**headers,'X-Molavi-Actor':'wp:9'}).status_code,403)
   response=client.get('/pipeline/supplier-price-evidence',headers=headers)
   self.assertEqual(response.get_json()['offers'][0]['state'],'warning')
   self.assertIn('product-1',[p['product_id'] for p in client.get('/api/shop').get_json()['products']])
   response=client.post('/pipeline/supplier-price-evidence',headers=headers,json={'product_id':'product-1','expected_revision':'','note':'checked supplier price'})
   self.assertEqual(response.status_code,409)
   self.assertEqual(response.get_json()['error'],'price_source_missing')
   response=client.post('/pipeline/supplier-price-evidence',headers=headers,json={'product_id':'product-1','expected_revision':'stale-revision','note':'checked supplier price'})
   self.assertEqual(response.status_code,409)
   response=client.post('/pipeline/supplier-price-evidence',headers=headers,json={'product_id':'product-1','expected_revision':self.revision,'note':'checked supplier price today'})
   self.assertEqual(response.status_code,200)
   self.assertEqual(Handoffs(self.db).get(response.get_json()['publish_job'])['kind'],'publish')
   self.assertEqual(client.get('/pipeline/supplier-price-evidence',headers=headers).get_json()['offers'],[])
   self.assertIn('product-1',[p['product_id'] for p in client.get('/api/shop').get_json()['products']])

 def test_invalid_price_verification_requests_return_specific_safe_errors(self):
  import app
  with patch.dict(os.environ,self.env),patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=self.store),patch.object(app,'ensure_telegram_webhook'):
   client=app.app.test_client();headers={'X-Molavi-Reference-Token':self.env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
   cases=[
    ({'product_id':'product-1','expected_revision':self.revision,'note':'checked','extra':'unexpected'},'invalid_body'),
    ({'product_id':7,'expected_revision':self.revision,'note':'checked'},'invalid_product_id'),
    ({'product_id':'product-1','expected_revision':self.revision,'note':'  '},'invalid_note'),
    ({'product_id':'product-1','expected_revision':'r'*201,'note':'checked'},'invalid_source_revision'),
   ]
   for body,error in cases:
    with self.subTest(error=error):
     response=client.post('/pipeline/supplier-price-evidence',headers=headers,json=body)
     self.assertEqual(response.status_code,400)
     self.assertEqual(response.get_json()['error'],error)

 def test_fourteen_day_offer_is_hidden_from_quote_until_reviewed(self):
  import app
  row=self.store.sheets['Products'][1]
  row[7]=int((self.now-timedelta(days=15)).timestamp())
  with patch.dict(os.environ,self.env),patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=self.store),patch.object(app,'ensure_telegram_webhook'):
   response=app.app.test_client().post('/api/cart',json={'lines':[{'product_id':'product-1','qty':1}]})
   self.assertEqual(response.status_code,200)
   self.assertEqual(response.get_json()['missing'],['product-1'])

if __name__=='__main__':unittest.main()
