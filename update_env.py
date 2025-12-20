#!/usr/bin/env python3
"""
Update .env file with Google Sheet ID for auto-import.
"""

def update_env():
    """Update .env file with sheet ID."""
    sheet_id = "1u5LGXqiEfcPTsopvHOwkh-qvJO5zDK98pVmFhL-xWDs"

    # Read current .env content
    try:
        with open('.env', 'r') as f:
            content = f.read()
    except FileNotFoundError:
        content = ""

    # Add sheet ID if not present
    if 'GOOGLE_SHEET_ID=' not in content:
        if content and not content.endswith('\n'):
            content += '\n'
        content += f'GOOGLE_SHEET_ID={sheet_id}\n'
        content += 'CHECK_INTERVAL=30\n'

        with open('.env', 'w') as f:
            f.write(content)

        print(f"SUCCESS: Updated .env with Sheet ID: {sheet_id}")
    else:
        print("Sheet ID already configured")

if __name__ == '__main__':
    update_env()
