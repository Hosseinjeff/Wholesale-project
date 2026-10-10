#!/usr/bin/env python3
"""
Flask app for Railway/Render deployment with Telegram webhooks.
"""

from flask import Flask, request, jsonify
import logging
import requests
import os
import time
import json
import asyncio
import threading
from urllib.parse import urlparse
from utils.logger import setup_logger
from telegram import Update
from telegram.ext import Application
from catalog.sync import build_store, finalize_batch, refresh_buying_desk, resolve_prompt, sheets_configured

app = Flask(__name__)

# Global variables - read from Railway environment variables
WEB_APP_URL = os.getenv('GOOGLE_WEB_APP_URL')
BOT_TOKEN = os.getenv('TELEGRAM_BOT_TOKEN')
BALE_BOT_TOKEN = os.getenv('BALE_BOT_TOKEN')
APP_VERSION = "2026-10-09-webhook-reply"
setup_logger(logging.INFO)
logger = logging.getLogger(__name__)

# Initialize Telegram Application (Optional - only if needed for advanced extraction)
tg_application = None
if BOT_TOKEN:
    try:
        tg_application = Application.builder().token(BOT_TOKEN).build()
        logger.info("Telegram Application initialized successfully")
    except Exception as e:
        logger.error(f"Failed to initialize Telegram Application: {e}")
        # Don't crash the whole app if TG init fails
        tg_application = None

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
    try:
        response = requests.post(
            f"https://api.telegram.org/bot{BOT_TOKEN}/setWebhook",
            json={
                "url": url,
                "allowed_updates": ["message", "edited_message", "channel_post", "edited_channel_post"],
                "drop_pending_updates": False,
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
        return response.status_code == 200 and bool(body.get("ok"))
    except Exception:
        logger.exception("setWebhook failed")
        return False


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


ensure_telegram_webhook()


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
            batch_manager = BatchManager(WEB_APP_URL)

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
    return {
        'id': str(message.get('message_id', '')),
        'content': message.get('text') or message.get('caption') or '',
        'has_media': bool(message.get('photo') or message.get('document') or message.get('video')),
        'media_type': get_media_type(message),
        'forwarded_by': message.get('from', {}).get('username', 'Unknown'),
        'forwarded_at': message.get('date', ''),
        'channel': channel,
        'channel_username': 'DirectMessage',
        'author': message.get('from', {}).get('username', 'User'),
        'timestamp': message.get('date'),
        'url': '',
        'chat_id': (message.get('chat') or {}).get('id'),
    }


def inbound_payload(message, channel):
    """Build the sheet payload for one Telegram or Bale update."""
    if channel == 'telegram' and 'forward_origin' in message:
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

    if not data.get('chat_id'):
        data['chat_id'] = (message.get('chat') or {}).get('id')
    return data, source


def update_message(update_json):
    for key in ('message', 'edited_message', 'channel_post', 'edited_channel_post'):
        message = update_json.get(key)
        if message:
            return message
    return None


def handle_inbound_update(update_json, channel):
    message = update_message(update_json)
    if not message:
        return jsonify({'status': 'ignored'})
    data, source = inbound_payload(message, channel)
    if not data:
        return jsonify({'status': 'ignored'})
    if source == "direct" and USE_SHEETS:
        try:
            outcome = resolve_prompt(build_store(), data.get("chat_id"), data.get("content") or "")
        except Exception:
            logger.exception("Prompt reply failed")
            outcome = {"matched": False}
        if outcome.get("matched"):
            send_chat_text(data.get("chat_id"), outcome.get("reply") or "")
            return jsonify({"status": "resolved" if outcome.get("understood") else "needs_answer"})
    success = enqueue_message(data)
    return jsonify({'status': 'success' if success else 'error', 'source': source})


@app.before_request
def register_webhook_when_serving():
    ensure_telegram_webhook()


@app.route('/webhook', methods=['POST'])
def telegram_webhook():
    """Handle Telegram webhook."""
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
    try:
        update_json = request.get_json()
        if not update_json:
            return jsonify({'status': 'error', 'message': 'No JSON data'}), 400
        return handle_inbound_update(update_json, 'bale')
    except Exception as e:
        logger.error(f"Bale webhook error: {str(e)}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

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
