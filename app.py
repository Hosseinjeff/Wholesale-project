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
from utils.logger import setup_logger
from telegram import Update
from telegram.ext import Application

app = Flask(__name__)

# Global variables - read from Railway environment variables
WEB_APP_URL = os.getenv('GOOGLE_WEB_APP_URL')
BOT_TOKEN = os.getenv('TELEGRAM_BOT_TOKEN')
APP_VERSION = "2026-02-16-batching-v1"
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
if WEB_APP_URL:
    logger.info(f"Web app URL: {WEB_APP_URL[:50]}...")
else:
    logger.error("GOOGLE_WEB_APP_URL environment variable not found!")

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
                self._finalize_locked()
            else:
                self.start_timer()

    def flush_and_finalize(self):
        with self.lock:
            self._finalize_locked()

    def _finalize_locked(self):
        if not self.buffer or not self.batch_id:
            return
        expected = len(self.buffer)
        payload = {
            "mode": "batch_ingest",
            "batch_id": self.batch_id,
            "messages": list(self.buffer),
            "transmission_complete": True,
            "expected_count": expected
        }
        try:
            resp = requests.post(self.web_app_url, json=payload, timeout=60, headers={'Content-Type': 'application/json'})
            logger.info(f"Finalize ack: {resp.status_code} {resp.text[:200]}")
            try:
                data = resp.json()
            except Exception:
                data = {}
            status = data.get("status")
            ack = data.get("ack")
            processed = data.get("processed_messages")
            rollback = data.get("rollback")
            for chat_id in list(self.chat_ids):
                try:
                    if not BOT_TOKEN:
                        continue
                    text = f"Batch {self.batch_id}: status={status}, ack={ack}, processed={processed}, rollback={rollback}"
                    url = f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage"
                    requests.post(url, json={"chat_id": chat_id, "text": text}, timeout=10)
                except Exception as e:
                    logger.error(f"Error sending Telegram status: {e}")
        except Exception as e:
            logger.error(f"Batch finalize error: {e}")
        finally:
            self.buffer = []
            self.batch_id = None
            self.chat_ids = set()
            if self.timer:
                try:
                    self.timer.cancel()
                except:
                    pass
            self.timer = None

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

def send_to_google_apps_script(data):
    """Send data to Google Apps Script."""
    if not WEB_APP_URL:
        logger.error("GOOGLE_WEB_APP_URL not configured")
        return False

    try:
        logger.info(f"Sending data to Google Apps Script: {WEB_APP_URL}")
        # Prefer fast ingestion by default: only write MessageData on webhook
        if 'processing_mode' not in data:
            data['processing_mode'] = 'message_only'
        logger.info(f"Data: {data}")

        global batch_manager
        if batch_manager is None:
            batch_manager = BatchManager(WEB_APP_URL)

        batch_manager.add_message(data)
        return True
    except Exception as e:
        logger.error(f"Error sending to Google Apps Script: {str(e)}")
        return False

@app.route('/webhook', methods=['POST'])
def telegram_webhook():
    """Handle Telegram webhook."""
    try:
        update_json = request.get_json()
        
        if not update_json:
            return jsonify({'status': 'error', 'message': 'No JSON data'}), 400

        # Handle Telegram Update object
        if 'message' in update_json:
            message = update_json['message']
            
            # 1. Process as forwarded message (original logic)
            if 'forward_origin' in message:
                forward_data = extract_forward_data(message)
                success = send_to_google_apps_script(forward_data)
                if success:
                    return jsonify({'status': 'success', 'source': 'forward'})
            
            # 2. Process as direct message (if text exists but not forwarded)
            elif 'text' in message:
                # Minimal data for direct messages
                direct_data = {
                    'id': str(message.get('message_id')),
                    'content': message.get('text'),
                    'channel_username': 'DirectMessage',
                    'author': message.get('from', {}).get('username', 'User'),
                    'timestamp': message.get('date'),
                    'channel': 'telegram'
                }
                success = send_to_google_apps_script(direct_data)
                if success:
                    return jsonify({'status': 'success', 'source': 'direct'})

        return jsonify({'status': 'ignored'})

    except Exception as e:
        logger.error(f"Webhook error: {str(e)}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

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
