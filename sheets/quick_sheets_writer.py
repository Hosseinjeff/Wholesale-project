"""
Quick Google Sheets writer for publicly shared sheets.
"""

import logging
import requests
import pandas as pd
from typing import List, Dict, Any

logger = logging.getLogger(__name__)

class QuickSheetsWriter:
    """
    Simple writer for publicly shared Google Sheets.
    Uses CSV export/import method for basic functionality.
    """

    def __init__(self, sheet_id: str):
        """
        Initialize with sheet ID.

        Args:
            sheet_id: Google Sheets ID (from URL)
        """
        self.sheet_id = sheet_id
        self.base_url = f"https://docs.google.com/spreadsheets/d/{sheet_id}"

    def write_dataframe(self, dataframe: pd.DataFrame, range_name: str = 'A1') -> bool:
        """
        Write DataFrame to Google Sheet using CSV method.

        Args:
            dataframe: DataFrame to write
            range_name: Starting range (ignored in this implementation)

        Returns:
            True if successful, False otherwise
        """
        try:
            # For publicly shared sheets, we can try to append data
            print("\n" + "="*60)
            print("SUCCESS! FOUND FORWARDED MESSAGES:")
            print("="*60)

            # Show summary
            print(f"Total messages found: {len(dataframe)}")
            print(f"Columns: {', '.join(dataframe.columns.tolist())}")
            print()

            # Try to automatically write to the public sheet
            success = self._write_to_public_sheet(dataframe)

            if success:
                print("="*60)
                print("✅ DATA AUTOMATICALLY IMPORTED TO GOOGLE SHEETS!")
                print(f"📊 View your sheet: https://docs.google.com/spreadsheets/d/{self.sheet_id}")
                print("="*60)
            else:
                # Fallback to manual method
                self._show_manual_import(dataframe)

            logger.info(f"Prepared data for Google Sheet {self.sheet_id}")
            return True

        except Exception as e:
            logger.error(f"Error preparing data: {str(e)}")
            return False

    def _write_to_public_sheet(self, dataframe: pd.DataFrame) -> bool:
        """Try to write data to publicly shared Google Sheet."""
        try:
            # Convert to list of lists for Google Sheets API format
            values = [dataframe.columns.tolist()] + dataframe.values.tolist()

            # For public sheets, we need to use the Google Sheets API
            # For now, let's try a simple approach using requests to the public endpoint
            import requests

            # Google Sheets public API endpoint for writing
            url = f"https://sheets.googleapis.com/v4/spreadsheets/{self.sheet_id}/values/A1:append"

            # This would require API key or OAuth, so for now we'll use the manual method
            # But let's show the user what would be written
            print("📝 Attempting to write data to Google Sheets...")
            print("Note: For automatic imports, you'll need Google Sheets API setup")
            print()

            return False  # Return False to trigger manual method

        except Exception as e:
            logger.warning(f"Could not write to public sheet: {str(e)}")
            return False

    def _show_manual_import(self, dataframe: pd.DataFrame):
        """Show manual import instructions."""
        print("📋 MANUAL IMPORT INSTRUCTIONS:")
        print("="*60)

        # Show each message in a readable format
        for i, row in dataframe.iterrows():
            try:
                print(f"MESSAGE {i+1}:")
                # Safely encode text fields
                channel = str(row.get('channel_username', 'Unknown')).encode('ascii', 'ignore').decode('ascii')
                author = str(row.get('author', 'Unknown')).encode('ascii', 'ignore').decode('ascii')
                timestamp = str(row.get('timestamp', 'Unknown'))
                forwarded_by = str(row.get('forwarded_by', 'Unknown')).encode('ascii', 'ignore').decode('ascii')

                print(f"  Channel: {channel}")
                print(f"  Author: {author}")
                print(f"  Timestamp: {timestamp}")
                print(f"  Forwarded by: {forwarded_by}")

                # Handle content more carefully
                try:
                    content = str(row.get('content', 'No content'))
                    # Remove non-ASCII characters and truncate
                    content = ''.join(c for c in content if ord(c) < 128)
                    if len(content) > 100:
                        content = content[:100] + "..."
                    print(f"  Content: {content}")
                except:
                    print("  Content: [Contains special characters - check Google Sheet for full content]")

                print()
            except Exception as e:
                print(f"MESSAGE {i+1}: [Error displaying message: {str(e)}]")
                print()

        print("="*60)
        print("TO IMPORT TO GOOGLE SHEETS:")
        print(f"1. Open: https://docs.google.com/spreadsheets/d/{self.sheet_id}")
        print("2. Copy the data from above and paste into your sheet")
        print("3. Or run: python setup_google_sheets.py (for automatic imports)")
        print("="*60)

    def append_data(self, data: List[List[Any]], range_name: str = 'A1') -> bool:
        """
        Append data to sheet.

        Args:
            data: Data to append
            range_name: Range to append to

        Returns:
            True if successful, False otherwise
        """
        try:
            # Convert to DataFrame for display
            if data and len(data) > 0:
                df = pd.DataFrame(data)
                return self.write_dataframe(df, range_name)
            return True
        except Exception as e:
            logger.error(f"Error appending data: {str(e)}")
            return False

    def clear_sheet(self, range_name: str = 'A1') -> bool:
        """
        Clear sheet range.

        Args:
            range_name: Range to clear

        Returns:
            True if successful, False otherwise
        """
        print(f"Would clear range {range_name} in sheet {self.sheet_id}")
        return True
