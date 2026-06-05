"""
repositories/signal_analytics_repository.py

Sprint 3 — Rule Performance Analytics

Aggregates evaluated signal data for rule performance reporting.

Design decisions:
  - Read-only repository: zero writes. All aggregation is pure SELECT.
  - Uses raw SQL via SQLAlchemy text() for complex aggregations
    (median, profit_factor, max_drawdown) that are awkward in ORM.
  - Returns typed dataclasses, not raw rows — callers get structured
    data without coupling to DB column names.
  - All queries filter is_correct IS NOT NULL to exclude:
      * pending signals (not yet evaluated)
      * cancelled market signals (ROI=0, is_correct=None)
    Cancelled signals are excluded from win_rate/ROI stats but counted
    separately in total_signals for transparency.
  - profit_factor = sum(positive ROI) / abs(sum(negative ROI))
    A ratio > 1.0 means the rule makes more than it loses.
    If no losses → float('inf'). If no wins → 0.0.
  - max_drawdown = worst single realized_roi (most negative value).
"""

from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal
from typing import Optional

from sqlalchemy import text
from sqlalchemy.orm import Session

from utils.logger import get_logger

log = get_logger(__name__)


# ---------------------------------------------------------------------------
# Result dataclasses
# ---------------------------------------------------------------------------

@dataclass
class RulePerformance:
    """
    Performance statistics for a single trigger_source (rule).

    Fields:
      rule_name       : trigger_source value (e.g. 'rule_1_extreme_low')
      total_signals   : all signals fired by this rule (incl. pending, cancelled)
      evaluated       : signals with is_correct IS NOT NULL
      winning         : is_correct = true
      losing          : is_correct = false
      cancelled       : winning_side = 'cancelled' (ROI = 0, excluded from rates)
      win_rate        : winning / (winning + losing)  — excludes cancelled
      avg_roi         : mean of realized_roi where is_correct IS NOT NULL
      median_roi      : median of realized_roi where is_correct IS NOT NULL
      profit_factor   : sum(positive ROI) / |sum(negative ROI)|
      max_drawdown    : min(realized_roi) — worst single trade
      best_trade_roi  : max(realized_roi) — best single trade
      worst_trade_roi : min(realized_roi) — same as max_drawdown
    """
    rule_name:       str
    total_signals:   int
    evaluated:       int
    winning:         int
    losing:          int
    cancelled:       int
    win_rate:        float
    avg_roi:         Optional[float]
    median_roi:      Optional[float]
    profit_factor:   float
    max_drawdown:    Optional[float]
    best_trade_roi:  Optional[float]
    worst_trade_roi: Optional[float]


@dataclass
class OverallPerformance:
    """Aggregate performance across all rules."""
    total_signals:  int
    evaluated:      int
    winning:        int
    losing:         int
    cancelled:      int
    win_rate:       float
    avg_roi:        Optional[float]
    median_roi:     Optional[float]
    profit_factor:  float
    max_drawdown:   Optional[float]
    net_roi:        Optional[float]   # sum of all realized_roi


@dataclass
class RecentSignal:
    """Single resolved signal for recent activity display."""
    signal_id:       int
    market_question: str
    trigger_source:  str
    direction:       str
    entry_price:     float
    edge_at_signal:  float
    resolved_outcome: Optional[str]
    is_correct:      Optional[bool]
    realized_roi:    Optional[float]
    fired_at:        str
    resolved_at:     Optional[str]


# ---------------------------------------------------------------------------
# Repository
# ---------------------------------------------------------------------------

class SignalAnalyticsRepository:
    """
    Read-only analytics queries for signal performance.

    All methods accept a Session — caller owns the transaction.
    No writes. No commits.
    """

    def get_rule_performance(self, session: Session) -> list[RulePerformance]:
        """
        Return performance breakdown by trigger_source (rule).

        Ordered by profit_factor DESC — best performing rules first.
        Rules with no evaluated signals are included (evaluated=0)
        so operators can see rules that have never fired a resolved signal.
        """
        sql = text("""
            SELECT
                trigger_source                                           AS rule_name,

                COUNT(*)                                                 AS total_signals,

                COUNT(*) FILTER (WHERE is_correct IS NOT NULL)           AS evaluated,

                COUNT(*) FILTER (WHERE is_correct = true)                AS winning,

                COUNT(*) FILTER (WHERE is_correct = false)               AS losing,

                COUNT(*) FILTER (
                    WHERE resolved_outcome = 'cancelled'
                )                                                        AS cancelled,

                -- win_rate excludes cancelled
                CASE
                    WHEN COUNT(*) FILTER (WHERE is_correct IS NOT NULL) = 0
                    THEN 0.0
                    ELSE ROUND(
                        COUNT(*) FILTER (WHERE is_correct = true)::numeric /
                        NULLIF(
                            COUNT(*) FILTER (WHERE is_correct IS NOT NULL),
                            0
                        ), 4
                    )
                END                                                      AS win_rate,

                ROUND(AVG(realized_roi) FILTER (
                    WHERE is_correct IS NOT NULL
                ), 4)                                                    AS avg_roi,

                -- PostgreSQL percentile_cont for median
                PERCENTILE_CONT(0.5) WITHIN GROUP (
                    ORDER BY realized_roi
                ) FILTER (
                    WHERE is_correct IS NOT NULL
                )                                                        AS median_roi,

                -- profit_factor = sum(gains) / |sum(losses)|
                CASE
                    WHEN SUM(realized_roi) FILTER (WHERE realized_roi < 0) = 0
                         OR SUM(realized_roi) FILTER (WHERE realized_roi < 0) IS NULL
                    THEN
                        CASE
                            WHEN SUM(realized_roi) FILTER (WHERE realized_roi > 0) > 0
                            THEN 999999.0   -- no losses, treat as very high PF
                            ELSE 0.0
                        END
                    ELSE ROUND(
                        COALESCE(SUM(realized_roi) FILTER (WHERE realized_roi > 0), 0) /
                        ABS(SUM(realized_roi) FILTER (WHERE realized_roi < 0)),
                        4
                    )
                END                                                      AS profit_factor,

                MIN(realized_roi) FILTER (
                    WHERE is_correct IS NOT NULL
                )                                                        AS max_drawdown,

                MAX(realized_roi) FILTER (
                    WHERE is_correct IS NOT NULL
                )                                                        AS best_trade_roi,

                MIN(realized_roi) FILTER (
                    WHERE is_correct IS NOT NULL
                )                                                        AS worst_trade_roi

            FROM signals
            WHERE deleted_at IS NULL
            GROUP BY trigger_source
            ORDER BY profit_factor DESC, win_rate DESC
        """)

        rows = session.execute(sql).mappings().all()

        return [
            RulePerformance(
                rule_name=row["rule_name"],
                total_signals=int(row["total_signals"]),
                evaluated=int(row["evaluated"]),
                winning=int(row["winning"]),
                losing=int(row["losing"]),
                cancelled=int(row["cancelled"]),
                win_rate=float(row["win_rate"] or 0),
                avg_roi=float(row["avg_roi"]) if row["avg_roi"] is not None else None,
                median_roi=float(row["median_roi"]) if row["median_roi"] is not None else None,
                profit_factor=float(row["profit_factor"] or 0),
                max_drawdown=float(row["max_drawdown"]) if row["max_drawdown"] is not None else None,
                best_trade_roi=float(row["best_trade_roi"]) if row["best_trade_roi"] is not None else None,
                worst_trade_roi=float(row["worst_trade_roi"]) if row["worst_trade_roi"] is not None else None,
            )
            for row in rows
        ]

    def get_overall_performance(self, session: Session) -> OverallPerformance:
        """Return aggregate performance across all rules."""
        sql = text("""
            SELECT
                COUNT(*)                                                 AS total_signals,
                COUNT(*) FILTER (WHERE is_correct IS NOT NULL)           AS evaluated,
                COUNT(*) FILTER (WHERE is_correct = true)                AS winning,
                COUNT(*) FILTER (WHERE is_correct = false)               AS losing,
                COUNT(*) FILTER (WHERE resolved_outcome = 'cancelled')   AS cancelled,
                CASE
                    WHEN COUNT(*) FILTER (WHERE is_correct IS NOT NULL) = 0 THEN 0.0
                    ELSE ROUND(
                        COUNT(*) FILTER (WHERE is_correct = true)::numeric /
                        NULLIF(COUNT(*) FILTER (WHERE is_correct IS NOT NULL), 0),
                        4
                    )
                END                                                      AS win_rate,
                ROUND(AVG(realized_roi) FILTER (WHERE is_correct IS NOT NULL), 4)
                                                                         AS avg_roi,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY realized_roi)
                    FILTER (WHERE is_correct IS NOT NULL)                AS median_roi,
                CASE
                    WHEN SUM(realized_roi) FILTER (WHERE realized_roi < 0) = 0
                         OR SUM(realized_roi) FILTER (WHERE realized_roi < 0) IS NULL
                    THEN CASE
                        WHEN SUM(realized_roi) FILTER (WHERE realized_roi > 0) > 0
                        THEN 999999.0 ELSE 0.0
                    END
                    ELSE ROUND(
                        COALESCE(SUM(realized_roi) FILTER (WHERE realized_roi > 0), 0) /
                        ABS(SUM(realized_roi) FILTER (WHERE realized_roi < 0)),
                        4
                    )
                END                                                      AS profit_factor,
                MIN(realized_roi) FILTER (WHERE is_correct IS NOT NULL)  AS max_drawdown,
                ROUND(SUM(realized_roi) FILTER (WHERE is_correct IS NOT NULL), 4)
                                                                         AS net_roi
            FROM signals
            WHERE deleted_at IS NULL
        """)

        row = session.execute(sql).mappings().first()

        return OverallPerformance(
            total_signals=int(row["total_signals"] or 0),
            evaluated=int(row["evaluated"] or 0),
            winning=int(row["winning"] or 0),
            losing=int(row["losing"] or 0),
            cancelled=int(row["cancelled"] or 0),
            win_rate=float(row["win_rate"] or 0),
            avg_roi=float(row["avg_roi"]) if row["avg_roi"] is not None else None,
            median_roi=float(row["median_roi"]) if row["median_roi"] is not None else None,
            profit_factor=float(row["profit_factor"] or 0),
            max_drawdown=float(row["max_drawdown"]) if row["max_drawdown"] is not None else None,
            net_roi=float(row["net_roi"]) if row["net_roi"] is not None else None,
        )

    def get_recent_resolved_signals(
        self,
        session: Session,
        limit: int = 50,
    ) -> list[RecentSignal]:
        """
        Return recently resolved signals with market question for display.
        Ordered by resolved_at DESC — most recent first.
        """
        sql = text("""
            SELECT
                s.id                             AS signal_id,
                m.question                       AS market_question,
                s.trigger_source,
                s.direction,
                s.market_probability_at_signal   AS entry_price,
                s.edge_at_signal,
                s.resolved_outcome,
                s.is_correct,
                s.realized_roi,
                s.fired_at,
                s.resolved_at
            FROM signals s
            JOIN markets m ON m.id = s.market_id
            WHERE s.status = 'resolved'
              AND s.deleted_at IS NULL
            ORDER BY s.resolved_at DESC
            LIMIT :limit
        """)

        rows = session.execute(sql, {"limit": limit}).mappings().all()

        return [
            RecentSignal(
                signal_id=row["signal_id"],
                market_question=row["market_question"],
                trigger_source=row["trigger_source"],
                direction=row["direction"],
                entry_price=float(row["entry_price"]),
                edge_at_signal=float(row["edge_at_signal"]),
                resolved_outcome=row["resolved_outcome"],
                is_correct=row["is_correct"],
                realized_roi=float(row["realized_roi"]) if row["realized_roi"] is not None else None,
                fired_at=str(row["fired_at"]),
                resolved_at=str(row["resolved_at"]) if row["resolved_at"] else None,
            )
            for row in rows
        ]

    def get_pending_count(self, session: Session) -> int:
        """Count signals still pending evaluation."""
        result = session.execute(
            text("SELECT COUNT(*) FROM signals WHERE status = 'pending' AND deleted_at IS NULL")
        ).scalar()
        return int(result or 0)

    def get_rule_signal_counts(self, session: Session) -> dict[str, int]:
        """Return {rule_name: total_count} for all rules. Used by diagnostic."""
        rows = session.execute(
            text("""
                SELECT trigger_source, COUNT(*) AS cnt
                FROM signals
                WHERE deleted_at IS NULL
                GROUP BY trigger_source
                ORDER BY cnt DESC
            """)
        ).all()
        return {row[0]: int(row[1]) for row in rows}
