#!/usr/bin/env python3
"""
Google Sheets API setup script.
"""

import os
import pickle
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError

SCOPES = ['https://www.googleapis.com/auth/spreadsheets']

def setup_google_sheets():
    """Set up Google Sheets API credentials."""
    creds = None

    # Check if token.pickle exists (saved credentials)
    if os.path.exists('token.pickle'):
        with open('token.pickle', 'rb') as token:
            creds = pickle.load(token)

    # If there are no (valid) credentials available, let the user log in
    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
        else:
            # Check if credentials.json exists
            if not os.path.exists('credentials.json'):
                print("ERROR: credentials.json not found!")
                print()
                print("SETUP INSTRUCTIONS:")
                print("1. Go to https://console.cloud.google.com/")
                print("2. Create a new project or select existing one")
                print("3. Enable Google Sheets API")
                print("4. Go to 'Credentials' in the left menu")
                print("5. Click 'Create Credentials' -> 'OAuth 2.0 Client IDs'")
                print("6. Choose 'Desktop application'")
                print("7. Download the JSON file and save as 'credentials.json'")
                print()
                input("Press Enter after you've downloaded credentials.json...")
                return setup_google_sheets()  # Retry

            flow = InstalledAppFlow.from_client_secrets_file(
                'credentials.json', SCOPES)
            creds = flow.run_local_server(port=0)

        # Save the credentials for the next run
        with open('token.pickle', 'wb') as token:
            pickle.dump(creds, token)

    try:
        # Build the service
        service = build('sheets', 'v4', credentials=creds)

        # Test the connection by getting spreadsheet info
        spreadsheet_id = input("Enter your Google Sheet ID (from the URL): ").strip()

        if not spreadsheet_id:
            print("ERROR: No Sheet ID provided")
            return False

        result = service.spreadsheets().get(spreadsheetId=spreadsheet_id).execute()
        sheet_title = result['properties']['title']

        print(f"SUCCESS: Connected to Google Sheet '{sheet_title}'")
        print(f"Sheet ID: {spreadsheet_id}")

        # Update .env file with credentials and sheet ID
        update_env_file(spreadsheet_id)

        return True

    except HttpError as err:
        print(f"ERROR: Google Sheets API error: {err}")
        print("Make sure:")
        print("- The Sheet ID is correct")
        print("- The sheet is shared with your Google account")
        return False
    except Exception as e:
        print(f"ERROR: {str(e)}")
        return False

def update_env_file(sheet_id=None):
    """Update .env file with Google credentials and sheet ID."""
    env_content = ""
    if os.path.exists('.env'):
        with open('.env', 'r') as f:
            env_content = f.read()

    # Check if GOOGLE_SHEETS_CREDENTIALS_PATH is already set
    if 'GOOGLE_SHEETS_CREDENTIALS_PATH=' not in env_content:
        env_content += '\nGOOGLE_SHEETS_CREDENTIALS_PATH=credentials.json\n'

    # Add sheet ID if provided
    if sheet_id and 'GOOGLE_SHEET_ID=' not in env_content:
        env_content += f'GOOGLE_SHEET_ID={sheet_id}\n'

    with open('.env', 'w') as f:
        f.write(env_content)
    print("Updated .env file with Google Sheets configuration")

def main():
    """Main setup function."""
    print("Google Sheets API Setup")
    print("=" * 30)
    print()

    print("This will set up automatic import to Google Sheets.")
    print("You'll need:")
    print("1. A Google Cloud Console project")
    print("2. Google Sheets API enabled")
    print("3. OAuth 2.0 credentials (credentials.json)")
    print("4. Your Google Sheet ID")
    print()

    if setup_google_sheets():
        print("\nSUCCESS: Google Sheets setup complete!")
        print("Now you can use automatic imports:")
        print("python auto_import.py")
    else:
        print("\nERROR: Setup failed. Please check the errors above.")

if __name__ == '__main__':
    main()
