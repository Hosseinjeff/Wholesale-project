"""
Telegram channel reader implementation.
"""

import logging
from typing import List, Dict, Any
from datetime import datetime
from telethon import TelegramClient
from telethon.errors import SessionPasswordNeededError

from .base_reader import BaseChannelReader

logger = logging.getLogger(__name__)

class TelegramReader(BaseChannelReader):
    """
    Reads posts from Telegram channels using Telethon library.
    """

    def __init__(self, config: Dict[str, Any]):
        super().__init__(config)
        self.api_id = config.get('TELEGRAM_API_ID')
        self.api_hash = config.get('TELEGRAM_API_HASH')
        self.channel_username = config.get('TELEGRAM_CHANNEL_USERNAME')
        self.phone_number = config.get('TELEGRAM_PHONE_NUMBER')
        self.client = None

    def authenticate(self) -> bool:
        """
        Authenticate with Telegram API.

        Returns:
            True if authentication successful, False otherwise
        """
        try:
            self.client = TelegramClient('session_name', self.api_id, self.api_hash)

            # Connect to Telegram
            logger.info("Connecting to Telegram...")
            self.client.start(phone=self.phone_number)

            # Check if we're authorized
            if not self.client.is_user_authorized():
                logger.error("Telegram authentication failed")
                return False

            logger.info("Successfully authenticated with Telegram")
            return True

        except SessionPasswordNeededError:
            logger.error("Telegram 2FA password required. Please handle manually.")
            return False
        except Exception as e:
            logger.error(f"Telegram authentication error: {str(e)}")
            return False

    def read_posts(self, limit: int = 100) -> List[Dict[str, Any]]:
        """
        Read posts from Telegram channel.

        Args:
            limit: Maximum number of posts to read

        Returns:
            List of standardized post dictionaries
        """
        if not self.client:
            if not self.authenticate():
                return []

        try:
            # Run async operations in a new event loop
            import asyncio
            return asyncio.run(self._read_posts_async(limit))

        except Exception as e:
            logger.error(f"Error reading Telegram posts: {str(e)}")
            return []
        finally:
            if self.client:
                try:
                    asyncio.run(self.client.disconnect())
                except:
                    pass

    async def _read_posts_async(self, limit: int) -> List[Dict[str, Any]]:
        """
        Async helper method for reading posts.
        """
        # Get the channel entity
        logger.info(f"Reading posts from channel: {self.channel_username}")
        channel = await self.client.get_entity(self.channel_username)

        # Get messages from the channel
        messages = []
        async for message in self.client.iter_messages(channel, limit=limit):
            standardized_post = self._standardize_post(message)
            messages.append(standardized_post)

        logger.info(f"Retrieved {len(messages)} messages from Telegram")
        return messages

    def _standardize_post(self, message) -> Dict[str, Any]:
        """
        Convert Telegram message to standardized format.

        Args:
            message: Telethon Message object

        Returns:
            Standardized post dictionary
        """
        return {
            'id': str(message.id),
            'channel': 'telegram',
            'channel_username': self.channel_username,
            'author': message.sender_id or 'Unknown',
            'content': message.text or '',
            'timestamp': self._format_timestamp(message.date),
            'url': f"https://t.me/{self.channel_username}/{message.id}",
            'has_media': message.media is not None,
            'media_type': type(message.media).__name__ if message.media else None,
            'reply_to': message.reply_to_msg_id,
            'views': getattr(message, 'views', 0),
            'forwards': getattr(message, 'forwards', 0),
            'edited': message.edit_date is not None,
            'raw_data': str(message)
        }
