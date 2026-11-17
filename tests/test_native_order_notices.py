import unittest
from catalog.order_notices import dispatch_order_notice


class NativeOrderNoticeTests(unittest.TestCase):
    def setUp(self):
        self.payload = {'type': 'order_notice', 'dispatch_key': 'a' * 64,
                        'order': {'id': 'synthetic-order', 'name': 'Synthetic buyer', 'lines': []}}
        self.sent = []

    def emit(self, *args):
        self.sent.append(args)
        return True

    def dispatch(self, **changes):
        args = dict(emit=self.emit, allowed_chats={'telegram': {'synthetic-owner'},
                    'bale': {'synthetic-bale'}}, telegram_chats=['synthetic-owner', 'untrusted'],
                    telegram_enabled=True, bale_enabled=True)
        args.update(changes)
        return dispatch_order_notice(self.payload, **args)

    def test_only_trusted_enabled_bot_destinations_are_queued(self):
        self.assertEqual(self.dispatch(), {'delivered': True, 'queued': 2})
        self.assertEqual({row[1] for row in self.sent}, {'synthetic-owner', 'synthetic-bale'})
        self.assertTrue(all(row[0] == 'order:' + 'a' * 64 for row in self.sent))
        self.assertTrue(all('synthetic-order' in row[2] for row in self.sent))

    def test_disabled_bot_and_absent_allowlist_do_not_send(self):
        self.assertEqual(self.dispatch(telegram_enabled=False, allowed_chats={})['queued'], 0)
        self.assertEqual(self.sent, [])

    def test_retry_keeps_the_original_notice_identity(self):
        self.dispatch(); first = list(self.sent); self.sent.clear(); self.dispatch()
        self.assertEqual(self.sent, first)

    def test_invalid_dispatch_key_is_rejected(self):
        self.payload['dispatch_key'] = 'invalid'
        with self.assertRaises(ValueError):
            self.dispatch()
        self.assertEqual(self.sent, [])
