from utils.logger import get_logger
from datetime import datetime, timezone, timedelta
from sqlalchemy import select
from typing import List, Dict, Any

from utils.db import get_session
from models.signal import Signal

log = get_logger(__name__)

class SignalRepository:
    """
    Handles persistence of generated signals.
    """

    def store_signals(self, signals_data: List[Dict[str, Any]], market_id: int) -> int:
        """
        Stores new signals for a market.
        Avoids creating duplicates if a pending signal for the same rule already exists.
        """
        if not signals_data:
            return 0
            
        inserted_count = 0
        now = datetime.now(timezone.utc)
        
        with get_session() as session:
            for s_data in signals_data:
                rule_name = s_data["trigger_source"]
                
                # Check if an active/pending signal for this rule already exists
                # to avoid spamming the same signal every 5 minutes
                existing = session.scalars(
                    select(Signal)
                    .where(Signal.market_id == market_id)
                    .where(Signal.trigger_source == rule_name)
                    .where(Signal.status == "pending")
                ).first()
                
                if existing:
                    continue
                    
                new_signal = Signal(
                    market_id=market_id,
                    direction=s_data["direction"],
                    market_probability_at_signal=s_data["context"]["price_entry"],
                    edge_at_signal=s_data["edge"],
                    confidence_at_signal=s_data["context"]["confidence"],
                    min_edge_threshold=0.05,
                    trigger_source=rule_name,
                    status="pending",
                    snapshot_data=s_data["context"],
                    fired_at=now,
                    expires_at=now + timedelta(hours=24),
                    momentum_24h_percent=s_data["context"].get("momentum"),
                    volume_24h_usd=s_data["context"].get("volume_24h"),
                    liquidity_usd=s_data["context"].get("liquidity"),
                    spread=s_data["context"].get("spread"),
                )
                
                session.add(new_signal)
                inserted_count += 1
                
            if inserted_count > 0:
                session.commit()
                
        return inserted_count
