import unittest
import os
from unittest.mock import patch

import app
from app import inbound_payload, webhook_url_from_env


class InboundPayloadTests(unittest.TestCase):
    def test_direct_text_keeps_chat_id_for_the_reply(self):
        data, source = inbound_payload({
            "message_id": 7,
            "text": "hi",
            "date": 1,
            "from": {"username": "me"},
            "chat": {"id": 42, "type": "private"},
        }, "telegram")

        self.assertEqual(source, "direct")
        self.assertEqual(data["chat_id"], 42)
        self.assertEqual(data["content"], "hi")
        self.assertEqual(data["channel"], "telegram")

    def test_forwarded_photo_caption_keeps_chat_id(self):
        data, source = inbound_payload({
            "message_id": 8,
            "caption": "catalog",
            "date": 2,
            "photo": [{"file_id": "abc"}],
            "from": {"username": "me"},
            "chat": {"id": 99, "type": "private"},
            "forward_origin": {
                "type": "channel",
                "date": 1,
                "message_id": 3,
                "chat": {"title": "Shop", "username": "shop"},
            },
        }, "telegram")

        self.assertEqual(source, "forward")
        self.assertEqual(data["chat_id"], 99)
        self.assertEqual(data["content"], "catalog")
        self.assertEqual(data["channel_username"], "@shop")

    def test_bale_direct_message_keeps_chat_id(self):
        data, source = inbound_payload({
            "message_id": 4,
            "text": "سلام",
            "chat": {"id": 15},
            "from": {"username": "ali"},
        }, "bale")

        self.assertEqual(source, "direct")
        self.assertEqual(data["chat_id"], 15)
        self.assertEqual(data["channel"], "bale")


class WebhookUrlTests(unittest.TestCase):
    def test_path_only_value_falls_back_to_public_domain(self):
        url = webhook_url_from_env({
            "RAILWAY_WEBHOOK_URL": "/webhook",
            "RAILWAY_PUBLIC_DOMAIN": "bot.example.com",
        })
        self.assertEqual(url, "https://bot.example.com/webhook")

    def test_bare_host_is_normalized(self):
        url = webhook_url_from_env({
            "RAILWAY_WEBHOOK_URL": "bot.example.com",
            "RAILWAY_PUBLIC_DOMAIN": "ignored.example",
        })
        self.assertEqual(url, "https://bot.example.com/webhook")

    def test_explicit_https_url_is_normalized(self):
        url = webhook_url_from_env({
            "RAILWAY_WEBHOOK_URL": "https://bot.example.com",
            "RAILWAY_PUBLIC_DOMAIN": "ignored.example",
        })
        self.assertEqual(url, "https://bot.example.com/webhook")


class WebhookRouteTests(unittest.TestCase):
    def test_webhook_registration_sends_telegram_secret_token(self):
        class Response:
            status_code=200
            def json(self): return {'ok':True,'description':'Webhook is set'}
        secret='configured-webhook-secret-0123456789'
        with patch.object(app,'BOT_TOKEN','test-token'), \
             patch.object(app,'_register_bot_commands'), \
             patch('app.requests.post',return_value=Response()) as post, \
             patch.dict(os.environ,{'RAILWAY_WEBHOOK_URL':'https://bot.example.com/webhook',
                                    'TELEGRAM_WEBHOOK_SECRET':secret}):
            self.assertTrue(app.register_telegram_webhook())
        self.assertEqual(post.call_args.kwargs['json']['secret_token'],secret)

    def test_webhook_registration_fails_closed_without_a_valid_secret(self):
        with patch.object(app,'BOT_TOKEN','test-token'), \
             patch('app.requests.post') as post, \
             patch.dict(os.environ,{'RAILWAY_WEBHOOK_URL':'https://bot.example.com/webhook'},clear=True):
            self.assertFalse(app.register_telegram_webhook())
        post.assert_not_called()

    def test_direct_message_route_keeps_chat_id(self):
        queued = []

        def capture(data):
            queued.append(data)
            return True

        original = app.enqueue_message
        app.enqueue_message = capture
        try:
            with patch.object(app, 'ensure_telegram_webhook'), patch.object(app, 'USE_SHEETS', False), patch.dict(os.environ, {
                    'TELEGRAM_WEBHOOK_SECRET': 'test-webhook-secret-32-characters-minimum',
                    'TELEGRAM_OPERATOR_CHAT_IDS': '42'}):
                response = app.app.test_client().post("/webhook", json={
                    "update_id": 1,
                    "message": {
                        "message_id": 3,
                        "text": "ping",
                        "chat": {"id": 42, "type": "private"},
                        "from": {"username": "me"},
                    },
                }, headers={'X-Telegram-Bot-Api-Secret-Token': 'test-webhook-secret-32-characters-minimum'})
        finally:
            app.enqueue_message = original

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.get_json()["source"], "direct")
        self.assertEqual(queued[0]["chat_id"], 42)
        self.assertEqual(queued[0]["content"], "ping")

    def test_telegram_webhook_rejects_missing_or_wrong_secret_before_queueing(self):
        with patch.object(app,'ensure_telegram_webhook'), patch.dict(os.environ, {'TELEGRAM_WEBHOOK_SECRET':'configured-webhook-secret-0123456789'}):
            client=app.app.test_client()
            body={'update_id':1,'message':{'message_id':1,'text':'intrusion',
                'chat':{'id':42,'type':'private'},'from':{'id':42}}}
            self.assertEqual(client.post('/webhook',json=body).status_code,401)
            self.assertEqual(client.post('/webhook',json=body,
                headers={'X-Telegram-Bot-Api-Secret-Token':'wrong-secret'}).status_code,401)

    def test_private_sender_must_be_in_the_trusted_owner_allowlist(self):
        queued=[]
        with patch.object(app,'ensure_telegram_webhook'), \
             patch.object(app,'enqueue_message',side_effect=lambda data: queued.append(data) or True), \
             patch.object(app,'USE_SHEETS',False), \
             patch.dict(os.environ, {'TELEGRAM_WEBHOOK_SECRET':'configured-webhook-secret-0123456789',
                                    'TELEGRAM_OPERATOR_CHAT_IDS':'288165176'}):
            client=app.app.test_client()
            outsider=client.post('/webhook',json={'message':{'message_id':1,'text':'price',
                'chat':{'id':99,'type':'private'},'from':{'id':99}}},
                headers={'X-Telegram-Bot-Api-Secret-Token':'configured-webhook-secret-0123456789'})
            owner=client.post('/webhook',json={'message':{'message_id':2,'text':'price',
                'chat':{'id':288165176,'type':'private'},'from':{'id':288165176}}},
                headers={'X-Telegram-Bot-Api-Secret-Token':'configured-webhook-secret-0123456789'})
        self.assertEqual(outsider.get_json()['status'],'ignored')
        self.assertEqual(owner.get_json()['status'],'success')
        self.assertEqual([item['chat_id'] for item in queued],[288165176])

    def test_supplier_channel_post_uses_channel_identity_and_edit_time(self):
        data,source=inbound_payload({
            'message_id':12,'date':1700000000,'edit_date':1700003600,'text':'Item price',
            'chat':{'id':-1001,'type':'channel','title':'Supplier','username':'supplier'},
        },'telegram')
        self.assertEqual(source,'channel')
        self.assertEqual(data['channel_username'],'@supplier')
        self.assertEqual(data['timestamp'],1700003600)
        self.assertEqual(data['source_update_at'],1700003600)
        self.assertEqual(data['url'],'https://t.me/supplier/12')
        self.assertEqual(data['chat_type'],'channel')
