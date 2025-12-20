"""
Base class for channel readers.
"""

from abc import ABC, abstractmethod
from typing import List, Dict, Any
from datetime import datetime

class BaseChannelReader(ABC):
    """
    Abstract base class for reading posts from various channels.
    """

    def __init__(self, config: Dict[str, Any]):
        """
        Initialize the channel reader.

        Args:
            config: Configuration dictionary
        """
        self.config = config

    @abstractmethod
    def read_posts(self, limit: int = 100) -> List[Dict[str, Any]]:
        """
        Read posts from the channel.

        Args:
            limit: Maximum number of posts to read

        Returns:
            List of post dictionaries with standardized format
        """
        pass

    @abstractmethod
    def authenticate(self) -> bool:
        """
        Authenticate with the channel API.

        Returns:
            True if authentication successful, False otherwise
        """
        pass

    def _standardize_post(self, raw_post: Dict[str, Any]) -> Dict[str, Any]:
        """
        Standardize a raw post into a common format.

        Args:
            raw_post: Raw post data from the channel

        Returns:
            Standardized post dictionary
        """
        # This method should be implemented by subclasses to convert
        # channel-specific post formats into a standard format
        raise NotImplementedError("Subclasses must implement _standardize_post")

    @staticmethod
    def _format_timestamp(timestamp) -> str:
        """
        Format timestamp to ISO string.

        Args:
            timestamp: Timestamp in various formats

        Returns:
            ISO formatted timestamp string
        """
        if isinstance(timestamp, datetime):
            return timestamp.isoformat()
        elif isinstance(timestamp, (int, float)):
            # Assume Unix timestamp
            return datetime.fromtimestamp(timestamp).isoformat()
        elif isinstance(timestamp, str):
            # Try to parse as ISO string
            try:
                dt = datetime.fromisoformat(timestamp.replace('Z', '+00:00'))
                return dt.isoformat()
            except ValueError:
                return timestamp
        else:
            return str(timestamp)
