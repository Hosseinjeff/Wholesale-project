"""
Telegram Bot API reader implementation using forwarded messages.
"""

import logging
from typing import List, Dict, Any
from datetime import datetime
import requests
import time

from .base_reader import BaseChannelReader

logger = logging.getLogger(__name__)

class TelegramBotReader(BaseChannelReader):
    """
    Reads forwarded posts from Telegram bot using Bot API.
    Users forward messages to the bot, which then processes them.
    """

    def __init__(self, config: Dict[str, Any]):
        super().__init__(config)
        self.bot_token = config.get('TELEGRAM_BOT_TOKEN')
        self.base_url = f"https://api.telegram.org/bot{self.bot_token}"
        self.last_update_id = 0

    def authenticate(self) -> bool:
        """
        Test bot authentication with Telegram API.

        Returns:
            True if authentication successful, False otherwise
        """
        try:
            response = requests.get(f"{self.base_url}/getMe", timeout=10)
            if response.status_code == 200:
                bot_info = response.json()
                if bot_info.get('ok'):
                    logger.info(f"Bot authenticated: @{bot_info['result']['username']}")
                    return True
            logger.error("Bot authentication failed")
            return False
        except Exception as e:
            logger.error(f"Authentication error: {str(e)}")
            return False

    def read_posts(self, limit: int = 100) -> List[Dict[str, Any]]:
        """
        Read forwarded messages sent to the bot.

        Args:
            limit: Maximum number of posts to read

        Returns:
            List of standardized post dictionaries
        """
        if not self.authenticate():
            return []

        try:
            # Get updates from bot (messages forwarded to it)
            updates_response = requests.get(
                f"{self.base_url}/getUpdates",
                params={
                    'offset': self.last_update_id + 1,
                    'limit': limit,
                    'timeout': 1
                },
                timeout=10
            )

            if not updates_response.json().get('ok'):
                logger.warning("No updates available")
                return []

            messages = []
            updates = updates_response.json()['result']

            for update in updates:
                if update.get('message') and update['message'].get('forward_origin'):
                    # This is a forwarded message
                    standardized_post = self._standardize_forwarded_post(update['message'])
                    messages.append(standardized_post)

                    # Update last processed update_id
                    self.last_update_id = max(self.last_update_id, update['update_id'])

            logger.info(f"Retrieved {len(messages)} forwarded messages")
            return messages

        except Exception as e:
            logger.error(f"Error reading forwarded posts: {str(e)}")
            return []

    def _standardize_forwarded_post(self, message: Dict[str, Any]) -> Dict[str, Any]:
        """
        Convert forwarded Telegram message to standardized format.

        Args:
            message: Forwarded message from bot

        Returns:
            Standardized post dictionary
        """
        forward_origin = message.get('forward_origin', {})

        # Extract original post information from forward_origin
        original_info = self._extract_forward_info(forward_origin)

        return {
            'id': str(message.get('message_id', '')),
            'channel': 'telegram',
            'channel_username': original_info.get('channel_username', 'Unknown'),
            'author': original_info.get('author', 'Unknown'),
            'content': message.get('text', '') or message.get('caption', ''),
            'timestamp': self._format_timestamp(original_info.get('timestamp', message.get('date', 0))),
            'url': original_info.get('url', ''),
            'has_media': bool(message.get('photo') or message.get('document') or message.get('video')),
            'media_type': self._get_media_type(message),
            'reply_to': None,
            'views': None,
            'forwards': None,
            'edited': bool(message.get('edit_date')),
            'forwarded_by': message.get('from', {}).get('username', 'Unknown'),
            'forwarded_at': self._format_timestamp(message.get('date', 0)),
            'raw_data': str(message)
        }

    def _extract_forward_info(self, forward_origin: Dict[str, Any]) -> Dict[str, Any]:
        """
        Extract information from forward_origin.

        Args:
            forward_origin: Forward origin data

        Returns:
            Dictionary with extracted information
        """
        info = {
            'author': 'Unknown',
            'channel_username': 'Unknown',
            'timestamp': None,
            'url': ''
        }

        origin_type = forward_origin.get('type')

        if origin_type == 'channel':
            channel_info = forward_origin.get('chat', {})
            info['author'] = channel_info.get('title', 'Unknown Channel')
            info['channel_username'] = f"@{channel_info.get('username', '')}"
            info['timestamp'] = forward_origin.get('date')
            if channel_info.get('username'):
                info['url'] = f"https://t.me/{channel_info['username']}/{forward_origin.get('message_id', '')}"

        elif origin_type == 'user':
            user_info = forward_origin.get('sender_user', {})
            info['author'] = user_info.get('first_name', '') + ' ' + user_info.get('last_name', '').strip()
            info['author'] = info['author'].strip() or user_info.get('username', 'Unknown User')
            info['timestamp'] = forward_origin.get('date')

        return info

    def _get_media_type(self, message: Dict[str, Any]) -> str:
        """Get media type from message."""
        if message.get('photo'):
            return 'photo'
        elif message.get('document'):
            return 'document'
        elif message.get('video'):
            return 'video'
        elif message.get('audio'):
            return 'audio'
        elif message.get('voice'):
            return 'voice'
        return None
