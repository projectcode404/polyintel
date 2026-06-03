from utils.logger import get_logger
import time
from datetime import datetime, timezone
from sqlalchemy import select

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
        self.signal_repo = SignalRepository()

    def run(self) -> None:
        log.info("signal_collector_run_start")
        start_time = time.monotonic()
        
        markets_evaluated = 0
        signals_generated = 0
        now = datetime.now(timezone.utc)
        today = now.date()
        
        with get_session() as session:
            # 1. Get active markets
            markets = session.scalars(
                select(Market).where(Market.status == "active")
            ).all()
            
            for market in markets:
                # Get latest snapshot
                latest_snapshot = session.scalars(
                    select(MarketSnapshot)
                    .where(MarketSnapshot.market_id == market.id)
                    .order_by(MarketSnapshot.snapshotted_at.desc())
                    .limit(1)
                ).first()
                
                if not latest_snapshot:
                    continue
                    
                # Get daily stat for today
                daily_stat = session.scalars(
                    select(MarketDailyStat)
                    .where(MarketDailyStat.market_id == market.id)
                    .where(MarketDailyStat.stat_date == today)
                ).first()
                
                # Evaluate rules
                signals = self.edge_calculator.evaluate(market, latest_snapshot, daily_stat)
                
                if signals:
                    # Store signals
                    inserted = self.signal_repo.store_signals(signals, market.id)
                    signals_generated += inserted
                
                markets_evaluated += 1
                
        duration = time.monotonic() - start_time
        log.info(
            "signal_collector_run_done",
            evaluated=markets_evaluated,
            signals=signals_generated,
            duration_sec=round(duration, 2)
        )
