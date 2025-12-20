#!/usr/bin/env python3
"""
Export Telegram forwarded messages to CSV for easy Google Sheets import.
"""

import csv
import os
from datetime import datetime
from channels.telegram_bot_reader import TelegramBotReader
from utils.config import load_config
from utils.logger import setup_logger
import logging

def export_to_csv():
    """Export forwarded messages to CSV file."""
    # Setup logging
    setup_logger(logging.INFO)
    logger = logging.getLogger(__name__)

    try:
        # Load configuration
        config = load_config()

        # Initialize bot reader
        reader = TelegramBotReader(config)

        # Read messages
        print("Reading forwarded messages...")
        messages = reader.read_posts(limit=100)  # Get up to 100 messages

        if not messages:
            print("No forwarded messages found.")
            return

        # Create CSV filename with timestamp
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        csv_filename = f"telegram_messages_{timestamp}.csv"

        # Write to CSV
        with open(csv_filename, 'w', newline='', encoding='utf-8') as csvfile:
            fieldnames = ['id', 'channel', 'channel_username', 'author', 'content',
                         'timestamp', 'url', 'has_media', 'media_type', 'forwarded_by',
                         'forwarded_at', 'edited']
            writer = csv.DictWriter(csvfile, fieldnames=fieldnames)

            # Write header
            writer.writeheader()

            # Write messages
            for msg in messages:
                # Clean up the data for CSV
                clean_msg = {}
                for key in fieldnames:
                    value = msg.get(key, '')
                    # Convert to string and handle None values
                    if value is None:
                        clean_msg[key] = ''
                    else:
                        clean_msg[key] = str(value)

                writer.writerow(clean_msg)

        print(f"SUCCESS: Exported {len(messages)} messages to {csv_filename}")
        print()
        print("To import into Google Sheets:")
        print("1. Open your Google Sheet")
        print("2. Go to File -> Import -> Upload")
        print(f"3. Select the file: {csv_filename}")
        print("4. Choose 'Replace spreadsheet' or 'Append rows'")
        print()
        print("CSV file location:", os.path.abspath(csv_filename))

    except Exception as e:
        logger.error(f"Export failed: {str(e)}")
        print(f"ERROR: Export failed: {str(e)}")

if __name__ == '__main__':
    export_to_csv()
