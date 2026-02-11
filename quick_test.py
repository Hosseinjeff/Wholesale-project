#!/usr/bin/env python3
"""
Quick test script for the Telegram forwarding + Google Sheets system.
"""

import sys
import requests
from channels.telegram_bot_reader import TelegramBotReader
from utils.config import load_config
from utils.logger import setup_logger
import logging

def test_google_sheet_access(sheet_id):
    """Test if we can access the Google Sheet."""
    try:
        # Try to access the sheet using the public sharing URL
        url = f"https://docs.google.com/spreadsheets/d/{sheet_id}/export?format=csv"
        response = requests.get(url, timeout=10)

        if response.status_code == 200:
            print("SUCCESS: Google Sheet is accessible!")
            return True
        elif response.status_code == 403:
            print("ERROR: Sheet is not publicly accessible")
            print("Please share the sheet with 'Anyone with the link can edit'")
            return False
        else:
            print(f"ERROR: Cannot access sheet (HTTP {response.status_code})")
            return False
    except Exception as e:
        print(f"ERROR: {str(e)}")
        return False

def test_telegram_bot():
    """Test Telegram bot functionality."""
    try:
        config = load_config()
        reader = TelegramBotReader(config)

        print("Testing bot authentication...")
        if reader.authenticate():
            print("SUCCESS: Bot authentication works!")

            print("Checking for forwarded messages...")
            messages = reader.read_posts(limit=5)

            if messages:
                print(f"SUCCESS: Found {len(messages)} forwarded message(s)!")
                return True
            else:
                print("No forwarded messages yet. Forward some messages to your bot first!")
                return True  # This is not an error
        else:
            print("ERROR: Bot authentication failed")
            return False
    except Exception as e:
        print(f"ERROR: {str(e)}")
        return False

def main():
    """Run all tests."""
    print("Quick Test: Telegram Forwarding + Google Sheets")
    print("=" * 50)

    # Test 1: Telegram Bot
    print("\n1. Testing Telegram Bot...")
    bot_ok = test_telegram_bot()

    # Test 2: Google Sheet (if sheet ID provided)
    sheet_id = input("\n2. Enter your Google Sheet ID (from the URL): ").strip()

    if sheet_id:
        print(f"Testing Google Sheet access for ID: {sheet_id}")
        sheet_ok = test_google_sheet_access(sheet_id)
    else:
        print("No Sheet ID provided - skipping Google Sheets test")
        sheet_ok = False

    print("\n" + "=" * 50)
    print("RESULTS:")

    if bot_ok:
        print("✅ Telegram bot: READY")
        print("   Your bot username: @molavisale_bot")
        print("   Forward messages to test it!")
    else:
        print("❌ Telegram bot: FAILED")

    if sheet_ok:
        print("✅ Google Sheet: ACCESSIBLE")
    elif sheet_id:
        print("❌ Google Sheet: NOT ACCESSIBLE")
        print("   Make sure sharing is set to 'Anyone with the link can edit'")
    else:
        print("⚠️  Google Sheet: NOT TESTED (no ID provided)")

    print("\nNEXT STEPS:")
    if bot_ok and sheet_ok:
        print("🎉 Everything is ready! Run the full import:")
        print(f"   python channel_to_sheets.py --channel telegram --sheet-id {sheet_id}")
    elif bot_ok:
        print("1. Create a Google Sheet and make it publicly editable")
        print("2. Get the Sheet ID from the URL")
        print("3. Run: python channel_to_sheets.py --channel telegram --sheet-id YOUR_ID")
    else:
        print("1. Check your TELEGRAM_BOT_TOKEN in .env file")
        print("2. Run: python demo_forwarding.py")

if __name__ == '__main__':
    main()

