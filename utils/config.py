"""
Configuration management utilities.
"""

import os
from typing import Dict, Any
from dotenv import load_dotenv

def load_config(config_path: str = '.env') -> Dict[str, Any]:
    """
    Load configuration from environment variables and .env file.

    Args:
        config_path: Path to the .env configuration file

    Returns:
        Dictionary containing configuration values
    """
    # Load environment variables from .env file
    load_dotenv(config_path)

    # Get all relevant environment variables
    config = {
        # Telegram configuration
        'TELEGRAM_BOT_TOKEN': os.getenv('TELEGRAM_BOT_TOKEN'),

        # Discord configuration
        'DISCORD_BOT_TOKEN': os.getenv('DISCORD_BOT_TOKEN'),
        'DISCORD_CHANNEL_ID': os.getenv('DISCORD_CHANNEL_ID'),

        # Slack configuration
        'SLACK_BOT_TOKEN': os.getenv('SLACK_BOT_TOKEN'),
        'SLACK_CHANNEL_ID': os.getenv('SLACK_CHANNEL_ID'),

        # Google Sheets configuration
        'GOOGLE_SHEETS_CREDENTIALS_PATH': os.getenv('GOOGLE_SHEETS_CREDENTIALS_PATH', 'credentials.json'),

        # General configuration
        'LOG_LEVEL': os.getenv('LOG_LEVEL', 'INFO'),
        'REQUEST_TIMEOUT': int(os.getenv('REQUEST_TIMEOUT', '30')),
        'MAX_RETRIES': int(os.getenv('MAX_RETRIES', '3')),
    }

    return config
