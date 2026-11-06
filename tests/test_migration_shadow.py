import sqlite3
import tempfile
import unittest
from pathlib import Path

from catalog.migration_shadow import migration_shadow_report, sqlite_shadow_summary


class MigrationShadowInventoryTests(unittest.TestCase):
    def test_inventory_is_read_only_and_keeps_only_migration_keys(self):
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'pipeline.sqlite')
            with sqlite3.connect(path) as db:
                db.executescript('''
                    CREATE TABLE jobs (id TEXT PRIMARY KEY,kind TEXT,state TEXT,payload TEXT);
                    CREATE TABLE sheet_sync_conflicts (
                        conflict_id TEXT PRIMARY KEY,product_id TEXT,source_revision TEXT,
                        state TEXT,choice TEXT,note TEXT);
                    CREATE TABLE supplier_price_verification_commands (
                        command_id TEXT PRIMARY KEY,offer_id TEXT,source_revision TEXT,
                        verified_at TEXT,verified_by TEXT,note TEXT);
                    CREATE TABLE supplier_price_evidence (
                        offer_id TEXT PRIMARY KEY,source_revision TEXT,verified_at TEXT,
                        method TEXT,verified_by TEXT);
                    INSERT INTO jobs VALUES ('job-1','batch','queued','private source payload');
                    INSERT INTO sheet_sync_conflicts VALUES
                        ('sheet:conflict-1','product-1','offer-7','resolved','server','private note');
                    INSERT INTO supplier_price_verification_commands VALUES
                        ('a1','offer-7','offer-7','2026-11-06T10:00:00Z','telegram:123','private note');
                    INSERT INTO supplier_price_evidence VALUES
                        ('offer-7','offer-7','2026-11-06T10:00:00Z','manual','telegram:123');
                ''')
            before = Path(path).stat().st_mtime_ns

            report = sqlite_shadow_summary(path)

            self.assertTrue(report['available'])
            self.assertEqual(report['tables']['jobs'], 1)
            self.assertEqual(report['job_states'], [{'kind':'batch','state':'queued','row_count':1}])
            self.assertEqual(report['sheet_conflicts']['counts_by_state'], {'resolved':1})
            self.assertEqual(report['sheet_conflicts']['rows'][0]['conflict_id'], 'sheet:conflict-1')
            self.assertEqual(report['supplier_price_verification_commands']['count'], 1)
            self.assertEqual(report['supplier_price_evidence']['count'], 1)
            serialized = repr(report)
            self.assertNotIn('private source payload', serialized)
            self.assertNotIn('private note', serialized)
            self.assertNotIn('telegram:123', serialized)
            self.assertEqual(Path(path).stat().st_mtime_ns, before)

    def test_missing_database_is_reported_without_creation(self):
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'not-created.sqlite')
            report = migration_shadow_report(path, '')
            self.assertFalse(report['pipeline']['available'])
            self.assertFalse(Path(path).exists())
            self.assertFalse(report['reference']['available'])


if __name__ == '__main__':
    unittest.main()
