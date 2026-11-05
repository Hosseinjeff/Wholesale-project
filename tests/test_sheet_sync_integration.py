"""End-to-end tests for live Product-sheet edits and owner conflict review."""
import os
import re
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
        'source_timestamp_basis': 'supplier_channel_update',
    }]})


def cell(store, field):
    rows = store.read('Products')
    return rows[1][rows[0].index(field)]


def edit_cell(store, field, value):
    rows = store.read('Products')
    store.write_cells('Products', 2, rows[0], {field: value})


def synthetic_extract(content, _channel):
    digits = str(content).translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789'))
    def price(label, fallback):
        match = re.search(label + r'\s*:\s*([0-9,]+)', digits)
        return int(match.group(1).replace(',', '')) if match else fallback
    sale = price('دونه ای', 52000)
    consumer = price('قیمت مصرف', 65000)
    package = price('قیمت هر باکس', 1250000)
    return [{
        'name':'کنسرو ماهی ۱۸۰ گرمی تاپ','sale_price':sale,'price':sale,
        'actual_price':consumer,'consumer_price':consumer,'package_price':package,
        'package_count':24,'price_type':'unit','currency':'IRT',
        'stock_status':'Available','confidence':0.99,'extraction_confidence':0.99,
    }]


class SheetSyncIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.db_path = str(Path(self.temp.name) / 'pipeline.sqlite')
        self.queue = Handoffs(self.db_path)
        self.store = MemoryStore()
        extraction = patch('catalog.sync._extract_supplier_products', side_effect=synthetic_extract)
        extraction.start()
        self.addCleanup(extraction.stop)
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
                'expected_revision': conflict['source_revision'],
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

    def test_sheet_conflict_decision_rejects_an_old_expected_revision(self):
        edit_cell(self.store, 'Consumer Price', 68000)
        import_offer(self.store, 'batch-2', 'post-2', '۷۰,۰۰۰')
        conflict = self.queue.sheet_sync_conflicts()[0]
        import app
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': TOKEN}
        headers = {'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}
        with patch.dict(os.environ, env), patch.object(app, 'USE_SHEETS', True), \
                patch.object(app, 'build_store', return_value=self.store):
            client = app.app.test_client()
            stale = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict['conflict_id'], 'choice': 'server', 'note': '',
                'expected_revision': 'older-source-revision',
            })
            current = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict['conflict_id'], 'choice': 'server', 'note': '',
                'expected_revision': conflict['source_revision'],
            })
        self.assertEqual(stale.status_code, 409)
        self.assertEqual(stale.get_json()['error'], 'conflict_changed')
        self.assertEqual(current.status_code, 200, current.get_json())
        self.assertEqual(cell(self.store, 'Consumer Price'), 70000)

    def test_stale_conflict_resolution_refreshes_and_supersedes_obsolete_card(self):
        import app as server
        rows = self.store.read('Products')
        headers, product = rows[0], rows[1]
        product_id = product[headers.index('Product ID')]
        product_name = product[headers.index('Product Name')]
        channel = product[headers.index('Channel Username')]
        edit_cell(self.store, 'Consumer Price', 68000)
        conflict_id = 'sheet:stale-source-revision'
        self.queue.record_sheet_conflict({
            'conflict_id': conflict_id, 'product_id': product_id,
            'product_name': product_name, 'channel': channel,
            'field': 'Consumer Price', 'source_revision': 'obsolete-offer-revision',
            'base': 65000, 'server': 70000, 'sheet': 68000,
        })

        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': TOKEN}
        headers = {'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'build_store', return_value=self.store), \
                patch.object(server, 'handoff_queue', return_value=self.queue):
            response = server.app.test_client().post('/pipeline/sheet-conflicts',
                headers=headers, json={'conflict_id': conflict_id, 'choice': 'server',
                                       'note': 'Apply current source after refreshing stale conflict'})

        self.assertEqual(response.status_code, 409)
        self.assertEqual(response.get_json()['error'], 'source_changed')
        self.assertTrue(response.get_json()['refreshed'])
        self.assertEqual(self.queue.get_sheet_conflict(conflict_id)['state'], 'superseded')
        self.assertEqual(self.queue.sheet_sync_conflicts(), [])
        self.assertEqual(cell(self.store, 'Consumer Price'), 65000)

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

    def test_repeating_the_same_sheet_conflict_resolution_returns_the_original_receipt(self):
        edit_cell(self.store, 'Consumer Price', 68000)
        import_offer(self.store, 'batch-2', 'post-2', '۷۰,۰۰۰')
        conflict = next(item for item in self.queue.sheet_sync_conflicts()
                        if item['field'] == 'Consumer Price')
        import app
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': TOKEN}
        headers = {'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}
        with patch.dict(os.environ, env), patch.object(app, 'USE_SHEETS', True), \
                patch.object(app, 'build_store', return_value=self.store):
            client = app.app.test_client()
            first = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict['conflict_id'], 'choice': 'server', 'note': '',
            })
            second = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict['conflict_id'], 'choice': 'server', 'note': '',
            })
        self.assertEqual(first.status_code, 200, first.get_json())
        self.assertEqual(second.status_code, 200, second.get_json())
        self.assertTrue(second.get_json()['idempotent'])
        self.assertEqual(first.get_json()['publish_job'], second.get_json()['publish_job'])
        self.assertEqual(cell(self.store, 'Consumer Price'), 70000)
        self.assertEqual(self.queue.get_sheet_conflict(conflict['conflict_id'])['state'], 'resolved')

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

    def test_server_owned_provenance_conflict_syncs_to_sheet_without_publication(self):
        import app as server
        from catalog.sync import SHEET_PROVENANCE_FIELDS

        field = 'Latest Offer ID'
        self.assertIn(field, SHEET_PROVENANCE_FIELDS)
        product_rows = self.store.read('Products')
        product_headers = product_rows[0]
        product = product_rows[1]
        product_id = product[product_headers.index('Product ID')]
        product_name = product[product_headers.index('Product Name')]
        channel = product[product_headers.index('Channel Username')]
        server_value = product[product_headers.index(field)]
        offer_rows = self.store.read('Offers')
        source_revision = offer_rows[-1][offer_rows[0].index('Offer ID')]
        self.assertEqual(server_value, source_revision)

        sheet_value = 'legacy-sheet-offer'
        edit_cell(self.store, field, sheet_value)
        conflict_id = 'sheet:server-owned-provenance'
        self.queue.record_sheet_conflict({
            'conflict_id': conflict_id, 'product_id': product_id, 'product_name': product_name,
            'channel': channel, 'field': field, 'source_revision': source_revision,
            'base': 'older-offer', 'server': server_value, 'sheet': sheet_value,
        })

        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': TOKEN}
        headers = {'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'build_store', return_value=self.store), \
                patch.object(server, 'handoff_queue', return_value=self.queue):
            client = server.app.test_client()
            listed = client.get('/pipeline/sheet-conflicts', headers=headers)
            self.assertEqual(listed.status_code, 200, listed.get_json())
            visible = next(item for item in listed.get_json()['conflicts']
                           if item['conflict_id'] == conflict_id)
            self.assertTrue(visible['server_owned'])

            rejected = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict_id, 'choice': 'sheet', 'note': 'not allowed for metadata',
            })
            self.assertEqual(rejected.status_code, 409)
            self.assertEqual(rejected.get_json()['error'], 'field_read_only')
            self.assertEqual(cell(self.store, field), sheet_value)

            accepted = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict_id, 'choice': 'server', 'note': '',
            })
            self.assertEqual(accepted.status_code, 200, accepted.get_json())
            self.assertTrue(accepted.get_json()['server_owned'])
            self.assertIsNone(accepted.get_json()['publish_job'])
            self.assertEqual(cell(self.store, field), server_value)
            self.assertEqual(self.queue.get_sheet_conflict(conflict_id)['state'], 'resolved')

            repeated = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict_id, 'choice': 'server', 'note': '',
            })
        self.assertEqual(repeated.status_code, 200, repeated.get_json())
        self.assertTrue(repeated.get_json()['idempotent'])
        self.assertIsNone(repeated.get_json()['publish_job'])
        self.assertEqual(cell(self.store, field), server_value)

    def test_reference_decision_is_included_when_rechecking_server_conflict_value(self):
        import app as server
        from catalog.sync import _restore_current_book
        from reference_import.item_reviews import ItemReviewLedger, review_case_id
        from reference_import.snapshots import ReferenceStore

        reference_path = str(Path(self.temp.name) / 'reference.sqlite')
        reference_store = ReferenceStore(reference_path, owner_ids=['wp:7'])
        with reference_store._connect() as db:
            db.execute('INSERT INTO reference_active VALUES (1,?)', ('snapshot-A',))
        product_rows = self.store.read('Products')
        product_headers = product_rows[0]
        product = product_rows[1]
        offer_id = product[product_headers.index('Latest Offer ID')]
        case_id = review_case_id(offer_id, 'snapshot-A')
        ledger = ItemReviewLedger(reference_path, owner_ids=['wp:7'])
        decision = ledger.decide(case_id=case_id, offer_id=offer_id, snapshot_id='snapshot-A',
            signature='synthetic-signature', choice='match', reference_id='R-1', price_role='unit',
            actor_id='wp:7', note='Owner confirmed the reference item')

        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_STORE_PATH': reference_path,
               'REFERENCE_OWNER_IDS': 'wp:7', 'REFERENCE_BRIDGE_TOKEN': TOKEN}
        with patch.dict(os.environ, env):
            _restore_current_book(self.store, [(product[product_headers.index('Product Name')],
                                                product[product_headers.index('Channel Username')])])

        product_rows = self.store.read('Products')
        product_headers = product_rows[0]
        product = product_rows[1]
        self.assertEqual(product[product_headers.index('Reference Decision ID')], decision['decision_id'])
        self.assertEqual(product[product_headers.index('Reference Approval State')], 'owner_approved')
        conflict_id = 'sheet:reference-approval-state'
        self.store.write_cells('Products', 2, product_headers, {'Reference Approval State': ''})
        source_revision = self.store.read('Offers')[-1][self.store.read('Offers')[0].index('Offer ID')]
        self.queue.record_sheet_conflict({'conflict_id': conflict_id,
            'product_id': product[product_headers.index('Product ID')],
            'product_name': product[product_headers.index('Product Name')],
            'channel': product[product_headers.index('Channel Username')],
            'field': 'Reference Approval State', 'source_revision': source_revision,
            'base': 'needs_review', 'server': 'owner_approved', 'sheet': ''})

        headers = {'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}
        with patch.object(server, 'USE_SHEETS', True), patch.object(server, 'build_store', return_value=self.store), \
                patch.object(server, 'handoff_queue', return_value=self.queue), patch.dict(os.environ, env):
            response = server.app.test_client().post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict_id, 'choice': 'server', 'note': 'Apply current approved reference state',
            })

        self.assertEqual(response.status_code, 200, response.get_json())
        self.assertEqual(cell(self.store, 'Reference Approval State'), 'owner_approved')
        self.assertEqual(self.queue.get_sheet_conflict(conflict_id)['choice'], 'server')

    def test_same_title_different_volumes_keep_separate_rows_and_latest_offers(self):
        from catalog.extract import OFFER_HEADERS
        from catalog.sync import (_offer_fields, _product_restore_key, _records,
                                  _restore_current_book, _row_from_map)

        name = 'نوشیدنی آلوورا بر پایه عسل'
        channel = '@supplier'
        timestamp = datetime.now(timezone.utc).isoformat()

        def add_offer(message_id, volume, sale):
            product = {'name': name, 'volume': volume, 'sale_price': sale, 'price': sale,
                'consumer_price': sale + 20000, 'actual_price': sale + 20000,
                'price_type': 'unit', 'currency': 'IRT', 'stock_status': 'Unknown',
                'confidence': .99, 'extraction_confidence': .99}
            message = {'id': message_id, 'batch_id': 'volume-batch', 'channel': 'telegram',
                'channel_username': channel, 'timestamp': timestamp,
                'source_timestamp_basis': 'supplier_channel_update', 'content': f'{name} {volume}',
                'url': f'https://t.me/supplier/{message_id}'}
            self.store.ensure('Offers', OFFER_HEADERS)
            headers = self.store.read('Offers')[0]
            self.store.append('Offers', [_row_from_map(headers, _offer_fields(
                product, message, f'volume-batch:{message_id}:0', timestamp, ''))])

        small = {'name': name, 'volume': '250 میل'}
        large = {'name': name, 'volume': '1.5 لیتر'}
        add_offer('volume-250', '250 میل', 50000)
        add_offer('volume-1500', '1.5 لیتر', 70000)
        _restore_current_book(self.store, [
            _product_restore_key(small, channel), _product_restore_key(large, channel)])

        products = _records(self.store.read('Products'))[1]
        matching = [row for row in products if row.get('Product Name') == name]
        self.assertEqual(len(matching), 2)
        self.assertEqual({row.get('Volume') for row in matching}, {'250 میل', '1.5 لیتر'})
        self.assertEqual(len({row.get('Product ID') for row in matching}), 2)

        add_offer('volume-250-new', '250 میل', 55000)
        _restore_current_book(self.store, [_product_restore_key(small, channel)])
        products = _records(self.store.read('Products'))[1]
        matching = [row for row in products if row.get('Product Name') == name]
        small_row = next(row for row in matching if row.get('Volume') == '250 میل')
        large_row = next(row for row in matching if row.get('Volume') == '1.5 لیتر')
        self.assertEqual(small_row.get('Sale Price'), 55000)
        self.assertEqual(small_row.get('Latest Offer ID'), 'volume-batch:volume-250-new:0')
        self.assertEqual(large_row.get('Sale Price'), 70000)

    def test_last_updated_server_value_uses_the_latest_offer_import_timestamp(self):
        import app as server
        from catalog.sync import SHEET_SERVER_OWNED_FIELDS

        field = 'Last Updated'
        self.assertIn(field, SHEET_SERVER_OWNED_FIELDS)
        rows = self.store.read('Products')
        headers = rows[0]
        product = rows[1]
        product_id = product[headers.index('Product ID')]
        product_name = product[headers.index('Product Name')]
        channel = product[headers.index('Channel Username')]
        sheet_value = '2026-10-17T23:13:00.000Z'
        edit_cell(self.store, field, sheet_value)
        offer_rows = self.store.read('Offers')
        offer_headers = offer_rows[0]
        source_revision = offer_rows[-1][offer_headers.index('Offer ID')]
        server_value = offer_rows[-1][offer_headers.index('Import Timestamp')]
        conflict_id = 'sheet:last-updated-provenance'
        self.queue.record_sheet_conflict({
            'conflict_id': conflict_id, 'product_id': product_id, 'product_name': product_name,
            'channel': channel, 'field': field, 'source_revision': source_revision,
            'base': '2026-10-18T00:00:00.000Z', 'server': server_value, 'sheet': sheet_value,
        })

        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': TOKEN}
        headers = {'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'build_store', return_value=self.store), \
                patch.object(server, 'handoff_queue', return_value=self.queue):
            response = server.app.test_client().post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict_id, 'choice': 'server', 'note': '',
            })
        self.assertEqual(response.status_code, 200, response.get_json())
        self.assertEqual(cell(self.store, field), server_value)
        self.assertTrue(response.get_json()['server_owned'])
        self.assertIsNone(response.get_json()['publish_job'])

    def test_preserved_sheet_identity_field_can_be_dismissed_without_rewriting_it(self):
        import app as server
        from catalog.sync import SHEET_PRESERVED_FIELDS

        field = 'Import Timestamp'
        self.assertIn(field, SHEET_PRESERVED_FIELDS)
        product_rows = self.store.read('Products')
        product_headers = product_rows[0]
        product = product_rows[1]
        product_id = product[product_headers.index('Product ID')]
        product_name = product[product_headers.index('Product Name')]
        channel = product[product_headers.index('Channel Username')]
        sheet_value = product[product_headers.index(field)]
        offer_rows = self.store.read('Offers')
        source_revision = offer_rows[-1][offer_rows[0].index('Offer ID')]
        conflict_id = 'sheet:preserved-import-timestamp'
        self.queue.record_sheet_conflict({
            'conflict_id': conflict_id, 'product_id': product_id, 'product_name': product_name,
            'channel': channel, 'field': field, 'source_revision': source_revision,
            'base': 'older-import-time', 'server': 'new-import-time', 'sheet': sheet_value,
        })

        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': TOKEN}
        headers = {'X-Molavi-Reference-Token': TOKEN, 'X-Molavi-Actor': 'wp:7'}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'build_store', return_value=self.store), \
                patch.object(server, 'handoff_queue', return_value=self.queue):
            client = server.app.test_client()
            listed = client.get('/pipeline/sheet-conflicts', headers=headers)
            self.assertEqual(listed.status_code, 200, listed.get_json())
            visible = next(item for item in listed.get_json()['conflicts']
                           if item['conflict_id'] == conflict_id)
            self.assertTrue(visible['sheet_preserved'])
            self.assertFalse(visible['server_owned'])
            rejected = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict_id, 'choice': 'server', 'note': 'do not replace import time',
            })
            self.assertEqual(rejected.status_code, 409)
            accepted = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict_id, 'choice': 'preserve', 'note': '',
            })
            repeated = client.post('/pipeline/sheet-conflicts', headers=headers, json={
                'conflict_id': conflict_id, 'choice': 'preserve', 'note': '',
            })
        self.assertEqual(accepted.status_code, 200, accepted.get_json())
        self.assertTrue(accepted.get_json()['sheet_preserved'])
        self.assertIsNone(accepted.get_json()['publish_job'])
        self.assertEqual(repeated.status_code, 200, repeated.get_json())
        self.assertTrue(repeated.get_json()['idempotent'])
        self.assertIsNone(repeated.get_json()['publish_job'])
        self.assertEqual(cell(self.store, field), sheet_value)
        self.assertEqual(self.queue.get_sheet_conflict(conflict_id)['choice'], 'preserve')


if __name__ == '__main__':
    unittest.main()
