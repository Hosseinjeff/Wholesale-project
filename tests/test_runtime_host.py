import os
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import Mock,patch
from catalog.durable import Handoffs
from catalog.runtime_host import enqueue_tasks,next_work_at,schedule_on_wordpress

class RuntimeHostTests(unittest.TestCase):
    def test_native_jobs_are_not_claimed_or_scheduled_on_railway(self):
        job=self.queue.put('publish','native-synthetic',{'synthetic':True})
        with self.queue.connect() as db:
            db.execute("INSERT INTO molavi_state_executors VALUES ('publish','wordpress',0)")
            db.execute('UPDATE jobs SET available=0 WHERE id=?',(job,))
        self.assertEqual(self.queue.job_host('publish'),'wordpress')
        self.assertIsNone(self.queue.claim())
        self.assertIsNone(self.queue.claim(job))
        self.assertIsNone(next_work_at(self.queue))
        self.assertEqual(self.queue.get(job)['state'],'queued')

    def test_bot_work_remains_claimable_beside_native_work(self):
        native=self.queue.put('publish','native-synthetic',{'synthetic':True})
        bot=self.queue.put('notice','bot-synthetic',{'synthetic':True})
        with self.queue.connect() as db:
            db.execute("INSERT INTO molavi_state_executors VALUES ('publish','wordpress',0)")
            db.execute('UPDATE jobs SET available=0')
        self.assertEqual(next_work_at(self.queue),0)
        self.assertEqual(self.queue.claim()['id'],bot)
        self.assertEqual(self.queue.get(native)['state'],'queued')

    def test_expired_native_leases_are_recovered_by_the_native_executor(self):
        job=self.queue.put('publish','native-expired',{'synthetic':True})
        with self.queue.connect() as db:
            db.execute("INSERT INTO molavi_state_executors VALUES ('publish','wordpress',0)")
            db.execute("UPDATE jobs SET state='running',lease='synthetic-old',expires=0 WHERE id=?",(job,))
        self.assertIsNone(self.queue.claim(job))
        self.assertIsNone(next_work_at(self.queue))
        with self.queue.connect() as db:
            db.execute("UPDATE molavi_state_executors SET host='railway' WHERE kind='publish'")
        self.assertEqual(self.queue.claim(job)['id'],job)

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.queue=Handoffs(str(Path(self.temp.name)/'queue.sqlite3'))
    def test_idle_and_owner_holds_do_not_schedule_a_wake(self):
        self.assertIsNone(next_work_at(self.queue))
        self.queue.put('publish','held',{})
        with self.queue.connect() as db:db.execute("UPDATE jobs SET state='waiting_reference'")
        self.assertIsNone(next_work_at(self.queue))
    def test_future_retry_and_unbatched_intake_remain_scheduled(self):
        self.queue.put('publish','later',{})
        with self.queue.connect() as db:db.execute("UPDATE jobs SET available=12345678900")
        self.assertEqual(next_work_at(self.queue),12345678900)
        self.queue.receive({'id':'m','channel_username':'@synthetic','content':'sample'})
        self.assertEqual(next_work_at(self.queue,now=15),15)
    def test_tasks_are_durable_and_idempotent_per_period(self):
        self.assertEqual(enqueue_tasks(self.queue,['sheet_sync'],now=7201),1)
        enqueue_tasks(self.queue,['sheet_sync'],now=7220)
        with self.queue.connect() as db:self.assertEqual(db.execute('SELECT count(*) FROM jobs').fetchone()[0],1)
        for tasks in [['arbitrary'],None,'sheet_sync']:
            with self.assertRaises(ValueError):enqueue_tasks(self.queue,tasks)
    def test_maintenance_reuses_job_leases_and_receipts(self):
        enqueue_tasks(self.queue,['housekeeping'],now=1)
        handler=Mock(return_value={'ok':True})
        self.assertTrue(self.queue.run_one(finalize=Mock(),publish=Mock(),order_write=Mock(),runtime_task=handler))
        handler.assert_called_once_with({'task':'housekeeping'})
        self.assertFalse(self.queue.run_one(finalize=Mock(),publish=Mock(),order_write=Mock(),runtime_task=handler))
    def test_callback_is_scoped_and_clears_empty_watchdog(self):
        response=Mock(status_code=200);response.json.return_value={'ok':True}
        with patch.dict(os.environ,{'SHOP_SITE_URL':'https://wp.test','REFERENCE_BRIDGE_TOKEN':'s'*40}),patch('catalog.runtime_host.requests.post',return_value=response) as post:
            self.assertTrue(schedule_on_wordpress(self.queue))
            call=post.call_args;self.assertIsNone(call.kwargs['json']['wake_at'])
            self.assertNotEqual(call.kwargs['headers']['X-Molavi-Runtime-Token'],'s'*40)
    def test_health_does_not_start_a_worker_or_poll_telegram(self):
        import app
        with patch('app._start_pipeline_worker') as start,patch('app.ensure_telegram_webhook') as webhook:
            response=app.app.test_client().get('/health')
        self.assertEqual(response.status_code,200);start.assert_not_called();webhook.assert_not_called()
    def test_read_only_requests_do_not_launch_on_demand_worker(self):
        import app
        with patch.dict(os.environ,{'PIPELINE_RUNTIME_MODE':'on_demand','PIPELINE_DB_PATH':str(Path(self.temp.name)/'queue.sqlite3'),'RAILWAY_ENVIRONMENT':'Staging'}),app.app.test_request_context('/pipeline/operations-dashboard'),patch('app.threading.Thread') as thread:
            app._start_pipeline_worker();thread.assert_not_called()
    def test_runtime_wake_requires_owner_auth_and_only_enqueues_known_tasks(self):
        import app
        env={'PIPELINE_RUNTIME_MODE':'on_demand','REFERENCE_BRIDGE_TOKEN':'s'*40,'REFERENCE_OWNER_IDS':'wp:1'}
        with patch.dict(os.environ,env),patch('app.handoff_queue',return_value=self.queue),patch('app._start_pipeline_worker') as start:
            client=app.app.test_client()
            self.assertEqual(client.post('/pipeline/runtime',json={'tasks':['sheet_sync']}).status_code,401)
            headers={'X-Molavi-Reference-Token':'s'*40,'X-Molavi-Actor':'wp:1'}
            self.assertEqual(client.post('/pipeline/runtime',json={'tasks':['bad']},headers=headers).status_code,400)
            response=client.post('/pipeline/runtime',json={'tasks':['sheet_sync']},headers=headers)
            self.assertEqual(response.status_code,202);self.assertTrue(response.json['ok'])
    def test_on_demand_worker_exits_without_periodic_network_polling(self):
        import tools.run_pipeline_handoffs as worker
        with patch('tools.run_pipeline_handoffs.handoff_queue',return_value=self.queue),patch('tools.run_pipeline_handoffs.run_durable_handoff',return_value=False),patch('catalog.runtime_host.schedule_on_wordpress',return_value=True) as schedule,patch('tools.run_pipeline_handoffs.build_store') as store:
            worker.run_on_demand_worker(threading.Event())
        schedule.assert_called_once_with(self.queue);store.assert_not_called()
    def test_inline_worker_is_not_respawned_after_normal_idle_exit(self):
        import app
        with patch.dict(os.environ,{'PIPELINE_RUNTIME_MODE':'on_demand'}),patch('tools.run_pipeline_handoffs.run_worker') as run:
            app._pipeline_worker_loop(threading.Event())
        run.assert_called_once()

if __name__=='__main__':unittest.main()
