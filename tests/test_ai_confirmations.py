import hashlib
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from catalog.ai_confirmations import (
    AIConfirmationLedger, ConfirmationConflict, apply_decision, review_cases,
    review_case_id, validate_choice,
)
from catalog.extract import MESSAGE_HEADERS, OFFER_HEADERS, PRODUCT_HEADERS
from catalog.pricing import catalog_from_records, default_settings
from catalog.sync import MemoryStore, _records, _row_from_map, refresh_ai_message_status


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
            'Forwarded By': 'owner', 'Import Timestamp': '2026-10-27T10:01:00Z',
            'Original Message': 'Tuna 24 pack, 240000 toman', 'Min Qty': 24,
            'Package Count': 24, 'Reference Match State': '',
        }
        self.store.append('Offers', [_row_from_map(OFFER_HEADERS, self.source)])
        self.store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, {
            'ID':'msg-1','Channel':'telegram','Channel Username':'@supplier','Content':'Tuna 24 pack, 240000 toman',
            'Timestamp':'2026-10-27T10:00:00Z','Status':'product','Batch ID':'batch-1','AI Status':'pending_ai'})])
        self.case = review_cases(self.store, self.ledger)[0]

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


if __name__ == '__main__':
    unittest.main()
