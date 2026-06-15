"""
models/live_trade.py

SQLAlchemy ORM models — mirror exact dari Laravel migrations:
  2026_06_14_181228_create_live_trades_table.php
  2026_06_14_181229_create_live_trade_orders_table.php
  2026_06_14_181230_create_live_trade_history_table.php

Laravel owns decision-making (writes live_trade_orders as PENDING,
reads results back into live_trades / live_trade_history).
Python (OrderExecutorJob) owns execution: polls PENDING orders from
live_trade_orders, places them via py-clob-client, writes fill results
back to the same row.

Python NEVER writes directly to live_trades or live_trade_history —
that is Laravel's responsibility (ProcessLiveOrderResultsJob), keeping
accounting logic (PnL, ROI, invariants) in one place after the
paper-trading bug fixes.
"""
from __future__ import annotations

from datetime import datetime
from decimal import Decimal
from typing import Optional

from sqlalchemy import (
    Boolean,
    DateTime,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column

from .market import Base


# =============================================================================
# LiveTrade
# =============================================================================
class LiveTrade(Base):
    """
    Mirror of live_trades table. Read-only from Python's perspective —
    only Laravel creates/updates rows here.
    """

    __tablename__ = "live_trades"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)

    trading_account_id: Mapped[int] = mapped_column(Integer, nullable=False)
    market_id: Mapped[int] = mapped_column(Integer, nullable=False)
    signal_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)

    direction: Mapped[str] = mapped_column(String(10), nullable=False)
    entry_price: Mapped[Decimal] = mapped_column(Numeric(10, 6), nullable=False)
    exit_price: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    current_price: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    shares: Mapped[Decimal] = mapped_column(Numeric(18, 8), nullable=False)
    position_size_usd: Mapped[Decimal] = mapped_column(Numeric(15, 2), nullable=False)
    fees_usd: Mapped[Decimal] = mapped_column(Numeric(15, 4), nullable=False, default=0)
    pnl_usd: Mapped[Decimal] = mapped_column(Numeric(15, 4), nullable=False, default=0)
    unrealized_pnl_usd: Mapped[Decimal] = mapped_column(Numeric(15, 4), nullable=False, default=0)
    roi: Mapped[Decimal] = mapped_column(Numeric(10, 6), nullable=False, default=0)

    stop_loss_price: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    take_profit_price: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    breakeven_price: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)

    status: Mapped[str] = mapped_column(String(50), nullable=False)
    outcome: Mapped[Optional[str]] = mapped_column(String(20), nullable=True)

    clob_token_id: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)

    entered_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=False), nullable=True)
    exited_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=False), nullable=True)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=False), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=False), server_default=func.now())

    def __repr__(self) -> str:
        return (
            f"<LiveTrade id={self.id} market_id={self.market_id} "
            f"status={self.status!r} shares={self.shares}>"
        )


# =============================================================================
# LiveTradeOrder
# =============================================================================
class LiveTradeOrder(Base):
    """
    Mirror of live_trade_orders table — the Laravel <-> Python order queue.

    Python's OrderExecutorJob:
      1. SELECT ... WHERE status = 'PENDING' (with row locking)
      2. Sets status = 'PROCESSING'
      3. Places order via py-clob-client
      4. Updates avg_fill_price, filled_shares, fee_usd, clob_order_id,
         tx_hash, status (FILLED / PARTIAL_FILLED / FAILED)

    Python NEVER sets processed_by_laravel — that flag is owned by
    Laravel's ProcessLiveOrderResultsJob.
    """

    __tablename__ = "live_trade_orders"

    # Order type constants (mirror PHP-side string values)
    ORDER_TYPE_ENTRY = "ENTRY"
    ORDER_TYPE_EXIT_FULL = "EXIT_FULL"
    ORDER_TYPE_EXIT_PARTIAL = "EXIT_PARTIAL"

    SIDE_BUY = "BUY"
    SIDE_SELL = "SELL"

    STATUS_PENDING = "PENDING"
    STATUS_PROCESSING = "PROCESSING"
    STATUS_FILLED = "FILLED"
    STATUS_PARTIAL_FILLED = "PARTIAL_FILLED"
    STATUS_FAILED = "FAILED"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)

    live_trade_id: Mapped[Optional[int]] = mapped_column(
        Integer, ForeignKey("live_trades.id"), nullable=True
    )
    trading_account_id: Mapped[int] = mapped_column(Integer, nullable=False)
    market_id: Mapped[int] = mapped_column(Integer, nullable=False)
    signal_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)

    order_type: Mapped[str] = mapped_column(String(20), nullable=False)
    side: Mapped[str] = mapped_column(String(10), nullable=False)
    token_id: Mapped[str] = mapped_column(String(255), nullable=False)

    size_usd: Mapped[Optional[Decimal]] = mapped_column(Numeric(15, 2), nullable=True)
    shares: Mapped[Optional[Decimal]] = mapped_column(Numeric(18, 8), nullable=True)

    status: Mapped[str] = mapped_column(String(20), nullable=False, default=STATUS_PENDING)

    expected_price: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    avg_fill_price: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    filled_shares: Mapped[Optional[Decimal]] = mapped_column(Numeric(18, 8), nullable=True)
    fee_usd: Mapped[Optional[Decimal]] = mapped_column(Numeric(15, 4), nullable=True)

    clob_order_id: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    tx_hash: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    error_message: Mapped[Optional[str]] = mapped_column(Text, nullable=True)

    reason: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    processed_by_laravel: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)

    processed_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=False), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=False), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=False), server_default=func.now())

    def __repr__(self) -> str:
        return (
            f"<LiveTradeOrder id={self.id} order_type={self.order_type!r} "
            f"side={self.side!r} status={self.status!r}>"
        )


# =============================================================================
# LiveTradeHistory
# =============================================================================
class LiveTradeHistory(Base):
    """
    Mirror of live_trade_history table. Read-only from Python's
    perspective — only Laravel writes accounting events here.
    """

    __tablename__ = "live_trade_history"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)

    live_trade_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("live_trades.id"), nullable=False
    )
    live_trade_order_id: Mapped[Optional[int]] = mapped_column(
        Integer, ForeignKey("live_trade_orders.id"), nullable=True
    )

    event_type: Mapped[str] = mapped_column(String(50), nullable=False)
    price_at_event: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    shares_affected: Mapped[Decimal] = mapped_column(Numeric(18, 8), nullable=False, default=0)
    pnl_realized: Mapped[Decimal] = mapped_column(Numeric(15, 4), nullable=False, default=0)
    reason: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)

    created_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=False), nullable=True)

    def __repr__(self) -> str:
        return f"<LiveTradeHistory id={self.id} event_type={self.event_type!r}>"
