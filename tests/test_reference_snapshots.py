import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from reference_import.decisions import DecisionConflict
from reference_import.importer import HEADERS, PDF_SOURCE_URL, parse_rows
from reference_import.snapshots import ReferenceStore
from reference_import.workflow import active_reference_use, reference_use
from reference_fixtures import staged_candidate


class SnapshotTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.path=Path(self.tmp.name)/'reference.sqlite'
        self.store=ReferenceStore(self.path,owner_ids=['wp:7'])
        self.now=datetime.now(timezone.utc)

    def candidate(self,hours=1):
        timestamp=(self.now-timedelta(hours=hours)).isoformat()
        return staged_candidate(timestamp)

    def activate(self,hours=1,previous=None):
        version=self.store.stage(self.candidate(hours))
        self.store.review(version,actor_id='wp:7',note='Synthetic coverage and price review')
        self.store.activate(version,actor_id='wp:7',expected_active_id=previous)
        return version

    def test_staging_does_not_activate_and_unreviewed_cannot_activate(self):
        version=self.store.stage(self.candidate())
        self.assertIsNone(self.store.active_snapshot())
        with self.assertRaises(DecisionConflict):
            self.store.activate(version,actor_id='wp:7',expected_active_id=None)

    def test_failure_cannot_be_bypassed_by_omitting_failure_id(self):
        self.activate()
        self.store.record_refresh_failure('job-1')
        self.assertFalse(active_reference_use(self.store,now=self.now)['allow_automatic'])
        restarted=ReferenceStore(self.path,owner_ids=['wp:7'])
        self.assertFalse(active_reference_use(restarted,now=self.now)['allow_automatic'])

    def test_all_incidents_must_be_allowed_and_pause_remains_blocking(self):
        version=self.activate()
        for job in ('job-1','job-2'):self.store.record_refresh_failure(job)
        self.store.choose('refresh:job-1',actor_id='wp:7',choice='use_last_snapshot',expected_snapshot_id=version)
        self.assertFalse(active_reference_use(self.store,now=self.now)['allow_automatic'])
        self.store.choose('refresh:job-2',actor_id='wp:7',choice='pause_review',expected_snapshot_id=version)
        self.assertEqual(active_reference_use(self.store,now=self.now)['state'],'pause_review')

    def test_replacement_retires_old_events_and_late_choice_is_rejected(self):
        old=self.activate(2); self.store.record_refresh_failure('job-1')
        new=self.activate(1,previous=old)
        self.assertEqual(self.store.active_snapshot()['snapshot_id'],new)
        self.assertEqual(self.store.pending(),[])
        self.assertTrue(active_reference_use(self.store,now=self.now)['allow_automatic'])
        with self.assertRaises(DecisionConflict):
            self.store.choose('refresh:job-1',actor_id='wp:7',choice='use_last_snapshot',expected_snapshot_id=old)
        self.assertEqual(self.store.stage(self.candidate(2)),old) # immutable previous data retained

    def test_stale_activation_loses_without_replacing_active_data(self):
        first=self.activate(2)
        candidate=self.store.stage(self.candidate(1))
        self.store.review(candidate,actor_id='wp:7',note='Synthetic review')
        with self.assertRaises(DecisionConflict):
            self.store.activate(candidate,actor_id='wp:7',expected_active_id=None)
        self.assertEqual(self.store.active_snapshot()['snapshot_id'],first)

    def test_failed_refresh_retry_after_activation_does_not_reopen_incident(self):
        old=self.activate(2);self.store.record_refresh_failure('job-1')
        self.activate(1,previous=old);self.store.record_refresh_failure('job-1')
        self.assertEqual(self.store.pending(),[])

    def test_invalid_candidate_cannot_replace_previous_snapshot(self):
        version=self.activate()
        candidate=self.candidate(0.5);candidate['rows'][0][3]=-1
        with self.assertRaises(ValueError):self.store.stage(candidate)
        self.assertEqual(self.store.active_snapshot()['snapshot_id'],version)

    def test_candidate_without_coverage_metadata_cannot_be_staged(self):
        candidate=self.candidate();candidate.pop('coverage_verified')
        with self.assertRaises(ValueError):self.store.stage(candidate)

    def test_legacy_pdf_candidate_is_rejected_even_with_complete_row_metadata(self):
        candidate=self.candidate()
        candidate['source_type']='legacy_pdf_gemini'
        candidate['source_url']=PDF_SOURCE_URL
        with self.assertRaises(ValueError):self.store.stage(candidate)

    def test_non_owner_cannot_review_or_activate(self):
        version=self.store.stage(self.candidate())
        with self.assertRaises(PermissionError):
            self.store.review(version,actor_id='wp:8',note='unapproved')
        with self.assertRaises(PermissionError):
            self.store.activate(version,actor_id='wp:8',expected_active_id=None)
        self.assertIsNone(self.store.active_snapshot())

if __name__=='__main__':unittest.main()

class SnapshotActivationLockTests(unittest.TestCase):
 def test_activation_waits_for_reference_assisted_intake_to_finish(self):
  import threading
  with tempfile.TemporaryDirectory() as directory:
   store=ReferenceStore(Path(directory)/'reference.sqlite',owner_ids=['wp:7'])
   now=datetime.now(timezone.utc);stamp=(now-timedelta(hours=1)).isoformat()
   candidate=staged_candidate(stamp)
   version=store.stage(candidate);store.review(version,actor_id='wp:7',note='synthetic')
   started=threading.Event();done=threading.Event();errors=[]
   def activate():
    started.set()
    try:store.activate(version,actor_id='wp:7',expected_active_id=None)
    except Exception as exc:errors.append(exc)
    finally:done.set()
   with store.activation_lock():
    t=threading.Thread(target=activate);t.start();self.assertTrue(started.wait(1))
    self.assertFalse(done.wait(.05))
   self.assertTrue(done.wait(2));t.join();self.assertEqual(errors,[])
   self.assertEqual(store.active_snapshot()['snapshot_id'],version)
