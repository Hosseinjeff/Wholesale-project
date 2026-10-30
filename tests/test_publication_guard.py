import tempfile,threading,time,unittest,multiprocessing
from pathlib import Path
from unittest.mock import patch
from catalog.durable import Handoffs
from catalog.sync import MemoryStore

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
