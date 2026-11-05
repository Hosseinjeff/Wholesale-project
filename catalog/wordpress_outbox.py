"""Small Railway consumer for durable WordPress-owned workflow commands."""
from __future__ import annotations

import hashlib
import hmac
import re
from urllib.parse import urlparse

import requests


_TOKEN_SCOPE = b"molavi-wordpress-outbox-v1"
_COMMAND_ID = re.compile(r"^[0-9a-f]{64}$")


def wordpress_outbox_token(bridge_token: str) -> str:
    """Derive a service-only credential without sending the shared bridge key."""
    return hmac.new(bridge_token.encode("utf-8"), _TOKEN_SCOPE, hashlib.sha256).hexdigest()


def drain_sheet_conflict_outbox(
    site_url: str,
    bridge_token: str,
    process_command,
    *,
    session=None,
    limit: int = 5,
) -> dict:
    """Claim due WordPress commands, apply them through the local Railway API, and save receipts.

    ``process_command`` receives the command payload and returns ``(status_code, json_body)``.
    A remote receipt write can fail after Railway has applied a command; stable command IDs and
    the conflict resolver's idempotency make the next delivery safe.
    """
    parsed = urlparse((site_url or "").strip())
    hostname = (parsed.hostname or "").lower()
    trusted_host = hostname == "molavisale.ir" or hostname.endswith(".molavisale.ir")
    if (parsed.scheme != "https" or not trusted_host or parsed.username or parsed.password
            or parsed.query or parsed.fragment or (parsed.port not in (None, 443))
            or len(bridge_token or "") < 32):
        return {"claimed": 0, "delivered": 0, "retried": 0, "failed": 0, "error": "unconfigured"}
    http = session or requests
    headers = {
        "X-Molavi-Outbox-Token": wordpress_outbox_token(bridge_token),
        "Accept": "application/json",
    }
    root = site_url.rstrip("/") + "/wp-json/molavi/v1/outbox/sheet-conflicts"
    try:
        response = http.get(root, headers=headers, params={"limit": max(1, min(10, int(limit)))},
                            timeout=(4, 12))
        if response.status_code != 200:
            return {"claimed": 0, "delivered": 0, "retried": 0, "failed": 0,
                    "error": "outbox_read_failed"}
        body = response.json()
        commands = body.get("commands") if isinstance(body, dict) and body.get("ok") is True else None
        if not isinstance(commands, list):
            return {"claimed": 0, "delivered": 0, "retried": 0, "failed": 0,
                    "error": "outbox_response_invalid"}
    except (requests.RequestException, ValueError, TypeError):
        return {"claimed": 0, "delivered": 0, "retried": 0, "failed": 0,
                "error": "outbox_unreachable"}

    result = {"claimed": len(commands), "delivered": 0, "retried": 0, "failed": 0, "error": ""}

    def save_receipt(command_id, state, receipt):
        try:
            saved = http.post(root + "/receipt", headers=headers,
                              json={"command_id": command_id, "state": state, "receipt": receipt},
                              timeout=(4, 12))
            saved_body = saved.json() if saved.status_code == 200 else None
            return isinstance(saved_body, dict) and saved_body.get("ok") is True
        except (requests.RequestException, ValueError, TypeError):
            return False

    for command in commands:
        if not isinstance(command, dict):
            continue
        command_id = str(command.get("command_id") or "")
        payload = command.get("payload")
        if not _COMMAND_ID.fullmatch(command_id):
            continue
        if not isinstance(payload, dict):
            result["failed"] += 1
            if not save_receipt(command_id, "failed", {"error": "outbox_command_invalid"}):
                result["error"] = "outbox_receipt_rejected"
            continue
        expected_revision = payload.get("expected_revision")
        if (not isinstance(expected_revision, str) or not expected_revision
                or not isinstance(payload.get("conflict_id"), str)
                or payload.get("choice") not in ("server", "sheet", "preserve")):
            result["failed"] += 1
            if not save_receipt(command_id, "failed", {"error": "outbox_command_invalid"}):
                result["error"] = "outbox_receipt_rejected"
            continue

        try:
            status_code, receipt = process_command(payload)
            status_code = int(status_code)
        except Exception:
            status_code, receipt = 503, {"error": "railway_command_failed"}
        if not isinstance(receipt, dict):
            receipt = {"error": "railway_receipt_invalid"}

        valid_success = (
            status_code == 200
            and receipt.get("ok") is True
            and receipt.get("conflict_id") == payload.get("conflict_id")
            and receipt.get("choice") == payload.get("choice")
            and (receipt.get("server_owned") is True or receipt.get("sheet_preserved") is True
                 or bool(receipt.get("publish_job")))
        )
        transient = status_code in (408, 425, 429) or status_code >= 500
        if valid_success:
            state = "delivered"
            recorded_receipt = receipt
            result["delivered"] += 1
        elif transient:
            state = "queued"
            recorded_receipt = {"error": str(receipt.get("error") or "railway_temporarily_unavailable")[:500]}
            result["retried"] += 1
        else:
            state = "failed"
            recorded_receipt = {"error": str(receipt.get("error") or "railway_rejected_command")[:500]}
            result["failed"] += 1

        if not save_receipt(command_id, state, recorded_receipt):
            result["error"] = "outbox_receipt_unreachable"
    return result
