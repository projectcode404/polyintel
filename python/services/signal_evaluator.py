"""
services/signal_evaluator.py

Sprint 3 — Signal Performance Tracking

Evaluates pending signals against resolved market outcomes.

Responsibilities:
  1. Find signals with status = 'pending' that have a resolved market_outcome
  2. Calculate is_correct and realized_roi per signal
  3. Update signal row: status='resolved', resolved_outcome, is_correct,
     realized_roi, resolved_at
  4. Return evaluation metrics for logging

Design decisions:
  - Pure evaluation logic — no HTTP calls, no external APIs.
  - One DB transaction per batch of signals for a single market.
    If one market's batch fails, others are not affected.
  - ROI formula is economically correct for binary prediction markets:
      YES win:  profit = (1.0 - entry) per unit. ROI = profit/entry * 100
      NO  win:  profit = entry per unit on NO side.
                NO entry price = (1.0 - market_prob).
                ROI = (1 - no_entry) / no_entry * 100
                    = entry / (1 - entry) * 100
      Loss:     -100% (total loss of position)
      Cancelled: 0% (refund, no gain no loss)
  - Signals for cancelled markets are marked resolved with ROI = 0,
    is_correct = None (neither right nor wrong).

ROI examples:
  Signal YES at market_prob = 0.35 → entry = 0.35
    WIN:  ((1.0 - 0.35) / 0.35) * 100 = +185.7%
    LOSS: -100%

  Signal NO at market_prob = 0.35 → NO entry = 1 - 0.35 = 0.65
    WIN:  (0.35 / (1.0 - 0.35)) * 100 = +53.8%
    LOSS: -100%

Run cadence: called by scheduler after snapshot collection,
  or run manually via analyze_rules.py.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from decimal import Decimal, ROUND_HALF_UP

from sqlalchemy import select
from sqlalchemy.orm import Session

from models.market import Market, MarketOutcome
from models.signal import Signal
from utils.db import get_session
from utils.logger import get_logger

log = get_logger(__name__)

# ---------------------------------------------------------------------------
# Result dataclass
# ---------------------------------------------------------------------------

@dataclass
class EvaluationResult:
    """Metrics from a single evaluator run."""
    evaluated:  int = 0
    skipped:    int = 0   # pending but no outcome yet
    errors:     int = 0
    correct:    int = 0
    incorrect:  int = 0
    cancelled:  int = 0
    duration_seconds: float = 0.0

    @property
    def win_rate(self) -> float:
        decided = self.correct + self.incorrect
        return (self.correct / decided) if decided > 0 else 0.0


# ---------------------------------------------------------------------------
# Service
# ---------------------------------------------------------------------------

class SignalEvaluator:
    """
    Evaluates pending signals after market resolution.

    Usage:
        evaluator = SignalEvaluator()
        result = evaluator.run()

    Called by scheduler or analyze_rules.py.
    """

    # Batch size per DB transaction — prevents lock contention on large runs
    BATCH_SIZE = 100

    def run(self) -> EvaluationResult:
        log.info("signal_evaluator_run_start")
        started_at = time.monotonic()
        result = EvaluationResult()

        try:
            pending_signals = self._load_pending_signals_with_outcomes()

            if not pending_signals:
                log.info("signal_evaluator_nothing_to_evaluate")
                return result

            log.info("signal_evaluator_loaded", count=len(pending_signals))

            # Process in batches
            for i in range(0, len(pending_signals), self.BATCH_SIZE):
                batch = pending_signals[i : i + self.BATCH_SIZE]
                batch_result = self._evaluate_batch(batch)

                result.evaluated  += batch_result.evaluated
                result.skipped    += batch_result.skipped
                result.errors     += batch_result.errors
                result.correct    += batch_result.correct
                result.incorrect  += batch_result.incorrect
                result.cancelled  += batch_result.cancelled

        except Exception as exc:
            log.exception("signal_evaluator_unexpected_error", error=str(exc))
            raise
        finally:
            result.duration_seconds = time.monotonic() - started_at

        log.info(
            "signal_evaluator_run_done",
            evaluated=result.evaluated,
            correct=result.correct,
            incorrect=result.incorrect,
            cancelled=result.cancelled,
            skipped=result.skipped,
            errors=result.errors,
            win_rate=round(result.win_rate, 4),
            duration_sec=round(result.duration_seconds, 2),
        )

        return result

    # -------------------------------------------------------------------------
    # Private
    # -------------------------------------------------------------------------

    def _load_pending_signals_with_outcomes(self) -> list[tuple[Signal, MarketOutcome]]:
        """
        Load all pending signals that have a resolved market_outcome.

        JOIN logic:
          signals (status=pending)
          → markets (via market_id)
          → market_outcomes (via markets.id)

        Only returns signals where market_outcomes EXISTS — pending signals
        for markets that haven't resolved yet are naturally excluded.
        """
        with get_session() as session:
            rows = session.execute(
                select(Signal, MarketOutcome)
                .join(Market, Signal.market_id == Market.id)
                .join(MarketOutcome, MarketOutcome.market_id == Market.id)
                .where(
                    Signal.status == "pending",
                    Signal.deleted_at.is_(None),
                )
                .order_by(Signal.fired_at.asc())
            ).all()

            # Detach cleanly: convert to plain tuples of primitive values
            # to avoid DetachedInstanceError outside session
            return [
                _SignalOutcomePair(
                    signal_id=row.Signal.id,
                    market_id=row.Signal.market_id,
                    direction=row.Signal.direction,
                    entry_price=row.Signal.market_probability_at_signal,
                    trigger_source=row.Signal.trigger_source,
                    winning_side=row.MarketOutcome.winning_side,
                    resolved_at=row.MarketOutcome.resolved_at,
                )
                for row in rows
            ]

    def _evaluate_batch(self, batch: list["_SignalOutcomePair"]) -> EvaluationResult:
        result = EvaluationResult()

        try:
            with get_session() as session:
                for pair in batch:
                    try:
                        self._evaluate_one(session, pair, result)
                    except Exception as exc:
                        result.errors += 1
                        log.error(
                            "signal_evaluation_error",
                            signal_id=pair.signal_id,
                            error=str(exc),
                        )
        except Exception as exc:
            log.error(
                "signal_evaluator_batch_failed",
                batch_size=len(batch),
                error=str(exc),
            )
            result.errors += len(batch)

        return result

    def _evaluate_one(
        self,
        session: Session,
        pair: "_SignalOutcomePair",
        result: EvaluationResult,
    ) -> None:
        """Evaluate a single signal and update its row in DB."""

        winning_side = pair.winning_side
        direction    = pair.direction
        entry_price  = pair.entry_price
        now          = datetime.now(timezone.utc)

        # --- Cancelled market: ROI = 0, is_correct = None ---
        if winning_side == "cancelled":
            self._update_signal(
                session,
                signal_id=pair.signal_id,
                resolved_outcome="cancelled",
                is_correct=None,
                realized_roi=Decimal("0.0000"),
                resolved_at=pair.resolved_at,
            )
            result.evaluated += 1
            result.cancelled += 1
            log.debug(
                "signal_evaluated_cancelled",
                signal_id=pair.signal_id,
                trigger_source=pair.trigger_source,
            )
            return

        # --- Determine correctness ---
        is_correct = (direction == winning_side)

        # --- Calculate ROI ---
        realized_roi = self._calculate_roi(direction, entry_price, winning_side)

        # --- Update signal ---
        self._update_signal(
            session,
            signal_id=pair.signal_id,
            resolved_outcome=winning_side,
            is_correct=is_correct,
            realized_roi=realized_roi,
            resolved_at=pair.resolved_at,
        )

        result.evaluated += 1
        if is_correct:
            result.correct += 1
        else:
            result.incorrect += 1

        log.debug(
            "signal_evaluated",
            signal_id=pair.signal_id,
            trigger_source=pair.trigger_source,
            direction=direction,
            winning_side=winning_side,
            is_correct=is_correct,
            realized_roi=float(realized_roi),
            entry_price=float(entry_price),
        )

    def _calculate_roi(
        self,
        direction: str,
        entry_price: Decimal,
        winning_side: str,
    ) -> Decimal:
        """
        Calculate realized ROI for a signal.

        Binary prediction market economics:
          - YES token costs `entry_price` (= market probability)
          - NO  token costs `1 - entry_price`
          - Winner pays out 1.0 per token

        YES direction win:
          profit per unit = 1.0 - entry_price
          ROI = (1.0 - entry_price) / entry_price * 100

        NO direction win:
          no_entry_price = 1.0 - entry_price
          profit per unit = 1.0 - no_entry_price = entry_price
          ROI = entry_price / (1.0 - entry_price) * 100

        Any loss: ROI = -100 (full loss of position)

        Edge cases:
          entry_price = 0 → guard against division by zero → ROI = 0
          entry_price = 1 → guard against division by zero → ROI = 0
        """
        LOSS_ROI = Decimal("-100.0000")
        ZERO     = Decimal("0")
        ONE      = Decimal("1")

        # Guard: degenerate probabilities
        if entry_price <= ZERO or entry_price >= ONE:
            log.warning(
                "roi_calc_degenerate_entry",
                direction=direction,
                entry_price=str(entry_price),
            )
            return ZERO

        is_win = (direction == winning_side)

        if not is_win:
            return LOSS_ROI

        if direction == "yes":
            roi = ((ONE - entry_price) / entry_price) * Decimal("100")
        else:  # direction == "no"
            no_entry = ONE - entry_price
            if no_entry <= ZERO:
                return ZERO
            roi = (entry_price / no_entry) * Decimal("100")

        # Round to 4 decimal places for DB storage
        return roi.quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP)

    def _update_signal(
        self,
        session: Session,
        signal_id: int,
        resolved_outcome: str | None,
        is_correct: bool | None,
        realized_roi: Decimal,
        resolved_at: datetime | None,
    ) -> None:
        """Update signal row with evaluation results."""
        from sqlalchemy import update
        session.execute(
            update(Signal)
            .where(Signal.id == signal_id)
            .values(
                status="resolved",
                resolved_outcome=resolved_outcome,
                is_correct=is_correct,
                realized_roi=realized_roi,
                resolved_at=resolved_at,
                updated_at=datetime.now(timezone.utc),
            )
        )


# ---------------------------------------------------------------------------
# Internal DTO — avoids DetachedInstanceError
# ---------------------------------------------------------------------------

@dataclass
class _SignalOutcomePair:
    """
    Plain data container for one (Signal, MarketOutcome) join result.
    Created inside session, used outside — no ORM dependency.
    """
    signal_id:      int
    market_id:      int
    direction:      str
    entry_price:    Decimal
    trigger_source: str
    winning_side:   str
    resolved_at:    datetime | None
