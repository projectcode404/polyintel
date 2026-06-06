"""
collectors/signal_collector.py

Changelog:
  v1.1 — tambah filter di query markets:
    - hanya category = 'crypto'
    - hanya market yang end_date belum lewat (atau end_date NULL)
    Sebelumnya: semua active market dievaluasi termasuk sports/politics
    dan market yang sudah expired.
"""

from utils.logger import get_logger
import time
from datetime import datetime, timezone
from sqlalchemy import select, or_

from utils.db import get_session
from models.market import Market, MarketSnapshot
from models.stats import MarketDailyStat
from services.edge_calculator import EdgeCalculator
from repositories.signal_repository import SignalRepository

log = get_logger(__name__)


class SignalCollector:
    """
    Orchestrates the signal generation process by tying together
    markets, snapshots, stats, and the EdgeCalculator.
    """

    def __init__(self):
        self.edge_calculator = EdgeCalculator()
        self.signal_repo     = SignalRepository()

    def run(self) -> None:
        log.info("signal_collector_run_start")
        start_time = time.monotonic()

        markets_evaluated  = 0
        markets_skipped    = 0
        signals_generated  = 0
        now = datetime.now(timezone.utc)

        with get_session() as session:
            markets = session.scalars(
                select(Market).where(
                    Market.status      == "active",
                    Market.is_tracked  == True,
                    Market.deleted_at  == None,
                    # Hanya crypto — non-crypto tidak punya edge data crypto
                    Market.category    == "crypto",
                    # Hanya market yang belum expired
                    # (end_date NULL = evergreen market, tetap masuk)
                    or_(
                        Market.end_date == None,
                        Market.end_date > now,
                    ),
                    # Exclude micro/noise markets:
                    # "Up or Down" = 5-minute price direction markets,
                    # probability always ~50/50, no real edge possible.
                    Market.question.notilike("%up or down%"),
                )
            ).all()

            log.info("signal_collector_markets_loaded", count=len(markets))

            for market in markets:
                # Latest snapshot
                latest_snapshot = session.scalars(
                    select(MarketSnapshot)
                    .where(MarketSnapshot.market_id == market.id)
                    .order_by(MarketSnapshot.snapshotted_at.desc())
                    .limit(1)
                ).first()

                if not latest_snapshot:
                    markets_skipped += 1
                    continue

                # Daily stat hari ini
                daily_stat = session.scalars(
                    select(MarketDailyStat)
                    .where(MarketDailyStat.market_id == market.id)
                    .where(MarketDailyStat.stat_date  == now.date())
                ).first()

                signals = self.edge_calculator.evaluate(market, latest_snapshot, daily_stat)

                if signals:
                    inserted = self.signal_repo.store_signals(signals, market.id)
                    signals_generated += inserted

                markets_evaluated += 1

        duration = time.monotonic() - start_time
        log.info(
            "signal_collector_run_done",
            evaluated=markets_evaluated,
            skipped_no_snapshot=markets_skipped,
            signals_generated=signals_generated,
            duration_sec=round(duration, 2),
        )