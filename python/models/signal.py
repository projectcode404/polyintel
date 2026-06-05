"""
models/signal.py

Changelog:
  v1.1 — filter crypto & non-expired markets di signal_collector.
  v1.2 — Sprint 3: tambah evaluation fields.
          resolved_outcome, is_correct, realized_roi, resolved_at
          Semua nullable: signals existing (pending) belum punya nilai.
          NULL = belum dievaluasi. Diisi oleh SignalEvaluator setelah
          market_outcomes tersedia.
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
    String,
    Text,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship
from .market import Base


class Signal(Base):
    __tablename__ = "signals"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    market_id: Mapped[int] = mapped_column(
        Integer,
        ForeignKey("markets.id", ondelete="CASCADE"),
        nullable=False,
    )
    ai_prediction_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    direction: Mapped[str] = mapped_column(
        String(10),
        nullable=False,
        comment="yes | no — which side we are signalling",
    )

    # --- Signal context at fire time ---
    market_probability_at_signal: Mapped[Decimal] = mapped_column(
        Numeric(8, 6),
        nullable=False,
        comment="Market probability when signal was generated",
    )
    ai_probability_at_signal: Mapped[Optional[Decimal]] = mapped_column(
        Numeric(8, 6),
        nullable=True,
        comment="AI probability at signal time — populated in Sprint 4",
    )
    edge_at_signal: Mapped[Decimal] = mapped_column(
        Numeric(8, 6),
        nullable=False,
        comment="Edge when signal fired",
    )
    confidence_at_signal: Mapped[Optional[Decimal]] = mapped_column(
        Numeric(8, 6),
        nullable=True,
        comment="Confidence score — populated in Sprint 4",
    )

    min_edge_threshold: Mapped[Decimal] = mapped_column(
        Numeric(8, 6),
        default=Decimal("0.05"),
        nullable=False,
    )
    trigger_source: Mapped[str] = mapped_column(
        String(50),
        default="edge_threshold",
        nullable=False,
        comment="Rule name: rule_1_extreme_low, rule_2_momentum_up, etc.",
    )
    status: Mapped[str] = mapped_column(
        String(30),
        default="pending",
        nullable=False,
        comment="pending | resolved | expired | cancelled",
    )
    notes: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    snapshot_data: Mapped[Optional[dict]] = mapped_column(JSON, nullable=True)

    fired_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        nullable=False,
        comment="When the signal was generated",
    )
    expires_at: Mapped[Optional[datetime]] = mapped_column(
        DateTime(timezone=True),
        nullable=True,
    )

    # -------------------------------------------------------------------------
    # Sprint 3 — Evaluation fields
    # Diisi oleh services/signal_evaluator.py setelah market_outcomes tersedia.
    # NULL = belum dievaluasi.
    # -------------------------------------------------------------------------

    resolved_outcome: Mapped[Optional[str]] = mapped_column(
        String(20),
        nullable=True,
        comment="yes | no | cancelled — copied from market_outcomes.winning_side",
    )
    is_correct: Mapped[Optional[bool]] = mapped_column(
        Boolean,
        nullable=True,
        comment="True if signal direction matched winning_side. NULL = not yet evaluated.",
    )
    realized_roi: Mapped[Optional[Decimal]] = mapped_column(
        Numeric(10, 4),
        nullable=True,
        comment=(
            "ROI % based on entry probability and final outcome. "
            "NULL = not yet evaluated. "
            "Formula YES win:  ((1 - entry) / entry) * 100. "
            "Formula NO  win:  (entry / (1 - entry)) * 100. "
            "Loss (either direction): -100.0"
        ),
    )
    resolved_at: Mapped[Optional[datetime]] = mapped_column(
        DateTime(timezone=True),
        nullable=True,
        comment="Copied from market_outcomes.resolved_at — actual market resolution time.",
    )

    # --- Timestamps ---
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
    )
    deleted_at: Mapped[Optional[datetime]] = mapped_column(
        DateTime(timezone=True),
        nullable=True,
    )

    __table_args__ = (
        # Existing indexes (keep)
        Index("signals_market_status_idx", "market_id", "status"),
        Index("signals_source_idx", "trigger_source"),
        Index("signals_status_fired_idx", "status", "fired_at"),
        Index("signals_direction_idx", "direction"),
        Index("signals_edge_idx", "edge_at_signal"),
        # Sprint 3 — new indexes
        Index("signals_is_correct_idx", "is_correct"),
        Index("signals_resolved_at_idx", "resolved_at"),
        Index("signals_roi_idx", "realized_roi"),
        Index("signals_source_correct_idx", "trigger_source", "is_correct"),
    )

    def __repr__(self) -> str:
        return (
            f"<Signal id={self.id} market_id={self.market_id} "
            f"rule={self.trigger_source!r} status={self.status!r}>"
        )

    # -------------------------------------------------------------------------
    # Convenience helpers — used by SignalEvaluator
    # -------------------------------------------------------------------------

    @property
    def entry_price(self) -> Decimal:
        """
        Entry price = market_probability_at_signal.
        For YES signals: probability_yes at fire time.
        For NO  signals: same value (evaluator adjusts ROI formula).
        """
        return self.market_probability_at_signal

    @property
    def is_evaluated(self) -> bool:
        """True when this signal has been evaluated against an outcome."""
        return self.is_correct is not None

    @property
    def is_pending(self) -> bool:
        return self.status == "pending"
