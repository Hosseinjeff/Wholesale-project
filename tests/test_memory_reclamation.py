import os
import json
import socket
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.request
import urllib.error
from pathlib import Path
from unittest.mock import Mock, patch

from catalog import memory


class Timer:
    def __init__(self, delay, callback):
        self.delay, self.callback, self.cancelled = delay, callback, False
    def start(self):
        pass
    def cancel(self):
        self.cancelled = True


class MemoryReclamationTests(unittest.TestCase):
    def setUp(self):
        self.cleanup, self.recycle = Mock(), Mock()
        self.app = Mock(return_value=[b'response'])
        self.guard = memory.IdleReclaimer(self.app, self.recycle, cleanup=self.cleanup, timer_factory=Timer)

    def request(self, path='/work'):
        return self.guard({'PATH_INFO': path}, Mock())

    def test_health_and_fresh_worker_never_create_a_restart_loop(self):
        self.assertIsNone(self.guard.timer)
        self.assertEqual(list(self.request('/health')), [b'response'])
        self.assertIsNone(self.guard.timer)
        self.recycle.assert_not_called()

    def test_completed_burst_gets_one_cleanup_and_reset(self):
        list(self.request())
        timer = self.guard.timer
        self.assertEqual(timer.delay, 30)
        timer.callback()
        timer.callback()
        self.cleanup.assert_called_once()
        self.recycle.assert_called_once()

    def test_health_reads_do_not_extend_a_pending_cleanup_deadline(self):
        list(self.request())
        timer = self.guard.timer
        list(self.request('/health'))
        self.assertIs(self.guard.timer, timer)
        self.assertFalse(timer.cancelled)
        timer.callback()
        self.recycle.assert_called_once()

    def test_stream_and_overlapping_request_must_finish_first(self):
        first, second = self.request(), self.request()
        self.assertIsNone(self.guard.timer)
        first.close()
        first.close()
        self.assertEqual(self.guard.active, 1)
        self.assertIsNone(self.guard.timer)
        second.close()
        self.assertEqual(self.guard.active, 0)
        self.guard.timer.callback()
        self.recycle.assert_called_once()

    def test_stale_timer_cannot_clear_state_during_a_new_request(self):
        list(self.request())
        old = self.guard.timer
        response = self.request()
        self.assertTrue(old.cancelled)
        old.callback()
        self.cleanup.assert_not_called()
        response.close()
        old.callback()
        self.cleanup.assert_not_called()
        self.guard.timer.callback()
        self.cleanup.assert_called_once()

    def test_background_work_is_protected_before_thread_start_and_until_completion(self):
        list(self.request())
        old = self.guard.timer
        entered, release = threading.Event(), threading.Event()
        def work():
            entered.set()
            release.wait(3)
        with patch.object(memory, '_reclaimer', self.guard):
            thread = memory.start_background(work)
            self.assertTrue(entered.wait(1))
            self.assertEqual(self.guard.active, 1)
            old.callback()
            self.recycle.assert_not_called()
            release.set()
            thread.join(2)
        self.assertEqual(self.guard.active, 0)
        self.guard.timer.callback()
        self.recycle.assert_called_once()

    def test_failed_thread_start_releases_reservation(self):
        with patch.object(memory, '_reclaimer', self.guard), patch('catalog.memory.threading.Thread') as cls:
            cls.return_value.start.side_effect = RuntimeError('synthetic start failure')
            with self.assertRaises(RuntimeError):
                memory.start_background(Mock())
        self.assertEqual(self.guard.active, 0)

    def test_app_error_and_iterator_error_release_work(self):
        self.app.side_effect = ValueError('synthetic')
        with self.assertRaises(ValueError):
            self.request()
        self.assertEqual(self.guard.active, 0)
        def broken():
            yield b'part'
            raise ValueError('synthetic stream error')
        self.app.side_effect = None
        self.app.return_value = broken()
        with self.assertRaises(ValueError):
            list(self.request())
        self.assertEqual(self.guard.active, 0)

    def test_late_arrivals_are_retried_without_starting_work_in_retiring_process(self):
        list(self.request())
        self.guard.timer.callback()
        self.app.reset_mock()
        start = Mock()
        result = self.guard({'PATH_INFO': '/webhook'}, start)
        self.assertIn('503', start.call_args.args[0])
        self.assertIn(('Retry-After', '1'), start.call_args.args[1])
        self.assertTrue(list(result))
        self.app.assert_not_called()

    def test_cleanup_preserves_durable_data_and_drops_only_transient_state(self):
        from catalog import sync
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'pending.sqlite3')
            with sqlite3.connect(path) as db:
                db.execute('CREATE TABLE pending (value TEXT)')
                db.execute("INSERT INTO pending VALUES ('must survive')")
            session = Mock()
            with patch.dict(os.environ, {'PIPELINE_DB_PATH': path, 'REFERENCE_STORE_PATH': ''}), \
                    patch.object(sync, '_http_session', session), patch.object(sync, '_credentials', object()):
                memory.clear_transient_memory()
                self.assertIsNone(sync._http_session)
                self.assertIsNone(sync._credentials)
                session.close.assert_called_once()
            with sqlite3.connect(path) as db:
                self.assertEqual(db.execute('SELECT value FROM pending').fetchone()[0], 'must survive')

    def test_install_requires_on_demand_durable_mode(self):
        worker = Mock()
        original = worker.wsgi
        with patch.object(memory, '_reclaimer', None), patch.dict(os.environ, {'PIPELINE_RUNTIME_MODE': 'continuous'}):
            memory.install(worker)
            self.assertIs(worker.wsgi, original)

    def test_durable_intake_has_no_duplicate_timer_in_on_demand_mode(self):
        import app
        queue = Mock()
        queue.receive.return_value = ('synthetic-receipt', True)
        with patch.dict(os.environ, {'PIPELINE_RUNTIME_MODE': 'on_demand'}), \
                patch('app._start_pipeline_worker') as start, patch('app.threading.Timer') as timer:
            app.DurableBatchManager(queue).add_message({'id': 'synthetic'})
        queue.receive.assert_called_once()
        start.assert_called_once_with(force=True)
        timer.assert_not_called()

    def test_private_intake_acknowledges_the_first_durable_receipt_only(self):
        import app
        from catalog.durable import Handoffs

        with tempfile.TemporaryDirectory() as directory:
            queue = Handoffs(str(Path(directory)/'pipeline.sqlite'))
            data = {'id':'7','content':'sample listing','chat_type':'private','chat_id':'101','channel':'telegram'}

            def run_callback(target, **_kwargs):
                target()

            with patch.dict(os.environ, {'PIPELINE_RUNTIME_MODE':'on_demand'}), \
                    patch('app._start_pipeline_worker'), patch('app.send_chat_text') as send, \
                    patch('app.start_background', side_effect=run_callback):
                manager = app.DurableBatchManager(queue)
                manager.add_message(data)
                manager.add_message(data)
                send.assert_called_once_with('101', 'پیام دریافت شد و در صف بررسی است.', channel='telegram')

    def test_real_gunicorn_reclaims_heap_after_work_without_a_restart_loop(self):
        try:
            import gunicorn
        except ImportError:
            self.skipTest('Gunicorn required for process lifecycle verification')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'probe.py').write_text('''
import json,os,time
from catalog.memory import start_background
payload=None
def app(environ,start_response):
 global payload
 path=environ['PATH_INFO']
 if path=='/allocate':payload=bytearray(32*1024*1024)
 if path=='/background':
  def work():
   time.sleep(4)
   open(os.environ['PROBE_RECEIPT'],'w').write('completed')
  start_background(work,name='synthetic-background')
 start_response('200 OK',[('Content-Type','application/json')])
 return [json.dumps({'pid':os.getpid()}).encode()]
''')
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            env = {**os.environ, 'PIPELINE_RUNTIME_MODE': 'on_demand', 'PIPELINE_DB_PATH': str(root/'queue.sqlite3'),
                   'PIPELINE_IDLE_RESET_SECONDS': '1', 'PYTHONPATH': str(Path(__file__).resolve().parents[1]),
                   'PROBE_RECEIPT': str(root/'receipt')}
            log = root / 'gunicorn.log'
            with log.open('w') as output:
                process = subprocess.Popen([sys.executable, '-m', 'gunicorn', '--config',
                    str(Path(__file__).resolve().parents[1]/'gunicorn.conf.py'), 'probe:app',
                    '--bind', f'127.0.0.1:{port}', '--workers', '1', '--threads', '2', '--graceful-timeout', '2'],
                    cwd=root, env=env, stdout=output, stderr=output)
                def get(path='/health'):
                    deadline = time.monotonic()+5
                    while True:
                        try:
                            with urllib.request.urlopen(f'http://127.0.0.1:{port}'+path, timeout=3) as response:
                                return json.load(response)['pid']
                        except urllib.error.HTTPError as error:
                            if error.code != 503 or time.monotonic()>deadline:
                                raise
                            time.sleep(.1)
                try:
                    deadline = time.monotonic()+10
                    while True:
                        try:
                            first = get()
                            break
                        except OSError:
                            if time.monotonic()>deadline:
                                self.fail('Local Gunicorn did not boot: '+log.read_text()[-1000:])
                            time.sleep(.1)
                    time.sleep(1.3)
                    self.assertEqual(get(), first, 'health-only idle workers must not recycle')
                    self.assertEqual(get('/allocate'), first)
                    deadline = time.monotonic()+8
                    while get()==first and time.monotonic()<deadline:
                        time.sleep(.2)
                    second = get()
                    self.assertNotEqual(second, first, 'completed work must release the old process heap')
                    self.assertEqual(get('/background'), second)
                    time.sleep(2)
                    self.assertEqual(get(), second, 'active background work must survive the idle deadline')
                    deadline = time.monotonic()+10
                    while get()==second and time.monotonic()<deadline:
                        time.sleep(.3)
                    third = get()
                    self.assertNotEqual(third, second)
                    self.assertEqual((root/'receipt').read_text(), 'completed')
                    time.sleep(1.3)
                    self.assertEqual(get(), third, 'replacement must stay idle without another reset')
                finally:
                    process.terminate()
                    process.wait(timeout=10)


if __name__ == '__main__':
    unittest.main()
