"""
Google Sheets writer implementation.
"""

import logging
from typing import List, Dict, Any
import pandas as pd
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError
from google.auth.transport.requests import Request
from google_auth_oauthlib.flow import InstalledAppFlow

logger = logging.getLogger(__name__)

class GoogleSheetsWriter:
    """
    Writes data to Google Sheets using Google Sheets API.
    """

    SCOPES = ['https://www.googleapis.com/auth/spreadsheets']

    def __init__(self, credentials_path: str):
        """
        Initialize the Google Sheets writer.

        Args:
            credentials_path: Path to Google API credentials JSON file
        """
        self.credentials_path = credentials_path
        self.service = None
        self.creds = None

    def authenticate(self) -> bool:
        """
        Authenticate with Google Sheets API.

        Returns:
            True if authentication successful, False otherwise
        """
        try:
            self.creds = self._get_credentials()
            self.service = build('sheets', 'v4', credentials=self.creds)
            logger.info("Successfully authenticated with Google Sheets API")
            return True

        except Exception as e:
            logger.error(f"Google Sheets authentication error: {str(e)}")
            return False

    def _get_credentials(self):
        """
        Get or refresh Google API credentials.

        Returns:
            Google API credentials object
        """
        creds = None

        # Check if token.json exists (saved credentials)
        try:
            creds = Credentials.from_authorized_user_file('token.json', self.SCOPES)
        except FileNotFoundError:
            pass

        # If there are no (valid) credentials available, let the user log in
        if not creds or not creds.valid:
            if creds and creds.expired and creds.refresh_token:
                creds.refresh(Request())
            else:
                flow = InstalledAppFlow.from_client_secrets_file(
                    self.credentials_path, self.SCOPES)
                creds = flow.run_local_server(port=0)

            # Save the credentials for the next run
            with open('token.json', 'w') as token:
                token.write(creds.to_json())

        return creds

    def write_dataframe(self, spreadsheet_id: str, dataframe: pd.DataFrame,
                       range_name: str = 'A1', clear_sheet: bool = True) -> bool:
        """
        Write a pandas DataFrame to Google Sheets.

        Args:
            spreadsheet_id: Google Sheets spreadsheet ID
            dataframe: DataFrame to write
            range_name: Starting cell range (e.g., 'A1')
            clear_sheet: Whether to clear the sheet before writing

        Returns:
            True if write successful, False otherwise
        """
        if not self.service:
            if not self.authenticate():
                return False

        try:
            # Clear the sheet if requested
            if clear_sheet:
                self._clear_sheet(spreadsheet_id, range_name)

            # Convert DataFrame to values
            values = [dataframe.columns.tolist()] + dataframe.values.tolist()

            # Prepare the request body
            body = {
                'values': values
            }

            # Write the data
            result = self.service.spreadsheets().values().update(
                spreadsheetId=spreadsheet_id,
                range=range_name,
                valueInputOption='RAW',
                body=body
            ).execute()

            logger.info(f"Successfully wrote {result.get('updatedCells')} cells to Google Sheets")
            return True

        except HttpError as e:
            logger.error(f"Google Sheets API error: {e}")
            return False
        except Exception as e:
            logger.error(f"Error writing to Google Sheets: {str(e)}")
            return False

    def _clear_sheet(self, spreadsheet_id: str, range_name: str):
        """
        Clear a range in the Google Sheet.

        Args:
            spreadsheet_id: Google Sheets spreadsheet ID
            range_name: Range to clear
        """
        try:
            self.service.spreadsheets().values().clear(
                spreadsheetId=spreadsheet_id,
                range=range_name,
                body={}
            ).execute()
            logger.info(f"Cleared range {range_name} in Google Sheets")
        except Exception as e:
            logger.warning(f"Failed to clear sheet range: {str(e)}")

    def append_data(self, spreadsheet_id: str, data: List[List[Any]],
                   range_name: str = 'A1') -> bool:
        """
        Append data to Google Sheets.

        Args:
            spreadsheet_id: Google Sheets spreadsheet ID
            data: List of rows to append
            range_name: Starting cell range

        Returns:
            True if append successful, False otherwise
        """
        if not self.service:
            if not self.authenticate():
                return False

        try:
            body = {
                'values': data
            }

            result = self.service.spreadsheets().values().append(
                spreadsheetId=spreadsheet_id,
                range=range_name,
                valueInputOption='RAW',
                insertDataOption='INSERT_ROWS',
                body=body
            ).execute()

            logger.info(f"Successfully appended {result.get('updates', {}).get('updatedCells', 0)} cells")
            return True

        except HttpError as e:
            logger.error(f"Google Sheets API error: {e}")
            return False
        except Exception as e:
            logger.error(f"Error appending to Google Sheets: {str(e)}")
            return False
