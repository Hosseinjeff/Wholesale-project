#!/usr/bin/env python3
"""
Simple automated importer using webhooks.
Posts data to a Google Apps Script web app.
"""

import time
import logging
import requests
from channels.telegram_bot_reader import TelegramBotReader
from utils.config import load_config
from utils.logger import setup_logger

class SimpleAutoImporter:
    """Simple auto importer using Google Apps Script."""

    def __init__(self):
        self.config = load_config()
        self.bot_reader = TelegramBotReader(self.config)
        self.web_app_url = self.config.get('GOOGLE_WEB_APP_URL', '')
        self.last_message_id = 0
        self.check_interval = int(self.config.get('CHECK_INTERVAL', '30'))

        setup_logger(logging.INFO)
        self.logger = logging.getLogger(__name__)

    def start_monitoring(self):
        """Start monitoring for new messages."""
        print("Simple Auto-Import Started")
        print("=" * 30)
        print(f"Check interval: {self.check_interval} seconds")

        if not self.web_app_url:
            print("ERROR: GOOGLE_WEB_APP_URL not set in .env")
            print("Set up Google Apps Script first!")
            return

        print("Monitoring... (Press Ctrl+C to stop)")
        print()

        try:
            while True:
                self.check_for_new_messages()
                time.sleep(self.check_interval)
        except KeyboardInterrupt:
            print("\nAuto-import stopped")
        except Exception as e:
            print(f"Error: {str(e)}")

    def check_for_new_messages(self):
        """Check for new forwarded messages."""
        try:
            messages = self.bot_reader.read_posts(limit=10)

            new_messages = []
            for msg in messages:
                msg_id = int(msg.get('id', 0))
                if msg_id > self.last_message_id:
                    new_messages.append(msg)

            if new_messages:
                print(f"Found {len(new_messages)} new message(s)")
                for msg in new_messages:
                    self.send_to_google_apps_script(msg)
                    self.last_message_id = max(self.last_message_id, int(msg.get('id', 0)))

        except Exception as e:
            print(f"Error checking messages: {str(e)}")

    def send_to_google_apps_script(self, message):
        """Send message data to Google Apps Script."""
        try:
            # Prepare data for Google Apps Script
            data = {
                'id': message.get('id', ''),
                'channel': message.get('channel_username', ''),
                'author': message.get('author', ''),
                'content': message.get('content', ''),
                'timestamp': message.get('timestamp', ''),
                'url': message.get('url', ''),
                'forwarded_by': message.get('forwarded_by', ''),
                'forwarded_at': message.get('forwarded_at', '')
            }

            # Send to Google Apps Script
            response = requests.post(self.web_app_url, json=data, timeout=30)

            if response.status_code == 200:
                print(f"SUCCESS: Imported message from {data['channel']}")
            else:
                print(f"ERROR: Failed to import - {response.status_code}")

        except Exception as e:
            print(f"ERROR: {str(e)}")

def main():
    """Main function."""
    importer = SimpleAutoImporter()
    importer.start_monitoring()

if __name__ == '__main__':
    main()

