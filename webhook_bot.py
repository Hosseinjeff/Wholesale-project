#!/usr/bin/env python3
"""
Telegram webhook bot that sends data directly to Google Apps Script.
No continuous polling - uses Telegram's webhook system.
"""

import logging
import requests
from telegram import Update, Bot
from telegram.ext import Application, MessageHandler, filters, ContextTypes
from utils.config import load_config
from utils.logger import setup_logger

class WebhookBot:
    """Telegram bot using webhooks for desktop-independent operation."""

    def __init__(self):
        self.config = load_config()
        self.bot_token = self.config.get('TELEGRAM_BOT_TOKEN')
        self.web_app_url = self.config.get('GOOGLE_WEB_APP_URL')

        if not self.bot_token:
            raise ValueError("TELEGRAM_BOT_TOKEN not found in .env")

        if not self.web_app_url:
            raise ValueError("GOOGLE_WEB_APP_URL not found in .env")

        setup_logger(logging.INFO)
        self.logger = logging.getLogger(__name__)

        # Initialize bot
        self.application = Application.builder().token(self.bot_token).build()

        # Add message handler for ALL messages (not just forwarded)
        self.application.add_handler(MessageHandler(
            filters.TEXT & (~filters.COMMAND),
            self.handle_forwarded_message
        ))

        self.logger.info("Webhook bot initialized")

    async def handle_forwarded_message(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Handle messages and send to Google Apps Script."""
        try:
            message = update.message
            if not message:
                return

            # Extract message data (handles both forwarded and direct)
            message_data = self.extract_message_data(message)

            # Send to Google Apps Script
            success = self.send_to_google_apps_script(message_data)

            if success:
                await message.reply_text(f"✅ Message imported! (ID: {message_data['id']})")
                self.logger.info(f"Imported message from {message_data['channel_username']}")
            else:
                await message.reply_text("❌ Failed to import message")
                self.logger.error("Failed to import message")

        except Exception as e:
            self.logger.error(f"Error handling message: {str(e)}")
            try:
                await message.reply_text(f"❌ Error: {str(e)}")
            except:
                pass

    def extract_message_data(self, message):
        """Extract data from message (forwarded or direct)."""
        import time
        
        # Create a stable ID using message_id
        # Note: message_id is unique within a chat. For global uniqueness, we use it directly.
        # If forwarded, the message_id is from the current bot chat, not the source channel.
        unique_id = str(message.message_id)
        
        # Base data
        data = {
            'id': unique_id,
            'content': message.text or message.caption or '',
            'has_media': bool(message.photo or message.document or message.video),
            'media_type': self.get_media_type(message),
            'forwarded_by': message.from_user.username if message.from_user else 'Unknown',
            'forwarded_at': message.date.isoformat() if message.date else None,
            'channel': 'telegram',
            'channel_username': 'DirectMessage',
            'author': message.from_user.full_name if message.from_user else 'Unknown',
            'timestamp': message.date.isoformat() if message.date else None,
            'url': ''
        }

        # Extract origin information if forwarded
        if message.forward_origin:
            forward_origin = message.forward_origin
            if forward_origin.type == 'channel':
                chat = forward_origin.chat
                data['channel_username'] = f"@{chat.username}" if chat.username else chat.title
                data['author'] = chat.title or 'Channel'
                data['timestamp'] = forward_origin.date.isoformat() if forward_origin.date else None
                if chat.username:
                    data['url'] = f"https://t.me/{chat.username}/{forward_origin.message_id}"
            elif forward_origin.type == 'user':
                user = forward_origin.sender_user
                if user:
                    data['author'] = user.full_name
                    data['timestamp'] = forward_origin.date.isoformat() if forward_origin.date else None

        return data

    def get_media_type(self, message):
        """Get media type from message."""
        if message.photo:
            return 'photo'
        elif message.document:
            return 'document'
        elif message.video:
            return 'video'
        elif message.audio:
            return 'audio'
        elif message.voice:
            return 'voice'
        return None

    def send_to_google_apps_script(self, data):
        """Send data to Google Apps Script webhook."""
        try:
            response = requests.post(
                self.web_app_url,
                json=data,
                timeout=10,
                headers={'Content-Type': 'application/json'}
            )

            return response.status_code == 200

        except Exception as e:
            self.logger.error(f"Error sending to Google Apps Script: {str(e)}")
            return False

    def run_polling(self):
        """Run bot in polling mode (for testing)."""
        print("🤖 Bot running in polling mode...")
        print("Press Ctrl+C to stop")
        print("(For production, deploy to a server and use webhooks)")
        print()

        try:
            self.application.run_polling()
        except KeyboardInterrupt:
            print("\n👋 Bot stopped")

    def set_webhook(self, webhook_url):
        """Set Telegram webhook (for server deployment)."""
        try:
            bot = Bot(token=self.bot_token)
            success = bot.set_webhook(url=webhook_url)

            if success:
                self.logger.info(f"Webhook set to: {webhook_url}")
                print(f"✅ Webhook set successfully: {webhook_url}")
                return True
            else:
                print("❌ Failed to set webhook")
                return False

        except Exception as e:
            print(f"❌ Error setting webhook: {str(e)}")
            return False

def main():
    """Main function."""
    try:
        bot = WebhookBot()

        # For now, run in polling mode (user can test locally)
        # In production, this would be deployed to a server with webhooks
        bot.run_polling()

    except ValueError as e:
        print(f"Configuration error: {str(e)}")
        print("Please check your .env file")
    except Exception as e:
        print(f"Error: {str(e)}")

if __name__ == '__main__':
    main()
