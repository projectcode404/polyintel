from __future__ import annotations
from datetime import datetime
from decimal import Decimal
from typing import Optional

from sqlalchemy import (
    JSON,
    DateTime,
    ForeignKey,
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
    direction: Mapped[str] = mapped_column(String(10), nullable=False)
    
    market_probability_at_signal: Mapped[Decimal] = mapped_column(Numeric(8, 6), nullable=False)
    ai_probability_at_signal: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    edge_at_signal: Mapped[Decimal] = mapped_column(Numeric(8, 6), nullable=False)
    confidence_at_signal: Mapped[Optional[Decimal]] = mapped_column(Numeric(8, 6), nullable=True)
    
    min_edge_threshold: Mapped[Decimal] = mapped_column(Numeric(8, 6), default=0.05)
    trigger_source: Mapped[str] = mapped_column(String(50), default="edge_threshold")
    status: Mapped[str] = mapped_column(String(30), default="pending")
    notes: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    
    snapshot_data: Mapped[Optional[dict]] = mapped_column(JSON, nullable=True)
    
    fired_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    expires_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
    )
    deleted_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)

    def __repr__(self) -> str:
        return f"<Signal id={self.id} market_id={self.market_id} rule={self.trigger_source!r}>"
