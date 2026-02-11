#!/usr/bin/env python3
"""
Automated Telegram to Google Sheets importer.
Continuously monitors for new forwarded messages and adds them to Google Sheets.
"""

import os
import time
import logging
import json
import requests
from datetime import datetime
from channels.telegram_bot_reader import TelegramBotReader
from sheets.google_sheets_writer import GoogleSheetsWriter
from utils.config import load_config
from utils.logger import setup_logger

class AutoImporter:
    """Automated importer that monitors for new messages."""

    def __init__(self):
        self.config = load_config()
        self.bot_reader = TelegramBotReader(self.config)
        self.sheets_writer = None
        self.state_file = 'importer_state.json'
        self.last_message_id = self.load_state()
        self.sheet_id = self.config.get('GOOGLE_SHEET_ID', '')
        self.check_interval = int(self.config.get('CHECK_INTERVAL', '60'))  # seconds
        self.web_app_url = self.config.get('GOOGLE_WEB_APP_URL', '')

        # Setup logging
        setup_logger(logging.INFO)
        self.logger = logging.getLogger(__name__)

    def load_state(self):
        """Load the last processed message ID from state file."""
        if os.path.exists(self.state_file):
            try:
                with open(self.state_file, 'r') as f:
                    state = json.load(f)
                    last_id = state.get('last_message_id', 0)
                    print(f"🔄 Loaded last_message_id: {last_id}")
                    return int(last_id)
            except Exception as e:
                print(f"⚠️ Error loading state file: {e}")
        return 0

    def save_state(self):
        """Save the last processed message ID to state file."""
        try:
            with open(self.state_file, 'w') as f:
                json.dump({'last_message_id': self.last_message_id, 'last_update': datetime.now().isoformat()}, f)
        except Exception as e:
            self.logger.error(f"Failed to save state: {e}")

    def setup_google_sheets(self):
        """Setup Google Sheets writer."""
        try:
            credentials_path = self.config.get('GOOGLE_SHEETS_CREDENTIALS_PATH', 'credentials.json')
            self.sheets_writer = GoogleSheetsWriter(credentials_path)
            self.logger.info("Google Sheets API initialized")
            return True
        except Exception as e:
            self.logger.error(f"Failed to setup Google Sheets: {str(e)}")
            return False

    def start_monitoring(self):
        """Start continuous monitoring for new messages."""
        print("🤖 Auto-Import Monitor Started")
        print("=" * 40)
        print(f"📊 Google Sheet ID: {self.sheet_id}")
        print(f"🔄 Check interval: {self.check_interval} seconds")
        print("=" * 40)

        if not self.sheet_id:
            print("❌ GOOGLE_SHEET_ID not set in .env file")
            return

        if not self.setup_google_sheets():
            print("❌ Failed to setup Google Sheets API")
            print("Run: python setup_google_sheets.py")
            return

        print("✅ Monitoring started... (Press Ctrl+C to stop)")
        print()

        try:
            while True:
                self.check_for_new_messages()
                time.sleep(self.check_interval)

        except KeyboardInterrupt:
            print("\n👋 Auto-import stopped by user")
        except Exception as e:
            self.logger.error(f"Auto-import error: {str(e)}")
            print(f"❌ Auto-import error: {str(e)}")

    def check_for_new_messages(self):
        """Check for new forwarded messages and import them."""
        try:
            # Get recent messages
            messages = self.bot_reader.read_posts(limit=10)

            if not messages:
                return

            # Filter for new messages (higher ID than last processed)
            new_messages = []
            for msg in messages:
                msg_id = int(msg.get('id', 0))
                if msg_id > self.last_message_id:
                    new_messages.append(msg)

            if not new_messages:
                return

            # Sort by ID to process in order
            new_messages.sort(key=lambda x: int(x.get('id', 0)))

            print(f"📨 Found {len(new_messages)} new forwarded message(s)")

            # Import each new message
            for msg in new_messages:
                self.import_message_to_sheets(msg)
                self.last_message_id = max(self.last_message_id, int(msg.get('id', 0)))
                self.save_state()

        except Exception as e:
            self.logger.error(f"Error checking messages: {str(e)}")

    def import_message_to_sheets(self, message):
        """Import a single message to Google Sheets and Web App."""
        try:
            # Prepare data for sheets (single row) - Matches MessageData headers in GAS
            row_data = [[
                message.get('id', ''),
                message.get('channel', ''),
                message.get('channel_username', ''),
                message.get('author', ''),
                message.get('content', ''),
                message.get('timestamp', ''),
                message.get('url', ''),
                message.get('forwarded_by', ''),
                message.get('forwarded_at', ''),
                message.get('has_media', False),
                message.get('media_type', ''),
                datetime.now().isoformat(),
                'imported (direct)'
            ]]

            # 1. Append to Google Sheets directly (legacy/backup)
            success = False
            if self.sheets_writer and self.sheet_id:
                success = self.sheets_writer.append_data(self.sheet_id, row_data)
                if success:
                    print(f"✅ Appended to Google Sheets directly: {message.get('id')}")

            # 2. Send to Google Apps Script Web App (Primary for processing)
            if self.web_app_url:
                try:
                    response = requests.post(
                        self.web_app_url,
                        json=message,
                        timeout=30
                    )
                    if response.status_code == 200:
                        res_json = response.json()
                        if res_json.get('status') == 'success':
                            print(f"🚀 Web App Import Success: {message.get('id')} - Found {res_json.get('products_found', 0)} products")
                            success = True
                        elif res_json.get('status') == 'duplicate':
                            print(f"ℹ️ Web App Duplicate: {message.get('id')}")
                            success = True # Consider success as it's already there
                        else:
                            print(f"⚠️ Web App Import Warning: {res_json.get('message')}")
                    else:
                        print(f"❌ Web App HTTP Error: {response.status_code}")
                except Exception as web_err:
                    print(f"❌ Web App Connection Error: {web_err}")

            if success:
                channel = message.get('channel_username', 'Unknown')
                author = message.get('author', 'Unknown')
                print(f"✨ Successfully processed message from {channel}")
            else:
                print("❌ Failed to import message through any method")

        except Exception as e:
            self.logger.error(f"Error importing message: {str(e)}")
            print(f"❌ Error importing message: {str(e)}")

def main():
    """Main function."""
    importer = AutoImporter()
    importer.start_monitoring()

if __name__ == '__main__':
    main()

