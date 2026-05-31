"""
models/market.py

SQLAlchemy ORM models — mirror exact dari Laravel migrations.
Python writes, Laravel reads. Semua timestamp UTC.

SPRINT 2 FIX: tambah MarketOutcome yang direferensikan di Market.outcome
tapi belum ada di Sprint 1.
"""

from __future__ import annotations

from datetime import datetime
from decimal import Decimal
from typing import Optional

from sqlalchemy import (
    JSON,
    Boolean,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    pass


# =============================================================================
# Market
# =============================================================================

class Market(Base):
    __tablename__ = "markets"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    condition_id: Mapped[str] = mapped_column(String(100), unique=True, nullable=False)
    slug: Mapped[Optional[str]] = mapped_column(String(200), unique=True, nullable=True)
    question: Mapped[str] = mapped_column(String(500), nullable=False)
    description: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    category: Mapped[str] = mapped_column(String(50), default="crypto", nullable=False)
    sub_category: Mapped[Optional[str]] = mapped_column(String(100), nullable=True)
    tags: Mapped[Optional[str]] = mapped_column(String, nullable=True)
    resolution_source: Mapped[Optional[str]] = mapped_column(String(300), nullable=True)
    start_date: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    end_date: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    resolved_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    status: Mapped[str] = mapped_column(String(30), default="active", nullable=False)
    market_probability: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    volume_usd: Mapped[Decimal] = mapped_column(Numeric(20, 2), default=0, nullable=False)
    liquidity_usd: Mapped[Decimal] = mapped_column(Numeric(20, 2), default=0, nullable=False)
    num_traders: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    ai_probability: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    edge: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    last_synced_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    is_tracked: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
    )
    deleted_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)

    # Relationships
    snapshots: Mapped[list["MarketSnapshot"]] = relationship(
        back_populates="market",
        cascade="all, delete-orphan",
    )
    outcome: Mapped[Optional["MarketOutcome"]] = relationship(
        back_populates="market",
        uselist=False,
    )

    __table_args__ = (
        Index("markets_status_category_tracked_idx", "status", "category", "is_tracked"),
        Index("markets_end_date_status_idx", "end_date", "status"),
        Index("markets_edge_idx", "edge"),
        Index("markets_probability_idx", "market_probability"),
        Index("markets_last_synced_idx", "last_synced_at"),
    )

    def __repr__(self) -> str:
        return f"<Market id={self.id} condition_id={self.condition_id!r} status={self.status!r}>"


# =============================================================================
# MarketSnapshot
# =============================================================================

class MarketSnapshot(Base):
    __tablename__ = "market_snapshots"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    market_id: Mapped[int] = mapped_column(
        Integer,
        ForeignKey("markets.id", ondelete="CASCADE"),
        nullable=False,
    )
    probability_yes: Mapped[Decimal] = mapped_column(Numeric(8, 6), nullable=False)
    probability_no: Mapped[Decimal] = mapped_column(Numeric(8, 6), nullable=False)
    best_bid: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    best_ask: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    spread: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    volume_usd: Mapped[Decimal] = mapped_column(Numeric(20, 2), default=0, nullable=False)
    volume_24h_usd: Mapped[Decimal] = mapped_column(Numeric(20, 2), default=0, nullable=False)
    liquidity_usd: Mapped[Decimal] = mapped_column(Numeric(20, 2), default=0, nullable=False)
    btc_price_usd: Mapped[Optional[Decimal]] = mapped_column(Numeric(20, 2), nullable=True)
    eth_price_usd: Mapped[Optional[Decimal]] = mapped_column(Numeric(20, 2), nullable=True)
    fear_greed_index: Mapped[Optional[int]] = mapped_column(SmallInteger, nullable=True)
    btc_dominance: Mapped[Optional[Decimal]] = mapped_column(Numeric(6, 4), nullable=True)
    collector_version: Mapped[Optional[str]] = mapped_column(String(20), nullable=True)
    snapshotted_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
    )

    market: Mapped["Market"] = relationship(back_populates="snapshots")

    __table_args__ = (
        Index("snapshots_market_time_idx", "market_id", "snapshotted_at"),
        Index("snapshots_time_idx", "snapshotted_at"),
        Index("snapshots_market_prob_idx", "market_id", "probability_yes"),
        Index("snapshots_volume_idx", "market_id", "volume_24h_usd"),
    )

    def __repr__(self) -> str:
        return (
            f"<MarketSnapshot id={self.id} market_id={self.market_id} "
            f"prob_yes={self.probability_yes} at={self.snapshotted_at}>"
        )


# =============================================================================
# MarketOutcome
# Sprint 1 migration: 2024_01_01_000003_create_market_outcomes_table.php
# Direferensikan di Market.outcome — WAJIB ada di sini.
# Python collector MENULIS ke tabel ini saat market resolved.
# =============================================================================

class MarketOutcome(Base):
    __tablename__ = "market_outcomes"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    market_id: Mapped[int] = mapped_column(
        Integer,
        ForeignKey("markets.id", ondelete="CASCADE"),
        unique=True,    # Satu market hanya boleh punya satu outcome
        nullable=False,
    )

    # Hasil resolusi
    winning_side: Mapped[str] = mapped_column(
        String(20),
        nullable=False,
        comment="yes | no | cancelled",
    )
    resolution_price: Mapped[Decimal] = mapped_column(
        Numeric(8, 6),
        nullable=False,
        comment="1.0 = YES resolved, 0.0 = NO resolved",
    )

    # State sebelum resolusi — untuk analisis kalibrasi
    final_probability_before_resolution: Mapped[Optional[Decimal]] = mapped_column(
        Numeric(8, 6), nullable=True
    )
    peak_probability_yes: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    low_probability_yes: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    total_volume_usd: Mapped[Decimal] = mapped_column(Numeric(20, 2), default=0, nullable=False)

    # Source resolusi
    resolved_by: Mapped[Optional[str]] = mapped_column(String(200), nullable=True)
    resolution_notes: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    resolved_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
    )

    # Relationship balik ke Market
    market: Mapped["Market"] = relationship(back_populates="outcome")

    __table_args__ = (
        Index("outcomes_winning_side_idx", "winning_side"),
        Index("outcomes_resolved_at_idx", "resolved_at"),
    )

    def __repr__(self) -> str:
        return f"<MarketOutcome id={self.id} market_id={self.market_id} side={self.winning_side!r}>"

    def resolved_yes(self) -> bool:
        return self.winning_side == "yes"

    def was_cancelled(self) -> bool:
        return self.winning_side == "cancelled"

    def numeric_outcome(self) -> Optional[float]:
        """Untuk Brier score: 1.0 jika YES, 0.0 jika NO, None jika cancelled."""
        return {"yes": 1.0, "no": 0.0, "cancelled": None}.get(self.winning_side)
