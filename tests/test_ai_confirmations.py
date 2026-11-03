import hashlib
import json
import os
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

from catalog.ai_confirmations import (
    AIConfirmationLedger, ConfirmationConflict, apply_decision, case_reextraction_inventory,
    review_cases, review_case_id, validate_choice,
)
from catalog.extract import MESSAGE_HEADERS, OFFER_HEADERS, PRODUCT_HEADERS
from catalog.pricing import catalog_from_records, default_settings
from catalog.sync import MemoryStore, _records, _row_from_map, refresh_ai_message_status
from reference_import.importer import HEADERS as REFERENCE_HEADERS


class AIConfirmationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'pipeline.sqlite')
        self.ledger = AIConfirmationLedger(self.path)
        self.store = MemoryStore()
        self.store.ensure('Offers', OFFER_HEADERS)
        self.store.ensure('Products', PRODUCT_HEADERS)
        self.store.ensure('MessageData', MESSAGE_HEADERS)
        self.source = {
            'Offer ID': 'batch-1:msg-1:0', 'Product Name': 'Tuna 24 pack',
            'Channel Username': '@supplier', 'Channel': 'telegram', 'Sale Price': 240000,
            'Price': 240000, 'Actual Price': 320000, 'Consumer Price': 320000,
            'Package Price': 240000, 'Price Type': 'needs_review', 'Currency': 'IRT',
            'Packaging': 'carton', 'Volume': '180g', 'Category': 'Canned Food',
            'Variation Type': '', 'Stock Status': 'Available', 'Description': '',
            'Status': 'needs_review', 'Review Reason': 'supplier price role is unclear; low confidence',
            'Extraction Confidence Score': .42, 'Confidence': .42, 'Message ID': 'msg-1',
            'Batch ID': 'batch-1', 'Message Timestamp': '2026-10-27T10:00:00Z',
            'Source Post URL': 'https://t.me/supplier/1',
            'Forwarded By': 'owner', 'Import Timestamp': '2026-10-27T10:01:00Z',
            'Original Message': 'Tuna 24 pack, 240000 toman', 'Min Qty': 24,
            'Package Count': 24, 'Reference Match State': '',
        }
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, self.source)])
        self.store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, {
            'ID':'msg-1','Channel':'telegram','Channel Username':'@supplier','Content':'Tuna 24 pack, 240000 toman',
            'Timestamp':'2026-10-27T10:00:00Z','Status':'product','Batch ID':'batch-1','AI Status':'pending_ai'})])
        self.case = review_cases(self.store, self.ledger)[0]
        self.assertEqual(self.case['source_post_url'], 'https://t.me/supplier/1')

    def decision(self, *, choice='confirm', fields=None, expected=None, note='Checked source'):
        return self.ledger.decide(case_id=self.case['case_id'], offer_id=self.case['offer_id'],
            signature=self.case['signature'], choice=choice, fields=fields or {}, actor_id='wp:7',
            note=note, expected_decision_id=expected)

    def package_fields(self):
        return {'price_role':'package','sale_price':240000,'actual_price':320000,
                'package_count':24,'min_qty':24,'stock_status':'Available'}

    def test_reference_only_hold_is_not_duplicated_in_ai_queue(self):
        other = dict(self.source, **{'Offer ID':'batch-1:msg-2:0',
            'Review Reason':'reference: price_role_needs_review'})
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, other)])
        cases = review_cases(self.store, self.ledger)
        self.assertEqual([item['offer_id'] for item in cases], [self.source['Offer ID']])

    def test_same_source_variants_group_only_when_review_terms_match(self):
        first = dict(self.source, **{'Offer ID':'batch-1:msg-1:1', 'Product Name':'Tuna Lemon',
                                     'Variation Type':'Lemon'})
        second = dict(self.source, **{'Offer ID':'batch-1:msg-1:2', 'Product Name':'Tuna Herb',
                                      'Variation Type':'Herb'})
        self.store.append('Offers', [
            _row_from_map(OFFER_HEADERS, first), _row_from_map(OFFER_HEADERS, second)])
        cases = review_cases(self.store, self.ledger)
        self.assertEqual(len(cases), 3)
        first_case = next(item for item in cases if item['offer_id'] == first['Offer ID'])
        second_case = next(item for item in cases if item['offer_id'] == second['Offer ID'])
        self.assertEqual(first_case['variation_type'], 'Lemon')
        self.assertEqual(first_case['variant_group_id'], second_case['variant_group_id'])

        other_message = dict(self.source, **{'Offer ID':'batch-2:msg-2:0', 'Product Name':'Tuna Mint',
            'Variation Type':'Mint', 'Message ID':'msg-2', 'Batch ID':'batch-2'})
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, other_message)])
        cases = review_cases(self.store, self.ledger)
        other_case = next(item for item in cases if item['offer_id'] == other_message['Offer ID'])
        self.assertNotIn('variant_group_id', other_case)

        other_size = dict(self.source, **{'Offer ID':'batch-1:msg-1:4', 'Product Name':'Tuna Mint',
            'Variation Type':'Mint', 'Volume':'220 میل'})
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, other_size)])
        cases = review_cases(self.store, self.ledger)
        size_case = next(item for item in cases if item['offer_id'] == other_size['Offer ID'])
        self.assertNotIn('variant_group_id', size_case)

        changed = dict(self.source, **{'Offer ID':'batch-1:msg-1:3', 'Product Name':'Tuna Chili',
                                       'Variation Type':'Chili', 'Sale Price':250000})
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, changed)])
        cases = review_cases(self.store, self.ledger)
        changed_case = next(item for item in cases if item['offer_id'] == changed['Offer ID'])
        self.assertNotIn('variant_group_id', changed_case)

    def test_case_includes_ranked_reference_suggestions_for_owner_review(self):
        reference = [''] * len(REFERENCE_HEADERS)
        reference[0] = 'Tuna 24 pack'
        reference[1] = 'Tuna'
        reference[2] = 'tuna'
        reference[3] = 2400000
        reference[4] = 100000
        reference[6] = 24
        reference[13] = 'Canned Food'
        reference[16] = 'ref-tuna-24'
        cases = review_cases(self.store, self.ledger,
            reference_snapshot={'rows': [reference]})
        case = next(item for item in cases if item['offer_id'] == self.source['Offer ID'])
        self.assertEqual(case['reference_candidates'][0]['reference_id'], 'ref-tuna-24')
        self.assertEqual(case['reference_candidates'][0]['name'], 'Tuna 24 pack')
        self.assertGreater(case['reference_candidates'][0]['score'], 0.35)

    def test_duplicate_variation_labels_are_not_grouped(self):
        first = dict(self.source, **{'Offer ID':'batch-1:msg-1:1', 'Product Name':'Tuna Lemon',
                                     'Variation Type':'Lemon'})
        second = dict(self.source, **{'Offer ID':'batch-1:msg-1:2', 'Product Name':'Tuna Lemon',
                                      'Variation Type':'Lemon'})
        self.store.append('Offers', [
            _row_from_map(OFFER_HEADERS, first), _row_from_map(OFFER_HEADERS, second)])
        cases = review_cases(self.store, self.ledger)
        self.assertTrue(all('variant_group_id' not in item for item in cases))

    def test_identical_reextractions_from_one_source_post_share_one_review_decision(self):
        repeated = dict(self.source, **{
            'Offer ID':'reextract:run-2:msg-2:0', 'Message ID':'msg-2',
            'Batch ID':'reextract:run-2', 'Import Timestamp':'2026-10-28T11:00:00Z',
        })
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, repeated)])
        cases = review_cases(self.store, self.ledger)
        self.assertEqual(len(cases), 1)
        case = cases[0]
        self.assertFalse(case['historical_reextract'])

        decision = self.ledger.decide(case_id=case['case_id'], offer_id=case['offer_id'],
            signature=case['signature'], choice='confirm', fields=self.package_fields(),
            actor_id='wp:7', note='Verified once against the linked supplier post')
        apply_decision(self.store, self.ledger, case, decision)
        self.assertEqual(review_cases(self.store, self.ledger), [])

    def test_open_case_reextraction_inventory_deduplicates_variants_by_source_post(self):
        first = dict(self.source, **{'Offer ID':'batch-1:msg-1:1', 'Product Name':'Juice Mango',
            'Variation Type':'Mango'})
        second = dict(self.source, **{'Offer ID':'batch-1:msg-1:2', 'Product Name':'Juice Peach',
            'Variation Type':'Peach'})
        self.store.append('Offers', [
            _row_from_map(OFFER_HEADERS, first), _row_from_map(OFFER_HEADERS, second)])

        inventory = case_reextraction_inventory(self.store, self.ledger)

        self.assertEqual(inventory['case_count'], 3)
        self.assertEqual(inventory['message_count'], 1)
        self.assertEqual(inventory['unmatched_case_ids'], [])
        self.assertEqual(inventory['messages'][0]['case_count'], 3)
        self.assertEqual(inventory['messages'][0]['source_message_id'], 'msg-1')

    def test_reextraction_imports_clean_recent_result_and_closes_old_case(self):
        from catalog.sync import reextract_saved_message
        now = datetime.now(timezone.utc).isoformat()
        content = 'Beans, price 100000 toman, consumer 150000 toman'
        source = {**self.source, 'Offer ID':'batch-current:msg-current:0',
            'Product Name':'Beans', 'Sale Price':0, 'Actual Price':150000,
            'Price':'', 'Status':'needs_review', 'Review Reason':'low confidence',
            'Message ID':'msg-current', 'Batch ID':'batch-current',
            'Message Timestamp':now, 'Original Message':content,
            'Source Post URL':'https://t.me/supplier/2'}
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        store.ensure('MessageData', MESSAGE_HEADERS)
        store.append('Offers', [_row_from_map(OFFER_HEADERS, source)])
        store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, {
            'ID':'msg-current','Channel':'telegram','Channel Username':'@supplier',
            'Content':content,'Timestamp':now,'URL':'https://t.me/supplier/2',
            'Status':'product','Batch ID':'batch-current','AI Status':'needs_confirmation'})])
        ledger = AIConfirmationLedger(self.path)
        inventory = case_reextraction_inventory(store, ledger)
        payload = {**inventory['messages'][0], 'run_id':'a' * 32, 'actor_id':'wp:7'}
        extracted = [{'name':'Beans','sale_price':100000,'price':100000,
            'actual_price':150000,'consumer_price':150000,'package_price':0,
            'packaging':'','volume':'','weight':'','category':'Food','variation_type':'',
            'currency':'IRT','stock_status':'Unknown','description':content,
            'confidence':0.98,'extraction_confidence':0.98,'price_type':'unit'}]

        with patch.dict(os.environ, {'PIPELINE_DB_PATH':self.path,'REFERENCE_STORE_PATH':''}), \
                patch('catalog.sync._extract_supplier_products', return_value=extracted):
            result = reextract_saved_message(store, payload)

        self.assertEqual(result['status'], 'reprocessed')
        self.assertEqual(result['offers_written'], 1)
        self.assertEqual(result['open_cases_remaining'], 0)
        self.assertEqual(review_cases(store, ledger), [])
        self.assertEqual(len(store.read('Products')), 2)
        self.assertEqual(ledger.source_reextractions()[payload['source_key']]['outcome'], 'products')
        _, messages = _records(store.read('MessageData'))
        self.assertEqual(messages[0]['AI Status'], 'ai_processed')

    def test_historical_reextraction_keeps_price_out_of_current_book(self):
        from catalog.sync import reextract_saved_message
        timestamp = (datetime.now(timezone.utc) - timedelta(days=30)).isoformat()
        content = 'Beans, price 100000 toman, consumer 150000 toman'
        source = {**self.source, 'Offer ID':'batch-old:msg-old:0',
            'Product Name':'Beans', 'Sale Price':0, 'Actual Price':150000,
            'Price':'', 'Status':'needs_review', 'Review Reason':'low confidence',
            'Message ID':'msg-old', 'Batch ID':'batch-old',
            'Message Timestamp':timestamp, 'Original Message':content,
            'Source Post URL':'https://t.me/supplier/3'}
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        store.ensure('MessageData', MESSAGE_HEADERS)
        store.append('Offers', [_row_from_map(OFFER_HEADERS, source)])
        store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, {
            'ID':'msg-old','Channel':'telegram','Channel Username':'@supplier',
            'Content':content,'Timestamp':timestamp,'URL':'https://t.me/supplier/3',
            'Status':'product','Batch ID':'batch-old','AI Status':'needs_confirmation'})])
        ledger = AIConfirmationLedger(self.path)
        payload = {**case_reextraction_inventory(store, ledger)['messages'][0],
            'run_id':'b' * 32,'actor_id':'wp:7'}
        extracted = [{'name':'Beans','sale_price':100000,'price':100000,
            'actual_price':150000,'consumer_price':150000,'package_price':0,
            'packaging':'','volume':'','weight':'','category':'Food','variation_type':'',
            'currency':'IRT','stock_status':'Unknown','description':content,
            'confidence':0.98,'extraction_confidence':0.98,'price_type':'unit'}]

        with patch.dict(os.environ, {'PIPELINE_DB_PATH':self.path,'REFERENCE_STORE_PATH':''}), \
                patch('catalog.sync._extract_supplier_products', return_value=extracted):
            result = reextract_saved_message(store, payload)

        self.assertEqual(result['status'], 'reprocessed')
        self.assertEqual(result['open_cases_remaining'], 1)
        self.assertEqual(len(store.read('Products')), 1)
        case = review_cases(store, ledger)[0]
        self.assertTrue(case['historical_reextract'])
        self.assertIn('Historical price needs current supplier verification', case['reason'])

    def test_unpriced_variants_are_not_grouped_as_same_price(self):
        first = dict(self.source, **{'Offer ID':'batch-1:msg-1:1', 'Product Name':'Juice Peach',
            'Variation Type':'Peach', 'Sale Price':0, 'Package Price':0, 'Detected Price':0})
        second = dict(self.source, **{'Offer ID':'batch-1:msg-1:2', 'Product Name':'Juice Apple',
            'Variation Type':'Apple', 'Sale Price':0, 'Package Price':0, 'Detected Price':0})
        self.store.append('Offers', [
            _row_from_map(OFFER_HEADERS, first), _row_from_map(OFFER_HEADERS, second)])
        cases = review_cases(self.store, self.ledger)
        self.assertTrue(all('variant_group_id' not in item for item in cases))

    def test_decision_is_durable_idempotent_and_compare_and_set(self):
        first = self.decision(fields=self.package_fields())
        retry = self.decision(fields=self.package_fields())
        self.assertEqual(retry['decision_id'], first['decision_id'])
        with self.assertRaises(ConfirmationConflict):
            self.decision(choice='not_product', expected=None)
        corrected = self.ledger.decide(case_id=self.case['case_id'], offer_id=self.case['offer_id'],
            signature=self.case['signature'], choice='confirm',
            fields={**self.package_fields(),'sale_price':250000}, actor_id='wp:7', note='Corrected amount',
            expected_decision_id=first['decision_id'])
        self.assertNotEqual(corrected['decision_id'], first['decision_id'])
        self.assertEqual(self.ledger.latest(self.case['case_id'])['decision_id'], corrected['decision_id'])
        self.assertEqual(os.stat(self.path).st_mode & 0o777, 0o600)

    def test_confirmation_appends_derived_offer_and_normalized_product_idempotently(self):
        decision = self.decision(fields=self.package_fields())
        original = self.store.read('Offers')[1]
        derived_id = apply_decision(self.store, self.ledger, self.case, decision)
        apply_decision(self.store, self.ledger, self.case, decision)
        headers, offers = _records(self.store.read('Offers'))
        self.assertEqual(sum(row.get('Offer ID') == derived_id for row in offers), 1)
        source = next(row for row in offers if row['Offer ID'] == self.source['Offer ID'])
        derived = next(row for row in offers if row['Offer ID'] == derived_id)
        self.assertEqual(self.store.read('Offers')[1], original)
        self.assertEqual(source['Sale Price'], 240000)
        self.assertEqual(derived['Sale Price'], 10000)
        self.assertEqual(derived['Package Price'], 240000)
        self.assertEqual(derived['Min Qty'], 24)
        self.assertEqual(derived['Derived From Offer ID'], self.source['Offer ID'])
        products, product_rows = _records(self.store.read('Products'))
        self.assertEqual(product_rows[0]['Sale Price'], 10000)
        self.assertEqual(product_rows[0]['Min Qty'], 24)
        self.assertEqual(product_rows[0]['Status'], 'imported')
        self.assertEqual(len(self.store.read('Offers')), 3)
        refresh_ai_message_status(self.store,{'id':'msg-1','batch_id':'batch-1','channel':'telegram'})
        _,messages=_records(self.store.read('MessageData'))
        self.assertEqual(messages[0]['AI Status'],'ai_processed')
        self.assertEqual(json.loads(messages[0]['AI Review Case IDs']),[])

    def test_not_product_decision_stays_unavailable_and_out_of_catalog(self):
        decision = self.decision(choice='not_product', note='Message is not an offer')
        apply_decision(self.store, self.ledger, self.case, decision)
        _, products = _records(self.store.read('Products'))
        self.assertEqual(products[0]['Status'], 'not_product')
        _, records = _records(self.store.read('Products'))
        catalog = catalog_from_records(records, default_settings())
        self.assertEqual(catalog, [])
        refresh_ai_message_status(self.store,{'id':'msg-1','batch_id':'batch-1','channel':'telegram'})
        _,messages=_records(self.store.read('MessageData'))
        self.assertEqual(messages[0]['AI Status'],'not_product')

    def test_choice_validation_requires_explicit_price_role_and_package_count(self):
        payload = {'case_id':self.case['case_id'],'offer_id':self.case['offer_id'],
            'signature':self.case['signature'],'choice':'confirm','fields':self.package_fields(),
            'note':'check','expected_decision_id':None}
        validate_choice(payload)
        payload['fields']['package_count'] = ''
        with self.assertRaisesRegex(ValueError, 'package_count_required'):
            validate_choice(payload)
        payload['choice']='not_product'; payload['fields']={'unexpected':True}
        with self.assertRaises(ValueError): validate_choice(payload)


class AIConfirmationRouteTests(unittest.TestCase):
    def test_reextraction_run_reports_live_queue_not_overlapping_source_snapshots(self):
        from importlib import import_module
        server = import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path = str(Path(tmp) / 'pipeline.sqlite')
            store = MemoryStore(); store.ensure('Offers', OFFER_HEADERS)
            for index in range(2):
                source = {
                    'Offer ID':f'batch-{index}:message-{index}:0',
                    'Product Name':f'Beans {index}', 'Channel':'telegram',
                    'Channel Username':'@supplier', 'Status':'needs_review',
                    'Review Reason':'low confidence', 'Message ID':f'message-{index}',
                    'Batch ID':f'batch-{index}', 'Message Timestamp':'2026-10-28T10:00:00Z',
                    'Original Message':f'Beans {index}, 100000 toman',
                }
                store.append('Offers', [_row_from_map(OFFER_HEADERS, source)])

            status = {
                'run_id':'a' * 32, 'state':'complete', 'total':2, 'completed':2,
                'counts':{'done':2}, 'scope':'open_cases', 'case_count':2,
                'digest':'b' * 64,
                'items':[
                    {'state':'done','result':{'status':'reprocessed','products_found':1,
                        'offers_written':1,'open_cases_remaining':2}},
                    {'state':'done','result':{'status':'reprocessed','products_found':1,
                        'offers_written':1,'open_cases_remaining':2}},
                ],
            }

            class Queue:
                def __init__(self, db_path):
                    self.path = db_path
                def reextraction_status(self, run_id):
                    return status if run_id == status['run_id'] else None
                def get(self, job_id):
                    return {'state':'done','result':{'ok':True,'pushed':True}}

            queue = Queue(path)
            with patch.dict(os.environ, {'PIPELINE_DB_PATH':path,'REFERENCE_BRIDGE_TOKEN':'x'*40,
                    'REFERENCE_OWNER_IDS':'wp:7'}), patch.object(server,'USE_SHEETS',True), \
                    patch.object(server,'build_store',return_value=store), \
                    patch.object(server,'handoff_queue',return_value=queue):
                client = server.app.test_client()
                response = client.get('/pipeline/ai-confirmations?reextract=run&run_id=' + status['run_id'],
                    headers={'X-Molavi-Reference-Token':'x'*40,'X-Molavi-Actor':'wp:7'})
                self.assertEqual(response.status_code,200,response.get_json())
                self.assertEqual(response.get_json()['open_cases_remaining'],2)

    def test_owner_route_returns_receipt_and_enqueues_projection(self):
        from importlib import import_module
        server = import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path = str(Path(tmp) / 'pipeline.sqlite')
            store = MemoryStore(); store.ensure('Offers', OFFER_HEADERS); store.ensure('Products', PRODUCT_HEADERS)
            source = {'Offer ID':'batch:message:0','Product Name':'Beans','Channel':'telegram',
                'Channel Username':'@supplier','Sale Price':0,'Actual Price':150000,'Price Type':'needs_review',
                'Currency':'IRT','Status':'needs_review','Review Reason':'supplier price role is unclear',
                'Stock Status':'Available','Package Count':0,'Min Qty':1,'Message ID':'message',
                'Batch ID':'batch','Message Timestamp':'2026-10-28T10:00:00Z',
                'Import Timestamp':'2026-10-28T10:01:00Z','Original Message':'Beans, 100000 toman'}
            store.append('Offers', [_row_from_map(OFFER_HEADERS, source)])
            ledger = AIConfirmationLedger(path)
            case = review_cases(store, ledger)[0]
            payload = {'case_id':case['case_id'],'offer_id':case['offer_id'],'signature':case['signature'],
                'choice':'confirm','fields':{'price_role':'unit','sale_price':100000,'actual_price':150000,
                    'package_count':'','min_qty':1,'stock_status':'Available'},
                'note':'Verified against post','expected_decision_id':None}
            old_sheets = server.USE_SHEETS
            with patch.dict(os.environ, {'PIPELINE_DB_PATH':path,'REFERENCE_BRIDGE_TOKEN':'x'*40,
                    'REFERENCE_OWNER_IDS':'wp:7'}), patch.object(server,'USE_SHEETS',True), \
                    patch.object(server,'build_store',return_value=store), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                client = server.app.test_client()
                headers = {'X-Molavi-Reference-Token':'x'*40,'X-Molavi-Actor':'wp:7'}
                listing=client.get('/pipeline/ai-confirmations',headers=headers)
                self.assertEqual(listing.status_code,200)
                self.assertFalse(listing.get_json()['publication_held'])
                rejected = client.get('/pipeline/ai-confirmations',headers={**headers,'X-Molavi-Actor':'wp:8'})
                self.assertEqual(rejected.status_code,403)
                queue=server.handoff_queue();queue.hold_publication('batch-hold','uncertain')
                self.assertEqual(client.post('/pipeline/ai-confirmations',json=payload,headers=headers).status_code,409)
                with queue.connect() as db: db.execute('DELETE FROM publication_holds WHERE hold_id=?',('batch-hold',))
                response = client.post('/pipeline/ai-confirmations',json=payload,headers=headers)
                self.assertEqual(response.status_code,200,response.get_json())
                receipt=response.get_json()
                self.assertEqual(receipt['case_id'],case['case_id'])
                self.assertEqual(receipt['choice'],'confirm')
                self.assertEqual(receipt['projection_state'],'imported')
                self.assertTrue(receipt['publish_job'].startswith('publish:ai-confirmation:'))
                retry=client.post('/pipeline/ai-confirmations',json=payload,headers=headers)
                self.assertEqual(retry.status_code,200,retry.get_json())
                self.assertEqual(retry.get_json()['decision_id'],receipt['decision_id'])
                self.assertEqual(len(store.read('Offers')),3)
                self.assertEqual(client.post('/pipeline/ai-confirmations',json=payload,
                    headers={**headers,'X-Molavi-Reference-Token':'bad'}).status_code,401)
            server.USE_SHEETS = old_sheets

    def test_historical_confirmation_keeps_unknown_stock_explicit(self):
        from importlib import import_module
        server = import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path = str(Path(tmp) / 'pipeline.sqlite')
            store = MemoryStore(); store.ensure('Offers', OFFER_HEADERS); store.ensure('Products', PRODUCT_HEADERS)
            source = {'Offer ID':'reextract:run:message:0','Product Name':'Beans',
                'Channel':'telegram','Channel Username':'@supplier','Sale Price':0,
                'Detected Price':100000,'Actual Price':150000,'Price Type':'needs_review',
                'Currency':'IRT','Status':'needs_review','Review Reason':'Re-extraction result awaiting owner review',
                'Stock Status':'Unknown','Package Count':12,'Min Qty':1,'Message ID':'message',
                'Batch ID':'reextract:run','Message Timestamp':'2026-09-28T10:00:00Z',
                'Source Post URL':'https://t.me/supplier/1','Import Timestamp':'2026-10-28T10:01:00Z',
                'Original Message':'Beans, 100000 toman'}
            store.append('Offers', [_row_from_map(OFFER_HEADERS, source)])
            ledger = AIConfirmationLedger(path)
            case = review_cases(store, ledger)[0]
            payload = {'case_id':case['case_id'],'offer_id':case['offer_id'],'signature':case['signature'],
                'choice':'confirm','fields':{'price_role':'unit','sale_price':100000,'actual_price':150000,
                    'package_count':12,'min_qty':1,'stock_status':'Unknown'},
                'note':'Checked the current supplier post; stock was not stated.',
                'expected_decision_id':None,'source_current_verified':True}
            with patch.dict(os.environ, {'PIPELINE_DB_PATH':path,'REFERENCE_BRIDGE_TOKEN':'x'*40,
                    'REFERENCE_OWNER_IDS':'wp:7'}), patch.object(server,'USE_SHEETS',True), \
                    patch.object(server,'build_store',return_value=store), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                client=server.app.test_client()
                response=client.post('/pipeline/ai-confirmations',json=payload,
                    headers={'X-Molavi-Reference-Token':'x'*40,'X-Molavi-Actor':'wp:7'})
                self.assertEqual(response.status_code,200,response.get_json())
                decision=ledger.latest(case['case_id'])
                self.assertIn('موجودی همچنان نامشخص است',decision['note'])
                self.assertNotIn('وضعیت موجودی با منبع فعلی بررسی شد',decision['note'])


if __name__ == '__main__':
    unittest.main()
