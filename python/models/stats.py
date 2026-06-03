from __future__ import annotations
from datetime import date, datetime
from decimal import Decimal
from typing import Optional

from sqlalchemy import (
    Date,
    DateTime,
    ForeignKey,
    Integer,
    Numeric,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship
from .market import Base

class MarketDailyStat(Base):
    __tablename__ = "market_daily_stats"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    market_id: Mapped[int] = mapped_column(
        Integer,
        ForeignKey("markets.id", ondelete="CASCADE"),
        nullable=False,
    )
    stat_date: Mapped[date] = mapped_column(Date, nullable=False)
    
    volume_7d_avg_usd: Mapped[Optional[Decimal]] = mapped_column(Numeric(20, 2), nullable=True)
    oi_change_percent: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    momentum_24h_percent: Mapped[Optional[Decimal]] = mapped_column(Numeric(10, 6), nullable=True)
    
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
    )

    __table_args__ = (
        UniqueConstraint("market_id", "stat_date", name="mkt_daily_stats_unique"),
    )

    def __repr__(self) -> str:
        return f"<MarketDailyStat market_id={self.market_id} date={self.stat_date}>"
