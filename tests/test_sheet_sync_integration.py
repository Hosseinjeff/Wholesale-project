"""End-to-end tests for live Product-sheet edits and owner conflict review."""
import os
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

from catalog.durable import Handoffs
from catalog.extract import PRODUCT_HEADERS
from catalog.sync import MemoryStore, finalize_batch


TEXT = ('کنسرو ماهی ۱۸۰ گرمی تاپ\nقیمت هر باکس: ۱,۲۵۰,۰۰۰ تومان\n'
        'دونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی\nموجود ✅')
TOKEN = 'synthetic-sheet-sync-token-that-is-at-least-32-characters'


def import_offer(store, batch, post, consumer='۶۵,۰۰۰', sale='۵۲,۰۰۰'):
    content = TEXT.replace('۶۵,۰۰۰', consumer).replace('۵۲,۰۰۰', sale)
    return finalize_batch(store, {'batch_id': batch, 'expected_count': 1, 'messages': [{
        'id': post, 'channel': 'telegram', 'channel_username': '@bonakdarjavan', 'content': content,
        'timestamp': datetime.now(timezone.utc).isoformat(),
    }]})


def cell(store, field):
    rows = store.read('Products')
    return rows[1][rows[0].index(field)]


def edit_cell(store, field, value):
    rows = store.read('Products')
    store.write_cells('Products', 2, rows[0], {field: value})


class SheetSyncIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.db_path = str(Path(self.temp.name) / 'pipeline.sqlite')
        self.queue = Handoffs(self.db_path)
        self.store = MemoryStore()
        self.env = patch.dict(os.environ, {'PIPELINE_DB_PATH': self.db_path})
        self.env.start()
        self.addCleanup(self.env.stop)
        import_offer(self.store, 'batch-1', 'post-1')

    def test_every_non_provenance_product_column_accepts_sheet_edits(self):
        from catalog.sync import SHEET_PROVENANCE_FIELDS

        manual = {}
        numeric = {'Sale Price', 'Actual Price', 'Price', 'Consumer Price', 'Double Pack Price',
                   'Double Pack Consumer Price', 'Extraction Confidence Score', 'Confidence',
                   'Min Qty', 'Offer Count'}
        for index, field in enumerate(PRODUCT_HEADERS):
            if field in SHEET_PROVENANCE_FIELDS:
                continue
            manual[field] = 77000 + index if field in numeric else f'operator edit {field}'
        rows = self.store.read('Products')
        self.store.write_cells('Products', 2, rows[0], manual)

        result = import_offer(self.store, 'batch-2', 'post-2')

        self.assertEqual(result['status'], 'success')
        for field, value in manual.items():
            with self.subTest(field=field):
                self.assertEqual(cell(self.store, field), value)
        # The append-only source log still contains the new supplier message.
        offers = self.store.read('Offers')
        self.assertEqual(offers[-1][offers[0].index('Offer ID')], 'batch-2:post-2:0')
        self.assertIn(TEXT, [row[self.store.read('MessageData')[0].index('Content')]
                             for row in self.store.read('MessageData')[1:]])

    def test_concurrent_sheet_and_supplier_price_change_is_held_for_owner_choice(self):
        edit_cell(self.store, 'Consumer Price', 68000)
        import_offer(self.store, 'batch-2', 'post-2', '۷۰,۰۰۰')
        conflicts = self.queue.sheet_sync_conflicts()
        self.assertEqual(len(conflicts), 1)
        conflict = conflicts[0]
        self.assertEqual((conflict['base'], conflict['server'], conflict['sheet']), (65000, 70000, 68000))
        self.assertEqual(cell(self.store, 'Consumer Price'), 68000)

        import app
        from catalog.publish import site_products
        headers = {'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}
        with patch.object(app, 'USE_SHEETS', True), patch.object(app, 'build_store', return_value=self.store), \
                patch.dict(os.environ, {'PIPELINE_DB_PATH': self.db_path,
                                        'REFERENCE_BRIDGE_TOKEN': TOKEN, 'REFERENCE_OWNER_IDS': 'wp:7'}):
            client = app.app.test_client()
            listed = client.get('/pipeline/sheet-conflicts', headers=headers)
            self.assertEqual(listed.status_code, 200)
            self.assertEqual(len(listed.get_json()['conflicts']), 1)
            self.assertEqual(client.get('/api/shop').get_json()['products'], [])
            published = site_products(self.store)
            self.assertEqual(published[0]['status'], 'hold')
            response = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict['conflict_id'], 'choice': 'server', 'note': 'supplier message verified',
            })
            self.assertEqual(len(client.get('/api/shop').get_json()['products']), 1)

        self.assertEqual(response.status_code, 200)
        self.assertEqual(cell(self.store, 'Consumer Price'), 70000)
        self.assertEqual(self.queue.get_sheet_conflict(conflict['conflict_id'])['state'], 'resolved')
        self.assertEqual(self.queue.get(response.get_json()['publish_job'])['state'], 'queued')

    def test_new_supplier_revision_supersedes_stale_open_conflict(self):
        edit_cell(self.store, 'Consumer Price', 68000)
        import_offer(self.store, 'batch-2', 'post-2', '۷۰,۰۰۰')
        old_conflict = self.queue.sheet_sync_conflicts()[0]
        edit_cell(self.store, 'Consumer Price', 69000)
        import_offer(self.store, 'batch-3', 'post-3', '۷۱,۰۰۰')

        open_conflicts = self.queue.sheet_sync_conflicts()
        self.assertEqual(len(open_conflicts), 1)
        self.assertNotEqual(open_conflicts[0]['conflict_id'], old_conflict['conflict_id'])
        self.assertEqual(self.queue.get_sheet_conflict(old_conflict['conflict_id'])['state'], 'superseded')
        self.assertEqual(cell(self.store, 'Consumer Price'), 69000)

    def test_edit_between_fresh_read_and_write_is_kept_and_flagged(self):
        read_fresh = self.store.read_fresh
        reads = 0

        def concurrent_edit(name):
            nonlocal reads
            if name == 'Products':
                reads += 1
                if reads == 2:
                    edit_cell(self.store, 'Sale Price', 53000)
            return read_fresh(name)

        self.store.read_fresh = concurrent_edit
        import_offer(self.store, 'batch-2', 'post-2', sale='۵۵,۰۰۰')

        conflicts = [item for item in self.queue.sheet_sync_conflicts() if item['field'] == 'Sale Price']
        self.assertEqual(len(conflicts), 1)
        self.assertEqual((conflicts[0]['base'], conflicts[0]['server'], conflicts[0]['sheet']),
                         (52000, 55000, 53000))
        self.assertEqual(cell(self.store, 'Sale Price'), 53000)

    def test_invalid_numeric_sheet_edit_can_be_reviewed_and_corrected(self):
        edit_cell(self.store, 'Consumer Price', 'invalid price')
        import_offer(self.store, 'batch-2', 'post-2', '۷۰,۰۰۰')
        conflict = next(item for item in self.queue.sheet_sync_conflicts()
                        if item['field'] == 'Consumer Price')

        import app
        with patch.object(app, 'USE_SHEETS', True), patch.object(app, 'build_store', return_value=self.store), \
                patch.dict(os.environ, {'PIPELINE_DB_PATH': self.db_path,
                                        'REFERENCE_BRIDGE_TOKEN': TOKEN, 'REFERENCE_OWNER_IDS': 'wp:7'}):
            response = app.app.test_client().post('/pipeline/sheet-conflicts',
                headers={'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}, json={
                    'conflict_id': conflict['conflict_id'], 'choice': 'server', 'note': 'correct invalid amount',
                })

        self.assertEqual(response.status_code, 200)
        self.assertEqual(cell(self.store, 'Consumer Price'), 70000)

    def test_dashboard_resolution_refuses_a_sheet_edit_seen_before_write(self):
        edit_cell(self.store, 'Consumer Price', 68000)
        import_offer(self.store, 'batch-2', 'post-2', '۷۰,۰۰۰')
        conflict = next(item for item in self.queue.sheet_sync_conflicts()
                        if item['field'] == 'Consumer Price')
        read_fresh = self.store.read_fresh
        reads = 0

        def concurrent_edit(name):
            nonlocal reads
            if name == 'Products':
                reads += 1
                if reads == 2:
                    edit_cell(self.store, 'Consumer Price', 69000)
            return read_fresh(name)

        self.store.read_fresh = concurrent_edit
        import app
        with patch.object(app, 'USE_SHEETS', True), patch.object(app, 'build_store', return_value=self.store), \
                patch.dict(os.environ, {'PIPELINE_DB_PATH': self.db_path,
                                        'REFERENCE_BRIDGE_TOKEN': TOKEN, 'REFERENCE_OWNER_IDS': 'wp:7'}):
            response = app.app.test_client().post('/pipeline/sheet-conflicts',
                headers={'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}, json={
                    'conflict_id': conflict['conflict_id'], 'choice': 'server', 'note': 'review current sheet',
                })

        self.assertEqual(response.status_code, 409)
        self.assertEqual(cell(self.store, 'Consumer Price'), 69000)
        self.assertEqual(self.queue.get_sheet_conflict(conflict['conflict_id'])['state'], 'open')


if __name__ == '__main__':
    unittest.main()
