"""
utils/logger.py

Structured logging for the entire collector process.
Every module uses get_logger(__name__) — never logging.getLogger().

Design decisions:
- structlog renders JSON in production (LOG_FORMAT=json) so log aggregators
  (Datadog, Loki, CloudWatch) can parse fields natively.
- In development (LOG_FORMAT=console) it renders colourised key=value pairs
  that are easy to read in a terminal.
- stdlib logging is redirected through structlog so SQLAlchemy echo,
  APScheduler job logs, and httpx all appear in the same format.
- configure_logging() is called exactly ONCE in scheduler.py at process boot,
  before any other import that might trigger logging.
"""

from __future__ import annotations

import logging
import sys
from typing import Any

import structlog

from config.settings import settings


def configure_logging() -> None:
    """
    Wire structlog + stdlib logging for the whole process.
    Must be called once at startup before any logger is used.
    """
    log_level = getattr(logging, settings.log_level.upper(), logging.INFO)

    # Processors applied to every event, regardless of final renderer
    shared_processors: list[Any] = [
        structlog.contextvars.merge_contextvars,
        structlog.stdlib.add_logger_name,
        structlog.stdlib.add_log_level,
        structlog.stdlib.PositionalArgumentsFormatter(),
        structlog.processors.TimeStamper(fmt="iso", utc=True),
        structlog.processors.StackInfoRenderer(),
        structlog.processors.ExceptionRenderer(),
    ]

    renderer: Any = (
        structlog.processors.JSONRenderer()
        if settings.log_format == "json"
        else structlog.dev.ConsoleRenderer(colors=True)
    )

    structlog.configure(
        processors=shared_processors
        + [structlog.stdlib.ProcessorFormatter.wrap_for_formatter],
        logger_factory=structlog.stdlib.LoggerFactory(),
        wrapper_class=structlog.stdlib.BoundLogger,
        cache_logger_on_first_use=True,
    )

    formatter = structlog.stdlib.ProcessorFormatter(
        foreign_pre_chain=shared_processors,
        processors=[
            structlog.stdlib.ProcessorFormatter.remove_processors_meta,
            renderer,
        ],
    )

    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(formatter)

    root = logging.getLogger()
    root.handlers = [handler]
    root.setLevel(log_level)

    # Suppress noisy third-party loggers in normal operation
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)
    logging.getLogger("apscheduler").setLevel(logging.INFO)
    logging.getLogger("sqlalchemy.engine").setLevel(
        logging.DEBUG if settings.debug_sql else logging.WARNING
    )


def get_logger(name: str) -> structlog.stdlib.BoundLogger:
    """
    Return a bound structlog logger for the given module name.

    Usage:
        log = get_logger(__name__)
        log.info("snapshot_stored", market_id=42, probability_yes=0.63)
        log.warning("api_slow", elapsed_ms=4200, url="https://...")
        log.error("db_write_failed", exc_info=True)
    """
    return structlog.get_logger(name)
