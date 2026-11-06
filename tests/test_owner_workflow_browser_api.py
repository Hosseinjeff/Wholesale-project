import base64
import hashlib
import hmac
import json
import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from catalog.durable import Handoffs


def owner_token(secret, actor='wp:7', scope='owner:/pipeline/operations-dashboard', *, expires=None):
    now = int(time.time())
    claims = {'iss': 'molavi-wordpress', 'aud': 'molavi-owner-workflows', 'scope': scope,
              'actor': actor, 'iat': now, 'exp': expires or now + 600}
    payload = base64.urlsafe_b64encode(json.dumps(claims, separators=(',', ':')).encode()).decode().rstrip('=')
    signature = base64.urlsafe_b64encode(hmac.new(secret.encode(), payload.encode(), hashlib.sha256).digest()).decode().rstrip('=')
    return payload + '.' + signature


class OwnerWorkflowBrowserAPITests(unittest.TestCase):
    def setUp(self):
        import app as server
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.db_path = str(Path(self.tmp.name) / 'pipeline.sqlite')
        self.reference_path = str(Path(self.tmp.name) / 'reference.sqlite')
        self.secret = 'synthetic-owner-bridge-token-at-least-32-chars'
        webhook = patch.object(server, 'ensure_telegram_webhook', return_value=None)
        webhook.start()
        self.addCleanup(webhook.stop)

    def test_operations_api_accepts_exact_owner_scope_and_adds_origin_restricted_cors(self):
        import app as server
        queue = Handoffs(self.db_path)
        queue.put('batch', 'owner-review', {'private': 'must not appear'})
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': self.secret}
        origin = 'https://www.molavisale.ir'
        with patch.dict(os.environ, env), patch.object(server, 'handoff_queue', return_value=queue):
            client = server.app.test_client()
            accepted = client.get('/pipeline/operations-dashboard', headers={
                'Authorization': 'Bearer ' + owner_token(self.secret), 'Origin': origin})
            self.assertEqual(accepted.status_code, 200, accepted.get_json())
            self.assertEqual(accepted.headers.get('Access-Control-Allow-Origin'), origin)
            self.assertEqual(accepted.headers.get('Cache-Control'), 'no-store')
            self.assertNotIn('private', accepted.get_data(as_text=True))
            wrong_scope = client.get('/pipeline/operations-dashboard', headers={
                'Authorization': 'Bearer ' + owner_token(self.secret, scope='owner:/reference/item-reviews')})
            self.assertEqual(wrong_scope.status_code, 401)
            denied_origin = client.options('/pipeline/operations-dashboard', headers={
                'Origin': 'https://attacker.example', 'Access-Control-Request-Method': 'POST',
                'Access-Control-Request-Headers': 'authorization,content-type'})
            self.assertIsNone(denied_origin.headers.get('Access-Control-Allow-Origin'))

    def test_migration_shadow_inventory_is_owner_only_and_read_only(self):
        import sqlite3
        import app as server
        path = str(Path(self.tmp.name) / 'shadow.sqlite')
        with sqlite3.connect(path) as db:
            db.execute('CREATE TABLE jobs (id TEXT PRIMARY KEY,kind TEXT,state TEXT,payload TEXT)')
            db.execute("INSERT INTO jobs VALUES ('private-id','batch','queued','private payload')")
        before = Path(path).stat().st_mtime_ns
        env = {'PIPELINE_DB_PATH':path,'REFERENCE_STORE_PATH':'',
               'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':self.secret}
        with patch.dict(os.environ,env):
            client = server.app.test_client()
            denied = client.get('/pipeline/migration-shadow')
            self.assertEqual(denied.status_code,401)
            wrong_scope = client.get('/pipeline/migration-shadow',headers={
                'Authorization':'Bearer '+owner_token(self.secret,scope='owner:/pipeline/operations-dashboard')})
            self.assertEqual(wrong_scope.status_code,401)
            accepted = client.get('/pipeline/migration-shadow',headers={
                'Authorization':'Bearer '+owner_token(self.secret,scope='owner:/pipeline/migration-shadow')})
        self.assertEqual(accepted.status_code,200,accepted.get_json())
        self.assertEqual(accepted.headers.get('Cache-Control'),'no-store')
        self.assertEqual(accepted.get_json()['pipeline']['tables']['jobs'],1)
        self.assertNotIn('private payload',accepted.get_data(as_text=True))
        self.assertNotIn('private-id',accepted.get_data(as_text=True))
        self.assertEqual(Path(path).stat().st_mtime_ns,before)

    def test_ai_confirmation_and_reference_decision_routes_accept_scoped_browser_tokens(self):
        import app as server
        from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS, MESSAGE_HEADERS
        from catalog.sync import MemoryStore
        queue = Handoffs(self.db_path)
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        store.ensure('MessageData', MESSAGE_HEADERS)
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_STORE_PATH': self.reference_path,
               'REFERENCE_OWNER_IDS': 'wp:7', 'REFERENCE_BRIDGE_TOKEN': self.secret}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store):
            client = server.app.test_client()
            ai = client.get('/pipeline/ai-confirmations', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/ai-confirmations')})
            self.assertEqual(ai.status_code, 200, ai.get_json())
            self.assertEqual(ai.get_json()['cases'], [])
            decisions = client.get('/reference/decisions', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/reference/decisions')})
            self.assertEqual(decisions.status_code, 200, decisions.get_json())
            self.assertEqual(decisions.get_json()['events'], [])

    def test_photo_only_source_is_visible_and_can_be_explicitly_dismissed(self):
        import app as server
        from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS, MESSAGE_HEADERS
        from catalog.sync import MemoryStore, _row_from_map, _records
        queue=Handoffs(self.db_path)
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('Products',PRODUCT_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        media_rows=[_row_from_map(MESSAGE_HEADERS,{
            'ID':f'photo-msg-{index}','Channel':'telegram','Channel Username':'@supplier','Content':'',
            'Timestamp':'2026-11-05T10:00:00Z','Status':'product','Batch ID':f'photo-batch-{index}',
            'Has Media':True,'Media Type':'photo','Image File ID':f'AgACAgQAAxkBAAIB{index}',
            'Image File Unique ID':f'photo-unique-{index}','AI Status':'needs_media_review'})
            for index in range(13)]
        store.append('MessageData',media_rows)
        env={'PIPELINE_DB_PATH':self.db_path,'REFERENCE_OWNER_IDS':'wp:7',
            'REFERENCE_BRIDGE_TOKEN':self.secret}
        auth={'Authorization':'Bearer '+owner_token(self.secret,scope='owner:/pipeline/ai-confirmations')}
        with patch.dict(os.environ,env),patch.object(server,'USE_SHEETS',True), \
                patch.object(server,'handoff_queue',return_value=queue), \
                patch.object(server,'build_store',return_value=store):
            client=server.app.test_client()
            preview=client.get('/pipeline/ai-confirmations?reextract=preview',headers=auth)
            self.assertEqual(preview.status_code,200,preview.get_json())
            media=preview.get_json()['media_review_messages']
            self.assertEqual(len(media),12)
            self.assertEqual(preview.get_json()['media_review_total'],13)
            cursor=preview.get_json()['media_review_next_after']
            self.assertIsInstance(cursor,str)
            next_page=client.get('/pipeline/ai-confirmations?reextract=preview&media_after='+cursor,
                headers=auth)
            self.assertEqual(next_page.status_code,200,next_page.get_json())
            final_media=next_page.get_json()['media_review_messages']
            self.assertEqual(len(final_media),1)
            self.assertFalse({item['message_key'] for item in media}
                & {item['message_key'] for item in final_media})
            selected=media[0]
            decision=client.post('/pipeline/ai-confirmations',headers=auth,json={
                'action':'dismiss_media_message','message_key':selected['message_key']})
            self.assertEqual(decision.status_code,200,decision.get_json())
            self.assertEqual(decision.get_json()['choice'],'not_product')
            _,messages=_records(store.read('MessageData'))
            self.assertEqual(next(row for row in messages if row['ID']==selected['message_id'])['AI Status'],'owner_rejected')
            repeat=client.post('/pipeline/ai-confirmations',headers=auth,json={
                'action':'dismiss_media_message','message_key':selected['message_key']})
            self.assertEqual(repeat.status_code,200,repeat.get_json())
            self.assertTrue(repeat.get_json()['already_decided'])

    def test_photo_ocr_action_queues_the_exact_reviewed_caption_source(self):
        import app as server
        from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS, MESSAGE_HEADERS
        from catalog.sync import MemoryStore, _row_from_map
        queue=Handoffs(self.db_path)
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS)
        store.ensure('Products',PRODUCT_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'photo-message-ocr','Channel':'telegram','Channel Username':'@supplier',
            'Content':'لطفاً متن روی عکس را بررسی کنید','Timestamp':'2026-11-05T10:00:00Z','Status':'product',
            'Batch ID':'photo-batch-ocr','Has Media':True,'Media Type':'photo',
            'Image File ID':'AgACAgQAAxkBAAIB','Image File Unique ID':'photo-unique-ocr',
            'AI Status':'no_product_candidate'})])
        env={'PIPELINE_DB_PATH':self.db_path,'REFERENCE_OWNER_IDS':'wp:7',
            'REFERENCE_BRIDGE_TOKEN':self.secret,'GOOGLE_API_KEY':'synthetic-gemini-key'}
        auth={'Authorization':'Bearer '+owner_token(self.secret,scope='owner:/pipeline/ai-confirmations')}
        with patch.dict(os.environ,env),patch.object(server,'USE_SHEETS',True), \
                patch.object(server,'handoff_queue',return_value=queue), \
                patch.object(server,'build_store',return_value=store), \
                patch.object(server.threading,'Thread') as worker:
            client=server.app.test_client()
            preview=client.get('/pipeline/ai-confirmations?reextract=preview',headers=auth)
            self.assertEqual(preview.status_code,200,preview.get_json())
            item=preview.get_json()['media_review_messages'][0]
            run_id='e'*32
            queued=client.post('/pipeline/ai-confirmations',headers=auth,json={
                'action':'start_media_reextraction','message_key':item['message_key'],
                'run_id':run_id})

        self.assertEqual(queued.status_code,202,queued.get_json())
        self.assertEqual(queued.get_json()['queued'],1)
        self.assertEqual(queued.get_json()['scope'],'media_review')
        worker.assert_called_once()
        worker.return_value.start.assert_called_once()
        job=queue.get('reextract:'+run_id+':'+item['message_key'])
        self.assertEqual(job['state'],'queued')
        payload=json.loads(job['payload'])
        self.assertEqual(payload['image_file_unique_id'],'photo-unique-ocr')
        self.assertEqual(payload['reextraction_scope'],'media_review')
        self.assertEqual(payload['content'],'لطفاً متن روی عکس را بررسی کنید')
        self.assertEqual(payload['content_sha256'],hashlib.sha256(
            payload['content'].encode()).hexdigest())

    def test_recent_policy_replay_is_bound_to_preview_policy_and_source_digest(self):
        import app as server
        from datetime import datetime, timedelta, timezone
        from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS, MESSAGE_HEADERS
        from catalog.gemini import extraction_policy_version
        from catalog.sync import MemoryStore, _row_from_map

        queue=Handoffs(self.db_path)
        store=MemoryStore()
        store.ensure('Offers',OFFER_HEADERS);store.ensure('Products',PRODUCT_HEADERS)
        store.ensure('MessageData',MESSAGE_HEADERS)
        stamp=(datetime.now(timezone.utc)-timedelta(days=1)).isoformat()
        content='Beans 12 pack\nقیمت فروش ما: ۱۰۵/۰۰۰ تومان'
        store.append('MessageData',[_row_from_map(MESSAGE_HEADERS,{
            'ID':'recent-message','Channel':'telegram','Channel Username':'@supplier',
            'Content':content,'Timestamp':stamp,'URL':'https://t.me/supplier/99',
            'Status':'product','Batch ID':'recent-batch','AI Status':'ai_processed'})])
        # A previous review-stage extraction must be eligible under a new policy.
        store.append('Offers',[_row_from_map(OFFER_HEADERS,{
            'Offer ID':'reextract:old:message','Product Name':'Beans 12 pack',
            'Channel':'telegram','Channel Username':'@supplier','Status':'needs_review',
            'Review Reason':'low confidence','Message ID':'recent-message',
            'Batch ID':'reextract:old','Message Timestamp':stamp,
            'Source Post URL':'https://t.me/supplier/99','Original Message':content,
        })])
        env={'PIPELINE_DB_PATH':self.db_path,'REFERENCE_STORE_PATH':self.reference_path,
            'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':self.secret,
            'GOOGLE_API_KEY':'synthetic-gemini-key'}
        auth={'Authorization':'Bearer '+owner_token(self.secret,
            scope='owner:/pipeline/ai-confirmations')}
        with patch.dict(os.environ,env),patch.object(server,'USE_SHEETS',True), \
                patch.object(server,'handoff_queue',return_value=queue), \
                patch.object(server,'build_store',return_value=store), \
                patch.object(server.threading,'Thread') as worker:
            client=server.app.test_client()
            preview=client.get('/pipeline/ai-confirmations?reextract=preview',headers=auth)
            self.assertEqual(preview.status_code,200,preview.get_json())
            details=preview.get_json()['recent_policy']
            self.assertEqual(details['eligible_count'],1)
            self.assertEqual(details['policy_version'],extraction_policy_version())
            self.assertEqual(details['source_posts'][0]['source_post_url'],'https://t.me/supplier/99')
            run_id='9'*32
            request={'action':'start_recent_policy_reextraction','run_id':run_id,
                'expected_count':1,'expected_digest':details['digest'],
                'policy_version':details['policy_version']}
            stale_policy=client.post('/pipeline/ai-confirmations',headers=auth,json={
                **request,'policy_version':'gemini-0000000000000000'})
            self.assertEqual(stale_policy.status_code,409,stale_policy.get_json())
            stale_preview=client.post('/pipeline/ai-confirmations',headers=auth,json={
                **request,'expected_digest':'0'*64})
            self.assertEqual(stale_preview.status_code,409,stale_preview.get_json())
            started=client.post('/pipeline/ai-confirmations',headers=auth,json=request)

        self.assertEqual(started.status_code,202,started.get_json())
        self.assertEqual(started.get_json()['queued'],1)
        self.assertEqual(started.get_json()['scope'],'recent_policy')
        worker.assert_called_once()
        job=queue.reextraction_status(run_id)
        self.assertEqual(job['scope'],'recent_policy')
        self.assertEqual(job['policy_version'],details['policy_version'])
        with queue.connect() as db:
            payload=json.loads(db.execute('SELECT payload FROM jobs WHERE id LIKE ?',
                ('reextract:'+run_id+':%',)).fetchone()['payload'])
        self.assertEqual(payload['source_key'],details['source_posts'][0]['source_key'])
        self.assertEqual(payload['policy_version'],details['policy_version'])

    def test_open_ai_case_can_be_linked_to_reference_before_price_confirmation(self):
        import app as server
        from catalog.ai_confirmations import AIConfirmationLedger, review_cases
        from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS
        from catalog.sync import MemoryStore, _row_from_map
        from reference_import.item_reviews import ItemReviewLedger, offer_attributes, product_signature, review_case_id
        from reference_import.snapshots import ReferenceStore
        from reference_fixtures import staged_candidate, woocommerce_product

        queue = Handoffs(self.db_path)
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        source = {'Offer ID':'reextract:run-1:message-1:0', 'Product Name':'Beans 12 pack',
            'Channel':'telegram', 'Channel Username':'@supplier', 'Sale Price':0,
            'Detected Price':120000, 'Actual Price':160000, 'Package Price':'',
            'Price Type':'needs_review', 'Currency':'IRT', 'Packaging':'12 pack',
            'Package Count':12, 'Min Qty':1, 'Status':'needs_review',
            'Review Reason':'Historical price needs current verification', 'Stock Status':'Unknown',
            'Message ID':'message-1', 'Batch ID':'reextract:run-1',
            'Message Timestamp':'2026-10-20T10:00:00Z',
            'Source Post URL':'https://t.me/supplier/1',
            'Original Message':'Beans 12 pack, supplier price 120000 toman'}
        store.append('Offers', [_row_from_map(OFFER_HEADERS, source)])
        references = ReferenceStore(self.reference_path, owner_ids=['wp:7'])
        candidate = staged_candidate(products=[woocommerce_product(id=991, sku='REF-BEANS-12',
            name='Beans 12 pack', prices={'price':'150000','regular_price':'160000',
                'sale_price':'150000','currency_code':'IRT','currency_minor_unit':0})])
        snapshot_id = references.stage(candidate)
        references.review(snapshot_id, actor_id='wp:7', note='Synthetic test snapshot')
        references.activate(snapshot_id, actor_id='wp:7', expected_active_id=None)
        env = {'PIPELINE_DB_PATH':self.db_path, 'REFERENCE_STORE_PATH':self.reference_path,
            'REFERENCE_OWNER_IDS':'wp:7', 'REFERENCE_BRIDGE_TOKEN':self.secret}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store):
            client = server.app.test_client()
            ai_headers = {'Authorization':'Bearer '+owner_token(self.secret,
                scope='owner:/pipeline/ai-confirmations')}
            reference_headers = {'Authorization':'Bearer '+owner_token(self.secret,
                scope='owner:/reference/item-reviews')}
            case = next(item for item in review_cases(store, AIConfirmationLedger(self.db_path))
                if item['offer_id'] == source['Offer ID'])
            listing = client.get('/pipeline/ai-confirmations', headers=ai_headers)
            self.assertEqual(listing.status_code, 200, listing.get_json())
            visible = next(item for item in listing.get_json()['cases']
                if item['offer_id'] == source['Offer ID'])
            self.assertEqual(visible['reference_snapshot_id'], snapshot_id)
            self.assertEqual(visible['reference_candidates'][0]['reference_id'], 'REF-BEANS-12')
            self.assertNotIn('reference_assignment', visible)

            payload = {'action':'assign_ai_reference', 'case_id':case['case_id'],
                'offer_id':case['offer_id'], 'signature':case['signature'],
                'snapshot_id':snapshot_id, 'reference_id':'REF-BEANS-12',
                'price_role':'unit', 'expected_decision_id':None}
            assigned = client.post('/reference/item-reviews', json=payload, headers=reference_headers)
            self.assertEqual(assigned.status_code, 200, assigned.get_json())
            receipt = assigned.get_json()
            self.assertEqual(receipt['projection_state'], 'awaiting_ai_confirmation')
            self.assertIsNone(receipt['publish_job'])
            self.assertEqual(len(store.read('Products')), 1, 'reference assignment does not project a product')
            self.assertEqual(len(store.read('Offers')), 2, 'reference assignment does not append an approved offer')

            item_ledger = ItemReviewLedger(self.reference_path, owner_ids=['wp:7'])
            mapping = item_ledger.get(review_case_id(source['Offer ID'], snapshot_id))
            self.assertEqual(mapping['reference_id'], 'REF-BEANS-12')
            self.assertEqual(mapping['signature'], product_signature(offer_attributes(source)))
            refreshed = client.get('/pipeline/ai-confirmations', headers=ai_headers).get_json()
            assigned_case = next(item for item in refreshed['cases']
                if item['offer_id'] == source['Offer ID'])
            self.assertEqual(assigned_case['reference_assignment']['decision_id'], receipt['decision_id'])

            confirm_headers = {'Authorization':'Bearer '+owner_token(self.secret,
                scope='owner:/pipeline/ai-confirmations')}
            confirmed = client.post('/pipeline/ai-confirmations', json={
                'case_id':case['case_id'], 'offer_id':case['offer_id'], 'signature':case['signature'],
                'choice':'confirm', 'fields':{'price_role':'unit','sale_price':120000,
                    'actual_price':160000,'package_count':12,'min_qty':1,'stock_status':'Unknown'},
                'note':'Checked current price source', 'expected_decision_id':None,
                'source_current_verified':True}, headers=confirm_headers)
            self.assertEqual(confirmed.status_code, 200, confirmed.get_json())
            headers, product_row = store.read('Products')[0], store.read('Products')[1]
            self.assertEqual(product_row[headers.index('Reference Match ID')], 'REF-BEANS-12')
            self.assertEqual(product_row[headers.index('Reference Decision ID')], receipt['decision_id'])
            with queue.connect() as db:
                jobs = db.execute("SELECT COUNT(*) FROM jobs WHERE id LIKE 'publish:%'").fetchone()[0]
            self.assertEqual(jobs, 1, 'only final AI confirmation queues publication')

    def test_reextraction_start_dispatches_jobs_without_waiting_for_a_new_webhook(self):
        import app as server
        from catalog.extract import MESSAGE_HEADERS, OFFER_HEADERS, PRODUCT_HEADERS
        from catalog.sync import MemoryStore, _row_from_map
        queue = Handoffs(self.db_path)
        store = MemoryStore()
        store.ensure('MessageData', MESSAGE_HEADERS)
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        message = {
            'ID': 'message-1', 'Channel': 'telegram', 'Channel Username': '@supplier',
            'Content': 'چای سبز\nقیمت تکی: ۴۰٬۰۰۰ تومان', 'Batch ID': 'batch-1',
            'AI Status': 'needs_confirmation',
        }
        store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, message)])
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_STORE_PATH': self.reference_path,
               'REFERENCE_OWNER_IDS': 'wp:7', 'REFERENCE_BRIDGE_TOKEN': self.secret,
               'GOOGLE_API_KEY': 'test-api-key'}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store), \
                patch.object(server.threading, 'Thread') as worker:
            response = server.app.test_client().post('/pipeline/ai-confirmations', json={
                'action': 'start_reextraction', 'scope': 'pending', 'expected_count': 1,
            }, headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/ai-confirmations')})
        self.assertEqual(response.status_code, 202, response.get_json())
        self.assertEqual(response.get_json()['queued'], 1)
        worker.assert_called_once()
        self.assertEqual(worker.call_args.kwargs.get('target'), server._drain_reextraction_run)
        worker.return_value.start.assert_called_once()

    def test_open_case_reextraction_queues_each_original_supplier_post_once(self):
        import app as server
        from catalog.ai_confirmations import AIConfirmationLedger
        from catalog.extract import MESSAGE_HEADERS, OFFER_HEADERS, PRODUCT_HEADERS
        from catalog.sync import MemoryStore, _row_from_map
        queue = Handoffs(self.db_path)
        store = MemoryStore()
        store.ensure('MessageData', MESSAGE_HEADERS)
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        message = {
            'ID':'message-1','Channel':'telegram','Channel Username':'@supplier',
            'Content':'Juice mango and peach, price 100000 toman',
            'Timestamp':'2026-11-01T10:00:00Z','URL':'https://t.me/supplier/1',
            'Batch ID':'batch-1','AI Status':'needs_confirmation',
        }
        base_offer = {
            'Product Name':'Juice mango','Channel':'telegram','Channel Username':'@supplier',
            'Sale Price':100000,'Actual Price':150000,'Price Type':'unit','Currency':'IRT',
            'Status':'needs_review','Review Reason':'low confidence','Stock Status':'Unknown',
            'Message ID':'message-1','Batch ID':'batch-1',
            'Message Timestamp':'2026-11-01T10:00:00Z','Source Post URL':'https://t.me/supplier/1',
            'Original Message':message['Content'],
        }
        store.append('MessageData', [_row_from_map(MESSAGE_HEADERS, message)])
        store.append('Offers', [
            _row_from_map(OFFER_HEADERS, {**base_offer,'Offer ID':'batch-1:message-1:0'}),
            _row_from_map(OFFER_HEADERS, {**base_offer,'Offer ID':'batch-1:message-1:1',
                'Product Name':'Juice peach','Variation Type':'Peach'}),
        ])
        env = {'PIPELINE_DB_PATH':self.db_path,'REFERENCE_STORE_PATH':self.reference_path,
               'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_BRIDGE_TOKEN':self.secret,
               'GOOGLE_API_KEY':'test-api-key'}
        headers = {'Authorization':'Bearer '+owner_token(self.secret,
            scope='owner:/pipeline/ai-confirmations')}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store), \
                patch.object(server.threading, 'Thread') as worker:
            client=server.app.test_client()
            preview=client.get('/pipeline/ai-confirmations?reextract=preview',headers=headers)
            self.assertEqual(preview.status_code,200,preview.get_json())
            open_cases=preview.get_json()['open_cases']
            self.assertEqual(open_cases['case_count'],2)
            self.assertEqual(open_cases['message_count'],1)
            response=client.post('/pipeline/ai-confirmations',json={
                'action':'start_open_case_reextraction','run_id':'c'*32,
                'expected_case_count':2,'expected_message_count':1,
                'expected_digest':open_cases['digest'],
            },headers=headers)
        self.assertEqual(response.status_code,202,response.get_json())
        self.assertEqual(response.get_json()['queued'],1)
        status=queue.reextraction_status('c'*32)
        self.assertEqual(status['total'],1)
        self.assertEqual(status['scope'],'open_cases')
        self.assertEqual(status['case_count'],2)
        self.assertEqual(status['digest'],open_cases['digest'])
        with queue.connect() as db:
            payload=json.loads(db.execute('SELECT payload FROM jobs WHERE id LIKE ?',
                ('reextract:'+'c'*32+':%',)).fetchone()['payload'])
        self.assertIn(payload['source_offer_id'],
            {'batch-1:message-1:0','batch-1:message-1:1'})
        self.assertEqual(payload['case_count'],2)
        worker.assert_called_once()
        worker.return_value.start.assert_called_once()

    def test_item_review_empty_reference_state_has_complete_response_shape(self):
        import app as server
        queue = Handoffs(self.db_path)
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_STORE_PATH': self.reference_path,
               'REFERENCE_OWNER_IDS': 'wp:7', 'REFERENCE_BRIDGE_TOKEN': self.secret}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue):
            client = server.app.test_client()
            response = client.get('/reference/item-reviews', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/reference/item-reviews')})
        self.assertEqual(response.status_code, 200, response.get_json())
        self.assertEqual(response.get_json()['cases'], [])
        self.assertEqual(response.get_json()['recent_decisions'], [])
        self.assertFalse(response.get_json()['reference_state']['allow_automatic'])

    def test_publication_hold_and_supplier_freshness_accept_only_their_route_scopes(self):
        import app as server
        from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS
        from catalog.sync import MemoryStore
        queue = Handoffs(self.db_path)
        queue.hold_publication('batch-review', 'batch_unverified')
        store = MemoryStore()
        store.ensure('Offers', OFFER_HEADERS)
        store.ensure('Products', PRODUCT_HEADERS)
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': self.secret}
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue), \
                patch.object(server, 'build_store', return_value=store):
            client = server.app.test_client()
            hold = client.get('/pipeline/publication-holds', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/publication-holds')})
            self.assertEqual(hold.status_code, 200, hold.get_json())
            self.assertEqual(hold.get_json()['holds'][0]['hold_id'], 'batch-review')
            wrong = client.get('/pipeline/publication-holds', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/supplier-price-evidence')})
            self.assertEqual(wrong.status_code, 401)
            freshness = client.get('/pipeline/supplier-price-evidence', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/supplier-price-evidence')})
            self.assertEqual(freshness.status_code, 200, freshness.get_json())
            self.assertEqual(freshness.get_json()['offers'], [])

    def test_sheet_conflict_screen_accepts_only_its_browser_scope(self):
        import app as server
        queue = Handoffs(self.db_path)
        queue.record_sheet_conflict({'conflict_id': 'sheet:review-1', 'product_id': 'product-1',
            'product_name': 'Synthetic product', 'channel': '@supplier', 'field': 'Sale Price',
            'source_revision': 'revision-1', 'base': 1, 'server': 2, 'sheet': 3})
        env = {'PIPELINE_DB_PATH': self.db_path, 'REFERENCE_OWNER_IDS': 'wp:7',
               'REFERENCE_BRIDGE_TOKEN': self.secret}
        origin = 'https://www.molavisale.ir'
        with patch.dict(os.environ, env), patch.object(server, 'USE_SHEETS', True), \
                patch.object(server, 'handoff_queue', return_value=queue):
            client = server.app.test_client()
            wrong = client.get('/pipeline/sheet-conflicts', headers={'Authorization': 'Bearer ' + owner_token(
                self.secret, scope='owner:/pipeline/operations-dashboard')})
            self.assertEqual(wrong.status_code, 401)
            accepted = client.get('/pipeline/sheet-conflicts', headers={
                'Authorization': 'Bearer ' + owner_token(self.secret, scope='owner:/pipeline/sheet-conflicts'),
                'Origin': origin})
        self.assertEqual(accepted.status_code, 200, accepted.get_json())
        self.assertEqual(accepted.headers.get('Access-Control-Allow-Origin'), origin)
        self.assertEqual(accepted.get_json()['conflicts'][0]['conflict_id'], 'sheet:review-1')
        self.assertFalse(accepted.get_json()['conflicts'][0]['server_owned'])
        self.assertFalse(accepted.get_json()['conflicts'][0]['sheet_preserved'])


if __name__ == '__main__':
    unittest.main()
