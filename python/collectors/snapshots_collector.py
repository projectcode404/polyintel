"""
collectors/snapshots_collector.py

Fetches live probability, volume, and orderbook data for every tracked
market and writes immutable rows to `market_snapshots`.

Changelog:
  v1.3 — fix volume_24h_usd: sebelumnya pakai market.volume_usd (salah).
          Sekarang pakai market.volume_24h_usd yang benar dari Gamma field
          volume24hr. market.volume_usd tetap dipakai untuk total volume.
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

COMMIT_BATCH_SIZE = 20


@dataclass
class SnapshotCollectionResult:
    """Metrics for a single snapshot run."""
    snapshots_written: int = 0
    snapshots_skipped: int = 0
    markets_failed: int = 0
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
    """

    def __init__(self) -> None:
        self._polymarket = PolymarketService()
        self._price_service = ExternalPriceService()
        self._market_repo = MarketRepository()
        self._snapshot_repo = SnapshotRepository()

    def close(self) -> None:
        self._polymarket.close()
        self._price_service.close()

    def run(self) -> SnapshotCollectionResult:
        log.info("snapshots_collection_start")
        started_at = time.monotonic()
        result = SnapshotCollectionResult()

        try:
            markets = self._load_tracked_markets()
            if not markets:
                log.warning("snapshots_no_tracked_markets")
                return result

            total = len(markets)
            log.info("snapshots_markets_loaded", count=total)

            context = self._fetch_external_context()
            result.external_context_available = context.btc_price_usd is not None

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
        with get_session() as session:
            return self._market_repo.get_active_tracked(session)

    def _fetch_external_context(self) -> ExternalPriceContext:
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
        result = SnapshotCollectionResult()

        condition_ids = [m.condition_id for m in markets]
        orderbooks = self._fetch_orderbooks(condition_ids)

        snapshotted_at = datetime.now(timezone.utc)

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
                return cid, self._polymarket.get_orderbook(cid)
            except Exception as exc:
                log.error("orderbook_fetch_failed", condition_id=cid, error=str(exc))
                return cid, None

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

        FIX v1.3: volume_24h_usd sekarang dari market.volume_24h_usd
        (bukan market.volume_usd seperti sebelumnya).
        market.volume_usd = total lifetime volume.
        market.volume_24h_usd = rolling 24h volume dari Gamma field volume24hr.
        """
        snapshot = self._snapshot_repo.insert_snapshot(
            session,
            market_id=market.id,
            probability_yes=orderbook.probability_yes,
            probability_no=orderbook.probability_no,
            best_bid=orderbook.best_bid,
            best_ask=orderbook.best_ask,
            spread=orderbook.spread,
            # FIX: total lifetime volume
            volume_usd=market.volume_usd,
            # FIX: rolling 24h volume — sebelumnya salah pakai market.volume_usd
            volume_24h_usd=market.volume_24h_usd,
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
        return [lst[i : i + size] for i in range(0, len(lst), size)]