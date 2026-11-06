import unittest
from unittest.mock import Mock

from catalog.wordpress_outbox import drain_wordpress_workflow_outbox, wordpress_outbox_token


TOKEN = "synthetic-reference-bridge-token-" + ("x" * 32)
COMMAND = {
    "command_type": "sheet_conflict",
    "command_id": "a" * 64,
    "payload": {
        "conflict_id": "sheet:fixture",
        "choice": "server",
        "note": "Keep the current server-derived value.",
        "expected_revision": "offer:fixture:2",
    },
}


class Response:
    def __init__(self, status_code, body):
        self.status_code = status_code
        self.body = body

    def json(self):
        return self.body


class Session:
    def __init__(self, commands):
        self.commands = commands
        self.calls = []

    def get(self, url, **kwargs):
        self.calls.append(("GET", url, kwargs))
        return Response(200, {"ok": True, "commands": self.commands})

    def post(self, url, **kwargs):
        self.calls.append(("POST", url, kwargs))
        return Response(200, {"ok": True})


class WordPressOutboxTests(unittest.TestCase):
    def test_scoped_token_is_stable_and_does_not_equal_bridge_secret(self):
        token = wordpress_outbox_token(TOKEN)
        self.assertEqual(len(token), 64)
        self.assertNotEqual(token, TOKEN)
        self.assertEqual(token, wordpress_outbox_token(TOKEN))

    def test_successful_railway_receipt_is_recorded_as_delivered(self):
        session = Session([COMMAND])
        processed = []

        def apply(payload):
            processed.append(payload)
            return 200, {"ok": True, "conflict_id": payload["conflict_id"],
                "choice": payload["choice"], "server_owned": True}

        result = drain_wordpress_workflow_outbox("https://www.molavisale.ir", TOKEN,
            lambda _type,payload: apply(payload), session=session)
        self.assertEqual(result["delivered"], 1)
        self.assertEqual(result["retried"], 0)
        self.assertEqual(processed, [COMMAND["payload"]])
        get_call, receipt_call = session.calls
        self.assertEqual(get_call[0], "GET")
        self.assertEqual(get_call[2]["headers"]["X-Molavi-Outbox-Token"], wordpress_outbox_token(TOKEN))
        self.assertNotIn(TOKEN, get_call[2]["headers"]["X-Molavi-Outbox-Token"])
        self.assertTrue(receipt_call[1].endswith("/receipt"))
        self.assertEqual(receipt_call[2]["json"]["state"], "delivered")
        self.assertEqual(receipt_call[2]["json"]["command_id"], COMMAND["command_id"])

    def test_temporary_railway_failure_is_queued_and_permanent_rejection_fails(self):
        session = Session([COMMAND, {**COMMAND, "command_id": "b" * 64}])
        responses = iter([
            (503, {"error": "sheet_unavailable"}),
            (409, {"error": "conflict_changed"}),
        ])
        result = drain_wordpress_workflow_outbox("https://www.molavisale.ir", TOKEN,
            lambda _type,_payload: next(responses), session=session)
        self.assertEqual((result["retried"], result["failed"]), (1, 1))
        receipts = [call[2]["json"] for call in session.calls if call[0] == "POST"]
        self.assertEqual([item["state"] for item in receipts], ["queued", "failed"])
        self.assertEqual(receipts[0]["receipt"]["error"], "sheet_unavailable")

    def test_invalid_configuration_does_not_contact_wordpress_or_railway(self):
        session = Session([COMMAND])
        result = drain_wordpress_workflow_outbox("https://attacker.example", TOKEN,
            lambda _type,_payload: self.fail("must not process an untrusted URL"), session=session)
        self.assertEqual(result["error"], "unconfigured")
        self.assertEqual(session.calls, [])

    def test_supplier_price_receipt_is_revision_bound_and_uses_the_command_id(self):
        command_id="c"*64
        command={"command_type":"supplier_price","command_id":command_id,
            "payload":{"product_id":"product-1","expected_revision":"revision-1",
                "note":"checked source","command_id":command_id}}
        session=Session([command])
        applied=[]
        def apply(command_type,payload):
            applied.append((command_type,payload))
            return 200,{"ok":True,"product_id":payload["product_id"],
                "source_revision":payload["expected_revision"],"command_id":command_id,
                "verified_at":"2026-11-06T00:00:00Z","publish_job":"publish:1"}
        result=drain_wordpress_workflow_outbox("https://www.molavisale.ir",TOKEN,apply,session=session)
        self.assertEqual(result["delivered"],1)
        self.assertEqual(applied,[("supplier_price",command["payload"])])
        receipt=[call for call in session.calls if call[0]=="POST"][0][2]["json"]
        self.assertEqual(receipt["state"],"delivered")
        self.assertEqual(receipt["receipt"]["command_id"],command_id)


if __name__ == "__main__":
    unittest.main()
