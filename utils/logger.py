"""
Logging configuration utilities.
"""

import logging
import sys
from pathlib import Path

def setup_logger(log_level: int = logging.INFO, log_file: str = 'channel_import.log'):
    """
    Setup logging configuration for the application.

    Args:
        log_level: Logging level (e.g., logging.DEBUG, logging.INFO)
        log_file: Path to log file
    """
    # Create logs directory if it doesn't exist
    log_path = Path(log_file)
    log_path.parent.mkdir(exist_ok=True)

    # Configure logging
    logging.basicConfig(
        level=log_level,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
        handlers=[
            logging.FileHandler(log_file),
            logging.StreamHandler(sys.stdout)
        ]
    )

    # Reduce verbosity of some third-party libraries
    logging.getLogger('googleapiclient').setLevel(logging.WARNING)
    logging.getLogger('google.auth').setLevel(logging.WARNING)
    logging.getLogger('urllib3').setLevel(logging.WARNING)

def get_logger(name: str) -> logging.Logger:
    """
    Get a logger instance with the specified name.

    Args:
        name: Logger name (usually __name__)

    Returns:
        Configured logger instance
    """
    return logging.getLogger(name)
