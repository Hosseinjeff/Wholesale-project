import tempfile,threading,time,unittest,multiprocessing
from pathlib import Path
from unittest.mock import patch
from catalog.durable import Handoffs
from catalog.sync import MESSAGE_HEADERS, OFFER_HEADERS, MemoryStore, _row_from_map

class GuardTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
  self.queue=Handoffs(str(Path(self.tmp.name)/'queue.sqlite'))
 def test_persistent_hold_blocks_manual_and_queued_publish(self):
  import app
  self.queue.hold_publication('batch-1','reference_changed')
  with patch.object(app,'publish_shop') as publish:
   result=app.guarded_publish_shop(MemoryStore(),queue=Handoffs(self.queue.path))
   self.assertFalse(result['ok']);self.assertEqual(result['reason'],'publication_held_for_review')
   publish.assert_not_called()
 def test_parallel_publish_cannot_overtake_batch_lock(self):
  import app
  entered=threading.Event();finished=threading.Event();outputs=[]
  def publisher():
   entered.set()
   with patch.object(app,'publish_shop',return_value={'ok':True,'pushed':True}):
    outputs.append(app.guarded_publish_shop(MemoryStore(),queue=Handoffs(self.queue.path)))
   finished.set()
  with self.queue.publication_lock():
   thread=threading.Thread(target=publisher);thread.start();self.assertTrue(entered.wait(1))
   self.assertFalse(finished.wait(.05))
   self.queue.hold_publication('batch-2','reference_changed')
  self.assertTrue(finished.wait(2));thread.join()
  self.assertEqual(outputs[0]['reason'],'publication_held_for_review')
 def test_failed_batch_write_persists_global_hold(self):
  import app
  self.queue.receive({'id':'1','channel_username':'supplier','content':'sample'});job=self.queue.batch()
  with patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=MemoryStore()),patch.object(app,'finalize_batch',side_effect=RuntimeError('write interrupted')):
   app.run_durable_handoff(self.queue,job)
  self.assertEqual(self.queue.publication_holds()[0]['hold_id'],job.split(':',1)[1])

 def test_blocked_publish_waits_and_owner_reconciliation_releases_it(self):
  import app
  self.queue.hold_publication('batch-1','batch_unverified')
  self.queue.put('batch','batch-1',{'batch_id':'batch-1','messages':[]})
  with self.queue.connect() as db:db.execute("UPDATE jobs SET state='needs_review' WHERE id='batch:batch-1'")
  publish_id=self.queue.put('publish','pending-1',{})
  did_work=self.queue.run_one(finalize=lambda *_:{'status':'success'},publish=lambda:{'ok':True,'pushed':True},
   publish_with_payload=lambda payload:app.guarded_publish_shop(MemoryStore(),queue=self.queue),
   order_write=lambda *_:{'written':True})
  self.assertTrue(did_work)
  waiting=self.queue.get(publish_id)
  self.assertEqual(waiting['state'],'waiting_publication')
  self.assertEqual(waiting['error'],'publication_held_for_review')
  self.queue.resolve_publication_hold('batch-1',expected_reason='batch_unverified',actor_id='wp:7',
   note='Reviewed the batch before replay.',resume_batch=True)
  self.assertEqual(self.queue.get(publish_id)['state'],'queued')
  self.assertEqual(self.queue.get('batch:batch-1')['state'],'queued')

 def test_guard_survives_cross_process_contention(self):
  acquired=multiprocessing.Event()
  def worker(path,event):
   with Handoffs(path).publication_lock():event.set()
  with self.queue.publication_lock():
   process=multiprocessing.Process(target=worker,args=(self.queue.path,acquired));process.start()
   self.assertFalse(acquired.wait(.1))
  self.assertTrue(acquired.wait(2));process.join(2)
  self.assertEqual(process.exitcode,0)

 def test_database_aliases_share_one_cross_process_lock(self):
  alias=Path(self.tmp.name)/'queue-alias.sqlite'
  alias.symlink_to(Path(self.queue.path))
  aliased=Handoffs(str(alias))
  self.assertEqual(aliased.path,self.queue.path)
  entered=threading.Event()
  with self.queue.publication_lock():
   def worker():
    with aliased.publication_lock():entered.set()
   thread=threading.Thread(target=worker);thread.start()
   self.assertFalse(entered.wait(.1))
  self.assertTrue(entered.wait(2));thread.join(2)

if __name__=='__main__':unittest.main()

class GuardRecoveryTests(unittest.TestCase):
 def test_publication_hold_lists_bounded_source_context_for_the_owner(self):
  import os,app
  from unittest.mock import patch
  with tempfile.TemporaryDirectory() as directory:
   path=str(Path(directory)/'queue.sqlite');queue=Handoffs(path)
   queue.put('batch','context-1',{'batch_id':'context-1','expected_count':1,'messages':[{
    'id':'7317','channel_username':'@bonakdarjavan','timestamp':'2026-11-02T13:01:00Z',
    'url':'https://t.me/bonakdarjavan/7317','content':'supplier source text','chat_id':'private-chat'}]})
   with queue.connect() as db:
    db.execute("UPDATE jobs SET state='needs_review',result=? WHERE id='batch:context-1'",
     (queue.encode({'expected_count':1,'written_count':1,'processed_messages':0,
      'extraction_status':'error','extraction_error':'ai_extraction_failed','rollback':True}),))
   queue.hold_publication('context-1','batch_unverified')
   env={'PIPELINE_DB_PATH':path,'REFERENCE_OWNER_IDS':'wp:7',
        'REFERENCE_BRIDGE_TOKEN':'synthetic-reference-token-1234567890'}
   headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
   with patch.dict(os.environ,env),patch.object(app,'ensure_telegram_webhook'):
    data=app.app.test_client().get('/pipeline/publication-holds',headers=headers).get_json()
   context=data['holds'][0]['context']
   self.assertEqual(context['processing_error'],'ai_extraction_failed')
   self.assertEqual(context['messages'][0]['message_id'],'7317')
   self.assertEqual(context['messages'][0]['content'],'supplier source text')
   self.assertNotIn('chat_id',repr(context))

 def test_owner_can_reconcile_a_completed_batch_without_replaying_it(self):
  import json,os,app
  from unittest.mock import patch
  from catalog.ai_confirmations import review_case_id
  with tempfile.TemporaryDirectory() as directory:
   path=str(Path(directory)/'queue.sqlite');queue=Handoffs(path)
   hold_id='batch-reconciled';offer_id=hold_id+':7317:0'
   message={'id':'7317','channel_username':'@supplier','timestamp':'2026-11-02T13:01:00Z',
       'content':'supplier source','url':'https://t.me/supplier/7317'}
   queue.put('batch',hold_id,{'batch_id':hold_id,'expected_count':1,'messages':[message]})
   with queue.connect() as db:
    db.execute("UPDATE jobs SET state='failed',error='JobConflict',result=NULL WHERE id=?",('batch:'+hold_id,))
   queue.hold_publication(hold_id,'batch_in_progress')
   store=MemoryStore();store.ensure('MessageData',MESSAGE_HEADERS);store.ensure('Offers',OFFER_HEADERS)
   store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
       'ID':'7317','Channel Username':'@supplier','Content':'supplier source','Batch ID':hold_id,
       'AI Status':'needs_confirmation','AI Review Case IDs':json.dumps([review_case_id(offer_id)]),
       'AI Processed At':'2026-11-02T13:02:00+00:00'})])
   store.append('Offers',[_row_from_map(OFFER_HEADERS,{
       'Offer ID':offer_id,'Message ID':'7317','Channel Username':'@supplier','Batch ID':hold_id,
       'Status':'needs_review','Review Reason':'supplier sale amount has no confirmed unit/package basis'})])
   secret='synthetic-reference-token-1234567890'
   headers={'X-Molavi-Reference-Token':secret,'X-Molavi-Actor':'wp:7'}
   body={'hold_id':hold_id,'expected_reason':'batch_in_progress',
       'note':'Existing Sheet output and open AI cases were reconciled; do not replay.',
       'resume_batch':False,'expected_batch_state':'failed','expected_batch_error':'JobConflict'}
   with patch.dict(os.environ,{'PIPELINE_DB_PATH':path,'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':secret}), \
       patch.object(app,'USE_SHEETS',True),patch.object(app,'handoff_queue',return_value=queue), \
       patch.object(app,'build_store',return_value=store):
    response=app.app.test_client().post('/pipeline/publication-holds',headers=headers,json=body)
   self.assertEqual(response.status_code,200,response.get_json())
   self.assertFalse(response.get_json()['batch_resumed'])
   self.assertTrue(response.get_json()['reconciled'])
   self.assertEqual(queue.publication_holds(),[])
   self.assertEqual(queue.get('batch:'+hold_id)['state'],'failed')
   self.assertIsNone(queue.get('publish:batch:'+hold_id))

 def test_no_replay_reconciliation_requires_terminal_sheet_ai_state(self):
  import json,os,app
  from unittest.mock import patch
  from catalog.ai_confirmations import review_case_id
  with tempfile.TemporaryDirectory() as directory:
   path=str(Path(directory)/'queue.sqlite');queue=Handoffs(path)
   hold_id='batch-incomplete';offer_id=hold_id+':7318:0'
   queue.put('batch',hold_id,{'batch_id':hold_id,'expected_count':1,'messages':[
       {'id':'7318','channel_username':'@supplier','content':'supplier source'}]})
   with queue.connect() as db:
    db.execute("UPDATE jobs SET state='failed',error='JobConflict',result=NULL WHERE id=?",('batch:'+hold_id,))
   queue.hold_publication(hold_id,'batch_in_progress')
   store=MemoryStore();store.ensure('MessageData',MESSAGE_HEADERS);store.ensure('Offers',OFFER_HEADERS)
   store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
       'ID':'7318','Channel Username':'@supplier','Content':'supplier source','Batch ID':hold_id,
       'AI Status':'pending_ai','AI Review Case IDs':json.dumps([review_case_id(offer_id)]),
       'AI Processed At':''})])
   store.append('Offers',[_row_from_map(OFFER_HEADERS,{
       'Offer ID':offer_id,'Message ID':'7318','Channel Username':'@supplier','Batch ID':hold_id,
       'Status':'needs_review','Review Reason':'uncertain price role'})])
   secret='synthetic-reference-token-1234567890'
   headers={'X-Molavi-Reference-Token':secret,'X-Molavi-Actor':'wp:7'}
   body={'hold_id':hold_id,'expected_reason':'batch_in_progress','note':'No replay after incomplete review.',
       'resume_batch':False,'expected_batch_state':'failed','expected_batch_error':'JobConflict'}
   with patch.dict(os.environ,{'PIPELINE_DB_PATH':path,'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':secret}), \
       patch.object(app,'USE_SHEETS',True),patch.object(app,'handoff_queue',return_value=queue), \
       patch.object(app,'build_store',return_value=store):
    response=app.app.test_client().post('/pipeline/publication-holds',headers=headers,json=body)
   self.assertEqual(response.status_code,409,response.get_json())
   self.assertEqual(response.get_json()['error'],'batch_not_reconciled')
   self.assertEqual(queue.publication_holds()[0]['hold_id'],hold_id)

 def test_bulk_publication_hold_retry_is_atomic_and_audited(self):
  import os,app
  from unittest.mock import patch
  with tempfile.TemporaryDirectory() as directory:
   path=str(Path(directory)/'queue.sqlite');queue=Handoffs(path)
   for hold_id in ('batch-a','batch-b'):
    queue.hold_publication(hold_id,'batch_unverified')
    queue.put('batch',hold_id,{'batch_id':hold_id,'messages':[]})
    with queue.connect() as db:db.execute("UPDATE jobs SET state='needs_review' WHERE id=?",('batch:'+hold_id,))
   pending=queue.put('publish','pending',{'batch_id':'batch-a'})
   with queue.connect() as db:db.execute("UPDATE jobs SET state='waiting_publication' WHERE id=?",(pending,))
   env={'PIPELINE_DB_PATH':path,'REFERENCE_OWNER_IDS':'wp:7',
        'REFERENCE_BRIDGE_TOKEN':'synthetic-reference-token-1234567890'}
   headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
   body={'action':'resume_selected','holds':[
    {'hold_id':'batch-a','expected_reason':'batch_unverified'},
    {'hold_id':'batch-b','expected_reason':'batch_unverified'}]}
   with patch.dict(os.environ,env),patch.object(app,'ensure_telegram_webhook'):
    response=app.app.test_client().post('/pipeline/publication-holds',headers=headers,json=body)
   self.assertEqual(response.status_code,200,response.get_json())
   self.assertEqual(response.get_json()['resumed_count'],2)
   self.assertEqual(queue.publication_holds(),[])
   self.assertEqual(queue.get('batch:batch-a')['state'],'queued')
   self.assertEqual(queue.get('batch:batch-b')['state'],'queued')
   self.assertEqual(queue.get(pending)['state'],'queued')
   with queue.connect() as db:
    rows=db.execute('SELECT actor_id,note FROM publication_hold_resolutions ORDER BY hold_id').fetchall()
   self.assertEqual([row['actor_id'] for row in rows],['wp:7','wp:7'])
   self.assertTrue(all('authorized one batch retry' in row['note'] for row in rows))

 def test_owner_resolution_is_audited_and_requires_current_reason(self):
  import os,app
  from unittest.mock import patch
  with tempfile.TemporaryDirectory() as directory:
   path=str(Path(directory)/'queue.sqlite');queue=Handoffs(path);queue.hold_publication('batch','uncertain')
   queue.put('batch','batch',{'batch_id':'batch','messages':[]})
   with queue.connect() as db:db.execute("UPDATE jobs SET state='needs_review' WHERE id='batch:batch'")
   env={'PIPELINE_DB_PATH':path,'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':'synthetic-reference-token-1234567890'}
   with patch.dict(os.environ,env),patch.object(app,'ensure_telegram_webhook'):
    client=app.app.test_client();h={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'}
    self.assertEqual(client.get('/pipeline/publication-holds').status_code,401)
    self.assertEqual(client.get('/pipeline/publication-holds',headers={**h,'X-Molavi-Actor':'wp:8'}).status_code,403)
    self.assertEqual(client.get('/pipeline/publication-holds',headers=h).get_json()['holds'][0]['hold_id'],'batch')
    self.assertEqual(client.post('/pipeline/publication-holds',headers=h,json={'hold_id':'batch','expected_reason':'wrong','note':'reviewed','resume_batch':True}).status_code,409)
    self.assertEqual(client.post('/pipeline/publication-holds',headers=h,json={'hold_id':'batch','expected_reason':'uncertain','note':'synthetic reconciliation','resume_batch':True}).status_code,200)
    self.assertEqual(queue.publication_holds(),[])
    self.assertEqual(queue.get('batch:batch')['state'],'queued')
    with queue.connect() as db:self.assertEqual(db.execute('SELECT actor_id,note FROM publication_hold_resolutions').fetchone()['actor_id'],'wp:7')

 def test_reference_change_during_write_holds_existing_catalog_publication(self):
  import os,json,app
  from datetime import datetime,timedelta,timezone
  from unittest.mock import patch
  from reference_import.snapshots import ReferenceStore
  from reference_fixtures import staged_candidate
  with tempfile.TemporaryDirectory() as directory:
   ref=str(Path(directory)/'reference.sqlite');queue=Handoffs(str(Path(directory)/'pipeline.sqlite'))
   store=ReferenceStore(ref,owner_ids=['wp:7']);now=datetime.now(timezone.utc)
   def prepare(hours):
    stamp=(now-timedelta(hours=hours)).isoformat()
    candidate=staged_candidate(stamp)
    v=store.stage(candidate);store.review(v,actor_id='wp:7',note='synthetic');return v
   first=prepare(2);second=prepare(1);store.activate(first,actor_id='wp:7',expected_active_id=None)
   queue.receive({'id':'1','channel_username':'supplier','content':'sample'});job=queue.batch()
   def changed(*args,**kwargs):
    # Simulate a legacy/uncoordinated writer that bypasses the activation lock.
    with store._connect() as db:db.execute('UPDATE reference_active SET snapshot_id=? WHERE singleton=1',(second,))
    return {'status':'success','extraction_status':'success','rollback':False}
   with patch.dict(os.environ,{'REFERENCE_STORE_PATH':ref,'REFERENCE_OWNER_IDS':'wp:7'}),patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=MemoryStore()),patch.object(app,'finalize_batch',side_effect=changed),patch.object(app,'publish_shop') as publish:
    app.run_durable_handoff(queue,job)
    self.assertEqual(queue.get(job)['state'],'needs_review')
    self.assertEqual(queue.publication_holds()[0]['reason'],'reference_changed_during_batch')
    self.assertFalse(app.guarded_publish_shop(MemoryStore(),queue=queue)['ok'])
    publish.assert_not_called()

 def test_process_death_during_external_write_leaves_persistent_hold(self):
  import os,app,multiprocessing
  from unittest.mock import patch
  with tempfile.TemporaryDirectory() as directory:
   path=str(Path(directory)/'pipeline.sqlite');queue=Handoffs(path)
   queue.receive({'id':'1','channel_username':'supplier','content':'sample'});job=queue.batch()
   def crash_mid_write():
    with patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=MemoryStore()),patch.object(app,'finalize_batch',side_effect=lambda *a,**k:os._exit(23)):
     app.run_durable_handoff(Handoffs(path),job)
   process=multiprocessing.Process(target=crash_mid_write);process.start();process.join(5)
   self.assertEqual(process.exitcode,23)
   recovered=Handoffs(path)
   self.assertEqual(recovered.publication_holds()[0]['reason'],'batch_in_progress')
   with recovered.connect() as db:db.execute('UPDATE jobs SET expires=0 WHERE id=?',(job,))
   finalized=[]
   recovered.run_one(finalize=lambda p: finalized.append(True),publish=lambda:{'ok':True,'pushed':True},order_write=lambda p:{'written':True},job_id=job)
   self.assertEqual(recovered.get(job)['state'],'needs_review')
   self.assertEqual(finalized,[])
   env={'PIPELINE_DB_PATH':path,'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':'synthetic-reference-token-1234567890'}
   with patch.dict(os.environ,env),patch.object(app,'ensure_telegram_webhook'):
    response=app.app.test_client().post('/pipeline/publication-holds',
      headers={'X-Molavi-Reference-Token':env['REFERENCE_BRIDGE_TOKEN'],'X-Molavi-Actor':'wp:7'},
      json={'hold_id':job.split(':',1)[1],
            'expected_reason':'batch_in_progress','note':'Synthetic sheet reconciliation complete','resume_batch':True})
    self.assertEqual(response.status_code,200)
   with patch.dict(os.environ,env),patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=MemoryStore()),patch.object(app,'finalize_batch',return_value={'status':'success','extraction_status':'success','rollback':False}):
    self.assertTrue(app.run_durable_handoff(recovered,job))
    self.assertEqual(recovered.get(job)['state'],'done')
    publish_job='publish:'+job
    with patch.object(app,'publish_shop',return_value={'ok':True,'pushed':True,'images_left':0}):
     self.assertTrue(recovered.run_one(finalize=lambda p:{},publish=lambda:app.guarded_publish_shop(MemoryStore(),queue=recovered),order_write=lambda p:{'written':True},job_id=publish_job))
     self.assertEqual(recovered.get(publish_job)['state'],'done')
   self.assertEqual(recovered.publication_holds(),[])
   with patch.object(app,'publish_shop') as publish:
    self.assertTrue(app.guarded_publish_shop(MemoryStore(),queue=recovered)['ok'])
    publish.assert_called_once()
