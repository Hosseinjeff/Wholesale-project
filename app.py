#!/usr/bin/env python3
"""
Flask app for Railway/Render deployment with Telegram webhooks.
"""

from flask import Flask, request, jsonify
import logging
import requests
from utils.config import load_config
from utils.logger import setup_logger

app = Flask(__name__)

# Global variables
config = load_config()
WEB_APP_URL = config.get('GOOGLE_WEB_APP_URL')
setup_logger(logging.INFO)
logger = logging.getLogger(__name__)

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
        'url': ''
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
        response = requests.post(
            WEB_APP_URL,
            json=data,
            timeout=10,
            headers={'Content-Type': 'application/json'}
        )
        return response.status_code == 200
    except Exception as e:
        logger.error(f"Error sending to Google Apps Script: {str(e)}")
        return False

@app.route('/webhook', methods=['POST'])
def telegram_webhook():
    """Handle Telegram webhook."""
    try:
        update = request.get_json()

        if not update or 'message' not in update:
            return jsonify({'status': 'ignored'})

        message = update['message']

        # Check if it's a forwarded message
        if 'forward_origin' not in message:
            return jsonify({'status': 'ignored'})

        # Extract data
        forward_data = extract_forward_data(message)

        # Send to Google Apps Script
        success = send_to_google_apps_script(forward_data)

        if success:
            logger.info(f"Imported message from {forward_data['channel_username']}")
            return jsonify({'status': 'success'})
        else:
            logger.error("Failed to import to Google Apps Script")
            return jsonify({'status': 'error'}), 500

    except Exception as e:
        logger.error(f"Webhook error: {str(e)}")
        return jsonify({'status': 'error'}), 500

@app.route('/', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'running',
        'web_app_url': bool(WEB_APP_URL),
        'version': '1.0'
    })

if __name__ == '__main__':
    app.run(debug=True, host='0.0.0.0', port=8080)
