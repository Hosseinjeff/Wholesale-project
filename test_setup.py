#!/usr/bin/env python3
"""
Test script to verify project setup and configuration.
"""

import os
import sys
from dotenv import load_dotenv

def test_dependencies():
    """Test if all required dependencies are installed."""
    required_imports = [
        ('googleapiclient', 'googleapiclient'),
        ('google_auth_oauthlib', 'google_auth_oauthlib'),
        ('pandas', 'pandas'),
        ('dotenv', 'python-dotenv'),
        ('telethon', 'telethon'),
        ('slack_sdk', 'slack-sdk')
    ]

    missing_packages = []
    for import_name, package_name in required_imports:
        try:
            __import__(import_name)
        except ImportError:
            missing_packages.append(package_name)

    if missing_packages:
        print(f"ERROR: Missing packages: {', '.join(missing_packages)}")
        print("Run: pip install -r requirements.txt")
        return False
    else:
        print("SUCCESS: All dependencies are installed")
        return True

def test_config():
    """Test if configuration file exists and can be loaded."""
    if not os.path.exists('.env'):
        print("ERROR: .env file not found")
        print("Copy env_example.txt to .env and fill in your credentials")
        return False

    try:
        load_dotenv()
        print("SUCCESS: .env file exists and can be loaded")
        return True
    except Exception as e:
        print(f"ERROR: Error loading .env file: {e}")
        return False

def test_imports():
    """Test if all project modules can be imported."""
    modules_to_test = [
        'utils.config',
        'utils.logger',
        'channels.base_reader',
        'channels.telegram_reader',
        'channels.discord_reader',
        'channels.slack_reader',
        'sheets.google_sheets_writer'
    ]

    failed_imports = []
    for module in modules_to_test:
        try:
            __import__(module)
        except ImportError as e:
            failed_imports.append(f"{module}: {e}")

    if failed_imports:
        print("ERROR: Failed imports:")
        for failed in failed_imports:
            print(f"   {failed}")
        return False
    else:
        print("SUCCESS: All project modules can be imported")
        return True

def main():
    """Run all tests."""
    print("Testing Channel to Sheets project setup...\n")

    tests = [
        test_config,
        test_dependencies,
        test_imports
    ]

    passed = 0
    total = len(tests)

    for test in tests:
        if test():
            passed += 1
        print()

    print(f"Results: {passed}/{total} tests passed")

    if passed == total:
        print("SUCCESS: Project setup is complete and ready to use!")
        print("\nNext steps:")
        print("1. Fill in your credentials in .env")
        print("2. Run: python channel_to_sheets.py --help")
    else:
        print("WARNING: Please fix the issues above before running the application")
        sys.exit(1)

if __name__ == '__main__':
    main()
