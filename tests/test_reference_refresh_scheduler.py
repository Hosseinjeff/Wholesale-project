import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from reference_import.importer import SOURCE_URL, parse_rows
from reference_import.refresh_scheduler import ReferenceRefreshScheduler
from reference_import.snapshots import ReferenceStore


def candidate(at):
    rows=parse_rows(json.dumps([{'raw_name':'چای نمونه ۱۲ عددی','clean_name':'چای نمونه',
        'package_price':1200000,'package_count':12,'unit_price':100000,
        'category':'Tea','product_code':'TEA-12','currency':'TOMAN'}],ensure_ascii=False),at.isoformat())
    return {'schema_version':1,'status':'staged','requires_review':True,'source_url':SOURCE_URL,
        'pdf_sha256':'a'*64,'model':'gemini-3.1-flash-lite','extracted_at':at.isoformat(),
        'row_count':len(rows),'headers':list(__import__('reference_import.importer',fromlist=['HEADERS']).HEADERS),
        'rows':rows}


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
        failure=self.scheduler.run_if_due(lambda:(_ for _ in ()).throw(RuntimeError('private detail')),
            now=self.now)
        self.assertEqual(failure['state'],'failed')
        self.assertEqual(failure['error_code'],'RuntimeError')
        events=self.scheduler.store.pending()
        self.assertEqual(events[0]['reason'],'refresh_failed')
        self.assertTrue(events[0]['event_id'].startswith('refresh:'))
        self.assertNotIn('private detail',json.dumps(events))

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
