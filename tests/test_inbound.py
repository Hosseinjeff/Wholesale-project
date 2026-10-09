import unittest

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
    def test_direct_message_route_keeps_chat_id(self):
        queued = []

        def capture(data):
            queued.append(data)
            return True

        original = app.enqueue_message
        app.enqueue_message = capture
        try:
            response = app.app.test_client().post("/webhook", json={
                "update_id": 1,
                "message": {
                    "message_id": 3,
                    "text": "ping",
                    "chat": {"id": 42, "type": "private"},
                    "from": {"username": "me"},
                },
            })
        finally:
            app.enqueue_message = original

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.get_json()["source"], "direct")
        self.assertEqual(queued[0]["chat_id"], 42)
        self.assertEqual(queued[0]["content"], "ping")
