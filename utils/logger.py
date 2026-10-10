"""
Logging configuration utilities.
"""

import logging
import sys
from pathlib import Path

def setup_logger(log_level: int = logging.INFO, log_file: str | None = 'channel_import.log'):
    """
    Setup logging configuration for the application.

    Args:
        log_level: Logging level (e.g., logging.DEBUG, logging.INFO)
        log_file: Path to log file. None logs to the console only.
    """
    handlers = [logging.StreamHandler(sys.stdout)]
    if log_file:
        log_path = Path(log_file)
        log_path.parent.mkdir(exist_ok=True)
        handlers.insert(0, logging.FileHandler(log_file))

    # Configure logging
    logging.basicConfig(
        level=log_level,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
        handlers=handlers,
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
