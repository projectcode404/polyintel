"""
repositories/market_repository.py

All database access for the `markets` table.

Design decisions:
- Repository pattern: all SQL/ORM logic lives here. Collectors never
  touch SQLAlchemy directly — they call repository methods.
- Returns ORM objects (Market instances), not raw rows. Collectors work
  with typed objects that match the schema exactly.
- upsert_from_api() uses PostgreSQL's INSERT ... ON CONFLICT DO UPDATE
  via SQLAlchemy's insert().on_conflict_do_update(). This is atomic:
  concurrent collector runs cannot create duplicate market rows.
- update_probability_cache() updates only the denormalized cache columns
  on the markets table. It does NOT create a snapshot — that's the
  snapshot collector's job.
- Every write happens within a transaction provided by the caller's
  get_session() context manager. This repository never opens its own
  transaction.

Changelog:
  v1.3 — tambah fields dari RawMarket baru:
    volume_24h_usd, best_bid, best_ask, spread, price_change_1h,
    price_change_1d ke upsert_from_api().
    num_traders tetap di-upsert (= 0) agar kolom DB tidak berubah.
    Kolom baru di markets table WAJIB ada via migration sebelum deploy.
    Lihat migration: add_market_enrichment_fields.

  v1.4 — tambah MarketDTO dan get_active_tracked_dto().
    FIX: SnapshotsCollector dan SignalCollector mengakses market data
    DI LUAR session context. ORM objects raise DetachedInstanceError
    ketika diakses setelah session ditutup karena lazy-load tidak bisa
    berjalan tanpa session aktif.
    SOLUTION: get_active_tracked_dto() mengembalikan plain dataclass
    (MarketDTO) — tidak terikat session, aman diakses kapan saja.
    get_active_tracked() tetap ada untuk caller yang membutuhkan
    ORM objects di dalam session yang sama.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from decimal import Decimal
from typing import Optional

from sqlalchemy import select, update
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.orm import Session

from models.market import Market
from services.polymarket_service import RawMarket
from utils.logger import get_logger

log = get_logger(__name__)


# =============================================================================
# DTO — plain data object, tidak terikat SQLAlchemy session
# =============================================================================

@dataclass
class MarketDTO:
    """
    Plain data object yang merepresentasikan satu row dari markets table.

    KAPAN DIPAKAI:
      Gunakan MarketDTO (bukan Market ORM object) ketika data market akan
      diakses DI LUAR get_session() context — misalnya setelah session
      ditutup dan digunakan di thread lain.

      Contoh yang SALAH (DetachedInstanceError):
        with get_session() as session:
            markets = repo.get_active_tracked(session)
        # Session sudah ditutup di sini!
        for m in markets:
            print(m.condition_id)  # ← DetachedInstanceError

      Contoh yang BENAR (pakai DTO):
        with get_session() as session:
            markets = repo.get_active_tracked_dto(session)
        # Session sudah ditutup, tapi DTO tetap aman diakses
        for m in markets:
            print(m.condition_id)  # ← OK

    FIELDS:
      Hanya berisi fields yang dibutuhkan oleh SnapshotsCollector
      dan SignalCollector. Bukan seluruh kolom markets table.
      Tambah field di sini jika collector butuh data tambahan.
    """
    id: int
    condition_id: str
    question: str
    status: str
    volume_usd: Decimal
    volume_24h_usd: Decimal
    liquidity_usd: Decimal
    market_probability: Optional[Decimal]


# =============================================================================
# Repository
# =============================================================================

class MarketRepository:
    """
    Database access layer for the markets table.

    All methods accept a Session as first argument — the caller (collector)
    owns the transaction lifecycle via get_session(). This repository
    never commits or rolls back.
    """

    def upsert_from_api(self, session: Session, raw: RawMarket) -> Market:
        """
        Insert a new market or update an existing one by condition_id.

        Uses PostgreSQL's ON CONFLICT DO UPDATE (upsert) so this method
        is safe to call multiple times for the same condition_id.

        Fields NEVER overwritten on update:
          - id, created_at
          - ai_probability, edge  (owned by AI engine, Sprint 4)
          - is_tracked            (owned by operator config)
          - deleted_at            (soft delete)

        Fields updated on every sync:
          - question, description, status
          - market_probability, volume_usd, volume_24h_usd, liquidity_usd
          - num_traders (= 0, Gamma API tidak expose field ini)
          - best_bid, best_ask, spread (dari Gamma response)
          - price_change_1h, price_change_1d
          - end_date, resolved_at
          - last_synced_at, updated_at
          - tags, sub_category
        """
        import json

        now = datetime.now(timezone.utc)
        tags_json = json.dumps(raw.tags) if raw.tags else None

        insert_values = {
            "condition_id":      raw.condition_id,
            "slug":              raw.slug,
            "question":          raw.question[:500] if raw.question else "",
            "description":       raw.description,
            "category":          raw.category,
            "sub_category":      raw.sub_category,
            "tags":              tags_json,
            "resolution_source": raw.resolution_source,
            "start_date":        self._parse_dt(raw.start_date),
            "end_date":          self._parse_dt(raw.end_date),
            "resolved_at":       self._parse_dt(raw.resolved_at),
            "status":            raw.status,
            "market_probability": raw.market_probability,

            # Volume
            "volume_usd":        raw.volume_usd,
            "volume_24h_usd":    raw.volume_24h_usd,
            "liquidity_usd":     raw.liquidity_usd,

            # Traders — Gamma API tidak expose jumlah trader.
            # Field dipertahankan di DB, nilai = 0.
            "num_traders":       raw.num_traders,

            # Orderbook snapshot dari Gamma
            "best_bid":          raw.best_bid,
            "best_ask":          raw.best_ask,
            "spread":            raw.spread,

            # Price movement
            "price_change_1h":   raw.price_change_1h,
            "price_change_1d":   raw.price_change_1d,

            "is_tracked":        True,
            "last_synced_at":    now,
            "created_at":        now,
            "updated_at":        now,
        }

        # Fields to update on conflict
        # Exclude: condition_id (conflict key), created_at, is_tracked, ai_*, deleted_at
        update_values = {
            k: v for k, v in insert_values.items()
            if k not in ("condition_id", "created_at", "is_tracked")
        }

        stmt = (
            pg_insert(Market)
            .values(**insert_values)
            .on_conflict_do_update(
                index_elements=["condition_id"],
                set_=update_values,
            )
            .returning(Market)
        )

        result = session.execute(stmt)
        market = result.scalars().first()

        # SQLAlchemy 2.x: after execute(returning), object may be detached.
        if market is None:
            market = session.execute(
                select(Market).where(Market.condition_id == raw.condition_id)
            ).scalars().first()

        log.debug(
            "market_upserted",
            condition_id=raw.condition_id,
            question=raw.question[:60] if raw.question else "",
            status=raw.status,
            volume_24h=str(raw.volume_24h_usd),
            market_id=market.id if market else None,
        )

        return market  # type: ignore[return-value]

    def find_by_condition_id(
        self, session: Session, condition_id: str
    ) -> Optional[Market]:
        """Fetch a single market by its Polymarket condition_id."""
        return session.execute(
            select(Market).where(
                Market.condition_id == condition_id,
                Market.deleted_at.is_(None),
            )
        ).scalars().first()

    def find_by_id(self, session: Session, market_id: int) -> Optional[Market]:
        """Fetch a single market by its internal PK."""
        return session.execute(
            select(Market).where(
                Market.id == market_id,
                Market.deleted_at.is_(None),
            )
        ).scalars().first()

    def get_active_tracked(self, session: Session) -> list[Market]:
        """
        Return all active, tracked markets sebagai ORM objects.

        PERINGATAN: Gunakan method ini HANYA jika data akan diakses
        di dalam session context yang sama. Jika data perlu diakses
        setelah session ditutup, gunakan get_active_tracked_dto().

        Ordered by volume descending so high-value markets are
        snapshotted first if the batch is interrupted.
        """
        return list(
            session.execute(
                select(Market)
                .where(
                    Market.status == "active",
                    Market.is_tracked.is_(True),
                    Market.deleted_at.is_(None),
                )
                .order_by(Market.volume_usd.desc())
            ).scalars().all()
        )

    def get_active_tracked_dto(self, session: Session) -> list[MarketDTO]:
        """
        Return all active, tracked markets sebagai plain MarketDTO objects.

        KAPAN DIPAKAI:
          Gunakan method ini ketika data market akan diakses DI LUAR
          get_session() context. ORM objects (get_active_tracked) akan
          raise DetachedInstanceError setelah session ditutup.

          SnapshotsCollector dan SignalCollector WAJIB memakai method ini
          karena mereka load markets dalam satu session, kemudian
          memproses data tersebut di thread berbeda atau setelah
          session block selesai.

        IMPLEMENTATION:
          Menggunakan select() column-level (bukan select(Market)) agar
          SQLAlchemy tidak membuat ORM objects — hanya plain Row objects
          yang langsung dikonversi ke MarketDTO dataclass.
          Tidak ada lazy-load, tidak ada session dependency.

        Ordered by volume descending — sama dengan get_active_tracked().
        """
        rows = session.execute(
            select(
                Market.id,
                Market.condition_id,
                Market.question,
                Market.status,
                Market.volume_usd,
                Market.volume_24h_usd,
                Market.liquidity_usd,
                Market.market_probability,
            )
            .where(
                Market.status == "active",
                Market.is_tracked.is_(True),
                Market.deleted_at.is_(None),
            )
            .order_by(Market.volume_usd.desc())
        ).all()

        return [
            MarketDTO(
                id=row.id,
                condition_id=row.condition_id,
                question=row.question,
                status=row.status,
                volume_usd=row.volume_usd,
                volume_24h_usd=row.volume_24h_usd,
                liquidity_usd=row.liquidity_usd,
                market_probability=row.market_probability,
            )
            for row in rows
        ]

    def update_probability_cache(
        self,
        session: Session,
        market_id: int,
        probability: Decimal,
    ) -> None:
        """
        Update the denormalized probability cache on the markets table.

        Called by snapshot collector after each snapshot write.
        Keeps markets.market_probability current for dashboard queries.

        NOTE: volume_usd, volume_24h_usd, liquidity_usd, best_bid, best_ask
        NOT updated here — they come from Gamma API via MarketsCollector.
        """
        session.execute(
            update(Market)
            .where(Market.id == market_id)
            .values(
                market_probability=probability,
                last_synced_at=datetime.now(timezone.utc),
                updated_at=datetime.now(timezone.utc),
            )
        )

    def mark_resolved(
        self,
        session: Session,
        condition_id: str,
        resolved_at: datetime,
    ) -> None:
        """Mark a market as resolved and stop snapshot collection."""
        session.execute(
            update(Market)
            .where(Market.condition_id == condition_id)
            .values(
                status="resolved",
                resolved_at=resolved_at,
                is_tracked=False,
                updated_at=datetime.now(timezone.utc),
            )
        )
        log.info("market_marked_resolved", condition_id=condition_id)

    def count_active_tracked(self, session: Session) -> int:
        """Return count of active tracked markets. Used for health monitoring."""
        from sqlalchemy import func
        result = session.execute(
            select(func.count(Market.id)).where(
                Market.status == "active",
                Market.is_tracked.is_(True),
                Market.deleted_at.is_(None),
            )
        ).scalar()
        return result or 0

    # -------------------------------------------------------------------------
    # Private helpers
    # -------------------------------------------------------------------------

    @staticmethod
    def _parse_dt(value: str | None) -> datetime | None:
        """Parse ISO 8601 string to UTC-aware datetime. Returns None on failure."""
        if not value:
            return None
        try:
            from dateutil import parser as dtparser
            dt = dtparser.parse(value)
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=timezone.utc)
            return dt
        except Exception:
            return None