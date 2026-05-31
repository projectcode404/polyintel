"""
Database session factory.
All collectors import get_session() from here — single source of truth.
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Generator

from sqlalchemy import create_engine
from sqlalchemy.orm import Session, sessionmaker

from config.settings import settings

_engine = create_engine(
    settings.database_url,
    pool_pre_ping=True,       # Verify connections are alive before using
    pool_size=5,              # Collector is low-concurrency; 5 is plenty
    max_overflow=10,
    echo=settings.debug_sql,  # Log SQL in dev only
)

_SessionFactory = sessionmaker(bind=_engine, expire_on_commit=False)


@contextmanager
def get_session() -> Generator[Session, None, None]:
    """
    Context manager that provides a transactional database session.

    Usage:
        with get_session() as session:
            session.add(snapshot)
            # commits on exit, rolls back on exception

    Design: Always use transactions. Never write raw SQL in collectors
    when the ORM is sufficient.
    """
    session = _SessionFactory()
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()
