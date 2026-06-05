"""
Exponential backoff retry decorator for API calls.
All collector HTTP requests MUST use this.

Changelog:
  v1.1 — tambah parameter exclude_on yang menerima callable predicate.
          Dipakai oleh get_orderbook() agar PolymarketAPIError 404
          tidak di-retry (permanent error, bukan transient).
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
    exclude_on: Callable[[Exception], bool] | None = None,
) -> Callable:
    """
    Decorator: retry a function with exponential backoff.

    Args:
        max_attempts:    Jumlah maksimal percobaan.
        backoff_seconds: Base untuk exponential backoff (wait = base ** attempt).
        exceptions:      Exception types yang akan di-retry.
        exclude_on:      Optional callable(exc) -> bool. Kalau return True,
                         exception TIDAK di-retry dan langsung di-raise.
                         Dipakai untuk skip retry pada permanent errors (404, dll).

    Usage:
        @retry(
            exceptions=(PolymarketAPIError, httpx.HTTPError),
            exclude_on=lambda exc: getattr(exc, 'status_code', None) == 404,
        )
        def get_orderbook(...): ...
    """
    def decorator(func: Callable) -> Callable:
        @wraps(func)
        def wrapper(*args, **kwargs):
            last_exception = None
            for attempt in range(1, max_attempts + 1):
                try:
                    return func(*args, **kwargs)
                except exceptions as exc:
                    # Permanent error — jangan retry, langsung raise
                    if exclude_on is not None and exclude_on(exc):
                        logger.debug(
                            "Retry excluded for %s (attempt %d): %s",
                            func.__name__,
                            attempt,
                            exc,
                        )
                        raise

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