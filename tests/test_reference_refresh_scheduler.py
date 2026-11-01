import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from reference_import.importer import ImportFailure
from reference_import.refresh_scheduler import ReferenceRefreshScheduler
from reference_import.snapshots import ReferenceStore
from reference_fixtures import staged_candidate


def candidate(at):
    return staged_candidate(at.isoformat())


class ReferenceRefreshSchedulerTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.path=str(Path(self.tmp.name)/'reference.sqlite')
        self.scheduler=ReferenceRefreshScheduler(self.path,owner_ids=['wp:7'])
        self.now=datetime(2026,10,28,12,tzinfo=timezone.utc)

    def test_daily_refresh_stages_candidate_but_never_activates_it(self):
        first=self.scheduler.run_if_due(lambda:candidate(self.now),now=self.now)
        self.assertEqual(first['state'],'staged')
        self.assertEqual(self.scheduler.run_if_due(lambda:None,now=self.now+timedelta(hours=23))['state'],'not_due')
        gate=ReferenceStore(self.path,owner_ids=['wp:7'])
        self.assertIsNone(gate.active_snapshot())
        self.assertEqual(gate.snapshots()['snapshots'][0]['snapshot_id'],first['snapshot_id'])
        next_run=self.scheduler.run_if_due(lambda:candidate(self.now),
            now=self.now+timedelta(days=1))
        self.assertEqual(next_run['state'],'staged')

    def test_each_refresh_failure_opens_durable_owner_incident(self):
        failure=self.scheduler.run_if_due(lambda:(_ for _ in ()).throw(
            ImportFailure('private detail',code='gemini_http_403')),
            now=self.now)
        self.assertEqual(failure['state'],'failed')
        self.assertEqual(failure['error_code'],'gemini_http_403')
        events=self.scheduler.store.pending()
        self.assertEqual(events[0]['reason'],'refresh_failed')
        self.assertTrue(events[0]['event_id'].startswith('refresh:'))
        self.assertNotIn('private detail',json.dumps(events))
        self.assertNotIn('private detail',json.dumps(self.scheduler.state()))

    def test_owner_retry_bypasses_daily_interval_after_failure_with_cooldown(self):
        self.scheduler.run_if_due(lambda:(_ for _ in ()).throw(
            ImportFailure('private detail',code='gemini_http_403')),now=self.now)
        early=self.scheduler.run_if_due(lambda:candidate(self.now+timedelta(seconds=30)),
            now=self.now+timedelta(seconds=30),force=True)
        self.assertEqual(early['state'],'cooldown')
        retry=self.scheduler.run_if_due(lambda:candidate(self.now+timedelta(seconds=61)),
            now=self.now+timedelta(seconds=61),force=True)
        self.assertEqual(retry['state'],'staged')

    def test_owner_can_stage_new_retry_without_overwriting_prior_staged_candidate(self):
        first=self.scheduler.run_if_due(lambda:candidate(self.now),now=self.now)
        retry=self.scheduler.run_if_due(lambda:candidate(self.now+timedelta(minutes=2)),
            now=self.now+timedelta(minutes=2),force=True)
        self.assertEqual(retry['state'],'staged')
        snapshots=self.scheduler.store.snapshots()['snapshots']
        self.assertEqual(len(snapshots),2)
        self.assertEqual(snapshots[0]['snapshot_id'],retry['snapshot_id'])
        self.assertEqual(snapshots[1]['snapshot_id'],first['snapshot_id'])
        self.assertIsNone(self.scheduler.store.active_snapshot())

    def test_worker_crash_is_recorded_before_recovery_attempt(self):
        with self.scheduler._connect() as db:
            db.execute('''UPDATE reference_refresh_state SET last_attempt_at=?,state='running',
                occurrence_id='crashed-attempt' WHERE singleton=1''',(self.now.isoformat(),))
        result=self.scheduler.run_if_due(lambda:candidate(self.now+timedelta(minutes=2)),
            now=self.now+timedelta(minutes=3))
        self.assertEqual(result['state'],'staged')
        self.assertEqual(self.scheduler.store.pending()[0]['event_id'],'refresh:crashed-attempt')


if __name__=='__main__':
    unittest.main()
