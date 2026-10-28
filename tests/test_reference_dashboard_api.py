import io
import json
import tempfile
import unittest
from pathlib import Path
from reference_import.decisions import ReferenceDecisions
from reference_import.dashboard_api import DashboardAPI


class DashboardAPITests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.gate = ReferenceDecisions(Path(self.tmp.name)/'events.sqlite', owner_ids=['wp:7'])
        self.gate.open_event('failed-run-1', reason='refresh_failed', snapshot_id='A')
        self.token = 'synthetic-test-token-that-is-at-least-32-characters'
        self.api = DashboardAPI(self.gate, bridge_token=self.token)

    def call(self, method='GET', token=None, actor='wp:7', body=None, path='/decisions'):
        payload = json.dumps(body).encode() if body is not None else b''
        env = {'PATH_INFO':path, 'REQUEST_METHOD':method, 'CONTENT_LENGTH':str(len(payload)),
               'wsgi.input':io.BytesIO(payload), 'HTTP_X_MOLAVI_REFERENCE_TOKEN': token or self.token,
               'HTTP_X_MOLAVI_ACTOR':actor}
        reply = []
        raw = b''.join(self.api(env, lambda status, headers: reply.append((status,dict(headers)))))
        return int(reply[0][0].split()[0]), json.loads(raw), reply[0][1]

    def test_private_queue_requires_bridge_and_owner_and_disallows_cache(self):
        status, body, headers = self.call()
        self.assertEqual(status,200)
        self.assertEqual(body['events'][0]['event_id'],'failed-run-1')
        self.assertEqual(headers['Cache-Control'],'no-store')
        self.assertEqual(self.call(token='wrong')[0],401)
        self.assertEqual(self.call(actor='wp:8')[0],403)

    def test_decision_receipt_retry_and_conflict(self):
        body = dict(event_id='failed-run-1',snapshot_id='A',choice='use_last_snapshot')
        self.assertEqual(self.call('POST',body=body)[0],200)
        self.assertEqual(self.call('POST',body=body)[0],200)
        self.assertEqual(self.call()[1]['events'],[])
        body['choice']='pause_review'
        self.assertEqual(self.call('POST',body=body)[0],409)

    def test_snapshot_tamper_and_unknown_event_fail(self):
        for event,snapshot in [('failed-run-1','B'),('unknown','A')]:
            self.assertEqual(self.call('POST',body=dict(event_id=event,snapshot_id=snapshot,
                                                      choice='pause_review'))[0],409)

    def test_actor_cannot_be_supplied_in_payload(self):
        self.assertEqual(self.call('POST',body=dict(event_id='failed-run-1',snapshot_id='A',
                              choice='pause_review',actor='wp:7'))[0],400)

    def test_invalid_fields_methods_and_paths(self):
        for body in ([],{},dict(event_id=[],snapshot_id='A',choice='pause_review')):
            self.assertEqual(self.call('POST',body=body)[0],400)
        self.assertEqual(self.call('DELETE')[0],405)
        self.assertEqual(self.call(path='/unknown')[0],404)

    def test_storage_failure_never_returns_success(self):
        def unavailable():
            raise RuntimeError('internal path or credentials must not escape')
        self.gate.pending=unavailable
        status,body,_ = self.call()
        self.assertEqual(status,503)
        self.assertEqual(body,{'ok':False,'error':'decision_store_unavailable'})

if __name__ == '__main__':
    unittest.main()
