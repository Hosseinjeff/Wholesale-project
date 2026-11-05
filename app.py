#!/usr/bin/env python3
"""
Flask app for Railway/Render deployment with Telegram webhooks.
"""

from flask import Flask, Response, request, jsonify
import base64
import hashlib
import hmac
import json
import logging
import requests
import os
import re
import time
import threading
import uuid
from urllib.parse import urlparse
from utils.logger import setup_logger

from catalog.durable import (
    Handoffs, ReferencePending, ReferenceChanged, JobConflict,
    compact_pipeline_payloads,
)
from catalog.media import safe_file_id, stamp_inbound
from catalog.orders import format_order_notice, operator_chat_ids, remember_operator, upsert_shop_order
from catalog.pricing import catalog_from_records, default_settings, preview_records, resolve_quote
from catalog.status import command_name, private_chat, reply_for_command
from catalog.publish import publish_shop
from catalog.sync import (
    build_store,
    finalize_batch,
    refresh_buying_desk,
    release_idle_memory,
    SHEET_PROVENANCE_FIELDS,
    SHEET_PRESERVED_FIELDS,
    SHEET_SERVER_OWNED_FIELDS,
    resolve_prompt,
    reextract_saved_message,
    sheets_configured,
    shop_catalog,
)

app = Flask(__name__)

# Global variables - read from Railway environment variables
WEB_APP_URL = os.getenv('GOOGLE_WEB_APP_URL')
BOT_TOKEN = os.getenv('TELEGRAM_BOT_TOKEN')
BALE_BOT_TOKEN = os.getenv('BALE_BOT_TOKEN')
APP_VERSION = "2026-11-05-source-integrity-storage-trim"
setup_logger(logging.INFO, log_file=None)
logger = logging.getLogger(__name__)

# Log startup info
logger.info("Starting Flask app for Railway")
logger.info(f"GOOGLE_WEB_APP_URL: {'SET' if WEB_APP_URL else 'NOT SET'}")
logger.info(f"TELEGRAM_BOT_TOKEN: {'SET' if BOT_TOKEN else 'NOT SET'}")
USE_SHEETS = sheets_configured()
if USE_SHEETS:
    logger.info("Sheet writer: service account")
elif WEB_APP_URL:
    logger.info("Sheet writer: Google Apps Script")
    logger.info("Legacy Apps Script writer does not keep the offer log or current book.")
    logger.info(f"Web app URL: {WEB_APP_URL[:50]}...")
else:
    logger.error("No sheet writer configured. Set GOOGLE_SERVICE_ACCOUNT_JSON and GOOGLE_SHEET_ID, or GOOGLE_WEB_APP_URL.")

BATCH_MAX_SIZE = 100
BATCH_MAX_WAIT_SEC = 5.0

class BatchManager:
    def __init__(self, web_app_url):
        self.web_app_url = web_app_url
        self.lock = threading.Lock()
        self.buffer = []
        self.chat_ids = set()
        self.timer = None
        self.batch_id = None

    def start_timer(self):
        if self.timer:
            return
        self.timer = threading.Timer(BATCH_MAX_WAIT_SEC, self.flush_and_finalize)
        self.timer.daemon = True
        self.timer.start()

    def add_message(self, data):
        with self.lock:
            if not self.batch_id:
                self.batch_id = str(int(time.time() * 1000))
            self.buffer.append(data)
            chat_id = data.get('chat_id')
            if chat_id:
                self.chat_ids.add(chat_id)
            if len(self.buffer) >= BATCH_MAX_SIZE:
                batch_id, messages, chat_ids = self._snapshot_locked()
            else:
                self.start_timer()
                batch_id, messages, chat_ids = None, None, None
        if batch_id and messages:
            threading.Thread(target=self._send_finalize, args=(batch_id, messages, chat_ids), daemon=True).start()

    def flush_and_finalize(self):
        with self.lock:
            batch_id, messages, chat_ids = self._snapshot_locked()
        if batch_id and messages:
            threading.Thread(target=self._send_finalize, args=(batch_id, messages, chat_ids), daemon=True).start()

    def _snapshot_locked(self):
        if not self.buffer or not self.batch_id:
            return None, None, None
        batch_id = self.batch_id
        messages = list(self.buffer)
        chat_ids = list(self.chat_ids)
        self.buffer = []
        self.chat_ids = set()
        self.batch_id = None
        if self.timer:
            try:
                self.timer.cancel()
            except Exception:
                pass
        self.timer = None
        return batch_id, messages, chat_ids

    def _send_finalize(self, batch_id, messages, chat_ids):
        expected = len(messages)
        payload = {
            "mode": "batch_ingest",
            "batch_id": batch_id,
            "messages": messages,
            "transmission_complete": True,
            "expected_count": expected
        }
        try:
            if USE_SHEETS:
                data = finalize_batch(build_store(), payload)
                logger.info(
                    "Sheet batch %s status=%s processed=%s rollback=%s",
                    batch_id,
                    data.get("status"),
                    data.get("processed_messages"),
                    data.get("rollback"),
                )
                if data.get("status") == "success":
                    _publish_shop_quietly()
            else:
                resp = requests.post(self.web_app_url, json=payload, timeout=60, headers={'Content-Type': 'application/json'})
                logger.info(f"Finalize ack: {resp.status_code} {resp.text[:200]}")
                try:
                    data = resp.json()
                except Exception:
                    data = {}
            self._notify_chats(batch_id, chat_ids, data)
        except Exception:
            logger.exception("Batch finalize error")
            self._notify_chats(batch_id, chat_ids, {
                "status": "error",
                "ack": "writer_failed",
                "processed_messages": 0,
                "rollback": False,
            })

    def _notify_chats(self, batch_id, chat_ids, data):
        prompts = data.get("prompts") or []
        targets = []
        seen = set()
        for chat_id in list(chat_ids or []) + [prompt.get("chat_id") for prompt in prompts]:
            if chat_id in (None, "") or str(chat_id) in seen:
                continue
            seen.add(str(chat_id))
            targets.append(chat_id)
        for chat_id in targets:
            chat_prompts = [prompt for prompt in prompts if str(prompt.get("chat_id") or "") == str(chat_id)]
            send_chat_text(chat_id, format_batch_reply({**data, "prompts": chat_prompts}))

class DurableBatchManager:
    def __init__(self, queue):
        self.queue = queue
        self.timer = None
        self.lock = threading.Lock()

    def add_message(self, data):
        self.queue.receive(data)  # Commit before returning webhook acknowledgement.
        with self.lock:
            if self.timer is None:
                self.timer = threading.Timer(BATCH_MAX_WAIT_SEC, self.flush_and_finalize)
                self.timer.daemon = True
                self.timer.start()

    def flush_and_finalize(self):
        with self.lock:
            self.timer = None
        try:
            self.queue.batch(BATCH_MAX_SIZE)
            run_durable_handoff(self.queue)
        except Exception:
            logger.exception("Durable handoff remains pending for worker recovery")
            return
        try:
            retention_days, compacted = compact_pipeline_payloads(self.queue)
            if compacted['jobs_compacted'] or compacted['inbox_compacted']:
                logger.info("Compacted completed pipeline payloads jobs=%s inbox=%s retention_days=%s",
                            compacted['jobs_compacted'], compacted['inbox_compacted'], retention_days)
        except Exception:
            logger.exception("Completed payload compaction failed; business handoff already completed")


def handoff_queue():
    path = os.getenv("PIPELINE_DB_PATH")
    return Handoffs(path) if path else None


def _notice_chat_allowlists(queue=None):
    """Explicit recipients allowed to receive private operational notices."""
    def values(*names):
        return {part.strip() for name in names
                for part in (os.getenv(name) or '').split(',') if part.strip()}
    telegram = values('TELEGRAM_OPERATOR_CHAT_IDS', 'TELEGRAM_ADDITIONAL_OPERATOR_CHAT_IDS',
                      'ORDER_NOTIFY_CHAT_ID')
    if queue is not None:
        try:
            telegram.update(queue.approved_telegram_operator_ids())
        except Exception:
            logger.exception('Could not read approved Telegram operator identities')
    return {
        'telegram': telegram,
        'bale': values('BALE_OPERATOR_CHAT_IDS'),
    }


def run_durable_handoff(queue, job_id=None, worker_id=None):
    if not USE_SHEETS:
        raise RuntimeError("Durable pipeline requires the server Sheets writer")

    def notice(key, chat_id, text, channel="telegram"):
        if (channel not in {'telegram', 'bale'}
                or str(chat_id or '').strip() not in _notice_chat_allowlists(queue)[channel]):
            logger.warning("Skipped operational notice for a non-allowlisted recipient")
            return False
        queue.put("notice", key + ":" + str(chat_id) + ":" + channel,
                  {"chat_id": chat_id, "text": text, "channel": channel})
        return True

    def finalize(payload):
        with queue.publication_lock():
            return finalize_locked(payload)

    def finalize_locked(payload):
        reference_path = os.getenv("REFERENCE_STORE_PATH")
        if not reference_path:
            return finalize_with_reference(payload, None)
        from reference_import.snapshots import ReferenceStore
        owners = [x.strip() for x in (os.getenv("REFERENCE_OWNER_IDS") or "").split(",") if x.strip()]
        reference_store = ReferenceStore(reference_path, owner_ids=owners)
        with reference_store.activation_lock():
            return finalize_with_reference(payload, reference_store)

    def finalize_with_reference(payload, reference_store):
        reference = None
        if reference_store is not None:
            from datetime import datetime, timezone
            from reference_import.workflow import active_reference_use
            eligibility = active_reference_use(reference_store, now=datetime.now(timezone.utc))
            if not eligibility["allow_automatic"]:
                raise ReferencePending('reference decision required')
            reference = reference_store.active_snapshot()
            if reference is None or reference['snapshot_id'] != eligibility['snapshot_id']:
                raise ReferenceChanged('reference snapshot changed before intake')
            reference['usage_warning'] = eligibility.get('warning') or ''
            from reference_import.item_reviews import ItemReviewLedger
            reference['item_overrides'] = ItemReviewLedger(reference_store.database,
                owner_ids=reference_store.owner_ids).overrides(
                reference['snapshot_id'])
        queue.hold_publication(payload['batch_id'], 'batch_in_progress')
        try:
            result = finalize_batch(build_store(), payload, reference_snapshot=reference)
            if result.get('rollback') or result.get('status') != 'success' or result.get('extraction_status') != 'success':
                queue.hold_publication(payload['batch_id'], 'batch_unverified')
            if reference is not None:
                latest = reference_store.active_snapshot()
                if latest is None or latest['snapshot_id'] != reference['snapshot_id']:
                    queue.hold_publication(payload['batch_id'], 'reference_changed_during_batch')
                    raise ReferenceChanged('reference snapshot changed during intake')
        except Exception:
            queue.hold_publication(payload['batch_id'], 'batch_write_unverified')
            raise
        # Persist original per-chat review prompts independently of publication.
        if result.get("status") == "success":
            prompts = result.get("prompts") or []
            targets = {(str(m.get("chat_id")), m.get("channel") or "telegram")
                       for m in payload["messages"] if m.get("chat_id")}
            for chat, channel in targets:
                selected = [p for p in prompts if str(p.get("chat_id")) == chat]
                notice(payload["batch_id"], chat,
                       format_batch_reply({**result, "prompts": selected}), channel)
        if result.get('status') == 'success' and result.get('extraction_status') == 'success' and not result.get('rollback'):
            queue.resolve_publication_hold(payload['batch_id'], expected_reason='batch_in_progress',
                                           actor_id='system:batch', note='Batch and reference checks completed')
        return result

    def order_write(payload):
        order = payload["order"]
        store = build_store()
        result = upsert_shop_order(store, order)
        payment_state = str(order.get("payment_status") or "").lower()
        terminal_state = str(order.get("woo_status") or "").lower()
        if result.get("written") is True and (payment_state in {"verified", "refunded"}
                or terminal_state in {"cancelled", "refunded", "failed"}):
            from datetime import datetime
            from zoneinfo import ZoneInfo
            from catalog.order_fulfillment import PaidOrderPlanner
            from catalog.order_operations import OrderOperations
            today = datetime.now(ZoneInfo("Asia/Tehran")).date().isoformat()
            planner = PaidOrderPlanner(queue.path)
            result["fulfillment"] = planner.plan(order, today=today)
            if payment_state == "verified":
                OrderOperations(queue.path).ensure(str(order.get("id") or ""),
                    result["fulfillment"], today=today)
        if result.get("written") is True and payload.get("notify"):
            import hashlib
            key = hashlib.sha256(queue.encode(payload).encode()).hexdigest()
            recipients = _notice_chat_allowlists(queue)
            for channel, token, chats in (
                    ("telegram", BOT_TOKEN, operator_chat_ids(store, queue.approved_telegram_operator_ids())),
                    ("bale", BALE_BOT_TOKEN, sorted(recipients['bale']))):
                if token:
                    for chat in chats:
                        notice("order:" + key, chat, format_order_notice(order), channel)
        return result

    def notify(payload):
        channel = payload["channel"]
        if (channel not in {'telegram', 'bale'}
                or str(payload.get('chat_id') or '').strip() not in _notice_chat_allowlists(queue).get(channel, set())):
            return {"delivered": False, "skipped": "recipient_not_allowlisted"}
        token = BALE_BOT_TOKEN if channel == "bale" else BOT_TOKEN
        host = "https://tapi.bale.ai" if channel == "bale" else "https://api.telegram.org"
        if not token:
            return {"delivered": False}
        response = requests.post(host + "/bot" + token + "/sendMessage",
                                 json={"chat_id": payload["chat_id"], "text": payload["text"]}, timeout=10)
        return {"delivered": response.status_code == 200 and response.json().get("ok") is True}

    def publish_projection(payload):
        from catalog.sheet_projection import sheet_projection_digest
        store=build_store()
        expected=payload.get('sheet_digest') if isinstance(payload,dict) else None
        before=sheet_projection_digest(store)
        if expected and before!=expected:
            state=queue.sheet_projection_state() or {}
            if before!=state.get('published_digest'):
                queue.enqueue_sheet_projection(before)
            return {'ok':True,'pushed':True,'images_left':0,'superseded':True}
        result=guarded_publish_shop(store,queue=queue)
        if result.get('ok') and result.get('pushed') and int(result.get('images_left') or 0)==0:
            after=sheet_projection_digest(store)
            if after!=before:
                queue.enqueue_sheet_projection(after)
                return {'ok':True,'pushed':True,'images_left':0,'superseded':True}
            queue.mark_sheet_projection_published(after)
        return result

    def reextract(payload):
        return reextract_saved_message(build_store(),payload,write_lock=queue.publication_lock())

    return queue.run_one(finalize=finalize, publish=publish_projection,
                         publish_with_payload=publish_projection,
                         order_write=order_write, notify=notify, job_id=job_id,
                         reextract=reextract, worker_id=worker_id)


def _wake_reference_waiters_if_eligible(queue, reference_store):
    """Resume parked source batches only after the current reference gate is clear."""
    if queue is None or reference_store is None:
        return 0
    try:
        from datetime import datetime, timezone
        from reference_import.workflow import active_reference_use
        eligibility=active_reference_use(reference_store,now=datetime.now(timezone.utc))
        if not eligibility.get('allow_automatic'):
            return 0
        awakened=queue.wake_reference_waiters()
        if awakened:
            logger.info('Resumed reference-waiting supplier batches count=%s',awakened)
        return awakened
    except Exception:
        logger.exception('Reference-waiting supplier batches remain parked')
        return 0


def _drain_reextraction_run(queue, run_id):
    """Run the selected saved-message jobs without waiting for another webhook."""
    status=queue.reextraction_status(run_id)
    if not status:
        return
    for item in status['items']:
        if item.get('state')!='queued':
            continue
        try:
            run_durable_handoff(queue,item.get('job_id'))
        except Exception:
            logger.exception('Saved-message re-extraction worker failed for run %s',run_id)
    status=queue.reextraction_status(run_id)
    if status:
        logger.info('Saved-message re-extraction run %s finished %s/%s jobs; states=%s',
                    run_id,status['completed'],status['total'],status['counts'])
        written=sum(int((item.get('result') or {}).get('offers_written') or 0)
                    for item in status['items'])
        if written:
            try:
                publication_job=queue.put('publish',f'ai-reextraction:{run_id}',
                    {'source':'ai_reextraction','run_id':run_id})
                run_durable_handoff(queue,publication_job)
            except Exception:
                logger.exception('Could not publish catalog changes from re-extraction run %s',run_id)


batch_manager = None

def extract_forward_data(message):
    """Extract data from forwarded Telegram message."""
    forward_origin = message.get('forward_origin', {})

    # Base data
    data = {
        'id': str(message.get('message_id', '')),
        'content': message.get('text', '') or message.get('caption', ''),
        'has_media': bool(message.get('photo') or message.get('document') or message.get('video')),
        'media_type': get_media_type(message),
        'forwarded_by': message.get('from', {}).get('username', 'Unknown'),
        'forwarded_at': message.get('date', ''),
        'channel': 'telegram',
        'channel_username': 'Unknown',
        'author': 'Unknown',
        'timestamp': None,
        'url': '',
        'chat_id': message.get('chat', {}).get('id')
    }

    # Extract origin information
    origin_type = forward_origin.get('type')
    if origin_type == 'channel':
        chat = forward_origin.get('chat', {})
        data['channel_username'] = f"@{chat.get('username', '')}" if chat.get('username') else chat.get('title', 'Channel')
        data['author'] = chat.get('title', 'Channel')
        data['timestamp'] = forward_origin.get('date', '')
        if chat.get('username'):
            data['url'] = f"https://t.me/{chat['username']}/{forward_origin.get('message_id', '')}"

    elif origin_type == 'user':
        sender = forward_origin.get('sender_user', {})
        if sender:
            first_name = sender.get('first_name', '')
            last_name = sender.get('last_name', '')
            username = sender.get('username', '')
            data['author'] = f"{first_name} {last_name}".strip() or username or 'User'
            data['timestamp'] = forward_origin.get('date', '')

    return data

def get_media_type(message):
    """Get media type from message."""
    if message.get('photo'):
        return 'photo'
    elif message.get('document'):
        return 'document'
    elif message.get('video'):
        return 'video'
    elif message.get('audio'):
        return 'audio'
    elif message.get('voice'):
        return 'voice'
    return None

def extract_bale_forward_data(message):
    """Extract data from forwarded Bale message."""
    forward_from_chat = message.get('forward_from_chat', {})
    forward_from = message.get('forward_from', {})

    # Base data
    data = {
        'id': str(message.get('message_id', '')),
        'content': message.get('text', '') or message.get('caption', ''),
        'has_media': bool(message.get('photo') or message.get('document') or message.get('video')),
        'media_type': get_media_type(message),
        'forwarded_by': message.get('from', {}).get('username', 'Unknown'),
        'forwarded_at': message.get('date', ''),
        'channel': 'bale',
        'channel_username': 'Unknown',
        'author': 'Unknown',
        'timestamp': None,
        'url': '',
        'chat_id': message.get('chat', {}).get('id')
    }

    # Extract origin information
    if forward_from_chat:
        chat = forward_from_chat
        data['channel_username'] = f"@{chat.get('username', '')}" if chat.get('username') else chat.get('title', 'Channel')
        data['author'] = chat.get('title', 'Channel')
        data['timestamp'] = message.get('date')
        if chat.get('username'):
            data['url'] = f"https://bale.ai/{chat['username']}/{message.get('message_id', '')}"
    elif forward_from:
        user = forward_from
        first_name = user.get('first_name', '')
        last_name = user.get('last_name', '')
        username = user.get('username', '')
        data['author'] = f"{first_name} {last_name}".strip() or username or 'User'
        data['timestamp'] = message.get('date')

    return data

def webhook_url_from_env(env=None):
    """Public Telegram webhook URL for this deployment."""
    env = os.environ if env is None else env
    explicit = (env.get("RAILWAY_WEBHOOK_URL") or "").strip()
    candidate = explicit if "://" in explicit else f"https://{explicit}" if explicit else ""
    parsed = urlparse(candidate)
    if parsed.scheme == "https" and parsed.netloc and "." in parsed.netloc:
        path = parsed.path.rstrip("/")
        if not path.endswith("/webhook"):
            path = (path + "/webhook") if path else "/webhook"
        return f"https://{parsed.netloc}{path}"
    domain = (env.get("RAILWAY_PUBLIC_DOMAIN") or "").strip().strip("/")
    if domain:
        return f"https://{domain}/webhook"
    return ""


def register_telegram_webhook():
    """Point Telegram at this service. Pending updates stay queued until this is set."""
    url = webhook_url_from_env()
    if not BOT_TOKEN or not url:
        logger.info("Skipping Telegram webhook registration")
        return True
    secret = (os.getenv("TELEGRAM_WEBHOOK_SECRET") or "").strip()
    if not re.fullmatch(r"[A-Za-z0-9_-]{32,256}", secret):
        logger.error("Skipping Telegram webhook registration: webhook secret is missing or invalid")
        return False
    try:
        response = requests.post(
            f"https://api.telegram.org/bot{BOT_TOKEN}/setWebhook",
            json={
                "url": url,
                "allowed_updates": ["message", "edited_message", "channel_post", "edited_channel_post"],
                "drop_pending_updates": False,
                "secret_token": secret,
            },
            timeout=15,
        )
        body = {}
        try:
            body = response.json()
        except Exception:
            body = {}
        logger.info(
            "setWebhook status=%s ok=%s description=%s",
            response.status_code,
            body.get("ok"),
            body.get("description"),
        )
        if response.status_code == 200 and body.get("ok"):
            _register_bot_commands()
            return True
        return False
    except Exception as exc:
        # Do not log the request URL: it contains the bot token.
        logger.error("setWebhook failed (%s)", exc.__class__.__name__)
        return False


def _register_bot_commands():
    """Show the three store reports in Telegram's command menu."""
    if not BOT_TOKEN:
        return
    try:
        requests.post(
            f"https://api.telegram.org/bot{BOT_TOKEN}/setMyCommands",
            json={"commands": [
                {"command": "store", "description": "وضعیت کلی فروشگاه"},
                {"command": "orders", "description": "وضعیت سفارش‌ها"},
                {"command": "products", "description": "وضعیت کالاها"},
            ]},
            timeout=15,
        )
    except Exception:
        logger.exception("setMyCommands failed")


_webhook_registered = False
_webhook_lock = threading.Lock()


def ensure_telegram_webhook():
    global _webhook_registered
    if _webhook_registered:
        return
    with _webhook_lock:
        if _webhook_registered:
            return
        _webhook_registered = True

    def _run():
        global _webhook_registered
        # Let gunicorn bind before Telegram delivers the queued updates.
        time.sleep(1)
        if register_telegram_webhook():
            return
        with _webhook_lock:
            _webhook_registered = False

    threading.Thread(target=_run, name="telegram-webhook", daemon=True).start()



def format_batch_reply(data):
    """Tell the operator what was saved, and ask when a price is in doubt."""
    if data.get("rollback"):
        return "این دسته ذخیره نشد. قیمت قبلی سر جایش ماند."
    if data.get("status") == "error":
        return "این دسته ذخیره نشد."
    lines = []
    saved = data.get("saved") or []
    if saved:
        lines.append("ثبت شد:")
        for item in saved[:20]:
            price = item.get("sale") or "بدون قیمت واحد"
            lines.append(f"{item.get('name')} — {price}")
    prompts = data.get("prompts") or []
    if prompts:
        if lines:
            lines.append("")
        lines.append(prompts[0].get("question") or "")
    if not lines:
        return "قیمتی در این پیام نبود."
    return "\n".join(lines)


def send_chat_text(chat_id, text):
    if not chat_id or not text:
        return
    try:
        if BOT_TOKEN:
            response = requests.post(
                f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage",
                json={"chat_id": chat_id, "text": text},
                timeout=10,
            )
            if response.status_code != 200:
                logger.error("Telegram sendMessage failed: %s %s", response.status_code, response.text[:200])
        if BALE_BOT_TOKEN:
            response = requests.post(
                f"https://tapi.bale.ai/bot{BALE_BOT_TOKEN}/sendMessage",
                json={"chat_id": chat_id, "text": text},
                timeout=10,
            )
            if response.status_code != 200:
                logger.error("Bale sendMessage failed: %s %s", response.status_code, response.text[:200])
    except Exception:
        logger.exception("Error sending status update")


def enqueue_message(data):
    """Queue one inbound message for the sheet writer."""
    if not USE_SHEETS and not WEB_APP_URL:
        logger.error("No sheet writer configured")
        send_chat_text(data.get("chat_id"), "Sheet writer is not configured.")
        return False

    try:
        if 'processing_mode' not in data:
            data['processing_mode'] = 'message_only'
        logger.info(
            "Queued message id=%s channel=%s chat_id=%s",
            data.get("id"),
            data.get("channel"),
            data.get("chat_id"),
        )

        global batch_manager
        if batch_manager is None:
            batch_manager = DurableBatchManager(handoff_queue()) if os.getenv("PIPELINE_DB_PATH") else BatchManager(WEB_APP_URL)

        batch_manager.add_message(data)
        return True
    except Exception:
        logger.exception("Error queueing inbound message")
        send_chat_text(data.get("chat_id"), "Failed to queue the message.")
        return False


def send_to_google_apps_script(data):
    """Backward-compatible name for the inbound queue."""
    return enqueue_message(data)


def extract_direct_data(message, channel):
    """Extract a private or channel post that was not forwarded."""
    chat = message.get('chat') if isinstance(message.get('chat'), dict) else {}
    is_channel_post = str(chat.get('type') or '') == 'channel'
    channel_username = str(chat.get('username') or '').strip()
    post_id = str(message.get('message_id') or '')
    source_timestamp = (message.get('edit_date') or message.get('date')) if is_channel_post else message.get('date')
    if is_channel_post and channel_username:
        source_url = f"https://t.me/{channel_username}/{post_id}" if post_id else ''
    else:
        source_url = ''
    author = (chat.get('title') or channel_username or 'Channel') if is_channel_post else (
        message.get('from', {}).get('username', 'User'))
    return {
        'id': post_id,
        'content': message.get('text') or message.get('caption') or '',
        'has_media': bool(message.get('photo') or message.get('document') or message.get('video')),
        'media_type': get_media_type(message),
        'forwarded_by': message.get('from', {}).get('username', '') or author,
        'forwarded_at': message.get('date', ''),
        'channel': channel,
        'channel_username': (f"@{channel_username}" if channel_username else (chat.get('title') or 'Channel')) if is_channel_post else 'DirectMessage',
        'author': author,
        'timestamp': source_timestamp,
        'source_update_at': message.get('edit_date') if is_channel_post else '',
        'url': source_url,
        'chat_id': chat.get('id'),
        'chat_type': chat.get('type') or '',
    }


def inbound_payload(message, channel):
    """Build the sheet payload for one Telegram or Bale update."""
    chat = message.get('chat') if isinstance(message.get('chat'), dict) else {}
    if channel == 'telegram' and str(chat.get('type') or '') == 'channel':
        data = extract_direct_data(message, channel)
        source = 'channel'
    elif channel == 'telegram' and 'forward_origin' in message:
        data = extract_forward_data(message)
        source = 'forward'
    elif channel == 'bale' and ('forward_from_chat' in message or 'forward_from' in message):
        data = extract_bale_forward_data(message)
        source = 'forward'
    elif message.get('text') or message.get('caption') or get_media_type(message):
        data = extract_direct_data(message, channel)
        source = 'direct'
    else:
        return None, 'ignored'

    # A private message proves when the owner sent it, not when the supplier
    # updated its offer. Preserve that distinction so owner-submitted text cannot
    # silently reset supplier-price freshness.
    if source == 'channel':
        data['source_timestamp_basis'] = 'supplier_channel_update'
    elif source == 'forward' and channel == 'telegram':
        origin = message.get('forward_origin') if isinstance(message.get('forward_origin'), dict) else {}
        data['source_timestamp_basis'] = (
            'supplier_channel_forward' if origin.get('type') == 'channel' else 'owner_forward'
        )
    elif source == 'forward':
        data['source_timestamp_basis'] = 'forward_receipt'
    elif private_chat(data):
        data['source_timestamp_basis'] = 'owner_submission'
    else:
        data['source_timestamp_basis'] = 'unverified_direct'

    if not data.get('chat_id'):
        data['chat_id'] = (message.get('chat') or {}).get('id')
    stamp_inbound(data, message)
    return data, source


def update_message(update_json):
    for key in ('message', 'edited_message', 'channel_post', 'edited_channel_post'):
        message = update_json.get(key)
        if message:
            return message
    return None


def _remember_quiet(data):
    if not USE_SHEETS or not private_chat(data):
        return
    try:
        sender = data.get("forwarded_by") or data.get("author") or ""
        remember_operator(build_store(), data.get("chat_id"), sender, sender)
    except Exception:
        logger.exception("Could not remember operator chat")


def handle_inbound_update(update_json, channel):
    message = update_message(update_json)
    if not message:
        return jsonify({'status': 'ignored'})
    data, source = inbound_payload(message, channel)
    if not data:
        return jsonify({'status': 'ignored'})
    chat_type = str(data.get('chat_type') or '')
    if chat_type in {'group', 'supergroup'}:
        return jsonify({'status': 'ignored'})
    if private_chat(data):
        allowlist_name = 'TELEGRAM_OPERATOR_CHAT_IDS' if channel == 'telegram' else 'BALE_OPERATOR_CHAT_IDS'
        allowlist = {part.strip() for part in (os.getenv(allowlist_name) or '').split(',') if part.strip()}
        if channel == 'telegram':
            allowlist.update(part.strip() for part in
                (os.getenv('TELEGRAM_ADDITIONAL_OPERATOR_CHAT_IDS') or '').split(',') if part.strip())
            queue = handoff_queue()
            if queue is not None:
                try:
                    allowlist.update(queue.approved_telegram_operator_ids())
                except Exception:
                    logger.exception('Could not read approved Telegram operator identities')
        if not allowlist or str(data.get('chat_id')) not in allowlist:
            if channel == 'telegram':
                if queue is None:
                    return jsonify({'status':'enrollment_storage_unavailable'}),503
                try:
                    stored=queue.record_pending_telegram_chat(data.get('chat_id'))
                    if not stored:
                        return jsonify({'status':'ignored'})
                except Exception:
                    logger.exception('Could not record pending Telegram account identity')
                    return jsonify({'status':'enrollment_storage_unavailable'}),503
            logger.warning("Ignoring private %s update from a non-allowlisted chat", channel)
            return jsonify({'status': 'ignored'})
    command = command_name(data.get("content") or "") if source == "direct" else None
    if command:
        _remember_quiet(data)
        store = None
        if USE_SHEETS:
            try:
                store = build_store()
            except Exception:
                logger.exception("Status store failed")
                store = None
        send_chat_text(data.get("chat_id"), reply_for_command(command, store))
        return jsonify({"status": "command", "command": command})
    _remember_quiet(data)
    if source == "direct" and USE_SHEETS:
        try:
            outcome = resolve_prompt(build_store(), data.get("chat_id"), data.get("content") or "")
        except Exception:
            logger.exception("Prompt reply failed")
            outcome = {"matched": False}
        if outcome.get("matched"):
            if outcome.get("understood"):
                threading.Thread(target=_publish_shop_quietly, daemon=True).start()
            send_chat_text(data.get("chat_id"), outcome.get("reply") or "")
            return jsonify({"status": "resolved" if outcome.get("understood") else "needs_answer"})
    success = enqueue_message(data)
    return jsonify({'status': 'success' if success else 'error', 'source': source}), 200 if success else 503


@app.before_request
def register_webhook_when_serving():
    ensure_telegram_webhook()


@app.teardown_request
def _release_memory_after_request(_exc):
    release_idle_memory()


@app.route('/webhook', methods=['POST'])
def telegram_webhook():
    """Handle Telegram webhook."""
    expected = (os.getenv('TELEGRAM_WEBHOOK_SECRET') or '').strip()
    sent = request.headers.get('X-Telegram-Bot-Api-Secret-Token') or ''
    if not expected or not hmac.compare_digest(sent, expected):
        return jsonify({'status': 'unauthorized'}), 401
    try:
        update_json = request.get_json()
        if not update_json:
            return jsonify({'status': 'error', 'message': 'No JSON data'}), 400
        return handle_inbound_update(update_json, 'telegram')
    except Exception as e:
        logger.error(f"Webhook error: {str(e)}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

@app.route('/bale/webhook', methods=['POST'])
def bale_webhook():
    """Handle Bale webhook."""
    expected = (os.getenv('BALE_WEBHOOK_SECRET') or '').strip()
    sent = request.headers.get('X-Bale-Webhook-Secret') or ''
    if not expected or not hmac.compare_digest(sent, expected):
        return jsonify({'status': 'unauthorized'}), 401
    try:
        update_json = request.get_json()
        if not update_json:
            return jsonify({'status': 'error', 'message': 'No JSON data'}), 400
        return handle_inbound_update(update_json, 'bale')
    except Exception as e:
        logger.error(f"Bale webhook error: {str(e)}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

def _shop_token_ok() -> bool:
    secret = os.getenv("SHOP_NOTIFY_SECRET") or ""
    sent = request.headers.get("X-Shop-Token") or ""
    if not secret or not sent:
        return False
    return hmac.compare_digest(sent, secret)


@app.route("/pipeline/publication-holds", methods=["GET", "POST"])
def publication_holds_route():
    token = os.getenv("REFERENCE_BRIDGE_TOKEN") or ""
    owners = {x.strip() for x in (os.getenv("REFERENCE_OWNER_IDS") or "").split(",") if x.strip()}
    queue = handoff_queue()
    if queue is None or len(token) < 32 or not owners:
        return jsonify({"ok": False, "error": "unconfigured"}), 503
    actor, auth_error = _owner_api_actor('owner:/pipeline/publication-holds', token, owners)
    if auth_error:
        return jsonify({"ok": False, "error": "unauthorized" if auth_error == 401 else "owner_required"}), auth_error
    if request.method == "GET":
        holds = queue.publication_holds()
        for hold in holds:
            hold['context'] = queue.publication_hold_context(str(hold.get('hold_id') or ''))
        result = {"ok": True, "holds": holds}
    else:
        body = request.get_json(silent=True)
        if isinstance(body, dict) and body.get('action') == 'resume_selected':
            if set(body) != {'action','holds'} or not isinstance(body.get('holds'),list):
                return jsonify({'ok':False,'error':'invalid_body'}),400
            try:
                with queue.publication_lock():
                    resumed=queue.resolve_publication_holds(body['holds'],actor_id=actor,
                        note='Owner reviewed the linked source messages and authorized one batch retry.')
            except JobConflict:
                return jsonify({'ok':False,'error':'publication_hold_changed'}),409
            except ValueError:
                return jsonify({'ok':False,'error':'invalid_body'}),400
            result={'ok':True,'resumed_count':len(resumed),'resumed_hold_ids':resumed,
                    'batch_resumed':True}
            response=jsonify(result)
            response.headers['Cache-Control']='no-store'
            return response
        if not isinstance(body, dict):
            return jsonify({"ok": False, "error": "invalid_body"}), 400
        if body.get('resume_batch') is False:
            required={'hold_id','expected_reason','note','resume_batch',
                      'expected_batch_state','expected_batch_error'}
            if (set(body)!=required or body.get('expected_reason')!='batch_in_progress'
                    or body.get('expected_batch_state')!='failed'
                    or body.get('expected_batch_error')!='JobConflict'
                    or not isinstance(body.get('hold_id'),str) or not body['hold_id']
                    or len(body['hold_id'])>100 or not isinstance(body.get('note'),str)
                    or not body['note'].strip() or len(body['note'])>1000):
                return jsonify({"ok":False,"error":"invalid_body"}),400
            try:
                with queue.publication_lock():
                    job=queue.get('batch:'+body['hold_id'])
                    if (not job or job.get('state')!=body['expected_batch_state']
                            or job.get('error')!=body['expected_batch_error']):
                        return jsonify({"ok":False,"error":"publication_hold_changed"}),409
                    context=queue.publication_hold_context(body['hold_id'])
                    sources=context.get('messages') or []
                    expected=int(context.get('expected_messages') or 0)
                    if not sources or expected!=len(sources):
                        return jsonify({"ok":False,"error":"batch_not_reconciled"}),409
                    from catalog.ai_confirmations import review_case_id
                    from catalog.sync import _ai_review_reason,_fresh_read,_records
                    store=build_store()
                    _,message_rows=_records(_fresh_read(store,'MessageData'))
                    _,offer_rows=_records(_fresh_read(store,'Offers'))
                    for source in sources:
                        message_id=str(source.get('message_id') or '')
                        channel=str(source.get('channel') or '')
                        matches=[row for row in message_rows
                            if str(row.get('ID') or '')==message_id
                            and str(row.get('Channel Username') or '')==channel
                            and str(row.get('Batch ID') or '')==body['hold_id']]
                        if len(matches)>1:
                            return jsonify({"ok":False,"error":"batch_not_reconciled"}),409
                        if not matches:
                            # Some older completed batches persisted their Offers rows and
                            # AI review cases but never wrote MessageData. Those offers are
                            # sufficient to prove that the source was handled only when
                            # every offer for that source is itself awaiting AI review.
                            # Never infer completion from a partial or publishable offer.
                            source_offers=[offer for offer in offer_rows
                                if str(offer.get('Message ID') or '')==message_id
                                and str(offer.get('Channel Username') or '')==channel
                                and str(offer.get('Batch ID') or '')==body['hold_id']]
                            if not source_offers or any(not _ai_review_reason(offer)
                                    or not str(offer.get('Offer ID') or '').strip()
                                    for offer in source_offers):
                                return jsonify({"ok":False,"error":"batch_not_reconciled"}),409
                            continue
                        row=matches[0]
                        ai_state=str(row.get('AI Status') or '').strip().lower()
                        if (ai_state not in {'needs_confirmation','ai_processed','no_product_candidate',
                                             'owner_rejected','not_product'}
                                or not str(row.get('AI Processed At') or '').strip()):
                            return jsonify({"ok":False,"error":"batch_not_reconciled"}),409
                        if ai_state=='needs_confirmation':
                            try:
                                case_ids=json.loads(str(row.get('AI Review Case IDs') or '[]'))
                            except (TypeError,ValueError):
                                case_ids=[]
                            offer_cases={review_case_id(str(offer.get('Offer ID') or ''))
                                for offer in offer_rows
                                if str(offer.get('Message ID') or '')==message_id
                                and str(offer.get('Channel Username') or '')==channel
                                and str(offer.get('Batch ID') or '')==body['hold_id']
                                and _ai_review_reason(offer)}
                            if (not isinstance(case_ids,list) or not case_ids
                                    or set(case_ids)!=offer_cases):
                                return jsonify({"ok":False,"error":"batch_not_reconciled"}),409
                    queue.resolve_publication_hold(body['hold_id'],
                        expected_reason=body['expected_reason'],actor_id=actor,
                        note=body['note'],resume_batch=False)
            except ValueError:
                return jsonify({"ok":False,"error":"publication_hold_changed"}),409
            except Exception:
                logger.exception('Publication hold reconciliation failed')
                return jsonify({"ok":False,"error":"publication_reconciliation_unavailable"}),503
            response=jsonify({"ok":True,"hold_id":body['hold_id'],
                "batch_resumed":False,"reconciled":True})
            response.headers['Cache-Control']='no-store'
            return response
        if (set(body) != {"hold_id", "expected_reason", "note", "resume_batch"}
                or body.get('resume_batch') is not True):
            return jsonify({"ok": False, "error": "invalid_body"}), 400
        try:
            with queue.publication_lock():
                queue.resolve_publication_hold(body['hold_id'], expected_reason=body['expected_reason'],
                                               actor_id=actor, note=body['note'], resume_batch=True)
        except ValueError:
            return jsonify({"ok": False, "error": "conflict_or_invalid"}), 409
        result = {"ok": True, "hold_id": body['hold_id'], "batch_resumed": True}
    response = jsonify(result)
    response.headers['Cache-Control'] = 'no-store'
    return response


@app.route('/pipeline/operations-dashboard', methods=['GET'])
def operations_dashboard_route():
    """Private, read-only summary of durable work that needs an operator."""
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners={value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue=handoff_queue()
    if queue is None or len(token)<32 or not owners:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    actor,auth_error=_owner_api_actor('owner:/pipeline/operations-dashboard',token,owners)
    if auth_error:
        return jsonify({'ok':False,'error':'unauthorized' if auth_error==401 else 'owner_required'}),auth_error
    try:
        limit=request.args.get('limit','100')
        if not limit.isdecimal():
            raise ValueError('invalid queue limit')
        summary=queue.operations_snapshot(limit=int(limit),cursor=request.args.get('cursor'))
    except ValueError:
        response=jsonify({'ok':False,'error':'invalid_queue_page'})
        response.status_code=400
        response.headers['Cache-Control']='no-store'
        return response
    summary['items_truncated']=summary['has_more']
    summary['publication_holds']=len(queue.publication_holds())
    summary['sheet_conflicts']=len(queue.sheet_sync_conflicts(state='open'))
    summary['worker']=queue.worker_status()
    summary['pending_telegram_chats']=queue.pending_telegram_chats(limit=50)
    backlog=queue.failed_backlog_snapshot()
    summary['failed_publication_ids']=backlog['publication_ids']
    summary['failed_batch_ids']=backlog['batch_ids']
    response=jsonify({'ok':True,'operations':summary})
    response.headers['Cache-Control']='no-store'
    return response


@app.route('/pipeline/telegram-operator-chats', methods=['POST','OPTIONS'])
def telegram_operator_chats_route():
    """Owner-only approval/rejection of a private Telegram sender identity."""
    if request.method == 'OPTIONS':
        return '', 204
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners={value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue=handoff_queue()
    if queue is None or len(token)<32 or not owners:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    actor,auth_error=_owner_api_actor('owner:/pipeline/telegram-operator-chats',token,owners)
    if auth_error:
        return jsonify({'ok':False,'error':'unauthorized' if auth_error==401 else 'owner_required'}),auth_error
    body=request.get_json(silent=True)
    required={'action','chat_id','expected_revision'}
    if (not isinstance(body,dict) or set(body)!=required
            or body.get('action') not in {'approve','reject'}
            or not isinstance(body.get('chat_id'),str)
            or not re.fullmatch(r'[0-9]{1,20}',body['chat_id'])
            or type(body.get('expected_revision')) is not int or body['expected_revision']<1):
        return jsonify({'ok':False,'error':'invalid_body'}),400
    try:
        result=queue.resolve_pending_telegram_chat(body['chat_id'],
            expected_revision=body['expected_revision'],approve=body['action']=='approve',actor_id=actor)
    except JobConflict:
        return jsonify({'ok':False,'error':'telegram_account_changed'}),409
    except ValueError:
        return jsonify({'ok':False,'error':'invalid_body'}),400
    except Exception:
        logger.exception('Telegram operator decision could not be persisted')
        return jsonify({'ok':False,'error':'telegram_operator_unavailable'}),503
    response=jsonify({'ok':True,**result})
    response.headers['Cache-Control']='no-store'
    return response


def _batch_sources_reconciled(store, queue, job_id):
    """Prove a failed batch already left complete, reviewable source rows in Sheets."""
    if not isinstance(job_id, str) or not job_id.startswith('batch:'):
        return False
    batch_id=job_id[len('batch:'):]
    context=queue.publication_hold_context(batch_id)
    sources=context.get('messages') or []
    try:
        expected=int(context.get('expected_messages') or 0)
    except (TypeError,ValueError,OverflowError):
        return False
    if (context.get('batch_state')!='failed' or not sources or expected!=len(sources)):
        return False
    from catalog.ai_confirmations import review_case_id
    from catalog.sync import _ai_review_reason,_fresh_read,_records
    _,message_rows=_records(_fresh_read(store,'MessageData'))
    _,offer_rows=_records(_fresh_read(store,'Offers'))
    for source in sources:
        message_id=str(source.get('message_id') or '')
        channel=str(source.get('channel') or '')
        matches=[row for row in message_rows
            if str(row.get('ID') or '')==message_id
            and str(row.get('Channel Username') or '')==channel
            and str(row.get('Batch ID') or '')==batch_id]
        if len(matches)>1:
            return False
        if not matches:
            source_offers=[offer for offer in offer_rows
                if str(offer.get('Message ID') or '')==message_id
                and str(offer.get('Channel Username') or '')==channel
                and str(offer.get('Batch ID') or '')==batch_id]
            if (not source_offers or any(not _ai_review_reason(offer)
                    or not str(offer.get('Offer ID') or '').strip() for offer in source_offers)):
                return False
            continue
        row=matches[0]
        ai_state=str(row.get('AI Status') or '').strip().lower()
        if (ai_state not in {'needs_confirmation','ai_processed','no_product_candidate',
                             'owner_rejected','not_product'}
                or not str(row.get('AI Processed At') or '').strip()):
            return False
        if ai_state=='needs_confirmation':
            try:
                case_ids=json.loads(str(row.get('AI Review Case IDs') or '[]'))
            except (TypeError,ValueError):
                case_ids=[]
            offer_cases={review_case_id(str(offer.get('Offer ID') or ''))
                for offer in offer_rows
                if str(offer.get('Message ID') or '')==message_id
                and str(offer.get('Channel Username') or '')==channel
                and str(offer.get('Batch ID') or '')==batch_id
                and _ai_review_reason(offer)}
            if (not isinstance(case_ids,list) or not case_ids
                    or any(not isinstance(case_id,str) or not case_id for case_id in case_ids)
                    or set(case_ids)!=offer_cases):
                return False
    return True


@app.route('/pipeline/reconcile-publication-backlog', methods=['POST','OPTIONS'])
def reconcile_publication_backlog_route():
    """One current Sheet→Woo sync closes superseded publish failures and source-verified batch failures."""
    if request.method=='OPTIONS':
        return '',204
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners={value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue=handoff_queue()
    if not USE_SHEETS or queue is None or len(token)<32 or not owners:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    actor,auth_error=_owner_api_actor('owner:/pipeline/operations-dashboard',token,owners)
    if auth_error:
        return jsonify({'ok':False,'error':'unauthorized' if auth_error==401 else 'owner_required'}),auth_error
    body=request.get_json(silent=True)
    if (not isinstance(body,dict) or set(body)!={'expected_publish_ids','expected_batch_ids'}
            or not isinstance(body.get('expected_publish_ids'),list)
            or not isinstance(body.get('expected_batch_ids'),list)):
        return jsonify({'ok':False,'error':'invalid_body'}),400
    publish_ids=body['expected_publish_ids']
    batch_ids=body['expected_batch_ids']
    if (len(publish_ids)>500 or len(batch_ids)>100
            or any(not isinstance(item,str) or not item.startswith('publish:') or len(item)>300 for item in publish_ids)
            or any(not isinstance(item,str) or not item.startswith('batch:') or len(item)>300 for item in batch_ids)
            or len(set(publish_ids))!=len(publish_ids) or len(set(batch_ids))!=len(batch_ids)
            or not publish_ids and not batch_ids):
        return jsonify({'ok':False,'error':'invalid_body'}),400
    try:
        with queue.publication_lock():
            if queue.publication_holds():
                return jsonify({'ok':False,'error':'publication_held_for_review'}),409
            snapshot=queue.failed_backlog_snapshot()
            if (not set(publish_ids).issubset(snapshot['publication_ids'])
                    or not set(batch_ids).issubset(snapshot['batch_ids'])):
                return jsonify({'ok':False,'error':'backlog_changed'}),409
            store=build_store()
            from catalog.sheet_projection import sheet_projection_digest
            digest_before=sheet_projection_digest(store)
            sync=publish_shop(store,max_rounds=12)
            try:
                images_left=int(sync.get('images_left') or 0)
            except (TypeError,ValueError,OverflowError):
                images_left=-1
            if sync.get('ok') is not True or sync.get('pushed') is not True or images_left!=0:
                sync_error = re.sub(r'[^A-Za-z0-9_. -]+','',str(sync.get('error') or sync.get('reason') or ''))[:100]
                sync_row = sync.get('row') if type(sync.get('row')) is int and sync.get('row') >= 0 else None
                return jsonify({'ok':False,'error':'catalog_sync_incomplete',
                    'sync_error':sync_error,'sync_row':sync_row,
                    'images_left':max(0,images_left) if images_left>=0 else None}),502
            digest_after=sheet_projection_digest(store)
            if digest_after!=digest_before:
                queue.enqueue_sheet_projection(digest_after)
                return jsonify({'ok':False,'error':'catalog_projection_changed',
                    'current_projection_queued':True}),409
            latest=queue.failed_backlog_snapshot()
            if (not set(publish_ids).issubset(latest['publication_ids'])
                    or not set(batch_ids).issubset(latest['batch_ids'])):
                return jsonify({'ok':False,'error':'backlog_changed'}),409
            queue.mark_sheet_projection_published(digest_after)
            reconciled_batches=[]
            for job_id in batch_ids:
                try:
                    if _batch_sources_reconciled(store,queue,job_id):
                        reconciled_batches.append(job_id)
                except Exception:
                    logger.exception('Failed batch source verification deferred for %s',job_id)
            reconciled=queue.reconcile_failed_backlog(
                publish_ids,reconciled_batches,actor_id=actor,sheet_digest=digest_after,sync_result=sync)
            reconciled_publications=reconciled['publication_ids']
            reconciled_batches=reconciled['batch_ids']
            unresolved_batches=sorted(set(batch_ids)-set(reconciled_batches))
        response=jsonify({
            'ok':True,'sheet_digest':digest_after,
            'catalog_sync':{key:sync.get(key) for key in ('count','created','updated','hidden','attached','images_left')},
            'failed_publications_reconciled':len(reconciled_publications),
            'failed_publication_ids':reconciled_publications,
            'batch_jobs_reconciled':len(reconciled_batches),
            'batch_job_ids_reconciled':reconciled_batches,
            'batch_job_ids_left_open':unresolved_batches,
        })
        response.headers['Cache-Control']='no-store'
        return response
    except JobConflict:
        return jsonify({'ok':False,'error':'backlog_changed'}),409
    except Exception as error:
        logger.exception('Failed publication backlog reconciliation failed')
        return jsonify({'ok':False,'error':'backlog_reconcile_unavailable',
            'cause':type(error).__name__}),503


@app.route('/pipeline/reconciliation', methods=['GET'])
def pipeline_reconciliation_route():
    """Owner-only, read-only join from intake receipts through Sheet and Woo visibility."""
    token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners = {value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue = handoff_queue()
    if not USE_SHEETS or queue is None or len(token) < 32 or not owners:
        return jsonify({'ok': False, 'error': 'unconfigured'}), 503
    actor, auth_error = _owner_api_actor('owner:/pipeline/operations-dashboard', token, owners)
    if auth_error:
        return jsonify({'ok': False, 'error': 'unauthorized' if auth_error == 401 else 'owner_required'}), auth_error
    try:
        from catalog.reconciliation import build_reconciliation_report
        limit = request.args.get('limit', default=200, type=int)
        report = build_reconciliation_report(build_store(), queue,
            site_url=os.getenv('SHOP_SITE_URL') or '', limit=max(1, min(limit or 200, 500)))
        response = jsonify(report)
        response.headers['Cache-Control'] = 'no-store'
        return response
    except Exception:
        logger.exception('Pipeline reconciliation unavailable')
        return jsonify({'ok': False, 'error': 'reconciliation_unavailable'}), 503


@app.route('/pipeline/product-status-snapshots', methods=['GET', 'POST'])
def product_status_snapshots_route():
    """Owner view and explicit capture for immutable product status history."""
    token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners = {value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue = handoff_queue()
    if queue is None or len(token) < 32 or not owners or not USE_SHEETS:
        return jsonify({'ok': False, 'error': 'unconfigured'}), 503
    actor, auth_error = _owner_api_actor('owner:/pipeline/product-status-snapshots', token, owners)
    if auth_error:
        return jsonify({'ok': False, 'error': 'unauthorized' if auth_error == 401 else 'owner_required'}), auth_error
    try:
        from datetime import datetime, timedelta, timezone
        from catalog.product_status import ProductStatusLedger, capture_from_store
        ledger = ProductStatusLedger(queue.path)
        if request.method == 'POST':
            body = request.get_json(silent=True)
            if not isinstance(body, dict) or body != {'action': 'capture'}:
                return jsonify({'ok': False, 'error': 'invalid_body'}), 400
            capture = capture_from_store(build_store(), queue.path, reason=f'owner_capture:{actor}')
            payload = ledger.list_snapshots(limit=30)
            for product in payload.get('products', []):
                product.pop('product_record', None)
            response = jsonify({'ok': True, 'capture': capture, **payload})
        else:
            requested_snapshot_id = request.args.get('snapshot_id', '').strip()
            if requested_snapshot_id and not re.fullmatch(r'[a-f0-9]{64}', requested_snapshot_id):
                return jsonify({'ok': False, 'error': 'invalid_snapshot_id'}), 400
            latest = ledger.latest_captured_at()
            due = ledger.latest_schema_version() < ledger.STATE_SCHEMA_VERSION
            if latest:
                try:
                    due = (due or datetime.fromisoformat(latest)
                           <= datetime.now(timezone.utc) - timedelta(hours=24))
                except ValueError:
                    due = True
            else:
                due = True
            if due:
                capture_from_store(build_store(), queue.path, reason='daily_status_checkpoint')
            payload = ledger.list_snapshots(limit=30, snapshot_id=requested_snapshot_id or None)
            if requested_snapshot_id and payload.get('selected_snapshot_id') != requested_snapshot_id:
                return jsonify({'ok': False, 'error': 'snapshot_not_found'}), 404
            for product in payload.get('products', []):
                product.pop('product_record', None)
            response = jsonify({'ok': True, **payload})
        response.headers['Cache-Control'] = 'no-store'
        return response
    except Exception:
        logger.exception('Product status snapshot unavailable')
        return jsonify({'ok': False, 'error': 'snapshot_unavailable'}), 503


@app.route('/pipeline/paid-orders', methods=['GET', 'POST'])
def paid_orders_route():
    token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners = {x.strip() for x in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if x.strip()}
    queue = handoff_queue()
    if queue is None or len(token) < 32 or not owners:
        return jsonify({'ok': False, 'error': 'unconfigured'}), 503
    sent = request.headers.get('X-Molavi-Reference-Token', '')
    actor = request.headers.get('X-Molavi-Actor', '')
    if not sent or not hmac.compare_digest(sent, token):
        return jsonify({'ok': False, 'error': 'unauthorized'}), 401
    if actor not in owners:
        return jsonify({'ok': False, 'error': 'owner_required'}), 403
    from catalog.order_fulfillment import PaidOrderPlanner
    from catalog.order_operations import OrderOperations
    from datetime import datetime
    from zoneinfo import ZoneInfo
    planner = PaidOrderPlanner(queue.path)
    operations = OrderOperations(queue.path)
    if request.method == 'GET':
        today = datetime.now(ZoneInfo('Asia/Tehran')).date().isoformat()
        plans = planner.list_plans()
        lots = planner.inventory.list_lots(today=today)
        try:
            products = shop_catalog(build_store()).get('products') or []
        except Exception:
            logger.exception('Could not load current sellable products for replacement suggestions')
            products = []
        from catalog.substitutions import suggest_owned_substitutes
        orders = []
        for plan in plans:
            order_id = str(plan.get('order_id') or '')
            source = planner.source_order(order_id) or {}
            workflow = operations.get(order_id)
            if plan.get('state') != 'released':
                workflow = operations.ensure(order_id, plan, today=today)
            excluded = {str(line.get('sku') or '') for line in source.get('lines', [])}
            suggestions = {}
            for line in plan.get('lines', []):
                if line.get('shortfall_units', 0) <= 0:
                    continue
                source_line = next((row for row in source.get('lines', [])
                                    if row.get('sku') == line.get('sku')), {})
                suggestions[line['sku']] = suggest_owned_substitutes(target_line=source_line,
                    shortfall=int(line['shortfall_units']), products=products, lots=lots,
                    excluded_skus=excluded)
            orders.append({**plan, 'workflow': workflow, 'substitution_candidates': suggestions,
                'order_total_toman': int(source.get('total') or 0),
                'original_line_totals_toman': {str(line.get('sku') or ''): int(line.get('total') or 0)
                    for line in source.get('lines', []) if line.get('sku')}})
        response = jsonify({'ok': True, 'orders': orders})
        response.headers['Cache-Control'] = 'no-store'
        return response
    body = request.get_json(silent=True)
    if (not isinstance(body, dict) or not isinstance(body.get('order_id'), str)
            or not body['order_id'].strip() or len(body['order_id']) > 40
            or not isinstance(body.get('note'), str) or not body['note'].strip() or len(body['note']) > 1000):
        return jsonify({'ok': False, 'error': 'invalid_body'}), 400
    try:
        source = planner.source_order(body['order_id'])
        if not source:
            return jsonify({'ok': False, 'error': 'order_not_found'}), 404
        today = datetime.now(ZoneInfo('Asia/Tehran')).date().isoformat()
        if 'supplier_terms' in body:
            if set(body) != {'order_id', 'supplier_terms', 'note'} or not isinstance(body['supplier_terms'], dict) \
                    or not 1 <= len(body['supplier_terms']) <= 20:
                return jsonify({'ok': False, 'error': 'invalid_body'}), 400
            allowed = {'supplier', 'units_per_pack', 'pack_price_toman', 'minimum_packs', 'pack_multiple', 'source_offer_id'}
            for sku, terms in body['supplier_terms'].items():
                if (not isinstance(sku, str) or not sku.strip() or len(sku) > 80 or not isinstance(terms, dict)
                        or set(terms) - allowed or not isinstance(terms.get('supplier'), str)
                        or not terms['supplier'].strip() or len(terms['supplier']) > 120
                        or not isinstance(terms.get('source_offer_id'), str) or not terms['source_offer_id'].strip()
                        or len(terms['source_offer_id']) > 120):
                    return jsonify({'ok': False, 'error': 'invalid_supplier_terms'}), 400
                for field in ('units_per_pack', 'pack_price_toman', 'minimum_packs', 'pack_multiple'):
                    value = terms.get(field, 1 if field in {'minimum_packs', 'pack_multiple'} else None)
                    maximum = 1_000_000 if field in {'units_per_pack', 'minimum_packs', 'pack_multiple'} else 1_000_000_000_000
                    if isinstance(value, bool) or not isinstance(value, int) or value <= 0 or value > maximum:
                        return jsonify({'ok': False, 'error': 'invalid_supplier_terms'}), 400
            plan = planner.plan(source, today=today, supplier_terms=body['supplier_terms'],
                                actor_id=actor, note=body['note'])
            workflow = operations.ensure(body['order_id'], plan, today=today)
        else:
            if set(body) != {'order_id', 'action', 'details', 'note'} or not isinstance(body.get('action'), str) \
                    or not isinstance(body.get('details'), dict):
                return jsonify({'ok': False, 'error': 'invalid_body'}), 400
            action = body['action']
            details = body['details']
            if action == 'propose_substitution':
                line_sku = str(details.get('sku') or '')
                candidate_sku = str(details.get('replacement_sku') or '')
                current_plan = planner.get(body['order_id']) or {}
                target = next((row for row in source.get('lines', []) if row.get('sku') == line_sku), None)
                case = operations.get(body['order_id']) or {}
                excluded = {str(line.get('sku') or '') for line in source.get('lines', [])}
                excluded.update(str(row.get('replacement_sku') or '') for row in case.get('resolutions', {}).values())
                from catalog.substitutions import suggest_owned_substitutes
                candidates = suggest_owned_substitutes(target_line=target or {},
                    shortfall=int(next((row.get('shortfall_units', 0) for row in current_plan.get('lines', [])
                                        if row.get('sku') == line_sku), 0)),
                    products=shop_catalog(build_store()).get('products') or [],
                    lots=planner.inventory.list_lots(today=today), excluded_skus=excluded)
                candidate = next((row for row in candidates if row['sku'] == candidate_sku), None)
                if not candidate or details.get('replacement_units') != candidate['required_units']:
                    return jsonify({'ok': False, 'error': 'candidate_unavailable'}), 409
                proposed_total = details.get('proposed_line_total_toman')
                floor = candidate['price_floor_toman'] * candidate['required_units']
                ceiling = candidate['price_ceiling_toman'] * candidate['required_units']
                if (isinstance(proposed_total, bool) or not isinstance(proposed_total, int)
                        or proposed_total <= 0 or floor <= 0 or ceiling < floor
                        or not floor <= proposed_total <= ceiling):
                    return jsonify({'ok': False, 'error': 'replacement_price_out_of_current_range'}), 409
            workflow = operations.apply(order=source, plan=planner.get(body['order_id']) or {},
                action=action, details=details, actor_id=actor, note=body['note'], today=today,
                inventory=planner.inventory)
            plan = planner.get(body['order_id']) or {}
    except ValueError as exc:
        return jsonify({'ok': False, 'error': 'conflict_or_invalid', 'message': str(exc)[:160]}), 409
    receipt = {'ok': True, 'order_id': body['order_id'], 'state': plan.get('state', 'needs_owner_review'),
               'workflow': workflow, 'plan': plan}
    if 'supplier_terms' in body:
        receipt['terms_hash'] = plan.get('terms_hash', '')
    else:
        receipt['action'] = body['action']
    response = jsonify(receipt)
    response.headers['Cache-Control'] = 'no-store'
    return response


@app.route('/pipeline/stock-lots', methods=['GET', 'POST'])
def stock_lots_route():
    token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners = {x.strip() for x in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if x.strip()}
    queue = handoff_queue()
    if queue is None or len(token) < 32 or not owners:
        return jsonify({'ok': False, 'error': 'unconfigured'}), 503
    sent = request.headers.get('X-Molavi-Reference-Token', '')
    actor = request.headers.get('X-Molavi-Actor', '')
    if not sent or not hmac.compare_digest(sent, token):
        return jsonify({'ok': False, 'error': 'unauthorized'}), 401
    if actor not in owners:
        return jsonify({'ok': False, 'error': 'owner_required'}), 403
    from catalog.inventory_lots import InventoryConflict, InventoryLots
    from datetime import date, datetime
    from zoneinfo import ZoneInfo
    today = datetime.now(ZoneInfo('Asia/Tehran')).date().isoformat()
    lots = InventoryLots(queue.path)
    if request.method == 'GET':
        fields = ('lot_id','sku','supplier','purchase_date','received_at','production_date','expiry_date','units',
                  'received_units','adjustment_units','unit_cost','available_units','committed_units','state',
                  'receipt_hash','revision')
        response = jsonify({'ok': True, 'lots': [{key: row[key] for key in fields}
                                                 for row in lots.list_lots(today=today)]})
        response.headers['Cache-Control'] = 'no-store'
        return response
    body = request.get_json(silent=True)
    required = {'lot_id','sku','supplier','purchase_date','received_at','production_date','expiry_date','units','unit_cost','note'}
    if not isinstance(body, dict) or set(body) != required:
        return jsonify({'ok': False, 'error': 'invalid_body'}), 400
    for key, limit in (('lot_id',120),('sku',80),('supplier',120)):
        if not isinstance(body[key],str) or not body[key].strip() or len(body[key])>limit:
            return jsonify({'ok':False,'error':'invalid_body'}),400
    if not isinstance(body['note'],str) or not body['note'].strip() or len(body['note'])>1000:
        return jsonify({'ok':False,'error':'invalid_body'}),400
    for key in ('purchase_date','received_at','production_date','expiry_date'):
        if not isinstance(body[key],str) or (body[key] and len(body[key])!=10):
            return jsonify({'ok':False,'error':'invalid_body'}),400
    if (isinstance(body['units'],bool) or not isinstance(body['units'],int)
            or not 1<=body['units']<=1_000_000 or isinstance(body['unit_cost'],bool)
            or not isinstance(body['unit_cost'],int) or not 1<=body['unit_cost']<=1_000_000_000_000):
        return jsonify({'ok':False,'error':'invalid_body'}),400
    try:
        date.fromisoformat(body['received_at'])
        if body['purchase_date']: date.fromisoformat(body['purchase_date'])
        if body['production_date']: date.fromisoformat(body['production_date'])
        if body['expiry_date']: date.fromisoformat(body['expiry_date'])
        state=lots.receive(lot_id=body['lot_id'].strip(),sku=body['sku'].strip(),
            supplier=body['supplier'].strip(),received_at=body['received_at'],
            purchase_date=body['purchase_date'] or None,
            production_date=body['production_date'] or None,expiry_date=body['expiry_date'] or None,
            units=body['units'],unit_cost=body['unit_cost'])
        lots.record_receipt(lot_id=body['lot_id'].strip(),actor_id=actor,note=body['note'],
                            created_at=datetime.now(ZoneInfo('Asia/Tehran')).isoformat())
    except InventoryConflict:
        return jsonify({'ok':False,'error':'lot_conflict'}),409
    except ValueError:
        return jsonify({'ok':False,'error':'invalid_lot_facts'}),400
    saved=lots.get_lot(body['lot_id'].strip())
    response=jsonify({'ok':True,'lot_id':body['lot_id'].strip(),'state':state,
                      'receipt_hash':saved['receipt_hash'] if saved else ''})
    response.headers['Cache-Control']='no-store'
    return response


@app.route('/pipeline/stock-lots/purchase-date', methods=['POST'])
def stock_lot_purchase_date_route():
    """Owner-audited correction for a historical lot missing its purchase date."""
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners={value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue=handoff_queue()
    if queue is None or len(token)<32 or not owners:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    sent=request.headers.get('X-Molavi-Reference-Token','')
    actor=request.headers.get('X-Molavi-Actor','')
    if not sent or not hmac.compare_digest(sent,token):
        return jsonify({'ok':False,'error':'unauthorized'}),401
    if actor not in owners:
        return jsonify({'ok':False,'error':'owner_required'}),403
    body=request.get_json(silent=True)
    if not isinstance(body,dict) or set(body)!={'lot_id','receipt_hash','purchase_date','note'}:
        return jsonify({'ok':False,'error':'invalid_body'}),400
    if (not isinstance(body['lot_id'],str) or not body['lot_id'].strip() or len(body['lot_id'])>120
            or not isinstance(body['receipt_hash'],str) or len(body['receipt_hash'])!=64
            or any(char not in '0123456789abcdef' for char in body['receipt_hash'])
            or not isinstance(body['purchase_date'],str) or not isinstance(body['note'],str)
            or not body['note'].strip() or len(body['note'])>1000):
        return jsonify({'ok':False,'error':'invalid_body'}),400
    from catalog.inventory_lots import InventoryConflict, InventoryLots
    from datetime import date, datetime
    from zoneinfo import ZoneInfo
    try:
        if date.fromisoformat(body['purchase_date']).isoformat()!=body['purchase_date']:
            return jsonify({'ok':False,'error':'invalid_purchase_date'}),400
        ledger=InventoryLots(queue.path)
        lot_id=body['lot_id'].strip()
        receipt_hash=ledger.add_missing_purchase_date(lot_id=lot_id,
            expected_receipt_hash=body['receipt_hash'],purchase_date=body['purchase_date'])
        ledger.record_receipt(lot_id=lot_id,actor_id=actor,note=body['note'],
            created_at=datetime.now(ZoneInfo('Asia/Tehran')).isoformat())
    except InventoryConflict:
        return jsonify({'ok':False,'error':'lot_changed'}),409
    except ValueError:
        return jsonify({'ok':False,'error':'invalid_purchase_date'}),400
    saved=ledger.get_lot(lot_id)
    response=jsonify({'ok':True,'lot_id':lot_id,'state':saved['state'],
        'receipt_hash':receipt_hash})
    response.headers['Cache-Control']='no-store'
    return response


@app.route('/pipeline/stock-lot-actions', methods=['GET','POST'])
def stock_lot_actions_route():
    """Append owner-audited stock count, damage/expiry, hold and release events."""
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners={value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue=handoff_queue()
    if queue is None or len(token)<32 or not owners:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    sent=request.headers.get('X-Molavi-Reference-Token','')
    actor=request.headers.get('X-Molavi-Actor','')
    if not sent or not hmac.compare_digest(sent,token):
        return jsonify({'ok':False,'error':'unauthorized'}),401
    if actor not in owners:
        return jsonify({'ok':False,'error':'owner_required'}),403
    from catalog.inventory_lots import InventoryConflict, InventoryLots
    from datetime import datetime
    from zoneinfo import ZoneInfo
    inventory=InventoryLots(queue.path)
    if request.method=='GET':
        response=jsonify({'ok':True,'events':inventory.recent_events(limit=100)})
        response.headers['Cache-Control']='no-store'
        return response
    body=request.get_json(silent=True)
    required={'lot_id','expected_revision','action','delta_units','reason','note'}
    if not isinstance(body,dict) or set(body)!=required:
        return jsonify({'ok':False,'error':'invalid_body'}),400
    if (not isinstance(body['lot_id'],str) or not body['lot_id'].strip() or len(body['lot_id'])>120
            or not isinstance(body['expected_revision'],str) or len(body['expected_revision'])!=64
            or any(char not in '0123456789abcdef' for char in body['expected_revision'])
            or not isinstance(body['action'],str) or not isinstance(body['reason'],str)
            or not isinstance(body['note'],str) or not body['note'].strip() or len(body['note'])>1000
            or isinstance(body['delta_units'],bool) or not isinstance(body['delta_units'],int)):
        return jsonify({'ok':False,'error':'invalid_body'}),400
    try:
        saved=inventory.apply_operator_event(lot_id=body['lot_id'].strip(),
            expected_revision=body['expected_revision'],actor_id=actor,action=body['action'],
            delta_units=body['delta_units'],reason=body['reason'],note=body['note'],
            created_at=datetime.now(ZoneInfo('Asia/Tehran')).isoformat(),
            today=datetime.now(ZoneInfo('Asia/Tehran')).date().isoformat())
    except InventoryConflict:
        return jsonify({'ok':False,'error':'lot_changed_or_action_conflict'}),409
    except ValueError:
        return jsonify({'ok':False,'error':'invalid_stock_action'}),400
    lot=inventory.get_lot(body['lot_id'].strip())
    response=jsonify({'ok':True,'lot_id':body['lot_id'].strip(),'state':saved['state'],
        'receipt_hash':lot['receipt_hash'],'event_id':saved['event_id'],
        'revision':saved['revision'],'previous_revision':saved['previous_revision'],
        'action':saved['action'],'units':saved['units'],'idempotent':saved['idempotent']})
    response.headers['Cache-Control']='no-store'
    return response


@app.route('/pipeline/clearance-proposals', methods=['GET', 'POST'])
def clearance_proposals_route():
    """Owner-only, auditable clearance calculations; never changes Woo prices."""
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners={value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue=handoff_queue()
    if queue is None or len(token)<32 or not owners:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    sent=request.headers.get('X-Molavi-Reference-Token','')
    actor=request.headers.get('X-Molavi-Actor','')
    if not sent or not hmac.compare_digest(sent,token):
        return jsonify({'ok':False,'error':'unauthorized'}),401
    if actor not in owners:
        return jsonify({'ok':False,'error':'owner_required'}),403
    from catalog.clearance_pricing import ClearanceLedger, propose_clearance_price
    from catalog.inventory_lots import InventoryLots
    from datetime import date, datetime
    from zoneinfo import ZoneInfo
    today=datetime.now(ZoneInfo('Asia/Tehran')).date().isoformat()
    inventory=InventoryLots(queue.path)
    ledger=ClearanceLedger(queue.path)
    if request.method=='GET':
        response=jsonify({'ok':True,'today':today,
            'near_cost_alert_threshold_toman':os.getenv('CLEARANCE_NEAR_COST_ALERT_TOMAN') or None,
            'lots':inventory.list_lots(today=today),'proposals':ledger.recent(limit=100)})
        response.headers['Cache-Control']='no-store'
        return response
    body=request.get_json(silent=True)
    required={'lot_id','receipt_hash','normal_price_toman','market_price_toman',
              'market_source','market_observed_date','note'}
    if not isinstance(body,dict) or set(body)!=required:
        return jsonify({'ok':False,'error':'invalid_body'}),400
    if (not isinstance(body['lot_id'],str) or not body['lot_id'].strip() or len(body['lot_id'])>120
            or not isinstance(body['receipt_hash'],str)
            or len(body['receipt_hash'])!=64
            or any(char not in '0123456789abcdef' for char in body['receipt_hash'])
            or not isinstance(body['market_source'],str) or not body['market_source'].strip()
            or len(body['market_source'])>200 or not isinstance(body['market_observed_date'],str)
            or not isinstance(body['note'],str) or not body['note'].strip() or len(body['note'])>1000):
        return jsonify({'ok':False,'error':'invalid_body'}),400
    for key in ('normal_price_toman','market_price_toman'):
        if (isinstance(body[key],bool) or not isinstance(body[key],int)
                or not 1<=body[key]<=1_000_000_000_000):
            return jsonify({'ok':False,'error':'invalid_price'}),400
    try:
        if date.fromisoformat(body['market_observed_date']).isoformat()!=body['market_observed_date']:
            raise ValueError('market date must use YYYY-MM-DD')
    except ValueError:
        return jsonify({'ok':False,'error':'invalid_market_date'}),400
    lot=inventory.get_lot(body['lot_id'].strip())
    if not lot or lot['receipt_hash']!=body['receipt_hash']:
        return jsonify({'ok':False,'error':'lot_changed'}),409
    current=next((item for item in inventory.list_lots(today=today)
                  if item['lot_id']==body['lot_id'].strip()),None)
    if not current or current['state']!='ready' or current['available_units']<=0:
        return jsonify({'ok':False,'error':'lot_not_eligible'}),409
    if not lot.get('purchase_date'):
        return jsonify({'ok':False,'error':'purchase_date_required'}),409
    threshold_raw=os.getenv('CLEARANCE_NEAR_COST_ALERT_TOMAN')
    threshold=None
    if threshold_raw not in (None,''):
        try:
            threshold=int(threshold_raw)
            if threshold<0 or threshold>1_000_000_000_000:
                raise ValueError
        except ValueError:
            return jsonify({'ok':False,'error':'invalid_clearance_threshold'}),503
    result=propose_clearance_price(purchase_date=lot['purchase_date'],
        production_date=lot['production_date'],expiry_date=lot['expiry_date'],today=today,
        purchase_cost=lot['unit_cost'],normal_price=body['normal_price_toman'],
        market_price=body['market_price_toman'],market_source=body['market_source'].strip(),
        market_observed_date=body['market_observed_date'],near_cost_threshold_toman=threshold)
    inputs={key:body[key] for key in required if key!='note'}
    inputs.update({'purchase_date':lot['purchase_date'],'production_date':lot['production_date'],
        'expiry_date':lot['expiry_date'],'purchase_cost_toman':lot['unit_cost'],
        'available_units':current['available_units']})
    try:
        saved=ledger.record(lot_id=lot['lot_id'],sku=lot['sku'],receipt_hash=lot['receipt_hash'],
            actor_id=actor,note=body['note'],inputs=inputs,result=result,
            created_at=datetime.now(ZoneInfo('Asia/Tehran')).isoformat())
    except ValueError:
        return jsonify({'ok':False,'error':'proposal_rejected'}),409
    response=jsonify({'ok':True,'proposal_id':saved['proposal_id'],'lot_id':lot['lot_id'],
        'state':lot['state'],'receipt_hash':lot['receipt_hash'],'result':saved['result']})
    response.headers['Cache-Control']='no-store'
    return response


@app.route('/pipeline/sheet-conflicts',methods=['GET','POST'])
def sheet_conflicts_route():
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners={x.strip() for x in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if x.strip()}
    queue=handoff_queue()
    if queue is None or not USE_SHEETS or not owners or len(token)<32:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    actor,auth_error=_owner_api_actor('owner:/pipeline/sheet-conflicts',token,owners)
    if auth_error:
        return jsonify({'ok':False,'error':'unauthorized' if auth_error==401 else 'owner_required'}),auth_error
    if request.method=='GET':
        conflicts=queue.sheet_sync_conflicts()
        for conflict in conflicts:
            conflict['server_owned']=conflict['field'] in SHEET_SERVER_OWNED_FIELDS
            conflict['sheet_preserved']=conflict['field'] in SHEET_PRESERVED_FIELDS
        result={'ok':True,'conflicts':conflicts}
    else:
        body=request.get_json(silent=True)
        if not isinstance(body,dict) or set(body)!={'conflict_id','choice','note'}:
            return jsonify({'ok':False,'error':'invalid_body'}),400
        conflict_id,choice,note=body.get('conflict_id'),body.get('choice'),body.get('note')
        if (not isinstance(conflict_id,str) or not conflict_id.startswith('sheet:') or len(conflict_id)>80 or
                choice not in ('server','sheet','preserve') or not isinstance(note,str) or len(note)>1000):
            return jsonify({'ok':False,'error':'invalid_body'}),400
        note=note.strip() or {
            'server':'Owner selected the current server-derived value under the server-first conflict policy.',
            'sheet':'Owner confirmed that the current Sheet cell was manually edited.',
            'preserve':'Preserve the existing canonical Sheet value for this immutable identity field.',
        }[choice]
        from catalog.sheet_reconcile import field_values_equal
        from catalog.sync import (_active_reference_snapshot_id, _apply_reference_item_review,
            _cell, _find_product_row_by_id, _fresh_read, _identity_fields, _offers_for_product,
            _product_from_offers, _product_row, _message_from_offer, _records,
            _product_restore_key, _restore_current_book,
            _read_sheet_if_present, _reference_item_review_ledger)
        try:
            with queue.publication_lock():
                conflict=queue.get_sheet_conflict(conflict_id)
                if not conflict:return jsonify({'ok':False,'error':'conflict_changed'}),409
                server_owned=conflict['field'] in SHEET_SERVER_OWNED_FIELDS
                sheet_preserved=conflict['field'] in SHEET_PRESERVED_FIELDS
                if server_owned and choice!='server':
                    return jsonify({'ok':False,'error':'field_read_only'}),409
                if sheet_preserved and choice!='preserve':
                    return jsonify({'ok':False,'error':'field_read_only'}),409
                if choice=='preserve' and not sheet_preserved:
                    return jsonify({'ok':False,'error':'field_read_only'}),409
                if conflict['field'] in SHEET_PROVENANCE_FIELDS and not (server_owned or sheet_preserved):
                    return jsonify({'ok':False,'error':'field_read_only'}),409
                if conflict['state']=='resolved' and conflict.get('choice')==choice:
                    # A browser retry after a lost response should acknowledge the
                    # original decision instead of presenting a false failure.
                    publish_job=None
                    if not server_owned and not sheet_preserved:
                        publish_job=queue.put('publish','sheet-conflict:'+conflict_id,
                            {'conflict_id':conflict_id,'product_id':conflict['product_id']})
                    response=jsonify({'ok':True,'conflict_id':conflict_id,'choice':choice,
                        'publish_job':publish_job,'server_owned':server_owned,
                        'sheet_preserved':sheet_preserved,'idempotent':True})
                    response.headers['Cache-Control']='no-store'
                    return response
                if conflict['state']!='open':return jsonify({'ok':False,'error':'conflict_changed'}),409
                store=build_store()
                products=_fresh_read(store,'Products')
                if not products:return jsonify({'ok':False,'error':'sheet_unavailable'}),503
                headers=[str(x) for x in products[0]]
                row_number=_find_product_row_by_id(products,headers,conflict['product_id'])
                if not row_number or conflict['field'] not in headers:return jsonify({'ok':False,'error':'row_changed'}),409
                row=products[row_number-1]
                product_record={str(header):row[index] if index<len(row) else ''
                                for index,header in enumerate(headers)}
                sheet_value=_cell(row,headers,conflict['field'])
                same_sheet=field_values_equal(conflict['field'],sheet_value,conflict['sheet'])
                already_server=field_values_equal(conflict['field'],sheet_value,conflict['server'])
                offers_rows=_read_sheet_if_present(store,'Offers')
                _,offer_records=_records(offers_rows)
                linked_offer_id=str(product_record.get('Latest Offer ID') or '')
                linked_offer=next((item for item in offer_records
                    if str(item.get('Offer ID') or '')==linked_offer_id),None) if linked_offer_id else None
                matching=_offers_for_product(offer_records,conflict['product_name'],conflict['channel'],
                    identity=_identity_fields(linked_offer or product_record))
                if not matching:
                    return jsonify({'ok':False,'error':'source_changed'}),409
                if str(matching[-1].get('Offer ID') or '')!=conflict['source_revision']:
                    # A stale conflict cannot be dismissed against its old offer. Rebuild
                    # this product from the latest source so reconciliation supersedes the
                    # obsolete card and exposes any still-current conflict on the next GET.
                    _restore_current_book(store, [_product_restore_key(matching[-1])])
                    return jsonify({'ok':False,'error':'source_changed','refreshed':True}),409
                server_product=_product_from_offers(matching)
                review_ledger=_reference_item_review_ledger()
                _apply_reference_item_review(server_product,matching[-1],review_ledger,
                    _active_reference_snapshot_id(review_ledger))
                current_server_row=_product_row(headers,server_product,_message_from_offer(matching[-1]))
                current_server=_cell(current_server_row,headers,conflict['field'])
                if conflict['field']=='Last Updated':
                    current_server=str(matching[-1].get('Import Timestamp') or '')
                if not sheet_preserved and not field_values_equal(conflict['field'],current_server,conflict['server']):
                    return jsonify({'ok':False,'error':'server_value_changed'}),409
                if choice=='sheet' and not same_sheet:return jsonify({'ok':False,'error':'sheet_value_changed'}),409
                if choice=='server' and not (same_sheet or already_server):
                    return jsonify({'ok':False,'error':'sheet_value_changed'}),409
                if choice=='preserve' and not same_sheet:return jsonify({'ok':False,'error':'sheet_value_changed'}),409
                # Re-read immediately before resolving/writing. Sheets has no CAS,
                # but this narrows the lost-edit window and refuses observed changes.
                prewrite=_fresh_read(store,'Products')
                pre_headers=[str(x) for x in prewrite[0]] if prewrite else []
                pre_row_number=_find_product_row_by_id(prewrite,pre_headers,conflict['product_id'])
                if not pre_row_number or conflict['field'] not in pre_headers:
                    return jsonify({'ok':False,'error':'row_changed'}),409
                pre_row=prewrite[pre_row_number-1]
                pre_value=_cell(pre_row,pre_headers,conflict['field'])
                pre_same_sheet=field_values_equal(conflict['field'],pre_value,conflict['sheet'])
                pre_already_server=field_values_equal(conflict['field'],pre_value,current_server)
                if choice=='sheet' and not pre_same_sheet:
                    return jsonify({'ok':False,'error':'sheet_value_changed'}),409
                if choice=='server' and not (pre_same_sheet or pre_already_server):
                    return jsonify({'ok':False,'error':'sheet_value_changed'}),409
                if choice=='preserve' and not pre_same_sheet:
                    return jsonify({'ok':False,'error':'sheet_value_changed'}),409
                if choice=='server' and not pre_already_server:
                    writer=getattr(store,'write_cells',None)
                    if not callable(writer):return jsonify({'ok':False,'error':'safe_cell_writer_unavailable'}),503
                    writer('Products',pre_row_number,pre_headers,{conflict['field']:current_server})
                    confirmed=_fresh_read(store,'Products')
                    check_headers=[str(x) for x in confirmed[0]] if confirmed else []
                    check_row_number=_find_product_row_by_id(confirmed,check_headers,conflict['product_id'])
                    checkrow=confirmed[check_row_number-1] if check_row_number else []
                    if not field_values_equal(conflict['field'],_cell(checkrow,check_headers,conflict['field']),current_server):
                        return jsonify({'ok':False,'error':'write_unconfirmed'}),409
                resolution=queue.resolve_sheet_conflict(conflict_id,choice=choice,actor_id=actor,note=note)
                publish_job=None
                if not server_owned and not sheet_preserved:
                    publish_job=queue.put('publish','sheet-conflict:'+conflict_id,
                                          {'conflict_id':conflict_id,'product_id':conflict['product_id']})
        except ValueError:
            return jsonify({'ok':False,'error':'conflict_or_invalid'}),409
        except Exception:
            logger.exception('Sheet conflict resolution failed')
            return jsonify({'ok':False,'error':'resolution_failed'}),503
        result={'ok':True,'conflict_id':conflict_id,'choice':resolution['choice'],
                'publish_job':publish_job,'server_owned':server_owned,'sheet_preserved':sheet_preserved}
    response=jsonify(result);response.headers['Cache-Control']='no-store';return response


@app.route('/pipeline/supplier-price-evidence', methods=['GET','POST'])
def supplier_price_evidence_route():
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners={x.strip() for x in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if x.strip()}
    ledger=_supplier_price_ledger()
    queue=handoff_queue()
    if not USE_SHEETS or ledger is None or queue is None or not owners or len(token)<32:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    actor,auth_error=_owner_api_actor('owner:/pipeline/supplier-price-evidence',token,owners)
    if auth_error:
        return jsonify({'ok':False,'error':'unauthorized' if auth_error==401 else 'owner_required'}),auth_error
    from datetime import datetime,timezone
    now=datetime.now(timezone.utc)
    try:
        store=build_store()
        offers=_current_supplier_price_evidence(store,ledger,now)
    except Exception:
        logger.exception('Supplier price evidence read failed')
        return jsonify({'ok':False,'error':'sheet_unavailable'}),503
    if request.method=='GET':
        result={'ok':True,'offers':[item for item in offers if item['state']!='current']}
    else:
        def reject(reason, status=400):
            # Log only the fixed validation code. Never log the owner note, product
            # text, request body, or authentication material.
            logger.info('Supplier price evidence request rejected: %s', reason)
            return jsonify({'ok':False,'error':reason}),status

        body=request.get_json(silent=True)
        if not isinstance(body,dict) or set(body)!={'product_id','expected_revision','note'}:
            return reject('invalid_body')
        product_id,revision,note=(body.get('product_id'),body.get('expected_revision'),body.get('note'))
        if not isinstance(product_id,str) or not product_id or len(product_id)>200:
            return reject('invalid_product_id')
        if not isinstance(revision,str) or not revision:
            return reject('price_source_missing',409)
        if len(revision)>200:
            return reject('invalid_source_revision')
        if not isinstance(note,str) or not note.strip() or len(note)>1000:
            return reject('invalid_note')
        offer=next((item for item in offers if item['product_id']==product_id),None)
        if offer is None or offer['source_revision']!=revision:
            return jsonify({'ok':False,'error':'offer_changed'}),409
        try:
            ledger.verify_manually(offer_id=product_id,source_revision=revision,actor_id=actor,
                                   note=note,now=now)
            publish_job=queue.put('publish','supplier-verification:'+product_id+':'+revision,
                                  {'product_id':product_id,'source_revision':revision})
        except ValueError:
            return jsonify({'ok':False,'error':'verification_rejected'}),409
        result={'ok':True,'product_id':product_id,'source_revision':revision,
                'verified_at':now.isoformat(),'publish_job':publish_job}
    response=jsonify(result)
    response.headers['Cache-Control']='no-store'
    return response


@app.route("/reference/decisions", methods=["GET", "POST"])
def reference_decisions_route():
    from reference_import.snapshots import ReferenceStore
    from reference_import.dashboard_api import DashboardAPI
    path = os.getenv("REFERENCE_STORE_PATH") or ""
    token = os.getenv("REFERENCE_BRIDGE_TOKEN") or ""
    owners = [x.strip() for x in (os.getenv("REFERENCE_OWNER_IDS") or "").split(",") if x.strip()]
    if not path or len(token) < 32 or not owners:
        return jsonify({"ok": False, "error": "unconfigured"}), 503
    actor, auth_error = _owner_api_actor('owner:/reference/decisions', token, owners)
    if auth_error:
        return jsonify({"ok": False, "error": "unauthorized" if auth_error == 401 else "owner_required"}), auth_error
    try:
        reference_store = ReferenceStore(path, owner_ids=owners)
        api = DashboardAPI(reference_store, bridge_token=token)
        code, result = api.handle(request.method,
            token=token,
            actor=actor,
            body=request.get_json(silent=True) if request.method == "POST" else None)
        if request.method == 'POST' and code == 200 and result.get('ok'):
            _wake_reference_waiters_if_eligible(handoff_queue(),reference_store)
    except Exception:
        logger.exception("Reference decision store unavailable")
        return jsonify({"ok": False, "error": "unavailable"}), 503
    response = jsonify(result)
    response.headers["Cache-Control"] = "no-store"
    return response, code


def _signed_browser_actor(authorization, bridge_token, owners, *, audience, scope):
    """Verify a short-lived WordPress-signed token for one owner API scope."""
    prefix = 'Bearer '
    if not isinstance(authorization, str) or not authorization.startswith(prefix):
        return None
    bearer = authorization[len(prefix):].strip()
    if not bearer or len(bearer) > 4096 or '.' not in bearer:
        return None
    encoded_payload, encoded_signature = bearer.split('.', 1)
    try:
        payload_bytes = base64.b64decode(
            encoded_payload + '=' * (-len(encoded_payload) % 4), altchars=b'-_', validate=True)
        signature = base64.b64decode(
            encoded_signature + '=' * (-len(encoded_signature) % 4), altchars=b'-_', validate=True)
        expected = hmac.new(bridge_token.encode('utf-8'), encoded_payload.encode('ascii'), 'sha256').digest()
        claims = json.loads(payload_bytes.decode('utf-8'))
    except (ValueError, UnicodeError, json.JSONDecodeError):
        return None
    if not hmac.compare_digest(signature, expected) or not isinstance(claims, dict):
        return None
    actor = claims.get('actor')
    issued_at = claims.get('iat')
    expires_at = claims.get('exp')
    now = int(time.time())
    if (claims.get('iss') != 'molavi-wordpress'
            or claims.get('aud') != audience
            or claims.get('scope') != scope
            or not isinstance(actor, str) or actor not in owners
            or not isinstance(issued_at, int) or isinstance(issued_at, bool)
            or not isinstance(expires_at, int) or isinstance(expires_at, bool)
            or issued_at > now + 30 or issued_at < now - 900
            or expires_at <= now or expires_at <= issued_at or expires_at > now + 900):
        return None
    return actor


def _reference_browser_actor(authorization, bridge_token, owners):
    """Verify the dedicated browser capability for reference snapshot actions."""
    return _signed_browser_actor(authorization, bridge_token, owners,
                                 audience='reference-snapshots', scope='reference:snapshots')


def _owner_api_actor(scope, bridge_token, owners):
    """Resolve a legacy server bridge or an owner-only, route-scoped browser token."""
    sent = request.headers.get('X-Molavi-Reference-Token', '')
    actor = request.headers.get('X-Molavi-Actor', '')
    if sent and hmac.compare_digest(sent, bridge_token):
        return actor, (None if actor in owners else 403)
    actor = _signed_browser_actor(request.headers.get('Authorization', ''), bridge_token, owners,
                                  audience='molavi-owner-workflows', scope=scope)
    return actor, (None if actor else 401)


@app.after_request
def reference_snapshots_browser_cors(response):
    browser_paths = {
        '/reference/snapshots',
        '/reference/decisions',
        '/reference/item-reviews',
        '/pipeline/operations-dashboard',
        '/pipeline/telegram-operator-chats',
        '/pipeline/reconcile-publication-backlog',
        '/pipeline/product-status-snapshots',
        '/pipeline/ai-confirmations',
        '/pipeline/publication-holds',
        '/pipeline/supplier-price-evidence',
        '/pipeline/sheet-conflicts',
    }
    if request.path not in browser_paths:
        return response
    response.headers.setdefault('Cache-Control', 'no-store')
    origin = request.headers.get('Origin', '')
    allowed_origins = {
        value.strip().rstrip('/')
        for value in (os.getenv('REFERENCE_BROWSER_ORIGINS')
                      or 'https://www.molavisale.ir,https://molavisale.ir').split(',')
        if value.strip()
    }
    if origin and origin in allowed_origins:
        response.headers['Access-Control-Allow-Origin'] = origin
        response.headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS'
        response.headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type'
        response.headers['Access-Control-Max-Age'] = '600'
        vary = [value.strip() for value in response.headers.get('Vary', '').split(',') if value.strip()]
        if 'Origin' not in vary:
            vary.append('Origin')
        response.headers['Vary'] = ', '.join(vary)
    return response


@app.route('/reference/snapshots', methods=['GET','POST','OPTIONS'])
def reference_snapshots_route():
    """Owner reviews Rezvan WooCommerce API candidates before ReferencePrices activation."""
    if request.method == 'OPTIONS':
        return '', 204
    from reference_import.decisions import DecisionConflict
    from reference_import.snapshots import ReferenceStore, has_verified_coverage
    path=os.getenv('REFERENCE_STORE_PATH') or ''
    token=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners=[value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()]
    if not path or len(token)<32 or not owners:
        return jsonify({'ok':False,'error':'unconfigured'}),503
    sent=request.headers.get('X-Molavi-Reference-Token','')
    actor=request.headers.get('X-Molavi-Actor','')
    if sent and hmac.compare_digest(sent,token):
        pass
    else:
        actor = _reference_browser_actor(request.headers.get('Authorization', ''), token, owners)
        if actor is None:
            return jsonify({'ok':False,'error':'unauthorized'}),401
    if actor not in owners:
        return jsonify({'ok':False,'error':'owner_required'}),403
    try:
        store=ReferenceStore(path,owner_ids=owners)
        if request.method=='GET':
            if request.args.get('include_rows')=='1':
                snapshot_id=request.args.get('snapshot_id','')
                if not re.fullmatch(r'[a-f0-9]{64}',snapshot_id):
                    return jsonify({'ok':False,'error':'invalid_fields'}),400
                candidate=store.snapshot(snapshot_id)
                if candidate is None:
                    return jsonify({'ok':False,'error':'snapshot_not_found'}),404
                response=jsonify({'ok':True,'snapshot_id':snapshot_id,
                    'row_count':candidate.get('row_count'),
                    'coverage_verified':has_verified_coverage(candidate),
                    'rows':store.snapshot_rows(snapshot_id)})
                response.headers['Cache-Control']='no-store'
                return response
            result=store.snapshots()
            try:
                from reference_import.refresh_scheduler import ReferenceRefreshScheduler
                interval=int(os.getenv('REFERENCE_REFRESH_INTERVAL_HOURS','24'))*3600
                result['refresh_state']=ReferenceRefreshScheduler(path,owner_ids=owners,
                    interval_seconds=interval).state()
            except Exception:
                result['refresh_state']=None
            response=jsonify({'ok':True,**result})
            response.headers['Cache-Control']='no-store'
            return response
        body=request.get_json(silent=True)
        if not isinstance(body,dict) or body.get('action') not in {'review','activate','refresh'}:
            return jsonify({'ok':False,'error':'invalid_body'}),400
        if body.get('action')=='refresh':
            if set(body)!={'action','expected_last_attempt_at'}:
                return jsonify({'ok':False,'error':'invalid_body'}),400
            expected_last=body.get('expected_last_attempt_at')
            if expected_last is not None and (not isinstance(expected_last,str) or len(expected_last)>80):
                return jsonify({'ok':False,'error':'invalid_fields'}),400
            from reference_import.refresh_scheduler import ReferenceRefreshScheduler
            interval=int(os.getenv('REFERENCE_REFRESH_INTERVAL_HOURS','24'))*3600
            scheduler=ReferenceRefreshScheduler(path,owner_ids=owners,interval_seconds=interval)
            current=scheduler.state() or {}
            if expected_last!=current.get('last_attempt_at'):
                return jsonify({'ok':False,'error':'refresh_state_changed'}),409
            outcome=scheduler.run_if_due(force=True)
            if outcome.get('state')=='cooldown':
                return jsonify({'ok':False,'error':'refresh_cooldown',
                    'retry_after_seconds':outcome.get('retry_after_seconds')}),429
            if outcome.get('state')=='not_due':
                return jsonify({'ok':False,'error':'refresh_not_retryable'}),409
            state=scheduler.state() or {}
            result={'ok':True,'action':'refresh','state':state.get('state'),
                'last_attempt_at':state.get('last_attempt_at'),'error_code':state.get('error_code')}
            if state.get('snapshot_id'):
                result['snapshot_id']=state['snapshot_id']
            if outcome.get('row_count'):
                result['row_count']=outcome['row_count']
            response=jsonify(result)
            response.headers['Cache-Control']='no-store'
            return response
        expected_keys=({'action','snapshot_id','note','expected_active_id'}
                       if body.get('action')=='review' else {'action','snapshot_id','expected_active_id'})
        if set(body)!=expected_keys:
            return jsonify({'ok':False,'error':'invalid_body'}),400
        snapshot_id=body.get('snapshot_id');expected=body.get('expected_active_id')
        if (not isinstance(snapshot_id,str) or len(snapshot_id)!=64
                or (expected is not None and (not isinstance(expected,str) or len(expected)!=64))):
            return jsonify({'ok':False,'error':'invalid_fields'}),400
        with store.activation_lock():
            current=store.active_snapshot()
            current_id=current['snapshot_id'] if current else None
            if expected!=current_id:
                return jsonify({'ok':False,'error':'active_snapshot_changed'}),409
            candidate=store.snapshot(snapshot_id)
            if candidate is None:
                return jsonify({'ok':False,'error':'snapshot_not_found'}),404
            from reference_import.snapshots import has_verified_coverage
            if not has_verified_coverage(candidate):
                return jsonify({'ok':False,'error':'snapshot_incomplete'}),409
            if body['action']=='review':
                note=body['note']
                if not isinstance(note,str) or not note.strip() or len(note)>1000:
                    return jsonify({'ok':False,'error':'review_note_required'}),400
                store.review(snapshot_id,actor_id=actor,note=note)
                result={'ok':True,'action':'review','snapshot_id':snapshot_id,
                        'reviewed_by':actor,'expected_active_id':expected}
            else:
                if not USE_SHEETS:
                    return jsonify({'ok':False,'error':'sheets_unavailable'}),503
                store._activate_locked(snapshot_id,actor_id=actor,expected_active_id=expected)
                active=store.active_snapshot()
                from reference_import.projection import project_reference_prices
                projection=project_reference_prices(build_store(),active)
                reference_update_summary = {'checked': 0, 'retained': 0, 'needs_review': 0}
                status_snapshot = None
                reconciliation_state = 'complete'
                try:
                    from reference_import.item_reviews import ItemReviewLedger
                    ItemReviewLedger(path, owner_ids=owners).inherit_mappings(
                        snapshot_id, max_price_change=0.05)
                    from catalog.sync import reconcile_reference_price_approvals
                    from catalog.product_status import capture_from_store
                    catalog_store = build_store()
                    reference_update_summary = reconcile_reference_price_approvals(
                        catalog_store, current, active, store, max_price_change=0.05)
                    queue = handoff_queue()
                    if queue is not None:
                        status_snapshot = capture_from_store(catalog_store, queue.path,
                            reason=f'reference_snapshot:{snapshot_id}')
                except Exception:
                    reconciliation_state = 'failed'
                    logger.exception('Could not reconcile product approvals for activated reference snapshot')
                result={'ok':True,'action':'activate','snapshot_id':snapshot_id,
                    'active_snapshot_id':active['snapshot_id'],'projection_state':'written',
                    'row_count':projection['row_count'],
                    'product_reconciliation_state':reconciliation_state,
                    'product_reference_updates':reference_update_summary,
                    'product_status_snapshot':status_snapshot}
                if reconciliation_state == 'complete':
                    _wake_reference_waiters_if_eligible(handoff_queue(),store)
        response=jsonify(result)
        response.headers['Cache-Control']='no-store'
        return response
    except DecisionConflict:
        return jsonify({'ok':False,'error':'snapshot_changed_or_unreviewed'}),409
    except Exception:
        logger.exception('Reference snapshot operation failed')
        return jsonify({'ok':False,'error':'snapshot_operation_unavailable'}),503


@app.route('/pipeline/ai-confirmations', methods=['GET', 'POST'])
def ai_confirmations_route():
    """Owner review of uncertain AI supplier extracts; source Offers are append-only."""
    from catalog.ai_confirmations import (
        AIConfirmationLedger, ConfirmationConflict, apply_decision, find_case,
        case_reextraction_inventory, reextraction_inventory, reextraction_message_key,
        review_cases, validate_choice,
    )
    from catalog.gemini import ai_extraction_configured
    token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners = {value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()}
    queue = handoff_queue()
    if not USE_SHEETS or queue is None or len(token) < 32 or not owners:
        return jsonify({'ok': False, 'error': 'unconfigured'}), 503
    actor, auth_error = _owner_api_actor('owner:/pipeline/ai-confirmations', token, owners)
    if auth_error:
        return jsonify({'ok': False, 'error': 'unauthorized' if auth_error == 401 else 'owner_required'}), auth_error
    ledger = AIConfirmationLedger(queue.path)
    try:
        store = build_store()
        if request.method == 'GET':
            reextract_action=request.args.get('reextract') or ''
            if reextract_action=='preview':
                inventory=reextraction_inventory(store,ledger)
                open_case_inventory=case_reextraction_inventory(store,ledger)
                pending_digest=hashlib.sha256(json.dumps(
                    sorted(item['message_key'] for item in inventory['pending_messages']),
                    separators=(',',':')).encode()).hexdigest()
                response=jsonify({'ok':True,'counts':inventory['counts'],
                    'ai_configured':ai_extraction_configured(),
                    'pending_digest':pending_digest,
                    'open_cases':{
                        'case_count':open_case_inventory['case_count'],
                        'message_count':open_case_inventory['message_count'],
                        'unmatched_case_count':len(open_case_inventory['unmatched_case_ids']),
                        'digest':open_case_inventory['digest'],
                    },
                    'active_run':queue.active_reextraction_run()})
                response.headers['Cache-Control']='no-store'
                return response
            if reextract_action=='run':
                run_id=request.args.get('run_id') or ''
                status=queue.reextraction_status(run_id)
                if not status:
                    return jsonify({'ok':False,'error':'unknown_reextraction_run'}),404
                outcomes={}
                products_found=0
                offers_written=0
                for item in status['items']:
                    result=item.get('result') or {}
                    outcome=str(result.get('status') or item.get('state') or 'unknown')
                    outcomes[outcome]=outcomes.get(outcome,0)+1
                    products_found+=int(result.get('products_found') or 0)
                    offers_written+=int(result.get('offers_written') or result.get('staged_offers') or 0)
                # Per-message snapshots overlap when identical products occur in several
                # source posts. Report the live queue once instead of summing those snapshots.
                open_cases_remaining=len(review_cases(store, ledger, limit=1000))
                publication=queue.get(f'publish:ai-reextraction:{run_id}')
                publication_state=str(publication.get('state') or '') if publication else ''
                response=jsonify({'ok':True,'run_id':status['run_id'],'state':status['state'],
                    'total':status['total'],'completed':status['completed'],'counts':status['counts'],
                    'scope':status.get('scope') or 'saved_messages',
                    'case_count':int(status.get('case_count') or 0),
                    'digest':status.get('digest') or '',
                    'outcomes':outcomes,'products_found':products_found,
                    'offers_written':offers_written,'open_cases_remaining':open_cases_remaining,
                    'catalog_publication':{
                        'state':publication_state,
                        'result':(publication.get('result') or {}) if publication else {},
                    }})
                response.headers['Cache-Control']='no-store'
                return response
            reference_snapshot = None
            reference_item_ledger = None
            reference_path = os.getenv('REFERENCE_STORE_PATH') or ''
            if reference_path and os.path.isfile(reference_path):
                try:
                    from datetime import datetime, timezone
                    from reference_import.snapshots import ReferenceStore
                    from reference_import.item_reviews import ItemReviewLedger
                    from reference_import.workflow import active_reference_use
                    reference_store = ReferenceStore(reference_path, owner_ids=sorted(owners))
                    candidate_snapshot = reference_store.active_snapshot()
                    reference_state = active_reference_use(reference_store, now=datetime.now(timezone.utc))
                    if (candidate_snapshot and reference_state.get('allow_automatic')
                            and candidate_snapshot.get('snapshot_id') == reference_state.get('snapshot_id')):
                        reference_snapshot = candidate_snapshot
                        reference_item_ledger = ItemReviewLedger(reference_path, owner_ids=sorted(owners))
                except Exception:
                    reference_snapshot = None
                    reference_item_ledger = None
            cases, recent = review_cases(store, ledger, include_history=True,
                                         reference_snapshot=reference_snapshot,
                                         reference_item_ledger=reference_item_ledger)
            response = jsonify({'ok': True, 'cases': cases, 'recent_decisions': recent,
                                'publication_held': bool(queue.publication_holds())})
            response.headers['Cache-Control'] = 'no-store'
            return response
        body = request.get_json(silent=True)
        if isinstance(body,dict) and body.get('action')=='start_open_case_reextraction':
            if set(body)!={'action','run_id','expected_case_count','expected_message_count','expected_digest'}:
                return jsonify({'ok':False,'error':'invalid_reextraction_request'}),400
            if not ai_extraction_configured():
                return jsonify({'ok':False,'error':'ai_extraction_unconfigured'}),409
            run_id=body.get('run_id')
            if not isinstance(run_id,str) or not re.fullmatch(r'[a-f0-9]{32}',run_id):
                return jsonify({'ok':False,'error':'invalid_reextraction_run'}),400
            expected_cases=body.get('expected_case_count')
            expected_messages=body.get('expected_message_count')
            expected_digest=body.get('expected_digest')
            if (isinstance(expected_cases,bool) or not isinstance(expected_cases,int) or expected_cases<1
                    or isinstance(expected_messages,bool) or not isinstance(expected_messages,int)
                    or expected_messages<1 or not isinstance(expected_digest,str)
                    or not re.fullmatch(r'[a-f0-9]{64}',expected_digest)):
                return jsonify({'ok':False,'error':'invalid_reextraction_count'}),400
            try:
                with queue.publication_lock():
                    if queue.active_reextraction_run():
                        return jsonify({'ok':False,'error':'reextraction_active'}),409
                    inventory=case_reextraction_inventory(store,ledger)
                    if (inventory['case_count']!=expected_cases
                            or inventory['message_count']!=expected_messages
                            or inventory['digest']!=expected_digest):
                        return jsonify({'ok':False,'error':'reextraction_preview_changed',
                            'current_case_count':inventory['case_count'],
                            'current_message_count':inventory['message_count']}),409
                    if inventory['unmatched_case_ids']:
                        return jsonify({'ok':False,'error':'reextraction_sources_unavailable',
                            'unmatched_case_count':len(inventory['unmatched_case_ids'])}),409
                    selected=[{**item,'reextraction_scope':'open_cases',
                        'reextraction_digest':inventory['digest']}
                        for item in inventory['messages']]
                    if not selected:
                        return jsonify({'ok':False,'error':'no_reextraction_messages'}),409
                    queued=queue.enqueue_reextraction(run_id,actor,selected)
            except JobConflict as exc:
                if str(exc).startswith('re-extraction run already active:'):
                    return jsonify({'ok':False,'error':'reextraction_active'}),409
                return jsonify({'ok':False,'error':'reextraction_conflict'}),409
            threading.Thread(target=_drain_reextraction_run,args=(queue,run_id),
                name='owner-open-case-reextract',daemon=True).start()
            response=jsonify({'ok':True,'run_id':run_id,'queued':queued,
                'case_count':expected_cases,'message_count':expected_messages,'scope':'open_cases'})
            response.headers['Cache-Control']='no-store'
            return response,202
        if isinstance(body,dict) and body.get('action')=='dismiss_pending_messages':
            if set(body)!={'action','expected_count','expected_digest','note'}:
                return jsonify({'ok':False,'error':'invalid_dismissal_request'}),400
            expected_count=body.get('expected_count')
            expected_digest=body.get('expected_digest')
            note=body.get('note')
            if (isinstance(expected_count,bool) or not isinstance(expected_count,int)
                    or expected_count<1 or not isinstance(expected_digest,str)
                    or not re.fullmatch(r'[a-f0-9]{64}',expected_digest)
                    or not isinstance(note,str) or len(note)>1000):
                return jsonify({'ok':False,'error':'invalid_dismissal_request'}),400
            with queue.publication_lock():
                if queue.active_reextraction_run():
                    return jsonify({'ok':False,'error':'reextraction_active'}),409
                inventory=reextraction_inventory(store,ledger)
                selected=inventory['pending_messages']
                current_digest=hashlib.sha256(json.dumps(
                    sorted(item['message_key'] for item in selected),
                    separators=(',',':')).encode()).hexdigest()
                if len(selected)!=expected_count or current_digest!=expected_digest:
                    return jsonify({'ok':False,'error':'reextraction_preview_changed',
                        'current_count':len(selected)}),409
                if not selected:
                    return jsonify({'ok':False,'error':'no_reextraction_messages'}),409
                ledger.dismiss_messages(selected,actor_id=actor,note=note)
            response=jsonify({'ok':True,'dismissed_count':len(selected),
                'dismissed_by_owner':inventory['counts'].get('dismissed_by_owner',0)+len(selected)})
            response.headers['Cache-Control']='no-store'
            return response
        if isinstance(body,dict) and body.get('action')=='dismiss_cases':
            if set(body)!={'action','cases','note'}:
                return jsonify({'ok':False,'error':'invalid_dismissal_request'}),400
            selected=body.get('cases')
            note=body.get('note')
            if (not isinstance(selected,list) or not selected or len(selected)>200
                    or not isinstance(note,str) or len(note)>1000):
                return jsonify({'ok':False,'error':'invalid_dismissal_request'}),400
            seen_case_ids=set()
            for item in selected:
                if not isinstance(item,dict) or set(item)!={'case_id','offer_id','signature','expected_decision_id'}:
                    return jsonify({'ok':False,'error':'invalid_dismissal_request'}),400
                case_id=item.get('case_id');offer_id=item.get('offer_id');signature=item.get('signature')
                expected=item.get('expected_decision_id')
                if (not isinstance(case_id,str) or not case_id or len(case_id)>100
                        or not isinstance(offer_id,str) or not offer_id or len(offer_id)>300
                        or not isinstance(signature,str) or not re.fullmatch(r'[a-f0-9]{64}',signature)
                        or (expected is not None and (not isinstance(expected,str)
                            or not re.fullmatch(r'[a-f0-9]{64}',expected)))
                        or case_id in seen_case_ids):
                    return jsonify({'ok':False,'error':'invalid_dismissal_request'}),400
                seen_case_ids.add(case_id)
            dismissed=[]
            failed=[]
            with queue.publication_lock():
                if queue.publication_holds():
                    return jsonify({'ok':False,'error':'publication_held_for_review'}),409
                available={case['case_id']:case for case in review_cases(store,ledger,limit=200)}
                cases_to_dismiss=[]
                for item in selected:
                    case=available.get(item['case_id'])
                    if (not case or case['offer_id']!=item['offer_id']
                            or case['signature']!=item['signature']
                            or case['previous_decision_id']!=item['expected_decision_id']):
                        return jsonify({'ok':False,'error':'stale_case'}),409
                    cases_to_dismiss.append(case)
                for case in cases_to_dismiss:
                    try:
                        decision=ledger.decide(case_id=case['case_id'],offer_id=case['offer_id'],
                            signature=case['signature'],choice='not_product',fields={},actor_id=actor,
                            note=note,expected_decision_id=case['previous_decision_id'])
                        apply_decision(store,ledger,case,decision)
                        publish_job=queue.put('publish','ai-confirmation:'+decision['decision_id'],
                            {'decision_id':decision['decision_id']})
                        try:
                            from catalog.sync import refresh_ai_message_status
                            refresh_ai_message_status(store,{'id':case['message_id'],
                                'batch_id':'' if case.get('historical_reextract') else case['batch_id'],
                                'channel':case['source_channel']})
                        except Exception:
                            logger.exception('Could not persist AI message dismissal status')
                        dismissed.append({'case_id':case['case_id'],'decision_id':decision['decision_id'],
                                          'publish_job':publish_job})
                    except Exception:
                        logger.exception('Bulk AI case dismissal failed for %s',case['case_id'])
                        failed.append({'case_id':case['case_id'],'error':'dismiss_failed'})
                if dismissed:
                    try:
                        from catalog.product_status import capture_from_store
                        capture_from_store(store, queue.path, reason='owner_ai_bulk_decision')
                    except Exception:
                        logger.exception('Could not snapshot product status after bulk AI decision')
            response=jsonify({'ok':True,'requested':len(selected),
                'dismissed_count':len(dismissed),'failed':failed,'dismissed':dismissed})
            response.headers['Cache-Control']='no-store'
            return response
        if isinstance(body,dict) and body.get('action')=='cancel_reextraction':
            if set(body)!={'action','run_id'}:
                return jsonify({'ok':False,'error':'invalid_reextraction_request'}),400
            try:
                cancelled=queue.cancel_reextraction(body['run_id'],actor)
            except (ValueError,JobConflict):
                return jsonify({'ok':False,'error':'reextraction_conflict'}),409
            response=jsonify({'ok':True,'run_id':body['run_id'],**cancelled})
            response.headers['Cache-Control']='no-store'
            return response
        if isinstance(body,dict) and body.get('action')=='start_reextraction':
            if set(body)!={'action','scope','expected_count'} or body.get('scope') not in {'pending','all'}:
                return jsonify({'ok':False,'error':'invalid_reextraction_request'}),400
            if not ai_extraction_configured():
                return jsonify({'ok':False,'error':'ai_extraction_unconfigured'}),409
            expected_count=body.get('expected_count')
            if isinstance(expected_count,bool) or not isinstance(expected_count,int) or expected_count<1:
                return jsonify({'ok':False,'error':'invalid_reextraction_count'}),400
            run_id=uuid.uuid4().hex
            try:
                with queue.publication_lock():
                    inventory=reextraction_inventory(store,ledger)
                    selected=(inventory['pending_messages'] if body['scope']=='pending'
                              else inventory['all_messages'])
                    if len(selected)!=expected_count:
                        return jsonify({'ok':False,'error':'reextraction_preview_changed',
                            'current_count':len(selected)}),409
                    if not selected:
                        return jsonify({'ok':False,'error':'no_reextraction_messages'}),409
                    items=[]
                    for message in selected:
                        content_sha256=hashlib.sha256(message['content'].encode()).hexdigest()
                        message_key=reextraction_message_key(message)
                        items.append({'message_id':message['message_id'],'batch_id':message['batch_id'],
                            'channel_username':message['channel_username'],'message_key':message_key,
                            'content_sha256':content_sha256,'timestamp':message['timestamp'],
                            'reextraction_scope':body['scope']})
                    queued=queue.enqueue_reextraction(run_id,actor,items)
            except JobConflict as exc:
                if str(exc).startswith('re-extraction run already active:'):
                    return jsonify({'ok':False,'error':'reextraction_active'}),409
                return jsonify({'ok':False,'error':'reextraction_conflict'}),409
            threading.Thread(target=_drain_reextraction_run,args=(queue,run_id),
                             name='owner-saved-message-reextract',daemon=True).start()
            response=jsonify({'ok':True,'run_id':run_id,'queued':queued,'scope':body['scope']})
            response.headers['Cache-Control']='no-store'
            return response,202
        try:
            validate_choice(body)
        except ValueError as exc:
            return jsonify({'ok': False, 'error': str(exc)}), 400
        with queue.publication_lock():
            if queue.publication_holds():
                return jsonify({'ok': False, 'error': 'publication_held_for_review'}), 409
            case = find_case(store, ledger, body['case_id'], body['offer_id'])
            if not case or case['signature'] != body['signature']:
                return jsonify({'ok': False, 'error': 'stale_case'}), 409
            if (body['choice']=='confirm' and case.get('historical_reextract')
                    and body.get('source_current_verified') is not True):
                return jsonify({'ok':False,'error':'reextraction_price_not_verified'}),400
            decision_note=body['note'].strip()
            if body['choice']=='confirm' and case.get('historical_reextract'):
                if body['fields']['stock_status'] == 'Unknown':
                    decision_note='قیمت با منبع فعلی بررسی شد؛ موجودی همچنان نامشخص است. '+decision_note
                else:
                    decision_note='قیمت و موجودی با منبع فعلی بررسی شد. '+decision_note
            decision = ledger.decide(case_id=case['case_id'], offer_id=case['offer_id'],
                signature=case['signature'], choice=body['choice'], fields=body['fields'],
                actor_id=actor, note=decision_note,
                expected_decision_id=body['expected_decision_id'])
            apply_decision(store, ledger, case, decision)
            try:
                from catalog.product_status import capture_from_store
                capture_from_store(store, queue.path, reason=f'owner_ai_decision:{decision["decision_id"]}')
            except Exception:
                logger.exception('Could not snapshot product status after AI decision')
            from catalog.sync import refresh_ai_message_status
            refresh_ai_message_status(store, {'id':case['message_id'],
                'batch_id':'' if case.get('historical_reextract') else case['batch_id'],
                'channel':case['source_channel']})
            reference_block = False
            if body['choice'] == 'confirm':
                source_reason = case['reason']
                reference_block = any(part.strip().lower().startswith('reference:')
                    for part in source_reason.split(';') if part.strip())
            projection_state = ('not_product' if body['choice'] == 'not_product'
                                else 'needs_review' if reference_block else 'imported')
            publish_job = queue.put('publish', 'ai-confirmation:' + decision['decision_id'],
                                    {'decision_id': decision['decision_id']})
        response = jsonify({'ok': True, 'case_id': case['case_id'], 'offer_id': case['offer_id'],
            'signature': case['signature'], 'choice': decision['choice'],
            'decision_id': decision['decision_id'], 'projection_state': projection_state,
            'publish_job': publish_job})
        response.headers['Cache-Control'] = 'no-store'
        return response
    except ConfirmationConflict:
        return jsonify({'ok': False, 'error': 'decision_conflict'}), 409
    except Exception:
        logger.exception('AI confirmation review failed')
        return jsonify({'ok': False, 'error': 'review_unavailable'}), 503


@app.route("/reference/item-reviews", methods=["GET", "POST"])
def reference_item_reviews_route():
    """Owner-only item correction; source Offers stay append-only."""
    from reference_import.snapshots import ReferenceStore
    from reference_import.item_reviews import (ItemReviewConflict, ItemReviewLedger,
        offer_attributes, product_signature, review_cases, review_case_id)
    from reference_import.matching import package_count, ranked_candidates
    from reference_import.importer import normalize_name
    from reference_import.workflow import active_reference_use
    from datetime import datetime, timezone
    path = os.getenv('REFERENCE_STORE_PATH') or ''
    token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners = [x.strip() for x in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if x.strip()]
    queue = handoff_queue()
    if not path or len(token) < 32 or not owners or queue is None or not USE_SHEETS:
        return jsonify({'ok': False, 'error': 'unconfigured'}), 503
    actor, auth_error = _owner_api_actor('owner:/reference/item-reviews', token, owners)
    if auth_error:
        return jsonify({'ok': False, 'error': 'unauthorized' if auth_error == 401 else 'owner_required'}), auth_error
    try:
        reference_store = ReferenceStore(path, owner_ids=owners)
        snapshot = reference_store.active_snapshot()
        eligibility = active_reference_use(reference_store, now=datetime.now(timezone.utc))
        if not eligibility['allow_automatic'] or not snapshot or snapshot['snapshot_id'] != eligibility['snapshot_id']:
            response = jsonify({'ok': True, 'reference_state': eligibility,
                                'cases': [], 'recent_decisions': []})
            response.headers['Cache-Control'] = 'no-store'
            return response
        sheet_store = build_store()
        ledger = ItemReviewLedger(path, owner_ids=owners)
        if request.method == 'GET':
            all_cases = review_cases(sheet_store, snapshot, ledger, include_decided=True,
                                     include_candidates=False)
            cases = [row for row in all_cases
                     if not row.get('decision') or row.get('projection_pending')]
            for row in cases:
                row['candidates'] = ranked_candidates(
                    {**(row.get('attributes') or {}),
                     'supplier_price': row.get('supplier_price') or ''},
                    snapshot.get('rows') or [], limit=12)
            recent_decisions = sorted((row for row in all_cases
                                       if row.get('decision') and not row.get('projection_pending')),
                key=lambda row: str((row.get('decision') or {}).get('created_at') or ''), reverse=True)[:50]
            response = jsonify({'ok': True, 'reference_state': eligibility,
                                'cases': cases, 'recent_decisions': recent_decisions})
            response.headers['Cache-Control'] = 'no-store'
            return response
        body = request.get_json(silent=True)
        if isinstance(body, dict) and body.get('action') == 'assign_ai_reference':
            required = {'action', 'case_id', 'offer_id', 'signature', 'snapshot_id',
                        'reference_id', 'price_role', 'expected_decision_id'}
            if set(body) != required:
                return jsonify({'ok': False, 'error': 'invalid_body'}), 400
            for field, maximum in (('case_id', 100), ('offer_id', 300), ('signature', 64),
                                   ('snapshot_id', 300), ('reference_id', 300), ('price_role', 20)):
                value = body[field]
                if not isinstance(value, str) or not value or len(value) > maximum:
                    return jsonify({'ok': False, 'error': 'invalid_fields'}), 400
            expected = body['expected_decision_id']
            if expected is not None and (not isinstance(expected, str)
                    or not re.fullmatch(r'[a-f0-9]{64}', expected)):
                return jsonify({'ok': False, 'error': 'invalid_fields'}), 400
            if not re.fullmatch(r'[a-f0-9]{64}', body['signature']):
                return jsonify({'ok': False, 'error': 'invalid_fields'}), 400
            if body['snapshot_id'] != snapshot['snapshot_id']:
                return jsonify({'ok': False, 'error': 'stale_snapshot'}), 409
            if body['price_role'] not in {'unit', 'package'}:
                return jsonify({'ok': False, 'error': 'invalid_price_role'}), 400
            reference_rows = [row for row in snapshot.get('rows') or []
                              if isinstance(row, (list, tuple)) and len(row) > 16]
            references = {str(row[16] or row[2]): row for row in reference_rows}
            selected_reference = references.get(body['reference_id'])
            if not selected_reference:
                return jsonify({'ok': False, 'error': 'unknown_reference'}), 400

            from catalog.ai_confirmations import AIConfirmationLedger, find_case
            from catalog.sync import _read_sheet_if_present, _records
            ai_ledger = AIConfirmationLedger(queue.path)
            with queue.publication_lock():
                with reference_store.activation_lock():
                    current_snapshot = reference_store.active_snapshot()
                    current_eligibility = active_reference_use(
                        reference_store, now=datetime.now(timezone.utc))
                    if (not current_eligibility.get('allow_automatic') or not current_snapshot
                            or current_snapshot.get('snapshot_id') != body['snapshot_id']):
                        return jsonify({'ok': False, 'error': 'stale_snapshot'}), 409
                    current_case = find_case(sheet_store, ai_ledger, body['case_id'], body['offer_id'])
                    if (not current_case or current_case.get('signature') != body['signature']
                            or (ai_ledger.latest(body['case_id']) or {}).get('signature') == body['signature']):
                        return jsonify({'ok': False, 'error': 'stale_case'}), 409
                    _, offers = _records(_read_sheet_if_present(sheet_store, 'Offers'))
                    source_offer = next((row for row in offers
                                         if str(row.get('Offer ID') or '') == body['offer_id']), None)
                    if not source_offer:
                        return jsonify({'ok': False, 'error': 'stale_case'}), 409
                    reference_case_id = review_case_id(body['offer_id'], body['snapshot_id'])
                    latest_reference_decision = ledger.get(reference_case_id)
                    latest_reference_id = (latest_reference_decision or {}).get('decision_id')
                    if expected != latest_reference_id:
                        return jsonify({'ok': False, 'error': 'review_conflict'}), 409
                    attributes = offer_attributes(source_offer)
                    if body['price_role'] == 'package' and not (
                            package_count(attributes) or float(selected_reference[6] or 0)):
                        return jsonify({'ok': False, 'error': 'package_count_required'}), 400
                    decision = ledger.decide(
                        case_id=reference_case_id, offer_id=body['offer_id'],
                        snapshot_id=body['snapshot_id'], signature=product_signature(attributes),
                        choice='match', reference_id=body['reference_id'],
                        price_role=body['price_role'], actor_id=actor,
                        note='Owner assigned a reference from the in-progress extraction card.',
                        expected_decision_id=expected)
            response = jsonify({'ok': True, 'case_id': body['case_id'],
                'offer_id': body['offer_id'], 'snapshot_id': body['snapshot_id'],
                'choice': 'match', 'reference_id': body['reference_id'],
                'reference_name': str(selected_reference[0] or body['reference_id']),
                'decision_id': decision['decision_id'], 'price_role': decision['price_role'],
                'projection_state': 'awaiting_ai_confirmation', 'publish_job': None})
            response.headers['Cache-Control'] = 'no-store'
            return response
        required = {'case_id', 'offer_id', 'snapshot_id', 'choice', 'reference_id', 'price_role',
                    'note', 'expected_decision_id'}
        if not isinstance(body, dict) or set(body) != required:
            return jsonify({'ok': False, 'error': 'invalid_body'}), 400
        for field, maximum in (('case_id', 300), ('offer_id', 300), ('snapshot_id', 300), ('choice', 20),
                               ('reference_id', 300), ('price_role', 20), ('note', 1000)):
            value = body[field]
            if not isinstance(value, str) or len(value) > maximum:
                return jsonify({'ok': False, 'error': 'invalid_fields'}), 400
        expected = body['expected_decision_id']
        if expected is not None and (not isinstance(expected, str) or len(expected) != 64):
            return jsonify({'ok': False, 'error': 'invalid_fields'}), 400
        if body['snapshot_id'] != snapshot['snapshot_id']:
            return jsonify({'ok': False, 'error': 'stale_snapshot'}), 409
        current = next((row for row in review_cases(sheet_store, snapshot, ledger, include_decided=True,
                                                     include_candidates=False)
                        if row['case_id'] == body['case_id']), None)
        if (not current or current['offer_id'] != body['offer_id']
                or current['snapshot_id'] != body['snapshot_id']):
            return jsonify({'ok': False, 'error': 'stale_case'}), 409
        if body['choice'] == 'match':
            reference_rows=[row for row in snapshot.get('rows') or []
                if isinstance(row,(list,tuple)) and len(row)>16]
            references = {str(row[16] or row[2]): row for row in reference_rows}
            manual_value=body['reference_id'].strip()
            selected_reference = references.get(manual_value)
            if manual_value and not selected_reference:
                normalized=normalize_name(manual_value)
                name_matches={str(row[16] or row[2]):row for row in reference_rows
                    if normalized and normalized in {normalize_name(str(row[0] or '')),
                                                      normalize_name(str(row[1] or ''))}}
                if len(name_matches)>1:
                    return jsonify({'ok':False,'error':'ambiguous_reference_name'}),400
                if len(name_matches)==1:
                    body['reference_id'],selected_reference=next(iter(name_matches.items()))
            if not body['reference_id'] or not selected_reference:
                return jsonify({'ok': False, 'error': 'unknown_reference'}), 400
            if body['price_role'] not in {'unit', 'package'}:
                return jsonify({'ok': False, 'error': 'invalid_price_role'}), 400
            if body['price_role'] == 'package' and not (
                    package_count(current['attributes']) or float(selected_reference[6] or 0)):
                return jsonify({'ok': False, 'error': 'package_count_required'}), 400
        elif body['choice'] == 'no_match':
            if body['reference_id'] or body['price_role'] != 'unresolved':
                return jsonify({'ok': False, 'error': 'invalid_no_match'}), 400
        else:
            return jsonify({'ok': False, 'error': 'invalid_choice'}), 400
        decision = ledger.decide(case_id=current['case_id'], offer_id=current['offer_id'],
            snapshot_id=current['snapshot_id'], signature=current['signature'],
            choice=body['choice'], reference_id=body['reference_id'],
            price_role=body['price_role'], actor_id=actor, note=body['note'],
            expected_decision_id=expected)
        projection_state = 'held_for_review'
        publish_job = None
        with queue.publication_lock():
            from catalog.sync import (_product_restore_key, _restore_current_book,
                                      _read_sheet_if_present, _records)
            from catalog.sheet_projection import sheet_projection_digest
            restore_record={'Product Name':current['product_name'],
                'Channel Username':current['channel'], **(current.get('attributes') or {})}
            _restore_current_book(sheet_store, [_product_restore_key(restore_record)])
            _, products = _records(_read_sheet_if_present(sheet_store, 'Products'))
            projected = next((row for row in products
                if str(row.get('Latest Offer ID') or '') == current['offer_id']), None)
            if not projected or str(projected.get('Reference Decision ID') or '') != decision['decision_id']:
                result = {'ok': True, 'case_id': current['case_id'], 'offer_id': current['offer_id'],
                          'snapshot_id': current['snapshot_id'], 'choice': decision['choice'],
                          'decision_id': decision['decision_id'], 'price_role': decision['price_role'],
                          'projection_state': 'pending_verification', 'publish_job': None}
                response = jsonify(result)
                response.headers['Cache-Control'] = 'no-store'
                return response, 202
            if body['choice'] == 'match':
                projection_state = str(projected.get('Status') or '').lower()
                if projection_state == 'needs_review':
                    projection_state = 'needs_review'
            else:
                if str(projected.get('Status') or '').lower() != 'needs_review':
                    result = {'ok': True, 'case_id': current['case_id'], 'offer_id': current['offer_id'],
                              'snapshot_id': current['snapshot_id'], 'choice': decision['choice'],
                              'decision_id': decision['decision_id'], 'price_role': decision['price_role'],
                              'projection_state': 'pending_verification', 'publish_job': None}
                    response = jsonify(result)
                    response.headers['Cache-Control'] = 'no-store'
                    return response, 202
            publish_job = queue.enqueue_sheet_projection(sheet_projection_digest(sheet_store))
            try:
                from catalog.product_status import capture_from_store
                capture_from_store(sheet_store, queue.path, reason=f'owner_reference_decision:{decision["decision_id"]}')
            except Exception:
                logger.exception('Could not snapshot product status after reference decision')
        result = {'ok': True, 'case_id': current['case_id'], 'offer_id': current['offer_id'],
                  'snapshot_id': current['snapshot_id'], 'choice': decision['choice'],
                  'decision_id': decision['decision_id'], 'price_role': decision['price_role'],
                  'projection_state': projection_state, 'publish_job': publish_job}
        response = jsonify(result)
        response.headers['Cache-Control'] = 'no-store'
        return response
    except ItemReviewConflict:
        return jsonify({'ok': False, 'error': 'review_conflict'}), 409
    except Exception:
        logger.exception('Reference item review unavailable')
        return jsonify({'ok': False, 'error': 'review_unavailable'}), 503


@app.route("/shop/order", methods=["POST"])
def shop_order():
    """Write one shop order into the sheet and, when asked, tell the bot chats."""
    if not _shop_token_ok():
        return jsonify({"status": "error", "message": "unauthorized"}), 401
    body = request.get_json(silent=True) or {}
    order = body.get("order") if isinstance(body, dict) else None
    if not isinstance(order, dict) or not str(order.get("id") or "").strip():
        return jsonify({"status": "error", "message": "missing order"}), 400
    queue = handoff_queue()
    if queue is not None:
        import hashlib, json
        key = hashlib.sha256(json.dumps({"order": order, "notify": bool(body.get("notify"))}, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
        receipt = queue.put("order", key, {"order": order, "notify": bool(body.get("notify"))})
        try:
            run_durable_handoff(queue, receipt)
        except Exception:
            logger.exception("Order receipt saved; delivery pending")
        saved = queue.get(receipt)
        written = saved["state"] == "done"
        return jsonify({"status": "success" if written else "pending", "written": written,
                        "order_id": str(order["id"]), "receipt_id": receipt,
                        "notified": 0, "notifications": "queued" if body.get("notify") else "not_requested"}), 200 if written else 503
    if not USE_SHEETS:
        return jsonify({"status": "error", "written": False, "message": "server sheet writer unavailable"}), 503
    result = {"written": False, "notified": 0}
    store = None
    if USE_SHEETS:
        try:
            store = build_store()
            result.update(upsert_shop_order(store, order))
        except Exception:
            logger.exception("Order sheet write failed")
            return jsonify({"status": "error", "message": "sheet write failed"}), 502
    if body.get("notify"):
        notice = format_order_notice(order)
        chats = operator_chat_ids(store, queue.approved_telegram_operator_ids() if queue is not None else ())
        for chat_id in chats:
            send_chat_text(chat_id, notice)
        result["notified"] = len(chats)
    return jsonify({"status": "success", **result})


def guarded_publish_shop(store, *, queue=None, **kwargs):
    queue = queue or handoff_queue()
    if queue is None:
        if os.getenv("REFERENCE_STORE_PATH"):
            return {"ok": False, "pushed": False, "reason": "durable_pipeline_required"}
        return publish_shop(store, **kwargs)
    with queue.publication_lock():
        if queue.publication_holds():
            return {"ok": False, "pushed": False, "reason": "publication_held_for_review"}
        return publish_shop(store, **kwargs)


def _publish_shop_quietly(max_rounds: int = 40) -> None:
    """Push the sheet to the shop. Failures stay in the log and do not roll back the batch."""
    if not USE_SHEETS:
        return
    try:
        guarded_publish_shop(build_store(), max_rounds=max_rounds)
    except Exception:
        logger.exception("Shop publish failed")


@app.route("/shop/publish", methods=["POST"])
def shop_publish():
    """Publish the current sheet to the shop. Used to catch up products already on the sheet."""
    if not _shop_token_ok():
        return jsonify({"ok": False, "error": "unauthorized"}), 401
    if not USE_SHEETS:
        return jsonify({"ok": False, "error": "sheets"}), 503
    try:
        body = request.get_json(silent=True) or {}
        images_only = bool(body.get("images_only")) if isinstance(body, dict) else False
        result = guarded_publish_shop(build_store(), max_rounds=12, images_only=images_only)
    except Exception:
        logger.exception("Shop publish failed")
        return jsonify({"ok": False, "error": "publish failed"}), 502
    return jsonify(result)


@app.route("/media/file/<file_id>", methods=["GET"])
def media_file(file_id):
    """Stream a product photo that was sent to the bot. The token stays on this host."""
    file_id = safe_file_id(file_id)
    if not file_id or not BOT_TOKEN:
        return jsonify({"status": "error"}), 404
    try:
        info = requests.get(
            f"https://api.telegram.org/bot{BOT_TOKEN}/getFile",
            params={"file_id": file_id},
            timeout=15,
        )
        body = info.json()
        path = ((body.get("result") or {}).get("file_path") or "") if info.status_code == 200 else ""
        if not path or ".." in path or path.startswith("/"):
            return jsonify({"status": "error"}), 404
        upstream = requests.get(
            f"https://api.telegram.org/file/bot{BOT_TOKEN}/{path}",
            timeout=20,
        )
        if upstream.status_code != 200 or len(upstream.content) > 8_000_000:
            return jsonify({"status": "error"}), 404
        kind = "image/jpeg"
        lower = path.lower()
        if lower.endswith(".png"):
            kind = "image/png"
        elif lower.endswith(".webp"):
            kind = "image/webp"
        return Response(upstream.content, mimetype=kind, headers={"Cache-Control": "public, max-age=86400"})
    except Exception:
        logger.exception("Product photo failed")
        return jsonify({"status": "error"}), 404


@app.route('/desk/refresh', methods=['POST'])
def refresh_desk():
    """Rebuild the buying list after a person edits Items in the sheet."""
    if not USE_SHEETS:
        return jsonify({'status': 'error', 'message': 'Service account sheet writer is not configured'}), 503
    try:
        counts = refresh_buying_desk(build_store())
        return jsonify({'status': 'success', **counts})
    except Exception as exc:
        logger.exception("Desk refresh failed")
        return jsonify({'status': 'error', 'message': exc.__class__.__name__}), 500


def shop_source(preview: bool) -> dict:
    """The live sheet when it is configured, otherwise the preview catalog."""
    if USE_SHEETS:
        store=build_store()
        catalog=shop_catalog(store)
        return _apply_supplier_price_gate(catalog,store)
    if preview or os.getenv("SHOP_PREVIEW") == "1":
        settings = default_settings()
        return {
            "settings": settings.as_dict(),
            "products": catalog_from_records(preview_records(), settings),
        }
    settings = default_settings()
    return {"settings": settings.as_dict(), "products": []}


def _supplier_price_ledger():
    path=os.getenv('PIPELINE_DB_PATH') or ''
    if not path:
        return None
    from catalog.supplier_freshness import PriceFreshness
    return PriceFreshness(path)


def _current_supplier_price_evidence(store,ledger,now=None):
    from datetime import datetime,timezone
    from catalog.supplier_freshness import current_supplier_offers
    return current_supplier_offers(store,ledger,now=now or datetime.now(timezone.utc))


def _apply_supplier_price_gate(catalog,store):
    # Do not expose a row with a broken source pointer in a public listing or quote.
    # The publication path already held these rows, but this separate response used
    # to check only freshness and Sheet conflicts.
    try:
        from catalog.source_integrity import product_offer_link_issues
        from catalog.sync import _fresh_read, _records
        product_rows=_fresh_read(store,'Products')
        offer_rows=_fresh_read(store,'Offers')
        product_records=_records(product_rows)[1]
        offer_records=_records(offer_rows)[1]
        source_issues=product_offer_link_issues(product_records,offer_records)
    except Exception:
        logger.exception('Shop source integrity unavailable; failing public catalog closed')
        catalog['products']=[]
        return catalog
    broken_source_ids={str(issue.get('product_id') or '') for issue in source_issues
                       if issue.get('product_id')}
    ledger=_supplier_price_ledger()
    queue=handoff_queue()
    eligible=None
    if ledger is not None:
        from datetime import datetime,timezone
        evidence=_current_supplier_price_evidence(store,ledger,datetime.now(timezone.utc))
        eligible={row['product_id'] for row in evidence if row['state'] in {'current','warning'}}
    conflicts={row['product_id'] for row in queue.sheet_sync_conflicts()} if queue is not None else set()
    catalog['products']=[product for product in catalog.get('products',[])
                         if (eligible is None or str(product.get('product_id') or '') in eligible)
                         and str(product.get('product_id') or '') not in conflicts
                         and str(product.get('product_id') or '') not in broken_source_ids]
    return catalog


@app.route("/api/shop", methods=["GET"])
def shop_api():
    preview = request.args.get("preview") == "1"
    try:
        catalog = shop_source(preview)
        settings = catalog.get("settings") or {}
        products = []
        for product in catalog.get("products") or []:
            regular = int(product.get("regular") or 0)
            price = int(product.get("ceiling") or regular)
            products.append({
                "product_id": product.get("product_id") or "",
                "name": product.get("name") or "",
                "category": product.get("category") or "",
                "regular_price": regular,
                "price": price,
                "current_discount": (regular - price) / regular if regular > 0 else 0.0,
                "stock_status": product.get("stock_status") or "Unknown",
            })
        return jsonify({
            "products": products,
            "settings": {"discount_target": int(settings.get("unlock_target") or 0)},
        })
    except Exception:
        logger.exception("Shop catalog failed")
        return jsonify({"status": "error", "products": [], "settings": {}}), 500


@app.route("/api/cart", methods=["POST"])
def cart_api():
    """Reprice every line and the aggregate discount for the cart as it stands."""
    preview = request.args.get("preview") == "1"
    body = request.get_json(silent=True) or {}
    lines = body.get("lines") if isinstance(body, dict) else None
    if not isinstance(lines, list):
        return jsonify({"status": "error", "message": "lines must be a list"}), 400
    try:
        catalog = shop_source(preview)
        unlock = int(catalog["settings"]["unlock_target"])
        quote = resolve_quote(catalog["products"], lines, unlock)
        quote["lines"] = [{
            "product_id": line.get("product_id") or "",
            "name": line.get("name") or "",
            "qty": int(line.get("qty") or 0),
            "regular_price": int(line.get("regular") or 0),
            "price": int(line.get("unit_price") or 0),
            "discount": float(line.get("discount_rate") or 0),
            "discount_amount": int(line.get("discount_amount") or 0),
        } for line in quote.get("lines") or []]
        return jsonify(quote)
    except Exception:
        logger.exception("Cart quote failed")
        return jsonify({"status": "error"}), 500


@app.route('/health', methods=['GET'])
def health_check():
    """Simple health check endpoint for Railway."""
    return jsonify({
        'status': 'healthy',
        'service': 'telegram-webhook',
        'timestamp': int(time.time()),
        'version': APP_VERSION
    })

@app.route('/health/detailed', methods=['GET'])
def detailed_health_check():
    """Detailed health check for debugging."""
    try:
        # Test Google Apps Script connectivity
        gas_status = 'unknown'
        if WEB_APP_URL:
            try:
                # Use a very short timeout for the detailed check
                test_response = requests.get(f'{WEB_APP_URL}?action=health', timeout=2)
                gas_status = 'connected' if test_response.status_code == 200 else 'error'
            except:
                gas_status = 'unreachable'

        return jsonify({
            'status': 'healthy',
            'service': 'telegram-webhook',
            'sheet_writer': 'service_account' if USE_SHEETS else 'apps_script',
            'google_apps_script': gas_status,
            'web_app_url': bool(WEB_APP_URL),
            'version': APP_VERSION,
            'timestamp': int(time.time())
        })
    except Exception as e:
        return jsonify({
            'status': 'unhealthy',
            'error': str(e),
            'timestamp': int(time.time())
        }), 500


# Register during worker startup so Telegram has switched to the secret header
# before it sends the first update to the new deployment.
if (os.getenv('PIPELINE_HANDOFF_WORKER') != '1' and BOT_TOKEN
        and (os.getenv('TELEGRAM_WEBHOOK_SECRET') or '').strip() and webhook_url_from_env()):
    ensure_telegram_webhook()

if __name__ == '__main__':
    # Production mode for Railway/Render
    port = int(os.environ.get('PORT', 8080))

    # Railway specific configuration
    is_production = os.environ.get('RAILWAY_ENVIRONMENT') == 'production'
    is_development = not is_production

    logger.info(f"Starting Flask app in {'production' if is_production else 'development'} mode")
    logger.info(f"Listening on port {port}")

    if is_production:
        # Production configuration for Railway
        app.run(
            host='0.0.0.0',
            port=port,
            debug=False,
            threaded=True,
            use_reloader=False
        )
    else:
        # Development configuration
        app.run(
            host='0.0.0.0',
            port=port,
            debug=True,
            threaded=True
        )
