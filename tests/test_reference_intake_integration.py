import json,unittest
from datetime import datetime,timezone
from catalog.sync import MemoryStore,finalize_batch
from reference_import.importer import parse_rows

class IntakeReferenceIntegrationTests(unittest.TestCase):
 def test_batch_records_snapshot_match_and_preserves_supplier_price(self):
  store=MemoryStore();now=datetime.now(timezone.utc).isoformat()
  msg='کنسرو ماهی ۱۸۰ گرمی تاپ\nدونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی'
  from catalog.extract import extract_products
  extracted=extract_products(msg,'@bonakdarjavan')[0];name=extracted['name']
  rows=parse_rows(json.dumps([dict(raw_name=name,clean_name=name,package_price=1250000,package_count=24,product_code='r1',pdf_row_number='1',volume=extracted.get('volume',''),brand=extracted.get('brand',''))]),now)
  snapshot={'snapshot_id':'snapshot-test','rows':rows}
  payload={'batch_id':'batch-1','messages':[{'id':'m1','content':msg,'channel_username':'@bonakdarjavan'}]}
  result=finalize_batch(store,payload,reference_snapshot=snapshot)
  self.assertEqual(result['extraction_status'],'success')
  headers,row=store.read('Offers')[0],store.read('Offers')[-1]
  self.assertEqual(row[headers.index('Reference Snapshot ID')],'snapshot-test')
  self.assertEqual(row[headers.index('Reference Match ID')],'r1')
  self.assertEqual(row[headers.index('Sale Price')],52000)

 def test_unmatched_reference_does_not_fabricate_a_price(self):
  store=MemoryStore();payload={'batch_id':'batch-2','messages':[{'id':'m1','content':'کنسرو ماهی ۱۸۰ گرمی تاپ\nدونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی','channel_username':'@bonakdarjavan'}]}
  result=finalize_batch(store,payload,reference_snapshot={'snapshot_id':'s','rows':[]})
  self.assertEqual(result['extraction_status'],'success')
  headers,row=store.read('Offers')[0],store.read('Offers')[-1]
  self.assertEqual(row[headers.index('Reference Match State')],'no_compatible_reference')
  self.assertEqual(row[headers.index('Reference Price Role Hint')],'needs_review')
  self.assertEqual(row[headers.index('Status')],'needs_review')

 def test_ambiguous_unit_pack_role_is_hidden_until_owner_review(self):
  from catalog.sync import import_product_data
  store=MemoryStore();stamp=datetime.now(timezone.utc).isoformat()
  rows=parse_rows(json.dumps([dict(raw_name='چای',clean_name='چای',package_price=50000,
      package_count=1,product_code='single-reference',pdf_row_number='1')]),stamp)
  product={'name':'چای','sale_price':50000,'price':50000,'consumer_price':70000,
      'actual_price':70000,'price_type':'unit','packaging':'','extraction_confidence':0.9}
  result=import_product_data(store,{'id':'ambiguous','batch_id':'ambiguous-batch',
      'channel_username':'@supplier','content':'چای'},
      reference_snapshot={'snapshot_id':'snapshot-role','rows':rows},prepared_products=[product])
  self.assertTrue(result['success'])
  offer_headers,row=store.read('Offers')[0],store.read('Offers')[-1]
  self.assertEqual(row[offer_headers.index('Reference Match State')],'exact_compatible_identity')
  self.assertEqual(row[offer_headers.index('Reference Price Role Hint')],'needs_review')
  self.assertEqual(row[offer_headers.index('Status')],'needs_review')
  self.assertIn('reference: price_role_needs_review',row[offer_headers.index('Review Reason')])
  self.assertEqual(row[offer_headers.index('Sale Price')],50000)

if __name__=='__main__':unittest.main()

class ReferenceQueueIntegrationTests(unittest.TestCase):
 def test_missing_snapshot_waits_without_publishing_and_decision_api_is_protected(self):
  import os,tempfile
  from pathlib import Path
  from unittest.mock import patch
  from catalog.durable import Handoffs
  import app
  with tempfile.TemporaryDirectory() as directory:
   db=str(Path(directory)/'pipeline.sqlite');ref=str(Path(directory)/'reference.sqlite')
   queue=Handoffs(db);queue.receive({'id':'1','channel_username':'supplier','content':'sample'});job=queue.batch()
   env={'REFERENCE_STORE_PATH':ref,'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':'synthetic-reference-token-1234567890'}
   with patch.dict(os.environ,env),patch.object(app,'USE_SHEETS',True),patch.object(app,'ensure_telegram_webhook'),patch.object(app,'build_store',return_value=MemoryStore()),patch.object(app,'publish_shop') as publish:
    app.run_durable_handoff(queue,job)
    self.assertEqual(queue.get(job)['state'],'waiting_reference')
    publish.assert_not_called()
    client=app.app.test_client()
    self.assertEqual(client.get('/reference/decisions').status_code,401)
    response=client.get('/reference/decisions',headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'})
    self.assertEqual(response.status_code,200)
    self.assertEqual(response.get_json()['events'][0]['reason'],'missing')

 def test_stale_reference_waits_for_owner_then_intake_uses_selected_snapshot(self):
  import os,tempfile
  from datetime import timedelta
  from pathlib import Path
  from unittest.mock import patch
  from catalog.durable import Handoffs
  from catalog.extract import extract_products
  from reference_import.snapshots import ReferenceStore
  from reference_import.importer import HEADERS,SOURCE_URL
  import app
  with tempfile.TemporaryDirectory() as directory:
   db=str(Path(directory)/'pipeline.sqlite');ref=str(Path(directory)/'reference.sqlite')
   snapshot_store=ReferenceStore(ref,owner_ids=['wp:7'])
   now=datetime.now(timezone.utc);stamp=(now-timedelta(hours=25)).isoformat()
   msg='کنسرو ماهی ۱۸۰ گرمی تاپ\nدونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی'
   extracted=extract_products(msg,'@bonakdarjavan')[0];name=extracted['name']
   rows=parse_rows(json.dumps([dict(raw_name=name,clean_name=name,package_price=1250000,package_count=24,product_code='r1',pdf_row_number='1',volume=extracted.get('volume',''))]),stamp)
   snapshot={'status':'staged','source_url':SOURCE_URL,'headers':list(HEADERS),'extracted_at':stamp,
       'row_count':1,'source_page_count':1,'processed_page_count':1,'first_pdf_row_number':1,
       'last_pdf_row_number':1,'coverage_verified':True,'rows':rows}
   version=snapshot_store.stage(snapshot);snapshot_store.review(version,actor_id='wp:7',note='synthetic coverage')
   snapshot_store.activate(version,actor_id='wp:7',expected_active_id=None)
   queue=Handoffs(db);queue.receive({'id':'m1','content':msg,'channel_username':'@bonakdarjavan'});job=queue.batch()
   store=MemoryStore();env={'REFERENCE_STORE_PATH':ref,'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':'synthetic-reference-token-1234567890'}
   with patch.dict(os.environ,env),patch.object(app,'USE_SHEETS',True),patch.object(app,'ensure_telegram_webhook'),patch.object(app,'build_store',return_value=store),patch.object(app,'publish_shop') as publish:
    app.run_durable_handoff(queue,job)
    self.assertEqual(queue.get(job)['state'],'waiting_reference');publish.assert_not_called()
    client=app.app.test_client();headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
    response=client.post('/reference/decisions',json={'event_id':'stale:'+version,'snapshot_id':version,'choice':'use_last_snapshot'},headers=headers)
    self.assertEqual(response.status_code,200)
    with queue.connect() as conn:conn.execute('UPDATE jobs SET available=0 WHERE id=?',(job,))
    app.run_durable_handoff(queue,job)
   self.assertEqual(queue.get(job)['state'],'done')
   h,r=store.read('Offers')[0],store.read('Offers')[-1]
   self.assertEqual(r[h.index('Reference Snapshot ID')],version)
   self.assertEqual(r[h.index('Reference Match ID')],'r1')
   self.assertTrue(r[h.index('Reference Usage Warning')])
   self.assertEqual(r[h.index('Sale Price')],52000)

 def test_item_review_corrects_current_projection_and_queues_guarded_shop_sync(self):
  import os,tempfile
  from pathlib import Path
  from unittest.mock import patch
  from catalog.durable import Handoffs
  from catalog.extract import OFFER_HEADERS,PRODUCT_HEADERS
  from catalog.sync import _row_from_map
  from reference_import.snapshots import ReferenceStore
  from reference_import.importer import HEADERS,SOURCE_URL
  import app
  with tempfile.TemporaryDirectory() as directory:
   db=str(Path(directory)/'pipeline.sqlite');ref=str(Path(directory)/'reference.sqlite')
   now=datetime.now(timezone.utc).isoformat()
   item=dict(raw_name='چای سبز',clean_name='چای سبز',package_price=480000,package_count=12,
             unit_price=40000,product_code='R-1',brand='الف',category='نوشیدنی',pdf_row_number='1')
   rows=parse_rows(json.dumps([item]),now)
   reference={'status':'staged','source_url':SOURCE_URL,'headers':list(HEADERS),
       'extracted_at':now,'row_count':1,'source_page_count':1,'processed_page_count':1,
       'first_pdf_row_number':1,'last_pdf_row_number':1,'coverage_verified':True,'rows':rows}
   references=ReferenceStore(ref,owner_ids=['wp:7']);snapshot_id=references.stage(reference)
   references.review(snapshot_id,actor_id='wp:7',note='synthetic current reference reviewed')
   references.activate(snapshot_id,actor_id='wp:7',expected_active_id=None)
   store=MemoryStore();store.ensure('Offers',OFFER_HEADERS);store.ensure('Products',PRODUCT_HEADERS)
   offer={'Offer ID':'batch:msg:0','Product Name':'چای سبز','Channel Username':'@supplier',
       'Sale Price':450000,'Price':450000,'Consumer Price':600000,'Actual Price':600000,
       'Status':'needs_review','Review Reason':'reference: multiple_candidates',
       'Reference Snapshot ID':snapshot_id,'Reference Match State':'multiple_candidates',
       'Reference Price Role Hint':'needs_review','Brand':'الف','Category':'نوشیدنی',
       'Package Count':12,'Packaging':'باکس 12 عددی','Import Timestamp':now}
   store.append('Offers',[_row_from_map(OFFER_HEADERS,offer)])
   product={'Product Name':'چای سبز','Channel Username':'@supplier','Latest Offer ID':'batch:msg:0',
       'Sale Price':450000,'Consumer Price':600000,'Status':'needs_review'}
   store.append('Products',[_row_from_map(PRODUCT_HEADERS,product)])
   queue=Handoffs(db)
   env={'PIPELINE_DB_PATH':db,'REFERENCE_STORE_PATH':ref,'REFERENCE_OWNER_IDS':'wp:7',
        'REFERENCE_BRIDGE_TOKEN':'synthetic-item-review-token-1234567890'}
   with patch.dict(os.environ,env),patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=store):
    client=app.app.test_client();url='/reference/item-reviews'
    self.assertEqual(client.get(url).status_code,401)
    headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
    listing=client.get(url,headers=headers)
    self.assertEqual(listing.status_code,200)
    case=listing.get_json()['cases'][0]
    self.assertEqual(case['offer_id'],'batch:msg:0')
    payload={'case_id':case['case_id'],'offer_id':case['offer_id'],'snapshot_id':snapshot_id,
        'choice':'match','reference_id':'R-1','price_role':'package','note':'Verified reference carton',
        'expected_decision_id':None}
    response=client.post(url,json=payload,headers=headers)
    self.assertEqual(response.status_code,200,response.get_json())
    self.assertEqual(response.get_json()['projection_state'],'imported')
    self.assertTrue(response.get_json()['publish_job'])
    product_headers=store.read('Products')[0];projected=store.read('Products')[1]
    self.assertEqual(projected[product_headers.index('Sale Price')],37500)
    self.assertEqual(projected[product_headers.index('Reference Match ID')],'R-1')
    self.assertEqual(projected[product_headers.index('Status')],'imported')
    offer_headers=store.read('Offers')[0];original=store.read('Offers')[1]
    self.assertEqual(original[offer_headers.index('Sale Price')],450000)
    self.assertEqual(original[offer_headers.index('Status')],'needs_review')
    queue_view=client.get(url,headers=headers).get_json()
    self.assertEqual(queue_view['cases'],[])
    self.assertEqual(len(queue_view['recent_decisions']),1)
    retract=dict(payload,choice='no_match',reference_id='',price_role='unresolved',
        note='Reference selection needs correction',expected_decision_id=response.get_json()['decision_id'])
    held=client.post(url,json=retract,headers=headers)
    self.assertEqual(held.status_code,200,held.get_json())
    self.assertEqual(held.get_json()['projection_state'],'held_for_review')
    product_headers=store.read('Products')[0];projected=store.read('Products')[1]
    self.assertEqual(projected[product_headers.index('Status')],'needs_review')
    self.assertEqual(projected[product_headers.index('Sale Price')],450000)
    restore=dict(payload,expected_decision_id=held.get_json()['decision_id'],
        note='Confirmed correct carton mapping')
    restored=client.post(url,json=restore,headers=headers)
    self.assertEqual(restored.status_code,200,restored.get_json())
    self.assertEqual(store.read('Products')[1][product_headers.index('Sale Price')],37500)
