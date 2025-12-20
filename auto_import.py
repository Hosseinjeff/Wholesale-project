#!/usr/bin/env python3
"""
Automated Telegram to Google Sheets importer.
Continuously monitors for new forwarded messages and adds them to Google Sheets.
"""

import time
import logging
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
        self.last_message_id = 0
        self.sheet_id = self.config.get('GOOGLE_SHEET_ID', '')
        self.check_interval = int(self.config.get('CHECK_INTERVAL', '60'))  # seconds

        # Setup logging
        setup_logger(logging.INFO)
        self.logger = logging.getLogger(__name__)

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

        except Exception as e:
            self.logger.error(f"Error checking messages: {str(e)}")

    def import_message_to_sheets(self, message):
        """Import a single message to Google Sheets."""
        try:
            # Prepare data for sheets (single row)
            row_data = [[
                message.get('id', ''),
                message.get('channel', ''),
                message.get('channel_username', ''),
                message.get('author', ''),
                message.get('content', ''),
                message.get('timestamp', ''),
                message.get('url', ''),
                message.get('has_media', False),
                message.get('media_type', ''),
                message.get('forwarded_by', ''),
                message.get('forwarded_at', ''),
                message.get('edited', False)
            ]]

            # Append to Google Sheets
            success = self.sheets_writer.append_data(self.sheet_id, row_data)

            if success:
                channel = message.get('channel_username', 'Unknown')
                author = message.get('author', 'Unknown')
                print(f"✅ Imported message from {channel} by {author}")
            else:
                print("❌ Failed to import message to Google Sheets")

        except Exception as e:
            self.logger.error(f"Error importing message: {str(e)}")
            print(f"❌ Error importing message: {str(e)}")

def main():
    """Main function."""
    importer = AutoImporter()
    importer.start_monitoring()

if __name__ == '__main__':
    main()
