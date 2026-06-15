"""
jobs/order_executor_job.py

Polls live_trade_orders for PENDING rows and executes them via
PolymarketExecutorService (py-clob-client).

Pola A (Laravel <-> Python order queue):
  - Laravel writes PENDING orders (ENTRY / EXIT_FULL / EXIT_PARTIAL)
  - This job claims PENDING rows (FOR UPDATE SKIP LOCKED), places them
    on Polymarket CLOB, and writes back fill results
  - Laravel's ProcessLiveOrderResultsJob picks up FILLED/FAILED rows
    and applies them to live_trades / live_trade_history

If live trading is not configured (no wallet private key), this job
is a no-op — it logs once and returns, without crashing the scheduler
or affecting any other collector jobs.
"""
from __future__ import annotations

from utils.db import get_session
from utils.logger import get_logger
from repositories.live_trade_order_repository import LiveTradeOrderRepository
from services.polymarket_executor_service import (
    PolymarketExecutorDisabled,
    PolymarketExecutorService,
)

log = get_logger(__name__)

_repo = LiveTradeOrderRepository()
_executor = PolymarketExecutorService()


def job_execute_live_orders() -> None:
    """
    Process pending live_trade_orders.

    Runs on a short interval (e.g. every 5-10 seconds) — orders must
    be executed promptly after Laravel queues them, since market
    conditions change quickly.
    """
    if not _executor.is_enabled():
        log.debug("live_trading_disabled_no_wallet")
        return

    log.info("job_execute_live_orders_start")

    try:
        with get_session() as session:
            orders = _repo.fetch_pending_for_processing(session, limit=10)

            if not orders:
                log.debug("live_orders_none_pending")
                return

            log.info("live_orders_claimed", count=len(orders))

            for order in orders:
                _process_order(session, order)

        log.info("job_execute_live_orders_done", processed=len(orders))

    except Exception as exc:
        log.exception("job_execute_live_orders_failed", error=str(exc))
        raise


def _process_order(session, order) -> None:
    """
    Execute a single order. Any exception is caught and recorded via
    mark_failed() — never propagated, so one bad order doesn't block
    the rest of the batch or crash the job.
    """
    try:
        if order.side == "BUY":
            result = _executor.place_market_order(
                token_id=order.token_id,
                side="BUY",
                size_usd=order.size_usd,
            )
        else:  # SELL
            result = _executor.place_market_order(
                token_id=order.token_id,
                side="SELL",
                shares=order.shares,
            )

        _repo.mark_filled(
            session,
            order,
            avg_fill_price=result.avg_fill_price,
            filled_shares=result.filled_shares,
            fee_usd=result.fee_usd,
            clob_order_id=result.clob_order_id,
            tx_hash=result.tx_hash,
            partial=result.partial,
        )

    except PolymarketExecutorDisabled as exc:
        # Should not happen — is_enabled() already checked at job level —
        # but handle defensively in case wallet config changes mid-run.
        _repo.mark_failed(session, order, error_message=str(exc))

    except Exception as exc:
        log.exception(
            "live_order_execution_error",
            order_id=order.id,
            order_type=order.order_type,
            side=order.side,
            error=str(exc),
        )
        _repo.mark_failed(session, order, error_message=str(exc))
