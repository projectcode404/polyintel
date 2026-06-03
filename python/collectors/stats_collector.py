from utils.logger import get_logger
from datetime import datetime, timezone, timedelta
from sqlalchemy import select, func

from utils.db import get_session
from models.market import Market, MarketSnapshot
from models.stats import MarketDailyStat

log = get_logger(__name__)

class StatsCollector:
    """
    Precomputes daily statistics for markets, such as 7-day average volume
    and 24h momentum, to be used by the EdgeCalculator for signal generation.
    """

    def run(self) -> None:
        log.info("stats_collector_run_start")
        
        now = datetime.now(timezone.utc)
        today = now.date()
        yesterday_time = now - timedelta(days=1)
        seven_days_ago = now - timedelta(days=7)
        
        updated_count = 0
        
        with get_session() as session:
            # 1. Get active markets
            markets = session.scalars(
                select(Market).where(Market.status == "active")
            ).all()
            
            for market in markets:
                # Calculate 7d average volume
                # For this, we can average the 'volume_24h_usd' from snapshots taken around midnight,
                # but to be simple, we can just look at the latest snapshot's total volume and subtract 
                # the total volume from 7 days ago, then divide by 7.
                # Actually, if we want avg 24h volume over 7 days, we can take the average of volume_24h_usd.
                avg_vol_result = session.execute(
                    select(func.avg(MarketSnapshot.volume_24h_usd))
                    .where(MarketSnapshot.market_id == market.id)
                    .where(MarketSnapshot.snapshotted_at >= seven_days_ago)
                ).scalar()
                
                avg_vol_7d = avg_vol_result if avg_vol_result is not None else 0
                
                # Calculate 24h momentum (probability change)
                latest_snapshot = session.scalars(
                    select(MarketSnapshot)
                    .where(MarketSnapshot.market_id == market.id)
                    .order_by(MarketSnapshot.snapshotted_at.desc())
                    .limit(1)
                ).first()
                
                snapshot_24h_ago = session.scalars(
                    select(MarketSnapshot)
                    .where(MarketSnapshot.market_id == market.id)
                    .where(MarketSnapshot.snapshotted_at <= yesterday_time)
                    .order_by(MarketSnapshot.snapshotted_at.desc())
                    .limit(1)
                ).first()
                
                momentum_24h = 0
                if latest_snapshot and snapshot_24h_ago:
                    momentum_24h = float(latest_snapshot.probability_yes) - float(snapshot_24h_ago.probability_yes)
                    
                # Store or update the daily stat
                stat = session.scalars(
                    select(MarketDailyStat)
                    .where(MarketDailyStat.market_id == market.id)
                    .where(MarketDailyStat.stat_date == today)
                ).first()
                
                if not stat:
                    stat = MarketDailyStat(
                        market_id=market.id,
                        stat_date=today,
                    )
                    session.add(stat)
                
                stat.volume_7d_avg_usd = avg_vol_7d
                stat.momentum_24h_percent = momentum_24h
                
                updated_count += 1
                
            session.commit()
            
        log.info("stats_collector_run_done", markets_processed=updated_count)
