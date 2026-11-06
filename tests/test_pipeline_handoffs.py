"""Synthetic supplier → pricing/publication → order replay; no external adapters."""
import os
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from catalog.durable import Handoffs, JobConflict, compact_pipeline_payloads, pipeline_payload_retention_days
from catalog.sync import MemoryStore, finalize_batch, shop_catalog
from catalog.pricing import resolve_quote
from catalog.orders import upsert_shop_order

TEXT=('کنسرو ماهی ۱۸۰ گرمی تاپ\nقیمت هر باکس: ۱,۲۵۰,۰۰۰ تومان\n'
      'دونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی\nموجود ✅')

class HandoffTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.path=str(Path(self.tmp.name)/'pipeline.sqlite');self.queue=Handoffs(self.path)
        from catalog.extract import extract_products
        extractor=patch('catalog.sync._extract_supplier_products',
            side_effect=lambda content,channel:extract_products(content,channel))
        extractor.start();self.addCleanup(extractor.stop)
        self.store=MemoryStore()
        self.message=dict(id='post-1',channel='telegram',channel_username='@bonakdarjavan',content=TEXT,chat_id=7,
            timestamp='2026-11-04T10:00:00Z',source_created_at='2026-10-01T10:00:00Z',
            source_update_at='2026-11-04T10:00:00Z',source_timestamp_basis='supplier_channel_update')
        self.adapters=dict(finalize=lambda p:finalize_batch(self.store,p),
            publish=lambda:dict(ok=True,pushed=True,images_left=0),
            order_write=lambda p:upsert_shop_order(self.store,p['order']))

    def ready_now(self,job_id):
        with self.queue.connect() as db:db.execute('UPDATE jobs SET available=0 WHERE id=?',(job_id,))

    def test_supplier_to_quote_and_durable_order_replay(self):
        first=self.queue.receive(self.message)
        self.assertEqual(self.queue.receive(self.message),first)
        self.queue=Handoffs(self.path) # restart before any extraction
        batch=self.queue.batch()
        self.queue.run_one(**self.adapters)
        self.assertEqual(self.queue.get(batch)['state'],'done')
        self.assertEqual(len(self.store.read('MessageData')),2)
        self.queue.run_one(**self.adapters) # publication is a separate job
        catalog=shop_catalog(self.store)
        self.assertTrue(catalog['products'])
        product=catalog['products'][0]
        quote=resolve_quote(catalog['products'],[{'product_id':product['product_id'],'qty':6}],2000000)
        self.assertGreater(quote['pay_total'],0)
        order=dict(id='sample-order-1',name='Synthetic customer',total=quote['pay_total'],lines=[{**line,'unit':line['unit_price'],'total':line['unit_price']*line['qty']} for line in quote['lines']])
        receipt=self.queue.put('order','sample-order-1',{'order':order})
        self.queue.run_one(**self.adapters)
        self.assertTrue(self.queue.get(receipt)['result']['written'])
        self.assertEqual(len(self.store.read('Orders')),2)
        self.assertEqual(self.queue.put('order','sample-order-1',{'order':order}),receipt)
        self.assertFalse(self.queue.run_one(**self.adapters))
        self.assertEqual(len(self.store.read('Orders')),2)

    def test_photo_caption_group_survives_restart_before_batch(self):
        caption={**self.message,'media_group_id':'album'}
        photo={**self.message,'id':'post-2','content':'','media_group_id':'album','image_file_id':'AgACAgQAAxkBAAIB'}
        self.queue.receive(caption);self.queue.receive(photo)
        queue=Handoffs(self.path);batch=queue.batch();queue.run_one(**self.adapters)
        self.assertEqual(queue.get(batch)['state'],'done')
        rows=self.store.read('Products');self.assertIn('AgACAgQAAxkBAAIB',rows[1])

    def test_edited_channel_post_with_same_text_uses_new_source_update_revision(self):
        first={**self.message,'id':'channel-post-9','url':'https://t.me/supplier/9',
               'source_update_at':'1700000000'}
        revised={**first,'source_update_at':'1700003600'}
        self.assertNotEqual(self.queue.receive(first),self.queue.receive(revised))

    def test_publication_failure_does_not_repeat_extraction(self):
        self.queue.receive(self.message);batch=self.queue.batch()
        self.queue.run_one(**self.adapters)
        publish='publish:'+batch
        bad={**self.adapters,'publish':lambda:dict(ok=True,pushed=True,images_left=1)}
        self.queue.run_one(**bad)
        self.assertEqual(self.queue.get(publish)['state'],'queued')
        self.assertEqual(self.queue.get(batch)['attempts'],1)
        self.ready_now(publish);self.queue.run_one(**self.adapters)
        self.assertEqual(self.queue.get(publish)['state'],'done')
        self.assertEqual(len(self.store.read('MessageData')),2)

    def test_expired_lease_recovers_and_old_worker_cannot_ack(self):
        key=self.queue.put('order','1',{'order':{'id':'1'}});old=self.queue.claim()
        self.assertIsNone(self.queue.claim())
        with self.queue.connect() as db:db.execute('UPDATE jobs SET expires=0 WHERE id=?',(key,))
        new=self.queue.claim()
        with self.assertRaises(JobConflict):self.queue.finish(old,{})
        self.queue.finish(new,{'written':True})

    def test_reference_waiting_batch_stays_parked_until_owner_gate_clears(self):
        self.queue.receive(self.message);batch=self.queue.batch()
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='waiting_reference',error='reference_decision_required' WHERE id=?",(batch,))
        self.assertIsNone(self.queue.claim())
        self.assertEqual(self.queue.wake_reference_waiters(),1)
        claimed=self.queue.claim(batch)
        self.assertIsNotNone(claimed)
        self.assertEqual(self.queue.get(batch)['state'],'running')
        self.assertEqual(claimed['attempts'],1)

    def test_waiting_publication_is_parked_until_last_hold_clears(self):
        self.queue.hold_publication('batch-blocked','batch_unverified')
        publish=self.queue.put('publish','blocked',{'batch_id':'batch-blocked'})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='waiting_publication' WHERE id=?",(publish,))
        self.assertIsNone(self.queue.claim())
        self.queue.resolve_publication_hold('batch-blocked',expected_reason='batch_unverified',
            actor_id='wp:7',note='Owner verified the batch.',resume_batch=False)
        self.assertEqual(self.queue.get(publish)['state'],'queued')

    def test_stable_batch_retry_does_not_duplicate_raw_messages_or_offers(self):
        self.queue.receive(self.message);batch=self.queue.batch();job=self.queue.claim(batch)
        payload=job['payload'];finalize_batch(self.store,payload)
        first={k:len(self.store.read(k)) for k in ('MessageData','Offers','Products')}
        finalize_batch(self.store,payload)
        self.assertEqual(first,{k:len(self.store.read(k)) for k in first})

    def test_completed_old_payloads_are_compacted_but_audit_identifiers_remain(self):
        source={**self.message,'content':TEXT,'timestamp':'2026-10-01T00:00:00Z'}
        self.queue.receive(source)
        batch=self.queue.batch()
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='done',updated_at=1000 WHERE id=?",(batch,))
        result=self.queue.compact_completed_payloads(now=1000+90*86400+1)
        self.assertEqual(result,{'jobs_compacted':1,'inbox_compacted':1})
        with self.queue.connect() as db:
            job=json.loads(db.execute('SELECT payload FROM jobs WHERE id=?',(batch,)).fetchone()['payload'])
            inbox=json.loads(db.execute('SELECT payload FROM inbox WHERE batch_id=?',(batch,)).fetchone()['payload'])
        self.assertTrue(job['compacted'])
        self.assertNotIn('content',job['messages'][0])
        self.assertEqual(job['messages'][0]['source_created_at'],'2026-10-01T10:00:00Z')
        self.assertEqual(job['messages'][0]['source_update_at'],'2026-11-04T10:00:00Z')
        self.assertEqual(job['messages'][0]['source_timestamp_basis'],'supplier_channel_update')
        self.assertEqual(inbox['content_sha256'],__import__('hashlib').sha256(TEXT.encode()).hexdigest())
        self.assertEqual(inbox['timestamp'],'2026-10-01T00:00:00Z')

    def test_payload_retention_defaults_to_thirty_days_and_is_bounded(self):
        with patch.dict(os.environ, {'PIPELINE_PAYLOAD_RETENTION_DAYS':''}):
            self.assertEqual(pipeline_payload_retention_days(), 30)
        self.assertEqual(pipeline_payload_retention_days('7'), 30)
        self.assertEqual(pipeline_payload_retention_days('45'), 45)
        self.assertEqual(pipeline_payload_retention_days('500'), 365)
        self.assertEqual(pipeline_payload_retention_days('invalid'), 30)

    def test_worker_compaction_uses_configured_retention_for_done_batches(self):
        self.queue.receive(self.message)
        batch=self.queue.batch()
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='done',updated_at=1000 WHERE id=?",(batch,))
        with patch.dict(os.environ, {'PIPELINE_PAYLOAD_RETENTION_DAYS':'30'}):
            days,result=compact_pipeline_payloads(self.queue,now=1000+30*86400+1)
        self.assertEqual(days,30)
        self.assertEqual(result,{'jobs_compacted':1,'inbox_compacted':1})

    def test_unallowlisted_notice_jobs_are_cancelled_without_touching_valid_recipients(self):
        trusted=self.queue.put('notice','trusted:telegram',
            {'chat_id':'288165176','channel':'telegram','text':'trusted'})
        stale=self.queue.put('notice','stale:telegram',
            {'chat_id':'-1','channel':'telegram','text':'synthetic'})
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='failed',attempts=8 WHERE id=?",(stale,))
        self.assertEqual(self.queue.cancel_unroutable_notice_jobs(
            {'telegram':{'288165176'},'bale':set()}),1)
        self.assertEqual(self.queue.get(trusted)['state'],'queued')
        cancelled=self.queue.get(stale)
        self.assertEqual(cancelled['state'],'cancelled')
        self.assertEqual(cancelled['error'],'recipient_not_allowlisted')
        self.assertEqual(cancelled['result'],{'delivered':False,'skipped':'recipient_not_allowlisted'})

    def test_skipped_notice_is_cancelled_and_publish_failure_keeps_safe_diagnostic(self):
        notice=self.queue.put('notice','untrusted:telegram',
            {'chat_id':'-1','channel':'telegram','text':'synthetic'})
        self.queue.run_one(**self.adapters,
            notify=lambda payload:{'delivered':False,'skipped':'recipient_not_allowlisted'})
        self.assertEqual(self.queue.get(notice)['state'],'cancelled')

        publish=self.queue.put('publish','diagnostic',{})
        self.queue.run_one(**self.adapters,
            publish_with_payload=lambda payload:{'ok':False,'pushed':False,'status':200,
                'error':'product_hold_failed','row':3,'text':'must not be persisted'})
        failed=self.queue.get(publish)
        self.assertEqual(failed['state'],'queued')
        self.assertEqual(failed['error'],'product_hold_failed')
        self.assertEqual(failed['result'],{'ok':False,'status':200,'error':'product_hold_failed','row':3})

    def test_failed_and_review_batches_are_never_compacted(self):
        self.queue.receive(self.message)
        batch=self.queue.batch()
        with self.queue.connect() as db:
            db.execute("UPDATE jobs SET state='needs_review',updated_at=1000 WHERE id=?",(batch,))
        result=self.queue.compact_completed_payloads(now=1000+365*86400)
        self.assertEqual(result,{'jobs_compacted':0,'inbox_compacted':0})
        with self.queue.connect() as db:
            payload=json.loads(db.execute('SELECT payload FROM inbox WHERE batch_id=?',(batch,)).fetchone()['payload'])
        self.assertEqual(payload['content'],TEXT)

    def test_extraction_rollback_is_held_and_never_published(self):
        self.queue.receive(self.message);batch=self.queue.batch()
        self.queue.run_one(**{**self.adapters,'finalize':lambda p:dict(status='success',extraction_status='error',rollback=True)})
        self.assertEqual(self.queue.get(batch)['state'],'needs_review')
        self.assertIsNone(self.queue.get('publish:'+batch))

    def test_unwritten_order_retries_instead_of_acknowledging_success(self):
        key=self.queue.put('order','1',{'order':{'id':'1'}})
        self.queue.run_one(**{**self.adapters,'order_write':lambda p:dict(written=False)})
        self.assertEqual(self.queue.get(key)['state'],'queued')
        self.ready_now(key);self.queue.run_one(**self.adapters)
        self.assertEqual(self.queue.get(key)['state'],'done')

    def test_failed_notification_retries_independently(self):
        key=self.queue.put('notice','1',{'chat_id':7,'text':'sample'})
        self.queue.run_one(**self.adapters,notify=lambda p:dict(delivered=False))
        self.assertEqual(self.queue.get(key)['state'],'queued')
        self.ready_now(key);self.queue.run_one(**self.adapters,notify=lambda p:dict(delivered=True))
        self.assertEqual(self.queue.get(key)['state'],'done')

    def test_direct_messages_from_different_chats_are_not_duplicates(self):
        a={**self.message,'channel_username':'DirectMessage'}
        b={**a,'chat_id':8}
        self.assertNotEqual(self.queue.receive(a),self.queue.receive(b))

    def test_reforward_of_same_source_post_reuses_receipt_across_destination_ids(self):
        source={**self.message,'url':'https://t.me/example_supplier/15'}
        forwarded_again={**source,'id':'new-destination-id','chat_id':9}
        self.assertEqual(self.queue.receive(source),self.queue.receive(forwarded_again))


class OrderRouteTests(unittest.TestCase):
    def test_paid_order_snapshot_and_product_identity_are_mirrored(self):
        store=MemoryStore()
        result=upsert_shop_order(store,{
            'id':'paid-sample','woo_status':'processing','payment_status':'verified',
            'payment_method':'existing-gateway','transaction_id':'tx-synthetic',
            'paid_at':'2026-10-28T10:00:00+03:30','total':200,
            'lines':[{'product_id':'prod-1','name':'tuna','qty':2,'total':200}],
        })
        self.assertTrue(result['written'])
        orders=store.read('Orders');header=orders[0];row=orders[1]
        self.assertEqual(row[header.index('Payment Status')],'verified')
        self.assertEqual(row[header.index('Woo Status')],'processing')
        self.assertEqual(row[header.index('Transaction ID')],'tx-synthetic')
        lines=store.read('OrderLines')
        self.assertEqual(lines[1][lines[0].index('SKU')],'prod-1')

    def test_order_receipt_is_not_success_without_sheet_write(self):
        import app
        with patch.object(app,'USE_SHEETS',False), patch.object(app,'ensure_telegram_webhook'), patch.dict(os.environ,{'SHOP_NOTIFY_SECRET':'synthetic','PIPELINE_DB_PATH':''}):
            response=app.app.test_client().post('/shop/order',json={'order':{'id':'sample'}},headers={'X-Shop-Token':'synthetic'})
        self.assertEqual(response.status_code,503)
        self.assertFalse(response.get_json()['written'])

    def test_durable_order_route_commits_receipt_and_retries_same_order_once(self):
        import app
        with tempfile.TemporaryDirectory() as directory:
            store=MemoryStore()
            with patch.object(app,'USE_SHEETS',True), patch.object(app,'ensure_telegram_webhook'), patch.object(app,'build_store',return_value=store), patch.dict(os.environ,{'SHOP_NOTIFY_SECRET':'synthetic','PIPELINE_DB_PATH':str(Path(directory)/'pipeline.sqlite')}):
                client=app.app.test_client()
                args=dict(json={'order':{'id':'sample','lines':[{'name':'sample','qty':1,'total':10}]}},headers={'X-Shop-Token':'synthetic'})
                first=client.post('/shop/order',**args);second=client.post('/shop/order',**args)
            self.assertEqual(first.status_code,200)
            self.assertTrue(first.get_json()['written'])
            self.assertEqual(first.get_json()['receipt_id'],second.get_json()['receipt_id'])
            self.assertEqual(len(store.read('Orders')),2)
            self.assertEqual(len(store.read('OrderLines')),2)

    def test_durable_order_route_retains_receipt_when_external_write_fails(self):
        import app
        with tempfile.TemporaryDirectory() as directory:
            path=str(Path(directory)/'pipeline.sqlite')
            with patch.object(app,'USE_SHEETS',True), patch.object(app,'ensure_telegram_webhook'), patch.object(app,'build_store',side_effect=RuntimeError('synthetic outage')), patch.dict(os.environ,{'SHOP_NOTIFY_SECRET':'synthetic','PIPELINE_DB_PATH':path}):
                response=app.app.test_client().post('/shop/order',json={'order':{'id':'sample'}},headers={'X-Shop-Token':'synthetic'})
            self.assertEqual(response.status_code,503)
            receipt=response.get_json()['receipt_id']
            self.assertEqual(Handoffs(path).get(receipt)['state'],'queued')

if __name__=='__main__':unittest.main()
