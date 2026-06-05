"""
collectors/markets_collector.py

Fetches active crypto markets from the Polymarket Gamma API and keeps
the `markets` table in sync.

Responsibilities:
  1. Page through all active markets on the Gamma API (crypto only)
  2. Upsert each market: insert new, update existing metadata
  3. Mark markets as resolved when the API reports them closed
  4. Log detailed metrics for every run

NOT responsible for:
  - Fetching snapshots (that is snapshots_collector.py)
  - Fetching external prices (that is external_price_service.py)
  - Signal generation or paper trading

Architecture:
  MarketsCollector depends on:
    - PolymarketService   (HTTP, owned by this collector instance)
    - MarketRepository    (DB writes)
    - get_session()       (transaction management)

  One MarketsCollector instance is created at scheduler startup and reused
  across runs. The httpx.Client inside PolymarketService persists between
  runs, reusing TCP connections for efficiency.

Run cadence: every 60 minutes (configurable via MARKET_SYNC_INTERVAL_MINUTES)
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field

from utils.db import get_session
from utils.logger import get_logger
from repositories.market_repository import MarketRepository
from services.polymarket_service import PolymarketService, PolymarketAPIError

log = get_logger(__name__)


@dataclass
class MarketCollectionResult:
    """
    Metrics for a single market collection run.
    Logged at INFO level after each run for monitoring.
    """
    inserted: int = 0
    updated: int = 0
    skipped: int = 0
    errors: int = 0
    pages_fetched: int = 0
    duration_seconds: float = 0.0

    @property
    def total_processed(self) -> int:
        return self.inserted + self.updated + self.skipped + self.errors


class MarketsCollector:
    """
    Synchronises the local markets table with Polymarket's Gamma API.

    Usage (called by scheduler every MARKET_SYNC_INTERVAL_MINUTES):
        collector = MarketsCollector()
        result = collector.run()

    The collector is stateless between runs — any state from the previous
    run is discarded and re-fetched from the API.
    """

    def __init__(self) -> None:
        self._service = PolymarketService()
        self._market_repo = MarketRepository()

    def close(self) -> None:
        """Release the HTTP client. Called on scheduler shutdown."""
        self._service.close()

    def run(self, full_scan: bool = False) -> MarketCollectionResult:
        """
        Execute a market sync run.

        full_scan=False (default, incremental):
            Fetches top 500 markets by volume. Used for frequent syncs.
            Captures all high-liquidity crypto markets. Runtime ~1-2s.

        full_scan=True (daily):
            Fetches all ~10,000 active markets. Discovers new/low-volume
            crypto markets. Runtime ~15-35s. Run once per day at midnight.

        Each page is committed in its own transaction — if a later page
        fails, earlier pages are already persisted.

        Returns a MarketCollectionResult with counts for monitoring.
        """
        log.info("markets_collection_start", full_scan=full_scan)
        started_at = time.monotonic()
        result = MarketCollectionResult()

        try:
            for page in self._service.iter_active_crypto_markets(full_scan=full_scan):
                result.pages_fetched += 1
                page_result = self._process_page(page)

                result.inserted += page_result.inserted
                result.updated += page_result.updated
                result.skipped += page_result.skipped
                result.errors += page_result.errors

        except PolymarketAPIError as exc:
            log.error(
                "markets_collection_api_error",
                error=str(exc),
                status_code=exc.status_code,
                pages_fetched=result.pages_fetched,
            )
            # Do not re-raise — partial sync is better than no sync.
            # The scheduler will retry on the next interval.

        except Exception as exc:
            log.exception("markets_collection_unexpected_error", error=str(exc))
            # Unexpected errors re-raise so the scheduler can log them
            # and we know something is genuinely broken.
            raise

        finally:
            result.duration_seconds = time.monotonic() - started_at

        log.info(
            "markets_collection_complete",
            inserted=result.inserted,
            updated=result.updated,
            skipped=result.skipped,
            errors=result.errors,
            pages=result.pages_fetched,
            duration_seconds=round(result.duration_seconds, 2),
        )

        return result

    # -------------------------------------------------------------------------
    # Private — page processing
    # -------------------------------------------------------------------------

    def _process_page(self, page: list) -> MarketCollectionResult:
        """
        Process one page of markets within a single transaction.

        The entire page is committed atomically. If any market in the page
        raises an unexpected exception, the page transaction is rolled back
        and we log the error — other pages are not affected.
        """
        result = MarketCollectionResult()

        try:
            with get_session() as session:
                for raw_market in page:
                    try:
                        self._upsert_market(session, raw_market, result)
                    except Exception as exc:
                        # Per-market error: log and count, do not abort the page
                        result.errors += 1
                        log.error(
                            "market_upsert_error",
                            condition_id=getattr(raw_market, "condition_id", "unknown"),
                            error=str(exc),
                        )

        except Exception as exc:
            # Page-level transaction failure — rolled back automatically
            log.error("page_transaction_failed", error=str(exc), page_size=len(page))
            result.errors += len(page)

        return result

    def _upsert_market(self, session, raw_market, result: MarketCollectionResult) -> None:
        """
        Upsert a single RawMarket into the database.

        We determine insert vs update by checking if the market already
        exists — this drives the result counters for monitoring accuracy.

        Note: the actual upsert is idempotent (ON CONFLICT DO UPDATE).
        The pre-check here is only for accurate metrics, not correctness.
        """
        if not raw_market.condition_id:
            log.warning("market_missing_condition_id", question=raw_market.question[:60])
            result.skipped += 1
            return

        # Check existence for metrics (cheap query, uses the condition_id unique index)
        existing = self._market_repo.find_by_condition_id(session, raw_market.condition_id)
        is_new = existing is None

        # Upsert
        market = self._market_repo.upsert_from_api(session, raw_market)

        if market is None:
            result.errors += 1
            return

        # Handle resolution: if Gamma says it's resolved but we have it as active,
        # mark_resolved() flips is_tracked=False to stop snapshot collection
        if raw_market.status == "resolved" and existing and existing.status == "active":
            from datetime import timezone
            from repositories.market_repository import MarketRepository
            resolved_dt = MarketRepository._parse_dt(raw_market.resolved_at)
            if resolved_dt:
                self._market_repo.mark_resolved(session, raw_market.condition_id, resolved_dt)

        if is_new:
            result.inserted += 1
            log.info(
                "market_inserted",
                condition_id=raw_market.condition_id,
                question=raw_market.question[:80] if raw_market.question else "",
                status=raw_market.status,
            )
        else:
            result.updated += 1
            log.debug(
                "market_updated",
                condition_id=raw_market.condition_id,
                status=raw_market.status,
            )
