"""
collectors/snapshots_collector.py

Fetches live probability, volume, and orderbook data for every tracked
market and writes immutable rows to `market_snapshots`.

Responsibilities:
  1. Load all active, tracked markets from the DB
  2. Fetch one orderbook per market from the CLOB API
  3. Fetch external context (BTC/ETH price, Fear & Greed) once per run
  4. Write one snapshot row per market (with external context attached)
  5. Update the denormalized probability cache on the markets table

NOT responsible for:
  - Fetching market metadata (that is markets_collector.py)
  - Signal generation or paper trading
  - Any AI probability estimation

Architecture:
  SnapshotsCollector depends on:
    - PolymarketService        (CLOB API calls)
    - ExternalPriceService     (BTC/ETH price, Fear & Greed)
    - MarketRepository         (read markets, update cache)
    - SnapshotRepository       (write snapshots)
    - get_session()            (transaction management)

Transaction strategy:
  Unlike the markets collector (which commits per page), the snapshot
  collector commits in BATCHES of COMMIT_BATCH_SIZE markets. This balances
  two concerns:
    - Atomicity: if the run is interrupted, completed batches are preserved
    - Performance: committing 200 individual transactions is ~5x slower than
      committing 10 batches of 20

Run cadence: every 5 minutes (configurable via SNAPSHOT_INTERVAL_MINUTES)
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from datetime import datetime, timezone

from config.settings import settings
from utils.db import get_session
from utils.logger import get_logger
from repositories.market_repository import MarketRepository
from repositories.snapshot_repository import SnapshotRepository
from services.polymarket_service import PolymarketService, PolymarketAPIError, RawOrderbook
from services.external_price_service import ExternalPriceService, ExternalPriceContext

log = get_logger(__name__)

# Number of markets to commit per transaction
COMMIT_BATCH_SIZE = 20


@dataclass
class SnapshotCollectionResult:
    """Metrics for a single snapshot run."""
    snapshots_written: int = 0
    snapshots_skipped: int = 0       # duplicate guard triggered
    markets_failed: int = 0          # CLOB API error or other failure
    markets_processed: int = 0
    external_context_available: bool = False
    duration_seconds: float = 0.0

    @property
    def success_rate(self) -> float:
        if self.markets_processed == 0:
            return 0.0
        return self.snapshots_written / self.markets_processed


class SnapshotsCollector:
    """
    Collects live probability snapshots for all tracked markets.

    Usage (called by scheduler every SNAPSHOT_INTERVAL_MINUTES):
        collector = SnapshotsCollector()
        result = collector.run()
    """

    def __init__(self) -> None:
        self._polymarket = PolymarketService()
        self._price_service = ExternalPriceService()
        self._market_repo = MarketRepository()
        self._snapshot_repo = SnapshotRepository()

    def close(self) -> None:
        """Release HTTP clients. Called on scheduler shutdown."""
        self._polymarket.close()
        self._price_service.close()

    def run(self) -> SnapshotCollectionResult:
        """
        Execute a full snapshot collection run.

        Steps:
          1. Load all active tracked markets from DB
          2. Fetch external price context (once, cached)
          3. Fetch CLOB orderbooks in batches
          4. Write snapshots and update market cache
        """
        log.info("snapshots_collection_start")
        started_at = time.monotonic()
        result = SnapshotCollectionResult()

        try:
            # Step 1: Load markets to snapshot
            markets = self._load_tracked_markets()
            if not markets:
                log.warning("snapshots_no_tracked_markets")
                return result

            total = len(markets)
            log.info("snapshots_markets_loaded", count=total)

            # Step 2: Fetch external context (BTC price, Fear & Greed)
            # This is fetched ONCE and attached to every snapshot in this run.
            # Failures are non-fatal — context fields will be NULL.
            context = self._fetch_external_context()
            result.external_context_available = context.btc_price_usd is not None

            # Step 3 & 4: Process markets in batches
            # Respect the safety cap from settings
            markets_to_process = markets[: settings.snapshot_batch_size]
            if len(markets_to_process) < total:
                log.warning(
                    "snapshots_batch_capped",
                    total=total,
                    cap=settings.snapshot_batch_size,
                )

            batches = self._chunk(markets_to_process, COMMIT_BATCH_SIZE)
            for batch_num, batch in enumerate(batches, start=1):
                batch_result = self._process_batch(batch, context, batch_num)
                result.snapshots_written += batch_result.snapshots_written
                result.snapshots_skipped += batch_result.snapshots_skipped
                result.markets_failed += batch_result.markets_failed
                result.markets_processed += batch_result.markets_processed

        except Exception as exc:
            log.exception("snapshots_collection_unexpected_error", error=str(exc))
            raise

        finally:
            result.duration_seconds = time.monotonic() - started_at

        log.info(
            "snapshots_collection_complete",
            written=result.snapshots_written,
            skipped=result.snapshots_skipped,
            failed=result.markets_failed,
            processed=result.markets_processed,
            success_rate=round(result.success_rate, 3),
            external_context=result.external_context_available,
            duration_seconds=round(result.duration_seconds, 2),
        )

        return result

    # -------------------------------------------------------------------------
    # Private — batch processing
    # -------------------------------------------------------------------------

    def _load_tracked_markets(self) -> list:
        """Load all active tracked markets in a read-only session."""
        with get_session() as session:
            return self._market_repo.get_active_tracked(session)

    def _fetch_external_context(self) -> ExternalPriceContext:
        """
        Fetch external price context. Failures return a null context —
        snapshot collection continues without external prices.
        """
        try:
            return self._price_service.get_context()
        except Exception as exc:
            log.warning("external_context_fetch_failed", error=str(exc))
            from services.external_price_service import ExternalPriceContext
            return ExternalPriceContext(
                btc_price_usd=None,
                eth_price_usd=None,
                fear_greed_index=None,
                btc_dominance=None,
            )

    def _process_batch(
        self,
        markets: list,
        context: ExternalPriceContext,
        batch_num: int,
    ) -> SnapshotCollectionResult:
        """
        Process one batch of markets within a single transaction.

        For each market:
          1. Fetch its CLOB orderbook (HTTP, outside transaction)
          2. Write a snapshot row (inside transaction)
          3. Update markets.market_probability cache (inside transaction)

        The HTTP fetches happen BEFORE the transaction opens so we don't
        hold the DB connection open during network I/O. All writes happen
        in one fast transaction at the end.
        """
        result = SnapshotCollectionResult()

        # Step 1: Fetch all CLOB orderbooks for this batch (outside transaction)
        condition_ids = [m.condition_id for m in markets]
        orderbooks = self._fetch_orderbooks(condition_ids)

        snapshotted_at = datetime.now(timezone.utc)

        # Step 2: Write snapshots in one transaction
        try:
            with get_session() as session:
                for market in markets:
                    result.markets_processed += 1
                    orderbook = orderbooks.get(market.condition_id)

                    if orderbook is None:
                        result.markets_failed += 1
                        log.warning(
                            "snapshot_skipped_no_orderbook",
                            market_id=market.id,
                            condition_id=market.condition_id,
                        )
                        continue

                    written = self._write_snapshot(
                        session=session,
                        market=market,
                        orderbook=orderbook,
                        context=context,
                        snapshotted_at=snapshotted_at,
                    )

                    if written:
                        result.snapshots_written += 1
                        # Update denormalized cache on markets table
                        # Volume/liquidity come from Gamma API (in market table), not CLOB API
                        self._market_repo.update_probability_cache(
                            session,
                            market_id=market.id,
                            probability=orderbook.probability_yes,
                        )
                    else:
                        result.snapshots_skipped += 1

        except Exception as exc:
            log.error(
                "snapshot_batch_transaction_failed",
                batch=batch_num,
                error=str(exc),
                market_count=len(markets),
            )
            result.markets_failed += len(markets)
            result.snapshots_written = 0

        log.debug(
            "snapshot_batch_complete",
            batch=batch_num,
            written=result.snapshots_written,
            skipped=result.snapshots_skipped,
            failed=result.markets_failed,
        )

        return result

    def _fetch_orderbooks(self, condition_ids: list[str]) -> dict[str, RawOrderbook | None]:
        import concurrent.futures
        
        results: dict[str, RawOrderbook | None] = {}

        def fetch_single(cid: str) -> tuple[str, RawOrderbook | None]:
            try:
                # Menggunakan method get_orderbook (single) dari polymarket_service
                return cid, self._polymarket.get_orderbook(cid)
            except Exception as exc:
                log.error("orderbook_fetch_failed", condition_id=cid, error=str(exc))
                return cid, None

        # Gunakan 15 thread pekerja agar bisa menarik banyak market sekaligus
        max_threads = 15
        with concurrent.futures.ThreadPoolExecutor(max_workers=max_threads) as executor:
            future_to_cid = {executor.submit(fetch_single, cid): cid for cid in condition_ids}
            for future in concurrent.futures.as_completed(future_to_cid):
                cid, orderbook = future.result()
                results[cid] = orderbook

        return results


    def _write_snapshot(
        self,
        session,
        market,
        orderbook: RawOrderbook,
        context: ExternalPriceContext,
        snapshotted_at: datetime,
    ) -> bool:
        """
        Write one snapshot row.
        Returns True if written, False if duplicate-guarded.

        Spread is computed here: best_ask - best_bid.
        Redundant (the migration comment says "computed on insert") but
        we compute it in Python for transparency rather than relying on
        a DB trigger or generated column.
        """
        snapshot = self._snapshot_repo.insert_snapshot(
            session,
            market_id=market.id,
            probability_yes=orderbook.probability_yes,
            probability_no=orderbook.probability_no,
            best_bid=orderbook.best_bid,
            best_ask=orderbook.best_ask,
            spread=orderbook.spread,
            volume_usd=market.volume_usd,
            volume_24h_usd=market.volume_usd,
            liquidity_usd=market.liquidity_usd,
            snapshotted_at=snapshotted_at,
            btc_price_usd=context.btc_price_usd,
            eth_price_usd=context.eth_price_usd,
            fear_greed_index=context.fear_greed_index,
            btc_dominance=context.btc_dominance,
            collector_version=settings.collector_version,
        )
        return snapshot is not None

    @staticmethod
    def _chunk(lst: list, size: int) -> list[list]:
        """Split a list into chunks of at most `size` elements."""
        return [lst[i : i + size] for i in range(0, len(lst), size)]
