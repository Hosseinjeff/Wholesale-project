import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from reference_import.decisions import ReferenceDecisions
from reference_import.importer import parse_rows, save_candidate
from reference_import.workflow import reference_use


class WorkflowTests(unittest.TestCase):
    def test_stale_snapshot_replay_wait_decide_retry_and_next_failure(self):
        now = datetime(2026, 10, 25, 12, tzinfo=timezone.utc)
        snapshot = {'snapshot_id': 'reviewed-A', 'extracted_at': (now-timedelta(hours=24)).isoformat()}
        with tempfile.TemporaryDirectory() as directory:
            gate = ReferenceDecisions(Path(directory)/'events.sqlite', owner_ids=['owner'])
            result = reference_use(gate, snapshot=snapshot, now=now)
            self.assertFalse(result['allow_automatic'])
            self.assertEqual(result['event_id'], 'stale:reviewed-A')
            gate.choose(result['event_id'], actor_id='owner', choice='use_last_snapshot',
                        expected_snapshot_id='reviewed-A')
            replay = reference_use(gate, snapshot=snapshot, now=now)
            self.assertTrue(replay['allow_automatic'])
            self.assertTrue(replay['warning'])
            failed = reference_use(gate, snapshot=snapshot, now=now, refresh_failure_id='job-2')
            self.assertFalse(failed['allow_automatic'])
            gate.choose(failed['event_id'], actor_id='owner', choice='pause_review',
                        expected_snapshot_id='reviewed-A')
            self.assertEqual(reference_use(gate, snapshot=snapshot, now=now,
                             refresh_failure_id='job-2')['state'], 'pause_review')

    def test_valid_candidate_is_not_automatically_activated(self):
        item = {'raw_name': 'چای', 'package_price': 480000, 'package_count': 12,
                'pdf_row_number':'1'}
        now = datetime(2026, 10, 25, 12, tzinfo=timezone.utc)
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)/'candidate.json'
            save_candidate({'status':'staged', 'rows':parse_rows(json.dumps([item]), now.isoformat())},target)
            gate = ReferenceDecisions(Path(directory)/'events.sqlite', owner_ids=['owner'])
            self.assertFalse(reference_use(gate, snapshot=None, now=now)['allow_automatic'])
            self.assertEqual(json.loads(target.read_text())['status'], 'staged')

    def test_fresh_snapshot_and_failure_before_cutoff(self):
        now = datetime(2026, 10, 25, 12, tzinfo=timezone.utc)
        snapshot = {'snapshot_id': 'A', 'extracted_at':(now-timedelta(hours=23)).isoformat()}
        with tempfile.TemporaryDirectory() as directory:
            gate = ReferenceDecisions(Path(directory)/'events.sqlite', owner_ids=['owner'])
            self.assertTrue(reference_use(gate, snapshot=snapshot, now=now)['allow_automatic'])
            self.assertFalse(reference_use(gate, snapshot=snapshot, now=now,
                                          refresh_failure_id='failed-job')['allow_automatic'])
