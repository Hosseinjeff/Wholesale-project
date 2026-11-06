import hashlib
import json
import os
import tempfile
import time
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

from catalog.ai_confirmations import (
    AIConfirmationLedger, ConfirmationConflict, apply_decision, case_reextraction_inventory,
    recent_policy_reextraction_inventory, reextraction_inventory, review_cases,
    review_case_id, validate_choice,
)
from catalog.durable import Handoffs
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
            'Source Timestamp Basis': 'supplier_channel_update',
            'Source Post Created At': '2026-10-20T10:00:00Z',
            'Source Post Updated At': '2026-10-27T10:00:00Z',
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
        self.assertEqual(self.case['source_created_at'], '2026-10-20T10:00:00Z')
        self.assertEqual(self.case['source_updated_at'], '2026-10-27T10:00:00Z')

    def decision(self, *, choice='confirm', fields=None, expected=None, note='Checked source'):
        return self.ledger.decide(case_id=self.case['case_id'], offer_id=self.case['offer_id'],
            signature=self.case['signature'], choice=choice, fields=fields or {}, actor_id='wp:7',
            note=note, expected_decision_id=expected)

    def package_fields(self):
        return {'price_role':'package','sale_price':240000,'actual_price':320000,
                'package_count':24,'min_qty':24,'stock_status':'Available'}

    def test_recent_trusted_edit_controls_freshness_when_original_post_is_old(self):
        created = datetime.now(timezone.utc) - timedelta(days=30)
        updated = datetime.now(timezone.utc) - timedelta(days=1)
        offer = dict(self.source, **{
            'Offer ID':'batch-edited:msg-1:0', 'Batch ID':'batch-edited',
            'Message Timestamp':updated.isoformat(),
            'Source Post Created At':created.isoformat(),
            'Source Post Updated At':updated.isoformat(),
        })
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, offer)])
        case = next(item for item in review_cases(self.store, self.ledger)
            if item['offer_id'] == offer['Offer ID'])
        self.assertFalse(case['historical_reextract'])
        self.assertFalse(case['source_current_check_required'])
        self.assertEqual(case['source_created_at'], created.isoformat())
        self.assertEqual(case['source_updated_at'], updated.isoformat())

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
        self.assertEqual(messages[0]['AI Status'],'owner_rejected')
        inventory=reextraction_inventory(self.store,self.ledger)
        self.assertEqual(inventory['counts']['owner_decisions_preserved'],1)
        self.assertEqual(inventory['counts']['pending'],0)

    def test_zero_candidate_message_is_distinct_and_remains_reextractable(self):
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'msg-empty','Channel':'telegram','Channel Username':'@supplier',
            'Content':'No product fields found','Timestamp':'2026-11-05T10:00:00Z',
            'Status':'product','Batch ID':'batch-empty','AI Status':'pending_ai'})])
        status_saved=refresh_ai_message_status(store,{'id':'msg-empty','batch_id':'batch-empty','channel':'telegram'})
        self.assertTrue(status_saved)
        _,messages=_records(store.read('MessageData'))
        self.assertEqual(messages[0]['AI Status'],'no_product_candidate')
        inventory=reextraction_inventory(store,self.ledger)
        self.assertEqual(inventory['counts']['pending'],1)
        self.assertEqual(inventory['counts']['no_product_candidates'],1)
        self.assertEqual(inventory['pending_messages'][0]['ai_status'],'no_product_candidate')

    def test_photo_only_message_remains_in_media_review_until_owner_decision(self):
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'msg-photo','Channel':'telegram','Channel Username':'@supplier','Content':'',
            'Timestamp':'2026-11-05T10:00:00Z','Status':'product','Batch ID':'batch-photo',
            'Has Media':True,'Media Type':'photo','Image File ID':'AgACAgQAAxkBAAIB',
            'Image File Unique ID':'photo-unique-1','AI Status':'pending_ai'})])

        self.assertTrue(refresh_ai_message_status(store,{'id':'msg-photo','batch_id':'batch-photo',
            'channel':'telegram','has_media':True}))
        _,messages=_records(store.read('MessageData'))
        self.assertEqual(messages[0]['AI Status'],'needs_media_review')
        inventory=reextraction_inventory(store,self.ledger)
        self.assertEqual(inventory['counts']['media_review'],1)
        item=inventory['media_review_messages'][0]
        self.assertEqual(item['image_file_unique_id'],'photo-unique-1')
        self.assertEqual(inventory['counts']['pending'],0)

        decision=self.ledger.dismiss_message(item,actor_id='wp:7')
        self.assertEqual(decision['message_key'],item['message_key'])
        self.assertEqual(reextraction_inventory(store,self.ledger)['counts']['media_review'],0)

    def test_sticker_only_message_is_reported_without_flooding_ocr_review(self):
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'msg-sticker','Channel':'telegram','Channel Username':'@supplier','Content':'',
            'Timestamp':'2026-11-05T10:00:00Z','Status':'product','Batch ID':'batch-sticker',
            'Has Media':True,'Media Type':'sticker','AI Status':'needs_media_review'})])

        inventory=reextraction_inventory(store,self.ledger)

        self.assertEqual(inventory['counts']['media_review'],0)
        self.assertEqual(inventory['counts']['unsupported_media'],1)
        self.assertEqual(inventory['media_review_messages'],[])
        self.assertEqual(inventory['pending_messages'],[])

    def test_photo_only_dismissal_identity_uses_saved_media_id(self):
        from catalog.ai_confirmations import reextraction_message_key
        first={'message_id':'photo-msg','batch_id':'photo-batch','channel_username':'@supplier',
            'timestamp':'2026-11-05T10:00:00Z','content':'','image_file_unique_id':'photo-1'}
        second={**first,'image_file_unique_id':'photo-2'}
        self.assertNotEqual(reextraction_message_key(first),reextraction_message_key(second))

        saved=self.ledger.dismiss_message(first,actor_id='wp:7')
        repeated=self.ledger.dismiss_message(first,actor_id='wp:7')
        self.assertEqual(saved['message_key'],repeated['message_key'])

    def test_photo_with_failed_caption_extraction_uses_only_the_media_review_queue(self):
        from catalog.ai_confirmations import reextraction_message_key
        caption='لطفاً متن روی عکس را بررسی کنید'
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'photo-caption','Channel':'telegram','Channel Username':'@supplier',
            'Content':caption,'Timestamp':'2026-11-05T10:00:00Z','Status':'product',
            'Batch ID':'photo-caption-batch','Has Media':True,'Media Type':'photo',
            'Image File ID':'AgACAgQAAxkBAAIB','Image File Unique ID':'photo-caption-unique',
            'AI Status':'no_product_candidate'})])

        inventory=reextraction_inventory(store,self.ledger)

        self.assertEqual(inventory['counts']['media_review'],1)
        self.assertEqual(inventory['counts']['no_product_candidates'],1)
        self.assertEqual(inventory['pending_messages'],[],
            'a caption that already produced no candidate must not also appear as a text-only retry')
        item=inventory['media_review_messages'][0]
        self.assertEqual(item['content'],caption)
        self.assertEqual(item['image_file_unique_id'],'photo-caption-unique')
        self.assertEqual(item['message_key'],reextraction_message_key({
            **item,'media_identity':item['media_identity']}))

    def test_photo_ocr_reextraction_attaches_transcript_and_keeps_offer_in_review(self):
        from catalog.ai_confirmations import reextraction_message_key
        from catalog.sync import reextract_saved_message
        content='آبمیوه تتراپک سون، ۲۲۰ میلی لیتر\nقیمت فروش ما: ۱۸/۵۰۰ تومان'
        timestamp=datetime.now(timezone.utc).isoformat()
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('Products',PRODUCT_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'photo-ocr-1','Channel':'telegram','Channel Username':'@supplier',
            'Content':'','Timestamp':timestamp,'Source Timestamp Basis':'supplier_post',
            'Status':'product','Batch ID':'photo-batch-1','AI Status':'needs_media_review',
            'Has Media':True,'Media Type':'photo','Image File ID':'AgACAgQAAxkBAAIB',
            'Image File Unique ID':'photo-ocr-unique','URL':'https://t.me/supplier/21'})])
        item=reextraction_inventory(store,self.ledger)['media_review_messages'][0]
        payload={**item,'content_sha256':hashlib.sha256(b'').hexdigest(),
            'run_id':'d'*32,'actor_id':'wp:7'}
        payload['message_key']=reextraction_message_key(payload)
        transcript='آبمیوه تتراپک سون، ۲۲۰ میلی لیتر\nقیمت فروش ما: ۱۸/۵۰۰ تومان'
        product={'name':'آبمیوه تتراپک سون، ۲۲۰ میلی لیتر','sale_price':18500,
            'price':18500,'actual_price':50000,'consumer_price':50000,
            'price_type':'unit','currency':'IRT','confidence':0.99,
            'extraction_confidence':0.99,'stock_status':'Unknown'}
        with patch.dict(os.environ,{'PIPELINE_DB_PATH':self.path},clear=True), \
                patch('catalog.media.fetch_telegram_image',return_value=(b'image','image/jpeg')) as fetch, \
                patch('catalog.gemini.extract_text_from_image',return_value=transcript) as ocr, \
                patch('catalog.sync._extract_supplier_products',return_value=[product]) as extract:
            result=reextract_saved_message(store,payload)

        self.assertEqual(result['status'],'reprocessed')
        fetch.assert_called_once_with('AgACAgQAAxkBAAIB')
        ocr.assert_called_once_with(b'image','image/jpeg',caption='')
        extract.assert_called_once_with(transcript,'@supplier')
        _,offers=_records(store.read('Offers'))
        self.assertEqual(len(offers),1)
        self.assertEqual(offers[0]['Original Message'],'')
        self.assertEqual(offers[0]['Media OCR Text'],transcript)
        self.assertEqual(offers[0]['Image File Unique ID'],'photo-ocr-unique')
        self.assertEqual(offers[0]['Status'],'needs_review')
        self.assertIn('Image OCR requires owner verification',offers[0]['Review Reason'])
        cases=review_cases(store,AIConfirmationLedger(self.path))
        self.assertEqual(len(cases),1)
        self.assertEqual(cases[0]['media_ocr_text'],transcript)
        self.assertEqual(cases[0]['original_message'],'')
        _,messages=_records(store.read('MessageData'))
        self.assertEqual(messages[0]['AI Status'],'needs_confirmation')

    def test_media_ocr_reextraction_combines_caption_with_image_text(self):
        from catalog.ai_confirmations import reextraction_message_key
        from catalog.sync import reextract_saved_message
        caption='لطفاً قیمت و نام کالا را از عکس بخوانید'
        transcript='آبمیوه تتراپک سون، ۲۲۰ میلی لیتر\nقیمت فروش ما: ۱۸/۵۰۰ تومان'
        timestamp=datetime.now(timezone.utc).isoformat()
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('Products',PRODUCT_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'photo-caption-ocr','Channel':'telegram','Channel Username':'@supplier',
            'Content':caption,'Timestamp':timestamp,'Source Timestamp Basis':'supplier_post',
            'Status':'product','Batch ID':'photo-caption-ocr-batch',
            'Has Media':True,'Media Type':'photo','Image File ID':'AgACAgQAAxkBAAIB',
            'Image File Unique ID':'photo-caption-ocr-unique','AI Status':'no_product_candidate'})])
        item=reextraction_inventory(store,self.ledger)['media_review_messages'][0]
        payload={**item,'content_sha256':hashlib.sha256(caption.encode()).hexdigest(),
            'reextraction_scope':'media_review','run_id':'f'*32,'actor_id':'wp:7'}
        payload['message_key']=reextraction_message_key(payload)
        product={'name':'آبمیوه تتراپک سون، ۲۲۰ میلی لیتر','sale_price':18500,
            'price':18500,'actual_price':50000,'consumer_price':50000,
            'price_type':'unit','currency':'IRT','confidence':0.99,
            'extraction_confidence':0.99,'stock_status':'Unknown'}
        with patch.dict(os.environ,{'PIPELINE_DB_PATH':self.path},clear=True), \
                patch('catalog.media.fetch_telegram_image',return_value=(b'image','image/jpeg')), \
                patch('catalog.gemini.extract_text_from_image',return_value=transcript), \
                patch('catalog.sync._extract_supplier_products',return_value=[product]) as extract:
            result=reextract_saved_message(store,payload)

        self.assertEqual(result['status'],'reprocessed')
        extract.assert_called_once_with(caption+'\n'+transcript,'@supplier')
        _,offers=_records(store.read('Offers'))
        self.assertEqual(offers[0]['Original Message'],caption)
        self.assertEqual(offers[0]['Media OCR Text'],transcript)
        self.assertEqual(offers[0]['Status'],'needs_review')

    def test_model_not_product_output_is_not_treated_as_owner_rejection(self):
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        source={**self.source,'Status':'not_product','Owner Decision ID':''}
        store.append('Offers',[_row_from_map(OFFER_HEADERS,source)])
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'msg-1','Channel':'telegram','Channel Username':'@supplier',
            'Content':'Saved source text','Timestamp':'2026-11-05T10:00:00Z',
            'Status':'product','Batch ID':'batch-1','AI Status':'pending_ai'})])

        refresh_ai_message_status(store,{'id':'msg-1','batch_id':'batch-1','channel':'telegram'})

        _,messages=_records(store.read('MessageData'))
        self.assertEqual(messages[0]['AI Status'],'no_product_candidate')
        inventory=reextraction_inventory(store,self.ledger)
        self.assertEqual(inventory['counts']['pending'],1)
        self.assertEqual(inventory['pending_messages'][0]['ai_status'],'no_product_candidate')

    def test_empty_reextraction_stays_available_for_a_later_retry(self):
        from catalog.sync import reextract_saved_message
        from catalog.ai_confirmations import reextraction_message_key
        content='Source post whose extraction returned no products'
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'msg-empty-retry','Channel':'telegram','Channel Username':'@supplier',
            'Content':content,'Timestamp':'2026-11-05T10:00:00Z','Status':'product',
            'Batch ID':'batch-empty-retry','AI Status':'no_product_candidate'})])
        item=reextraction_inventory(store,self.ledger)['pending_messages'][0]
        payload={**item,'content_sha256':hashlib.sha256(content.encode()).hexdigest(),
            'run_id':'c'*32,'actor_id':'wp:7'}
        payload['message_key']=reextraction_message_key(payload)

        with patch('catalog.sync._extract_supplier_products',return_value=[]):
            result=reextract_saved_message(store,payload)

        self.assertEqual(result['status'],'no_products')
        retry_inventory=reextraction_inventory(store,self.ledger)
        self.assertEqual(retry_inventory['counts']['pending'],1)
        self.assertEqual(retry_inventory['pending_messages'][0]['message_id'],'msg-empty-retry')

    def test_recent_policy_inventory_is_freshness_bound_and_version_idempotent(self):
        from catalog.gemini import extraction_policy_version
        from catalog.ai_confirmations import reextraction_source_key
        now=datetime(2026,11,6,12,0,tzinfo=timezone.utc)
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        sources=[
            {'ID':'recent','Batch ID':'batch-recent','Timestamp':(now-timedelta(days=1)).isoformat(),
             'Content':'Beans, price 100000 toman','AI Status':'ai_processed'},
            {'ID':'old','Batch ID':'batch-old','Timestamp':(now-timedelta(days=15)).isoformat(),
             'Content':'Old beans, price 100000 toman','AI Status':'ai_processed'},
            {'ID':'unknown','Batch ID':'batch-unknown','Timestamp':'',
             'Content':'Unknown-time beans, price 100000 toman','AI Status':'pending_ai'},
            {'ID':'future','Batch ID':'batch-future','Timestamp':(now+timedelta(hours=1)).isoformat(),
             'Content':'Future beans, price 100000 toman','AI Status':'pending_ai'},
        ]
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'Channel':'telegram','Channel Username':'@supplier','Status':'product',**item})
            for item in sources])
        # A prior review-stage result is eligible under a new policy version.
        store.append('Offers',[_row_from_map(OFFER_HEADERS,{
            'Offer ID':'reextract:old-run:old-offer','Product Name':'Beans',
            'Channel Username':'@supplier','Status':'needs_review','Review Reason':'low confidence',
            'Message ID':'recent','Batch ID':'reextract:old-run',
            'Message Timestamp':sources[0]['Timestamp'],'Original Message':sources[0]['Content'],
        })])
        version=extraction_policy_version()
        old_version='gemini-0000000000000000'
        receipt_key=reextraction_source_key(source_url='',channel='@supplier',message_id='recent',
            timestamp=sources[0]['Timestamp'],content=sources[0]['Content'])
        self.ledger.record_source_reextraction(source_key=receipt_key,
            batch_id='reextract:old-run:old-message',outcome='products',run_id='1'*32,
            actor_id='wp:7',policy_version=old_version)

        preview=recent_policy_reextraction_inventory(store,self.ledger,now=now)
        self.assertEqual(preview['policy_version'],version)
        self.assertEqual([item['message_id'] for item in preview['messages']],['recent'])
        self.assertEqual(preview['unknown_or_future_timestamp_count'],2)
        self.assertEqual(preview['outside_window_count'],1)

        self.ledger.record_source_reextraction(source_key=receipt_key,
            batch_id='reextract:current-run:current-message',outcome='no_products',run_id='2'*32,
            actor_id='wp:7',policy_version=version)
        same_policy=recent_policy_reextraction_inventory(store,self.ledger,now=now)
        self.assertEqual(same_policy['eligible_count'],0,
            'even a no-product result is not repeatedly charged to the same extraction policy')

    def test_recent_policy_inventory_includes_image_posts_as_review_only_ocr_work(self):
        now=datetime.now(timezone.utc)
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS);store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'recent-photo','Channel':'telegram','Channel Username':'@supplier',
            'Content':'','Timestamp':(now-timedelta(hours=4)).isoformat(),
            'Status':'product','Batch ID':'photo-batch','Has Media':True,'Media Type':'photo',
            'Image File ID':'AgACAgQAAxkBAAIB','Image File Unique ID':'photo-unique',
            'AI Status':'needs_media_review'})])
        preview=recent_policy_reextraction_inventory(store,self.ledger,now=now)
        self.assertEqual(preview['eligible_count'],1)
        self.assertEqual(preview['media_ocr_count'],1)
        self.assertTrue(preview['messages'][0]['media_ocr'])
        self.assertEqual(preview['messages'][0]['image_file_unique_id'],'photo-unique')

    def test_recent_policy_job_stops_when_the_policy_changes_after_enqueue(self):
        from catalog.gemini import extraction_policy_version
        from catalog.sync import reextract_saved_message
        current=extraction_policy_version()
        stale='gemini-0000000000000000'
        if stale==current:
            stale='gemini-1111111111111111'
        payload={'message_id':'stale-message','batch_id':'stale-batch',
            'channel_username':'@supplier','timestamp':'2026-11-06T10:00:00Z',
            'content_sha256':hashlib.sha256(b'post').hexdigest(),'message_key':'a'*64,
            'run_id':'5'*32,'actor_id':'wp:7','reextraction_scope':'recent_policy',
            'policy_version':stale}
        with patch('catalog.sync._extract_supplier_products') as extract:
            result=reextract_saved_message(self.store,payload)
        self.assertEqual(result['status'],'policy_changed')
        extract.assert_not_called()

    def test_source_reextraction_ledger_migrates_legacy_rows_to_unknown_policy(self):
        import sqlite3
        path=str(Path(self.tmp.name)/'legacy-pipeline.sqlite')
        with sqlite3.connect(path) as db:
            db.execute('''CREATE TABLE ai_source_reextractions (
                source_key TEXT PRIMARY KEY,batch_id TEXT NOT NULL,outcome TEXT NOT NULL,
                run_id TEXT NOT NULL,actor_id TEXT NOT NULL,completed_at REAL NOT NULL)''')
            db.execute('INSERT INTO ai_source_reextractions VALUES (?,?,?,?,?,?)',
                ('a'*64,'reextract:legacy:message','products','3'*32,'wp:7',time.time()))
        legacy=AIConfirmationLedger(path)
        row=legacy.source_reextractions()['a'*64]
        self.assertEqual(row['policy_version'],'')

    def test_prompt_and_model_changes_change_the_extraction_policy_fingerprint(self):
        from catalog import gemini
        version=gemini.extraction_policy_version()
        with patch.object(gemini,'_MESSAGE_PROMPT',gemini._MESSAGE_PROMPT+'\nNew extraction rule.'):
            self.assertNotEqual(gemini.extraction_policy_version(),version)
        with patch.dict(os.environ,{'AI_MODEL':'gemini-policy-test'},clear=True):
            self.assertNotEqual(gemini.extraction_policy_version(),version)

    def test_recent_policy_reextraction_updates_the_existing_product_row(self):
        from catalog.ai_confirmations import recent_policy_reextraction_inventory
        from catalog.gemini import extraction_policy_version
        from catalog.sync import _product_id_for_offer, reextract_saved_message
        content='Beans 12 pack\nقیمت فروش ما: ۱۰۵/۰۰۰ تومان'
        timestamp=(datetime.now(timezone.utc)-timedelta(days=1)).isoformat()
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS);store.ensure('Products',PRODUCT_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        product={'name':'Beans 12 pack','sale_price':100000,'price':100000,
            'actual_price':150000,'consumer_price':150000,'price_type':'unit',
            'currency':'IRT','package_count':12,'confidence':0.99,
            'extraction_confidence':0.99,'stock_status':'Available'}
        stable_id=_product_id_for_offer(product,'@supplier')
        store.append('Offers',[_row_from_map(OFFER_HEADERS,{
            'Offer ID':'batch-recent:msg-recent:0','Product ID':stable_id,
            'Product Name':'Beans 12 pack','Channel':'telegram','Channel Username':'@supplier',
            'Sale Price':100000,'Price':100000,'Actual Price':150000,'Consumer Price':150000,
            'Price Type':'unit','Currency':'IRT','Package Count':12,'Status':'imported',
            'Message ID':'msg-recent','Batch ID':'batch-recent','Message Timestamp':timestamp,
            'Source Post URL':'https://t.me/supplier/21','Original Message':content,
        })])
        store.append('Products',[_row_from_map(PRODUCT_HEADERS,{
            'Product ID':stable_id,'Product Name':'Beans 12 pack','Channel':'telegram',
            'Channel Username':'@supplier','Sale Price':100000,'Price':100000,
            'Actual Price':150000,'Consumer Price':150000,'Price Type':'unit',
            'Currency':'IRT','Package Count':12,'Status':'imported',
        })])
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'msg-recent','Channel':'telegram','Channel Username':'@supplier',
            'Content':content,'Timestamp':timestamp,'URL':'https://t.me/supplier/21',
            'Status':'product','Batch ID':'batch-recent','AI Status':'ai_processed'})])
        preview=recent_policy_reextraction_inventory(store,self.ledger)
        item=preview['messages'][0]
        payload={**item,'content_sha256':hashlib.sha256(content.encode()).hexdigest(),
            'run_id':'4'*32,'actor_id':'wp:7','reextraction_scope':'recent_policy',
            'policy_version':extraction_policy_version(),
            'reextraction_digest':preview['digest']}
        from catalog.ai_confirmations import reextraction_message_key
        payload['message_key']=reextraction_message_key(item)
        corrected={**product,'name':'Beans carton of 12','sale_price':105000,'price':105000}
        with patch.dict(os.environ,{'PIPELINE_DB_PATH':self.path},clear=True), \
                patch('catalog.sync._extract_supplier_products',return_value=[corrected]):
            result=reextract_saved_message(store,payload)
        self.assertEqual(result['status'],'reprocessed')
        _,products=_records(store.read('Products'))
        self.assertEqual(len(products),1)
        self.assertEqual(products[0]['Product ID'],stable_id)
        self.assertEqual(products[0]['Product Name'],'Beans carton of 12')
        self.assertEqual(products[0]['Sale Price'],105000)
        saved_receipt=AIConfirmationLedger(self.path).source_reextractions()[item['source_key']]
        self.assertEqual(saved_receipt['policy_version'],payload['policy_version'])

    def test_legacy_not_product_state_without_owner_decision_is_recoverable(self):
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'msg-legacy-empty','Channel':'telegram','Channel Username':'@supplier',
            'Content':'Source text saved before the status split','Timestamp':'2026-11-05T10:00:00Z',
            'Status':'product','Batch ID':'batch-legacy','AI Status':'not_product'})])
        inventory=reextraction_inventory(store,self.ledger)
        self.assertEqual(inventory['counts']['no_product_candidates'],1)
        self.assertEqual(inventory['pending_messages'][0]['ai_status'],'no_product_candidate')

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
                'Source Timestamp Basis':'supplier_channel_update',
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

    def test_publication_hold_blocks_only_cases_from_its_source(self):
        from importlib import import_module
        server=import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path=str(Path(tmp)/'pipeline.sqlite')
            store=MemoryStore();store.ensure('Offers',OFFER_HEADERS);store.ensure('Products',PRODUCT_HEADERS)
            store.ensure('MessageData',MESSAGE_HEADERS)
            held_source={'Offer ID':'held-batch:held-message:0','Product Name':'Held Beans',
                'Channel':'telegram','Channel Username':'@supplier','Sale Price':100000,
                'Actual Price':150000,'Price Type':'needs_review','Currency':'IRT',
                'Status':'needs_review','Review Reason':'supplier price role is unclear',
                'Stock Status':'Available','Package Count':0,'Min Qty':1,
                'Message ID':'held-message','Batch ID':'held-batch',
                'Message Timestamp':'2026-10-28T10:00:00Z',
                'Source Timestamp Basis':'supplier_channel_update',
                'Original Message':'Held Beans, 100000 toman'}
            independent={**held_source,'Offer ID':'independent-batch:free-message:0',
                'Product Name':'Independent Beans','Message ID':'free-message',
                'Batch ID':'independent-batch','Original Message':'Independent Beans, 100000 toman'}
            store.append('Offers',[_row_from_map(OFFER_HEADERS,held_source),
                _row_from_map(OFFER_HEADERS,independent)])
            store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
                'ID':source['Message ID'],'Channel':'telegram','Channel Username':'@supplier',
                'Content':source['Original Message'],'Timestamp':source['Message Timestamp'],
                'Status':'product','Batch ID':source['Batch ID'],'AI Status':'pending_ai'})
                for source in (held_source,independent)])
            queue=Handoffs(path)
            queue.put('batch','held-batch',{'batch_id':'held-batch','expected_count':1,'messages':[{
                'id':'held-message','channel_username':'@supplier','content':'Held Beans offer'}]})
            queue.hold_publication('held-batch','batch_unverified')
            headers={'X-Molavi-Reference-Token':'x'*40,'X-Molavi-Actor':'wp:7'}
            env={'PIPELINE_DB_PATH':path,'REFERENCE_BRIDGE_TOKEN':'x'*40,'REFERENCE_OWNER_IDS':'wp:7'}
            with patch.dict(os.environ,env),patch.object(server,'USE_SHEETS',True), \
                    patch.object(server,'build_store',return_value=store), \
                    patch.object(server,'handoff_queue',return_value=queue), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                client=server.app.test_client()
                listed=client.get('/pipeline/ai-confirmations',headers=headers).get_json()
                held=next(item for item in listed['cases'] if item['offer_id']==held_source['Offer ID'])
                free=next(item for item in listed['cases'] if item['offer_id']==independent['Offer ID'])
                self.assertTrue(listed['publication_held'])
                self.assertTrue(held['publication_held'])
                self.assertFalse(free['publication_held'])
                def payload(case):
                    return {'case_id':case['case_id'],'offer_id':case['offer_id'],
                        'signature':case['signature'],'choice':'confirm',
                        'fields':{'price_role':'unit','sale_price':100000,'actual_price':150000,
                            'package_count':'','min_qty':1,'stock_status':'Available'},
                        'note':'Verified against source post','expected_decision_id':None}
                blocked=client.post('/pipeline/ai-confirmations',json=payload(held),headers=headers)
                self.assertEqual(blocked.status_code,409,blocked.get_json())
                accepted=client.post('/pipeline/ai-confirmations',json=payload(free),headers=headers)
                self.assertEqual(accepted.status_code,200,accepted.get_json())
                self.assertEqual(len(queue.publication_holds()),1,
                    'the unrelated decision must not clear or change the held source batch')

    def test_hold_without_source_receipt_remains_a_global_fail_closed_guard(self):
        from importlib import import_module
        server=import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path=str(Path(tmp)/'pipeline.sqlite')
            store=MemoryStore();store.ensure('Offers',OFFER_HEADERS);store.ensure('Products',PRODUCT_HEADERS)
            source={'Offer ID':'batch:message:0','Product Name':'Beans','Channel':'telegram',
                'Channel Username':'@supplier','Sale Price':100000,'Actual Price':150000,
                'Price Type':'needs_review','Currency':'IRT','Status':'needs_review',
                'Review Reason':'supplier price role is unclear','Stock Status':'Available',
                'Package Count':0,'Min Qty':1,'Message ID':'message','Batch ID':'batch',
                'Message Timestamp':'2026-10-28T10:00:00Z','Original Message':'Beans, 100000 toman'}
            store.append('Offers',[_row_from_map(OFFER_HEADERS,source)])
            queue=Handoffs(path);queue.hold_publication('unknown-scope','legacy_hold')
            headers={'X-Molavi-Reference-Token':'x'*40,'X-Molavi-Actor':'wp:7'}
            env={'PIPELINE_DB_PATH':path,'REFERENCE_BRIDGE_TOKEN':'x'*40,'REFERENCE_OWNER_IDS':'wp:7'}
            with patch.dict(os.environ,env),patch.object(server,'USE_SHEETS',True), \
                    patch.object(server,'build_store',return_value=store), \
                    patch.object(server,'handoff_queue',return_value=queue), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                response=server.app.test_client().get('/pipeline/ai-confirmations',headers=headers)
                item=response.get_json()['cases'][0]
                self.assertTrue(item['publication_held'],
                    'a legacy hold without source context must remain globally fail closed')

    def test_old_forwarded_source_requires_fresh_verification_and_keeps_unknown_stock_explicit(self):
        from importlib import import_module
        server = import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path = str(Path(tmp) / 'pipeline.sqlite')
            store = MemoryStore(); store.ensure('Offers', OFFER_HEADERS); store.ensure('Products', PRODUCT_HEADERS)
            source = {'Offer ID':'batch-old:message:0','Product Name':'Beans',
                'Channel':'telegram','Channel Username':'@supplier','Sale Price':0,
                'Detected Price':100000,'Actual Price':150000,'Price Type':'needs_review',
                'Currency':'IRT','Status':'needs_review','Review Reason':'Re-extraction result awaiting owner review',
                'Stock Status':'Unknown','Package Count':12,'Min Qty':1,'Message ID':'message',
                'Batch ID':'batch-old','Message Timestamp':'2026-09-28T10:00:00Z',
                'Source Timestamp Basis':'supplier_channel_forward',
                'Source Post Created At':'2026-09-28T10:00:00Z',
                'Source Post URL':'https://t.me/supplier/1','Import Timestamp':'2026-10-28T10:01:00Z',
                'Original Message':'Beans, 100000 toman'}
            store.append('Offers', [_row_from_map(OFFER_HEADERS, source)])
            ledger = AIConfirmationLedger(path)
            case = review_cases(store, ledger)[0]
            self.assertFalse(case['historical_reextract'])
            self.assertTrue(case['source_current_check_required'])
            payload = {'case_id':case['case_id'],'offer_id':case['offer_id'],'signature':case['signature'],
                'choice':'confirm','fields':{'price_role':'unit','sale_price':100000,'actual_price':150000,
                    'package_count':12,'min_qty':1,'stock_status':'Unknown'},
                'note':'Checked the current supplier post; stock was not stated.',
                'expected_decision_id':None,'source_current_verified':False}
            with patch.dict(os.environ, {'PIPELINE_DB_PATH':path,'REFERENCE_BRIDGE_TOKEN':'x'*40,
                    'REFERENCE_OWNER_IDS':'wp:7'}), patch.object(server,'USE_SHEETS',True), \
                    patch.object(server,'build_store',return_value=store), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                client=server.app.test_client()
                blocked=client.post('/pipeline/ai-confirmations',json=payload,
                    headers={'X-Molavi-Reference-Token':'x'*40,'X-Molavi-Actor':'wp:7'})
                self.assertEqual(blocked.status_code,400,blocked.get_json())
                self.assertEqual(blocked.get_json()['error'],'source_current_verification_required')
                self.assertIsNone(ledger.latest(case['case_id']))
                payload['source_current_verified']=True
                response=client.post('/pipeline/ai-confirmations',json=payload,
                    headers={'X-Molavi-Reference-Token':'x'*40,'X-Molavi-Actor':'wp:7'})
                self.assertEqual(response.status_code,200,response.get_json())
                decision=ledger.latest(case['case_id'])
                self.assertIn('موجودی همچنان نامشخص است',decision['note'])
                self.assertNotIn('وضعیت موجودی با منبع فعلی بررسی شد',decision['note'])


if __name__ == '__main__':
    unittest.main()
