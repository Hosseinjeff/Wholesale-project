import tempfile
import unittest
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from pathlib import Path
from reference_import.decisions import DecisionConflict, ReferenceDecisions, snapshot_is_stale


class DecisionTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / 'decisions.sqlite'
        self.gate = ReferenceDecisions(self.path, owner_ids=['owner'])
        self.gate.open_event('job-1', reason='refresh_failed', snapshot_id='snapshot-A')

    def choose(self, choice='use_last_snapshot', event='job-1', snapshot='snapshot-A'):
        self.gate.choose(event, actor_id='owner', choice=choice, expected_snapshot_id=snapshot)

    def test_waits_without_response_and_survives_restart(self):
        restarted = ReferenceDecisions(self.path, owner_ids=['owner'])
        self.assertEqual(len(restarted.pending()), 1)
        self.assertEqual(restarted.outcome('job-1', current_snapshot_id='snapshot-A')['state'],
                         'awaiting_owner')
        self.assertFalse(restarted.outcome('job-1', current_snapshot_id='snapshot-A')['allow_automatic'])

    def test_choice_allows_only_its_snapshot_and_occurrence_with_warning(self):
        self.choose()
        outcome = self.gate.outcome('job-1', current_snapshot_id='snapshot-A')
        self.assertTrue(outcome['allow_automatic'])
        self.assertTrue(outcome['warning'])
        self.assertFalse(self.gate.outcome('job-1', current_snapshot_id='snapshot-B')['allow_automatic'])
        self.gate.open_event('job-2', reason='refresh_failed', snapshot_id='snapshot-A')
        self.assertFalse(self.gate.outcome('job-2', current_snapshot_id='snapshot-A')['allow_automatic'])

    def test_pause_requires_review(self):
        self.choose('pause_review')
        outcome = self.gate.outcome('job-1', current_snapshot_id='snapshot-A')
        self.assertEqual(outcome['state'], 'pause_review')
        self.assertFalse(outcome['allow_automatic'])

    def test_exact_retries_idempotent_and_conflicting_choices_rejected(self):
        self.gate.open_event('job-1', reason='refresh_failed', snapshot_id='snapshot-A')
        self.choose()
        self.choose()
        self.assertEqual(self.gate.pending(), [])
        with self.assertRaises(DecisionConflict):
            self.choose('pause_review')
        with self.assertRaises(DecisionConflict):
            self.gate.open_event('job-1', reason='stale', snapshot_id='snapshot-A')

    def test_wrong_actor_snapshot_and_unknown_event_rejected(self):
        with self.assertRaises(PermissionError):
            self.gate.choose('job-1', actor_id='operator', choice='pause_review',
                             expected_snapshot_id='snapshot-A')
        with self.assertRaises(DecisionConflict):
            self.choose(snapshot='snapshot-B')
        with self.assertRaises(DecisionConflict):
            self.gate.outcome('unknown', current_snapshot_id='snapshot-A')

    def test_missing_snapshot_cannot_be_selected(self):
        self.gate.open_event('missing-1', reason='missing', snapshot_id=None)
        with self.assertRaises(DecisionConflict):
            self.choose(event='missing-1', snapshot=None)
        self.choose('pause_review', event='missing-1', snapshot=None)

    def test_concurrent_choices_only_one_wins(self):
        def attempt(choice):
            try:
                self.choose(choice)
                return True
            except DecisionConflict:
                return False
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(attempt, ['pause_review', 'use_last_snapshot']))
        self.assertEqual(sum(results), 1)

    def test_age_cutoff_has_no_implicit_default(self):
        now = datetime(2026, 10, 25, tzinfo=timezone.utc)
        old = now - timedelta(hours=24)
        self.assertTrue(snapshot_is_stale(old, now, max_age_seconds=86400))
        self.assertFalse(snapshot_is_stale(old, now, max_age_seconds=172800))
        self.assertFalse(snapshot_is_stale(old, now, max_age_seconds=None))
        with self.assertRaises(ValueError):
            snapshot_is_stale(now + timedelta(seconds=1), now, max_age_seconds=86400)
        with self.assertRaises(TypeError):
            snapshot_is_stale(old, now)

if __name__ == '__main__':
    unittest.main()
