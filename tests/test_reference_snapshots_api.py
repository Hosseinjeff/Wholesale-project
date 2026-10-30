import json
import base64
import hmac
import os
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

from catalog.sync import MemoryStore
from reference_import.importer import ImportFailure, SOURCE_URL, HEADERS, parse_rows
from reference_import.projection import project_reference_prices
from reference_import.snapshots import ReferenceStore
from reference_import.refresh_scheduler import ReferenceRefreshScheduler


def staged_candidate():
    timestamp=datetime.now(timezone.utc).isoformat()
    rows=parse_rows(json.dumps([{'raw_name':'چای نمونه','clean_name':'چای نمونه',
        'package_price':1200000,'package_count':12,'unit_price':100000,
        'category':'Tea','product_code':'TEA-12','currency':'TOMAN'}],ensure_ascii=False),timestamp)
    return {'schema_version':1,'status':'staged','requires_review':True,'source_url':SOURCE_URL,
        'pdf_sha256':'b'*64,'model':'gemini-3.1-flash-lite','extracted_at':timestamp,
        'row_count':len(rows),'headers':list(HEADERS),'rows':rows}


class ReferenceSnapshotRouteTests(unittest.TestCase):
    def test_short_lived_browser_token_is_scoped_to_snapshots_and_cors_origin(self):
        from importlib import import_module
        server=import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path=str(Path(tmp)/'reference.sqlite')
            store=ReferenceStore(path,owner_ids=['wp:7'])
            snapshot_id=store.stage(staged_candidate())
            secret='browser-test-bridge-secret-'*2
            now=int(datetime.now(timezone.utc).timestamp())
            claims={'iss':'molavi-wordpress','aud':'reference-snapshots','scope':'reference:snapshots',
                'actor':'wp:7','iat':now,'exp':now+600}
            encoded=base64.urlsafe_b64encode(json.dumps(claims,separators=(',',':')).encode()).decode().rstrip('=')
            signature=base64.urlsafe_b64encode(hmac.new(secret.encode(),encoded.encode(),'sha256').digest()).decode().rstrip('=')
            authorization='Bearer '+encoded+'.'+signature
            with patch.dict(os.environ,{'REFERENCE_STORE_PATH':path,'REFERENCE_BRIDGE_TOKEN':secret,
                    'REFERENCE_OWNER_IDS':'wp:7'}),patch.object(server,'ensure_telegram_webhook',return_value=None):
                client=server.app.test_client()
                origin='https://www.molavisale.ir'
                headers={'Authorization':authorization,'Origin':origin}
                preflight=client.options('/reference/snapshots',headers={
                    'Origin':origin,'Access-Control-Request-Method':'POST',
                    'Access-Control-Request-Headers':'authorization,content-type'})
                self.assertEqual(preflight.status_code,204)
                self.assertEqual(preflight.headers.get('Access-Control-Allow-Origin'),origin)
                self.assertIn('authorization',preflight.headers.get('Access-Control-Allow-Headers','').lower())
                listing=client.get('/reference/snapshots',headers=headers)
                self.assertEqual(listing.status_code,200,listing.get_json())
                self.assertEqual(listing.get_json()['snapshots'][0]['snapshot_id'],snapshot_id)
                self.assertEqual(listing.headers.get('Access-Control-Allow-Origin'),origin)
                review=client.post('/reference/snapshots',json={
                    'action':'review','snapshot_id':snapshot_id,'note':'Reviewed from owner browser',
                    'expected_active_id':None},headers=headers)
                self.assertEqual(review.status_code,200,review.get_json())
                self.assertEqual(review.get_json()['action'],'review')
                denied_origin=client.get('/reference/snapshots',headers={
                    'Authorization':authorization,'Origin':'https://attacker.example'})
                self.assertEqual(denied_origin.status_code,200)
                self.assertIsNone(denied_origin.headers.get('Access-Control-Allow-Origin'))
                expired_claims={**claims,'iat':now-901,'exp':now-1}
                expired_payload=base64.urlsafe_b64encode(
                    json.dumps(expired_claims,separators=(',',':')).encode()).decode().rstrip('=')
                expired_sig=base64.urlsafe_b64encode(
                    hmac.new(secret.encode(),expired_payload.encode(),'sha256').digest()).decode().rstrip('=')
                expired=client.get('/reference/snapshots',headers={
                    'Authorization':'Bearer '+expired_payload+'.'+expired_sig,'Origin':origin})
                self.assertEqual(expired.status_code,401)

    def test_owner_can_retry_failed_refresh_without_activating_or_replaying_stale_state(self):
        from importlib import import_module
        server=import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path=str(Path(tmp)/'reference.sqlite')
            scheduler=ReferenceRefreshScheduler(path,owner_ids=['wp:7'])
            now=datetime.now(timezone.utc)
            failure=scheduler.run_if_due(lambda:(_ for _ in ()).throw(
                ImportFailure('private response detail',code='gemini_http_404')),
                now=now-timedelta(minutes=2))
            self.assertEqual(failure['error_code'],'gemini_http_404')
            payload={'action':'refresh','expected_last_attempt_at':scheduler.state()['last_attempt_at']}
            with patch.dict(os.environ,{'REFERENCE_STORE_PATH':path,'REFERENCE_BRIDGE_TOKEN':'z'*40,
                    'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_REFRESH_INTERVAL_HOURS':'24'}), \
                    patch.object(ReferenceRefreshScheduler,'_refresh_pdf',return_value=staged_candidate()), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                client=server.app.test_client()
                headers={'X-Molavi-Reference-Token':'z'*40,'X-Molavi-Actor':'wp:7'}
                result=client.post('/reference/snapshots',json=payload,headers=headers)
                self.assertEqual(result.status_code,200,result.get_json())
                self.assertEqual(result.get_json()['state'],'staged')
                self.assertEqual(result.get_json()['row_count'],1)
                self.assertIsNone(ReferenceStore(path,owner_ids=['wp:7']).active_snapshot())
                conflict=client.post('/reference/snapshots',json=payload,headers=headers)
                self.assertEqual(conflict.status_code,409)

    def test_projection_replaces_shorter_snapshot_and_clears_old_managed_rows(self):
        rows=parse_rows(json.dumps([{'raw_name':'New reference','clean_name':'New reference',
            'package_price':100000,'package_count':1,'unit_price':100000,
            'category':'Food','product_code':'NEW','currency':'TOMAN'}]),
            datetime.now(timezone.utc).isoformat())
        snapshot={'status':'staged','snapshot_id':'c'*64,'headers':list(HEADERS),'rows':rows}
        sheets=MemoryStore();sheets.ensure('ReferencePrices',list(HEADERS))
        sheets.append('ReferencePrices',[[f'old-{i}' for _ in HEADERS] for i in range(3)])
        receipt=project_reference_prices(sheets,snapshot)
        self.assertTrue(receipt['verified'])
        table=sheets.read('ReferencePrices')
        self.assertEqual(len(table),4)
        self.assertEqual(table[1][0],'New reference')
        self.assertEqual(table[2],['']*len(HEADERS))
        self.assertEqual(table[3],['']*len(HEADERS))

    def test_dashboard_reviews_activates_and_projects_only_after_owner_action(self):
        from importlib import import_module
        server=import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path=str(Path(tmp)/'reference.sqlite')
            gate=ReferenceStore(path,owner_ids=['wp:7'])
            snapshot_id=gate.stage(staged_candidate())
            sheets=MemoryStore()
            old_sheets=server.USE_SHEETS
            with patch.dict(os.environ,{'REFERENCE_STORE_PATH':path,'REFERENCE_BRIDGE_TOKEN':'z'*40,
                    'REFERENCE_OWNER_IDS':'wp:7','REFERENCE_REFRESH_INTERVAL_HOURS':'24'}), \
                    patch.object(server,'USE_SHEETS',True),patch.object(server,'build_store',return_value=sheets), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                client=server.app.test_client()
                headers={'X-Molavi-Reference-Token':'z'*40,'X-Molavi-Actor':'wp:7'}
                self.assertIsNone(gate.active_snapshot())
                listing=client.get('/reference/snapshots',headers=headers)
                self.assertEqual(listing.status_code,200)
                self.assertEqual(listing.get_json()['snapshots'][0]['snapshot_id'],snapshot_id)
                self.assertEqual(listing.get_json()['snapshots'][0]['preview'][0]['product_name'],'چای نمونه')
                review={'action':'review','snapshot_id':snapshot_id,'note':'Coverage and prices checked',
                    'expected_active_id':None}
                self.assertEqual(client.post('/reference/snapshots',json=review,headers=headers).status_code,200)
                self.assertIsNone(gate.active_snapshot())
                activate={'action':'activate','snapshot_id':snapshot_id,'expected_active_id':None}
                self.assertEqual(client.post('/reference/snapshots',json=activate,headers=headers).status_code,200)
                self.assertEqual(gate.active_snapshot()['snapshot_id'],snapshot_id)
                table=sheets.read('ReferencePrices')
                self.assertEqual(table[0],list(HEADERS))
                self.assertEqual(len(table),2)
                self.assertEqual(table[1][0],'چای نمونه')
                retry={**activate,'expected_active_id':snapshot_id}
                receipt=client.post('/reference/snapshots',json=retry,headers=headers)
                self.assertEqual(receipt.status_code,200,receipt.get_json())
                self.assertEqual(receipt.get_json()['projection_state'],'written')
                self.assertEqual(client.get('/reference/snapshots',headers={**headers,
                    'X-Molavi-Actor':'wp:8'}).status_code,403)
                self.assertEqual(client.get('/reference/snapshots',headers={**headers,
                    'X-Molavi-Reference-Token':'bad'}).status_code,401)
            server.USE_SHEETS=old_sheets

    def test_activation_conflict_and_unreviewed_candidates_are_rejected(self):
        from importlib import import_module
        server=import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path=str(Path(tmp)/'reference.sqlite');gate=ReferenceStore(path,owner_ids=['wp:7'])
            snapshot_id=gate.stage(staged_candidate())
            with patch.dict(os.environ,{'REFERENCE_STORE_PATH':path,'REFERENCE_BRIDGE_TOKEN':'z'*40,
                    'REFERENCE_OWNER_IDS':'wp:7'}),patch.object(server,'USE_SHEETS',True), \
                    patch.object(server,'build_store',return_value=MemoryStore()), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                client=server.app.test_client();headers={'X-Molavi-Reference-Token':'z'*40,'X-Molavi-Actor':'wp:7'}
                unreviewed=client.post('/reference/snapshots',json={'action':'activate',
                    'snapshot_id':snapshot_id,'expected_active_id':None},headers=headers)
                self.assertEqual(unreviewed.status_code,409)
                bad_review=client.post('/reference/snapshots',json={'action':'review',
                    'snapshot_id':snapshot_id,'note':'Review','expected_active_id':'a'*64},headers=headers)
                self.assertEqual(bad_review.status_code,409)

    def test_failed_referenceprices_projection_can_be_retried_for_active_snapshot(self):
        from importlib import import_module
        server=import_module('app')
        with tempfile.TemporaryDirectory() as tmp:
            path=str(Path(tmp)/'reference.sqlite');gate=ReferenceStore(path,owner_ids=['wp:7'])
            snapshot_id=gate.stage(staged_candidate())
            gate.review(snapshot_id,actor_id='wp:7',note='Checked')
            class FailingStore(MemoryStore):
                def write_rows(self,name,start_row,rows,value_input='RAW'):
                    if name=='ReferencePrices':raise RuntimeError('synthetic projection failure')
                    return super().write_rows(name,start_row,rows,value_input)
            with patch.dict(os.environ,{'REFERENCE_STORE_PATH':path,'REFERENCE_BRIDGE_TOKEN':'z'*40,
                    'REFERENCE_OWNER_IDS':'wp:7'}),patch.object(server,'USE_SHEETS',True), \
                    patch.object(server,'ensure_telegram_webhook',return_value=None):
                client=server.app.test_client();headers={'X-Molavi-Reference-Token':'z'*40,'X-Molavi-Actor':'wp:7'}
                payload={'action':'activate','snapshot_id':snapshot_id,'expected_active_id':None}
                with patch.object(server,'build_store',return_value=FailingStore()):
                    failed=client.post('/reference/snapshots',json=payload,headers=headers)
                self.assertEqual(failed.status_code,503)
                self.assertEqual(gate.active_snapshot()['snapshot_id'],snapshot_id)
                with patch.object(server,'build_store',return_value=MemoryStore()):
                    retry=client.post('/reference/snapshots',json={**payload,
                        'expected_active_id':snapshot_id},headers=headers)
                self.assertEqual(retry.status_code,200,retry.get_json())
                self.assertEqual(retry.get_json()['projection_state'],'written')


if __name__=='__main__':
    unittest.main()
