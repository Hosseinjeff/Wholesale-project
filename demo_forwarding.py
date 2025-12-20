#!/usr/bin/env python3
"""
Demo script showing how the Telegram forwarding approach works.
"""

from channels.telegram_bot_reader import TelegramBotReader
from utils.config import load_config
from utils.logger import setup_logger
import logging

def demo_forwarding():
    """Demonstrate how the forwarding approach works."""
    print("Telegram Forwarding Demo")
    print("=" * 50)

    # Setup logging
    setup_logger(logging.INFO)
    logger = logging.getLogger(__name__)

    try:
        # Load configuration
        config = load_config()
        bot_token = config.get('TELEGRAM_BOT_TOKEN')

        if not bot_token:
            print("ERROR: TELEGRAM_BOT_TOKEN not found in .env file")
            print("Please add your bot token from @BotFather")
            return

        print(f"SUCCESS: Bot token found: {bot_token[:20]}...")
        print()

        # Initialize bot reader
        reader = TelegramBotReader(config)

        # Test authentication
        print("Testing bot authentication...")
        if reader.authenticate():
            print("SUCCESS: Bot authentication successful!")
        else:
            print("ERROR: Bot authentication failed")
            return

        print()
        print("How the forwarding approach works:")
        print("1. You find interesting posts in any Telegram channel")
        print("2. You forward them to your bot")
        print("3. The bot receives and processes the forwarded messages")
        print("4. Data gets imported to Google Sheets")
        print()

        # Check for any existing forwarded messages
        print("Checking for forwarded messages...")
        messages = reader.read_posts(limit=5)

        if messages:
            print(f"SUCCESS: Found {len(messages)} forwarded message(s)!")
            for i, msg in enumerate(messages, 1):
                print(f"\nMessage {i}:")
                print(f"  Channel: {msg.get('channel_username', 'Unknown')}")
                print(f"  Author: {msg.get('author', 'Unknown')}")
                print(f"  Content: {msg.get('content', 'No text')[:100]}...")
                print(f"  Forwarded by: {msg.get('forwarded_by', 'Unknown')}")
        else:
            print("No forwarded messages found yet.")
            print("Forward some messages to your bot to see them here!")

        print()
        print("Next steps:")
        print("1. Forward some messages from channels to your bot")
        print("2. Run this script again to see the processed messages")
        print("3. Set up Google Sheets API to import the data")

    except Exception as e:
        logger.error(f"Demo failed: {str(e)}")
        print(f"ERROR: {str(e)}")

if __name__ == '__main__':
    demo_forwarding()
