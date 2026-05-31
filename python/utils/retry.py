"""
Exponential backoff retry decorator for API calls.
All collector HTTP requests MUST use this.
"""

from __future__ import annotations

import logging
import time
from functools import wraps
from typing import Callable, Type

from config.settings import settings

logger = logging.getLogger(__name__)


def retry(
    max_attempts: int = settings.max_retries,
    backoff_seconds: float = settings.retry_backoff_seconds,
    exceptions: tuple[Type[Exception], ...] = (Exception,),
) -> Callable:
    """
    Decorator: retry a function with exponential backoff.

    Usage:
        @retry(max_attempts=3, exceptions=(HTTPError, ConnectionError))
        def fetch_market(condition_id: str) -> dict: ...
    """
    def decorator(func: Callable) -> Callable:
        @wraps(func)
        def wrapper(*args, **kwargs):
            last_exception = None
            for attempt in range(1, max_attempts + 1):
                try:
                    return func(*args, **kwargs)
                except exceptions as exc:
                    last_exception = exc
                    wait = backoff_seconds ** attempt
                    logger.warning(
                        "Attempt %d/%d failed for %s: %s. Retrying in %.1fs",
                        attempt,
                        max_attempts,
                        func.__name__,
                        exc,
                        wait,
                    )
                    if attempt < max_attempts:
                        time.sleep(wait)
            logger.error("All %d attempts failed for %s", max_attempts, func.__name__)
            raise last_exception
        return wrapper
    return decorator
