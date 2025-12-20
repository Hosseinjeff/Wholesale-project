"""
Slack channel reader implementation.
"""

import logging
from typing import List, Dict, Any
from slack_sdk import WebClient
from slack_sdk.errors import SlackApiError

from .base_reader import BaseChannelReader

logger = logging.getLogger(__name__)

class SlackReader(BaseChannelReader):
    """
    Reads posts from Slack channels using Slack SDK.
    """

    def __init__(self, config: Dict[str, Any]):
        super().__init__(config)
        self.bot_token = config.get('SLACK_BOT_TOKEN')
        self.channel_id = config.get('SLACK_CHANNEL_ID')
        self.client = None

    def authenticate(self) -> bool:
        """
        Authenticate with Slack API.

        Returns:
            True if authentication successful, False otherwise
        """
        try:
            self.client = WebClient(token=self.bot_token)

            # Test authentication
            response = self.client.auth_test()
            if response["ok"]:
                logger.info(f"Slack authenticated as: {response['user']}")
                return True
            else:
                logger.error("Slack authentication failed")
                return False

        except SlackApiError as e:
            logger.error(f"Slack authentication error: {e.response['error']}")
            return False
        except Exception as e:
            logger.error(f"Slack authentication error: {str(e)}")
            return False

    def read_posts(self, limit: int = 100) -> List[Dict[str, Any]]:
        """
        Read posts from Slack channel.

        Args:
            limit: Maximum number of posts to read

        Returns:
            List of standardized post dictionaries
        """
        if not self.client:
            if not self.authenticate():
                return []

        try:
            # Get channel history
            response = self.client.conversations_history(
                channel=self.channel_id,
                limit=limit
            )

            if not response["ok"]:
                logger.error(f"Failed to get Slack channel history: {response.get('error')}")
                return []

            messages = []
            for message in response["messages"]:
                standardized_post = self._standardize_post(message)
                messages.append(standardized_post)

            logger.info(f"Retrieved {len(messages)} messages from Slack")
            return messages

        except SlackApiError as e:
            logger.error(f"Slack API error: {e.response['error']}")
            return []
        except Exception as e:
            logger.error(f"Error reading Slack posts: {str(e)}")
            return []

    def _standardize_post(self, message: Dict[str, Any]) -> Dict[str, Any]:
        """
        Convert Slack message to standardized format.

        Args:
            message: Slack message dictionary

        Returns:
            Standardized post dictionary
        """
        return {
            'id': message.get('ts', ''),
            'channel': 'slack',
            'channel_id': self.channel_id,
            'author': message.get('user', 'Unknown'),
            'content': message.get('text', ''),
            'timestamp': self._format_timestamp(float(message.get('ts', 0))),
            'url': f"https://slack.com/archives/{self.channel_id}/p{message.get('ts', '').replace('.', '')}",
            'has_media': bool(message.get('files')),
            'media_type': 'file' if message.get('files') else None,
            'thread_ts': message.get('thread_ts'),
            'reply_count': message.get('reply_count', 0),
            'reactions': message.get('reactions', []),
            'raw_data': str(message)
        }
