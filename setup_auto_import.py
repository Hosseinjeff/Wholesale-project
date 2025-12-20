#!/usr/bin/env python3
"""
Setup script for automatic imports using Google Apps Script.
"""

def create_apps_script_instructions():
    """Create instructions for Google Apps Script setup."""
    print("AUTO-IMPORT SETUP: Google Apps Script Method")
    print("=" * 50)
    print()
    print("This is the SIMPLEST way to get automatic imports working!")
    print()
    print("STEP 1: Create Google Apps Script")
    print("-" * 30)
    print("1. Go to: https://script.google.com")
    print("2. Click 'New Project'")
    print("3. Name it: 'Telegram Auto Import'")
    print("4. Delete all default code")
    print("5. Copy and paste the code from 'google_apps_script.js'")
    print("6. Save the project")
    print()

    print("STEP 2: Deploy as Web App")
    print("-" * 30)
    print("1. Click 'Deploy' -> 'New deployment'")
    print("2. Select type: 'Web app'")
    print("3. Description: 'Telegram Import Webhook'")
    print("4. Execute as: 'Me'")
    print("5. Who has access: 'Anyone' (important!)")
    print("6. Click 'Deploy'")
    print("7. COPY the Web App URL - you'll need it!")
    print()

    print("STEP 3: Update .env file")
    print("-" * 30)
    print("Add this line to your .env file:")
    print("GOOGLE_WEB_APP_URL=https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec")
    print("(Replace YOUR_SCRIPT_ID with the ID from the web app URL)")
    print()

    print("STEP 4: Test the Auto Import")
    print("-" * 30)
    print("Run: python simple_auto_import.py")
    print()
    print("Then forward a message to your bot - it should appear in Google Sheets automatically!")
    print()

    print("That's it! Much simpler than full Google API setup!")
    print("=" * 50)

if __name__ == '__main__':
    create_apps_script_instructions()
