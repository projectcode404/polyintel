"""
config/settings.py  (Sprint 2 — extends Sprint 1 with new tuning knobs)

All configuration from environment variables. No hardcoded values anywhere.
Pydantic-settings validates types at startup and raises clear errors on
misconfiguration, so the process fails fast rather than silently misbehaving.
"""

from __future__ import annotations
from urllib.parse import quote_plus
from pydantic_settings import BaseSettings
from pydantic import field_validator


class Settings(BaseSettings):

    # -------------------------------------------------------------------------
    # Database
    # -------------------------------------------------------------------------
    db_host: str = "localhost"
    db_port: int = 5432
    db_database: str = "polymarket"
    db_username: str = "polymarket"
    db_password: str

    # -------------------------------------------------------------------------
    # Redis  (reserved for Sprint 3 cache layer)
    # -------------------------------------------------------------------------
    redis_host: str = "redis"
    redis_port: int = 6379

    # -------------------------------------------------------------------------
    # Polymarket API endpoints
    # -------------------------------------------------------------------------
    polymarket_api_key: str = ""

    # Gamma API  — market metadata: question, end_date, category, tags, etc.
    polymarket_gamma_url: str = "https://gamma-api.polymarket.com"

    # CLOB API   — live orderbook / probability / volume data
    polymarket_clob_url: str = "https://clob.polymarket.com"

    # -------------------------------------------------------------------------
    # Collector schedule
    # -------------------------------------------------------------------------
    snapshot_interval_minutes: int = 5
    market_sync_interval_minutes: int = 60

    # How many markets per Gamma API page request
    gamma_page_size: int = 100

    # Safety cap: max markets to snapshot in one run (prevents runaway loops)
    snapshot_batch_size: int = 200

    # -------------------------------------------------------------------------
    # HTTP client
    # -------------------------------------------------------------------------
    http_connect_timeout: float = 10.0
    http_read_timeout: float = 30.0

    # -------------------------------------------------------------------------
    # Retry policy (used by @retry decorator in utils/retry.py)
    # -------------------------------------------------------------------------
    max_retries: int = 3
    retry_backoff_seconds: float = 2.0

    # -------------------------------------------------------------------------
    # Collector identity — written to every market_snapshots row
    # -------------------------------------------------------------------------
    collector_version: str = "1.0.0"

    # -------------------------------------------------------------------------
    # Logging
    # -------------------------------------------------------------------------
    log_level: str = "INFO"
    log_format: str = "console"   # "console" (dev)  |  "json" (production)
    debug_sql: bool = False

    # -------------------------------------------------------------------------
    # Computed properties
    # -------------------------------------------------------------------------

    @field_validator("snapshot_batch_size")   # ← tambah sebelum @property database_url
    @classmethod
    def validate_batch_size(cls, v: int) -> int:
        if v < 50:
            raise ValueError("snapshot_batch_size minimum 50")
        return v

    @property
    def database_url(self) -> str:
        """SQLAlchemy-compatible URL. Password is URL-encoded to handle special chars."""
        password = quote_plus(self.db_password)
        return (
            f"postgresql+psycopg2://{self.db_username}:{password}"
            f"@{self.db_host}:{self.db_port}/{self.db_database}"
        )

    @property
    def http_timeout(self) -> tuple[float, float]:
        """(connect_timeout, read_timeout) — passed directly to httpx.Timeout."""
        return (self.http_connect_timeout, self.http_read_timeout)

    model_config = {"env_file": ".env", "case_sensitive": False}


# Module-level singleton — import this everywhere
settings = Settings()
