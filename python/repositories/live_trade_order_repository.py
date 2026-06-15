"""
repositories/live_trade_order_repository.py

Data access layer for live_trade_orders — the Laravel <-> Python
order execution queue (Pola A).

OrderExecutorJob workflow:
  1. fetch_pending_for_processing() — locks PENDING rows so concurrent
     workers (not expected, but defensive) cannot double-process.
  2. mark_processing() — flips status to PROCESSING before calling
     the CLOB API (so a crash mid-execution doesn't leave the row
     looking untouched).
  3. mark_filled() / mark_failed() — records the execution result.

This repository NEVER touches live_trades or live_trade_history —
those are owned by Laravel's ProcessLiveOrderResultsJob, keeping all
accounting/PnL logic in one place (after the paper-trading bug fixes,
duplicating that logic in Python would be a regression risk).
"""
from __future__ import annotations

from datetime import datetime, timezone
from decimal import Decimal
from typing import Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from models.live_trade import LiveTradeOrder
from utils.logger import get_logger

log = get_logger(__name__)


class LiveTradeOrderRepository:
    """
    All methods accept a Session as first argument — the caller
    (OrderExecutorJob) owns the transaction lifecycle via get_session().
    This repository never commits or rolls back directly.
    """

    MAX_ATTEMPTS = 3

    def fetch_pending_for_processing(
        self, session: Session, limit: int = 10
    ) -> list[LiveTradeOrder]:
        """
        Fetch PENDING orders ordered by creation time (oldest first),
        with SELECT ... FOR UPDATE SKIP LOCKED to avoid double-processing
        if multiple OrderExecutorJob instances ever run concurrently.

        Immediately flips status to PROCESSING within the same
        transaction so a crash between fetch and execution leaves the
        row visibly "stuck in PROCESSING" rather than silently retried
        forever as PENDING.
        """
        stmt = (
            select(LiveTradeOrder)
            .where(LiveTradeOrder.status == LiveTradeOrder.STATUS_PENDING)
            .order_by(LiveTradeOrder.created_at.asc())
            .limit(limit)
            .with_for_update(skip_locked=True)
        )
        orders = list(session.execute(stmt).scalars().all())

        now = datetime.now(timezone.utc)
        for order in orders:
            order.status = LiveTradeOrder.STATUS_PROCESSING
            order.attempts = order.attempts + 1
            order.processed_at = now
            order.updated_at = now

        if orders:
            session.flush()

        return orders

    def mark_filled(
        self,
        session: Session,
        order: LiveTradeOrder,
        *,
        avg_fill_price: Decimal,
        filled_shares: Decimal,
        fee_usd: Decimal,
        clob_order_id: Optional[str] = None,
        tx_hash: Optional[str] = None,
        partial: bool = False,
    ) -> None:
        """Record a successful (full or partial) fill."""
        order.status = (
            LiveTradeOrder.STATUS_PARTIAL_FILLED if partial else LiveTradeOrder.STATUS_FILLED
        )
        order.avg_fill_price = avg_fill_price
        order.filled_shares = filled_shares
        order.fee_usd = fee_usd
        order.clob_order_id = clob_order_id
        order.tx_hash = tx_hash
        order.error_message = None
        order.updated_at = datetime.now(timezone.utc)

        log.info(
            "live_order_filled",
            order_id=order.id,
            order_type=order.order_type,
            side=order.side,
            avg_fill_price=str(avg_fill_price),
            filled_shares=str(filled_shares),
            fee_usd=str(fee_usd),
            partial=partial,
        )

    def mark_failed(
        self, session: Session, order: LiveTradeOrder, *, error_message: str
    ) -> None:
        """
        Record a failed execution attempt.

        If attempts < MAX_ATTEMPTS, status reverts to PENDING so the
        next OrderExecutorJob cycle retries. Otherwise status becomes
        FAILED permanently — Laravel's ProcessLiveOrderResultsJob must
        alert on FAILED orders (especially EXIT orders, which leave a
        position open with no automatic retry).
        """
        order.error_message = error_message[:2000]
        order.updated_at = datetime.now(timezone.utc)

        if order.attempts >= self.MAX_ATTEMPTS:
            order.status = LiveTradeOrder.STATUS_FAILED
            log.error(
                "live_order_failed_permanently",
                order_id=order.id,
                order_type=order.order_type,
                attempts=order.attempts,
                error=error_message,
            )
        else:
            order.status = LiveTradeOrder.STATUS_PENDING
            log.warning(
                "live_order_failed_will_retry",
                order_id=order.id,
                order_type=order.order_type,
                attempts=order.attempts,
                max_attempts=self.MAX_ATTEMPTS,
                error=error_message,
            )
