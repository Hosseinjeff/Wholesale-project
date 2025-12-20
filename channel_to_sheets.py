#!/usr/bin/env python3
"""
Channel Posts to Google Sheets Importer

This script reads posts from various channels (Telegram, Discord, Slack, etc.)
and imports them into Google Spreadsheets.

Usage:
    python channel_to_sheets.py --channel telegram --sheet-id YOUR_SHEET_ID
"""

import argparse
import logging
import os
import sys
from datetime import datetime
from typing import List, Dict, Any
import pandas as pd

# Import channel readers
from channels.telegram_bot_reader import TelegramBotReader
from channels.discord_reader import DiscordReader
from channels.slack_reader import SlackReader

# Import Google Sheets writers
from sheets.google_sheets_writer import GoogleSheetsWriter
from sheets.quick_sheets_writer import QuickSheetsWriter

# Import utilities
from utils.config import load_config
from utils.logger import setup_logger

# Supported channel types
SUPPORTED_CHANNELS = {
    'telegram': TelegramBotReader,
    'discord': DiscordReader,
    'slack': SlackReader
}

def parse_arguments():
    """Parse command line arguments."""
    parser = argparse.ArgumentParser(description='Import channel posts to Google Sheets')

    parser.add_argument(
        '--channel',
        required=True,
        choices=SUPPORTED_CHANNELS.keys(),
        help='Channel type to read from'
    )

    parser.add_argument(
        '--sheet-id',
        required=True,
        help='Google Sheets ID to write to'
    )

    parser.add_argument(
        '--range',
        default='A1',
        help='Starting cell range in Google Sheets (default: A1)'
    )

    parser.add_argument(
        '--limit',
        type=int,
        default=100,
        help='Maximum number of posts to fetch (default: 100)'
    )

    parser.add_argument(
        '--config',
        default='.env',
        help='Path to configuration file (default: .env)'
    )

    parser.add_argument(
        '--verbose',
        '-v',
        action='store_true',
        help='Enable verbose logging'
    )

    parser.add_argument(
        '--quick',
        action='store_true',
        help='Use quick mode for testing (manual copy-paste to Google Sheets)'
    )

    return parser.parse_args()

def validate_config(channel_type: str, config_path: str) -> Dict[str, Any]:
    """Validate configuration and credentials."""
    config = load_config(config_path)

    # Check required credentials based on channel type
    required_keys = {
        'telegram': ['TELEGRAM_BOT_TOKEN'],
        'discord': ['DISCORD_BOT_TOKEN', 'DISCORD_CHANNEL_ID'],
        'slack': ['SLACK_BOT_TOKEN', 'SLACK_CHANNEL_ID']
    }

    # Always need Google Sheets credentials
    required_keys[channel_type].extend(['GOOGLE_SHEETS_CREDENTIALS_PATH'])

    missing_keys = [key for key in required_keys[channel_type] if key not in config]

    if missing_keys:
        print(f"Missing required configuration keys: {', '.join(missing_keys)}")
        print(f"Please add them to your {config_path} file")
        sys.exit(1)

    return config

def main():
    """Main execution function."""
    args = parse_arguments()

    # Setup logging
    log_level = logging.DEBUG if args.verbose else logging.INFO
    setup_logger(log_level)

    logger = logging.getLogger(__name__)
    logger.info("Starting Channel to Sheets import")

    # Validate configuration
    config = validate_config(args.channel, args.config)

    try:
        # Initialize channel reader
        channel_class = SUPPORTED_CHANNELS[args.channel]
        reader = channel_class(config)

        # Read posts from channel
        logger.info(f"Reading posts from {args.channel} channel...")
        posts = reader.read_posts(limit=args.limit)

        if not posts:
            logger.warning("No posts found to import")
            return

        logger.info(f"Found {len(posts)} posts to import")

        # Convert to DataFrame for processing
        df = pd.DataFrame(posts)

        # Initialize Google Sheets writer
        if args.quick:
            logger.info("Using quick mode (manual copy-paste)...")
            sheets_writer = QuickSheetsWriter(args.sheet_id)
        else:
            sheets_writer = GoogleSheetsWriter(config['GOOGLE_SHEETS_CREDENTIALS_PATH'])

        # Write to Google Sheets
        logger.info(f"Writing to Google Sheets (ID: {args.sheet_id})...")
        if args.quick:
            sheets_writer.write_dataframe(df, args.range)
        else:
            sheets_writer.write_dataframe(
                spreadsheet_id=args.sheet_id,
                dataframe=df,
                range_name=args.range
            )

        logger.info("Import completed successfully!")

    except Exception as e:
        logger.error(f"Import failed: {str(e)}")
        sys.exit(1)

if __name__ == '__main__':
    main()
