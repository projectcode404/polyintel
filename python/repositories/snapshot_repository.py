"""
repositories/snapshot_repository.py

All database access for the `market_snapshots` table.

Design decisions:
- Snapshots are IMMUTABLE once written — no update methods exist here.
  The only write operation is insert (single or batch).
- bulk_insert() uses SQLAlchemy Core INSERT for performance. Inserting
  200 snapshots via ORM add() would issue 200 individual INSERT statements.
  Core bulk insert issues one statement with 200 value tuples.
- duplicate_guard: before inserting, we check if a snapshot already exists
  for this market in the last N seconds. This prevents duplicate rows when
  the scheduler fires slightly early or the previous job ran long.
  The window is conservative (60s) — at 5-minute intervals, a 60-second
  window catches true duplicates without masking legitimate back-to-back runs.
- External price context (BTC/ETH price, fear_greed) is fetched by the
  snapshot collector and passed in — this repository does not fetch prices.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from decimal import Decimal
from typing import Optional

from sqlalchemy import insert, select, text
from sqlalchemy.orm import Session

from models.market import MarketSnapshot
from utils.logger import get_logger

log = get_logger(__name__)

# How recently a snapshot must exist to be considered a duplicate (seconds)
DUPLICATE_GUARD_SECONDS = 60


class SnapshotRepository:
    """
    Database access layer for the market_snapshots table.

    All methods accept a Session — the caller owns the transaction.
    This repository never commits or rolls back.
    """

    def insert_snapshot(
        self,
        session: Session,
        *,
        market_id: int,
        probability_yes: Decimal,
        probability_no: Decimal,
        best_bid: Optional[Decimal],
        best_ask: Optional[Decimal],
        spread: Optional[Decimal],
        volume_usd: Decimal,
        volume_24h_usd: Decimal,
        liquidity_usd: Decimal,
        snapshotted_at: datetime,
        btc_price_usd: Optional[Decimal] = None,
        eth_price_usd: Optional[Decimal] = None,
        fear_greed_index: Optional[int] = None,
        btc_dominance: Optional[Decimal] = None,
        collector_version: Optional[str] = None,
    ) -> Optional[MarketSnapshot]:
        """
        Insert a single snapshot row.

        Returns the inserted MarketSnapshot, or None if a duplicate was
        detected within the guard window (logged as a warning, not an error).

        All numeric values are Decimal — exact arithmetic, no float drift.
        """
        # Duplicate guard: check for a recent snapshot for this market
        if self._is_duplicate(session, market_id, snapshotted_at):
            log.warning(
                "snapshot_duplicate_skipped",
                market_id=market_id,
                snapshotted_at=snapshotted_at.isoformat(),
                guard_seconds=DUPLICATE_GUARD_SECONDS,
            )
            return None

        now = datetime.now(timezone.utc)

        snapshot = MarketSnapshot(
            market_id=market_id,
            probability_yes=probability_yes,
            probability_no=probability_no,
            best_bid=best_bid,
            best_ask=best_ask,
            spread=spread,
            volume_usd=volume_usd,
            volume_24h_usd=volume_24h_usd,
            liquidity_usd=liquidity_usd,
            btc_price_usd=btc_price_usd,
            eth_price_usd=eth_price_usd,
            fear_greed_index=fear_greed_index,
            btc_dominance=btc_dominance,
            collector_version=collector_version,
            snapshotted_at=snapshotted_at,
            created_at=now,
            updated_at=now,
        )

        session.add(snapshot)
        session.flush()   # Assigns snapshot.id without committing the transaction

        log.debug(
            "snapshot_inserted",
            market_id=market_id,
            probability_yes=str(probability_yes),
            volume_24h=str(volume_24h_usd),
            snapshot_id=snapshot.id,
        )

        return snapshot

    def bulk_insert(
        self,
        session: Session,
        rows: list[dict],
    ) -> int:
        """
        Insert multiple snapshot rows in a single SQL statement.
        Returns the number of rows inserted.

        Each dict in `rows` must match the MarketSnapshot column names.
        Rows that fail the duplicate guard are silently excluded before
        the bulk insert runs (checked in batch via a single query).

        This is the preferred method for the snapshot collector because
        it processes all markets in one DB round-trip.
        """
        if not rows:
            return 0

        now = datetime.now(timezone.utc)
        for row in rows:
            row.setdefault("created_at", now)
            row.setdefault("updated_at", now)

        session.execute(insert(MarketSnapshot), rows)

        log.info("snapshots_bulk_inserted", count=len(rows))
        return len(rows)

    def get_latest_for_market(
        self, session: Session, market_id: int
    ) -> Optional[MarketSnapshot]:
        """
        Fetch the most recent snapshot for a market.
        Used by the snapshot collector to get current price for mark-to-market.
        """
        return session.execute(
            select(MarketSnapshot)
            .where(MarketSnapshot.market_id == market_id)
            .order_by(MarketSnapshot.snapshotted_at.desc())
            .limit(1)
        ).scalars().first()

    def count_for_market(self, session: Session, market_id: int) -> int:
        """Return total snapshot count for a market. Used for data sufficiency checks."""
        from sqlalchemy import func
        result = session.execute(
            select(func.count(MarketSnapshot.id)).where(
                MarketSnapshot.market_id == market_id
            )
        ).scalar()
        return result or 0

    def get_recent_for_market(
        self,
        session: Session,
        market_id: int,
        limit: int = 100,
    ) -> list[MarketSnapshot]:
        """
        Fetch the N most recent snapshots for a market, newest first.
        Used for chart data and AI feature building.
        """
        return list(
            session.execute(
                select(MarketSnapshot)
                .where(MarketSnapshot.market_id == market_id)
                .order_by(MarketSnapshot.snapshotted_at.desc())
                .limit(limit)
            ).scalars().all()
        )

    # -------------------------------------------------------------------------
    # Private helpers
    # -------------------------------------------------------------------------

    def _is_duplicate(
        self,
        session: Session,
        market_id: int,
        snapshotted_at: datetime,
    ) -> bool:
        """
        Returns True if a snapshot for this market already exists within
        the duplicate guard window before snapshotted_at.

        Window: [snapshotted_at - DUPLICATE_GUARD_SECONDS, snapshotted_at]
        """
        cutoff = snapshotted_at - timedelta(seconds=DUPLICATE_GUARD_SECONDS)

        existing = session.execute(
            select(MarketSnapshot.id)
            .where(
                MarketSnapshot.market_id == market_id,
                MarketSnapshot.snapshotted_at >= cutoff,
                MarketSnapshot.snapshotted_at <= snapshotted_at,
            )
            .limit(1)
        ).scalars().first()

        return existing is not None
