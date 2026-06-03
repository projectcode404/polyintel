from utils.logger import get_logger
from decimal import Decimal
from datetime import datetime, timezone, timedelta
from typing import List, Dict, Any

log = get_logger(__name__)

class EdgeCalculator:
    """
    Evaluates markets against predefined trading rules to generate signals.
    """

    def __init__(self):
        # Configuration
        self.min_volume_threshold = Decimal("10000.00")
        self.extreme_prob_low = Decimal("0.15")
        self.extreme_prob_high = Decimal("0.85")
        self.momentum_threshold = Decimal("0.10")  # 10% change
        self.volume_spike_multiplier = Decimal("3.0")
        
    def evaluate(self, market: Any, latest_snapshot: Any, daily_stat: Any) -> List[Dict[str, Any]]:
        """
        Evaluate a market and its current state against all rules.
        Returns a list of signals (dictionaries) if any rule triggers.
        """
        signals = []
        
        # We need a snapshot to evaluate probabilities and volume
        if not latest_snapshot:
            return signals
            
        prob_yes = latest_snapshot.probability_yes
        prob_no = latest_snapshot.probability_no
        volume_24h = latest_snapshot.volume_24h_usd
        
        # Base context for all signals
        now = datetime.now(timezone.utc)
        
        context = {
            "price_entry": float(prob_yes),
            "volume_24h": float(volume_24h),
            "volume_7d": float(daily_stat.volume_7d_avg_usd) if daily_stat and daily_stat.volume_7d_avg_usd else None,
            "momentum": float(daily_stat.momentum_24h_percent) if daily_stat and daily_stat.momentum_24h_percent else None,
            "confidence": 0.8, # Placeholder for rule-based
        }
        
        # Skip if basic liquidity isn't met for some rules, though we evaluate per rule
        has_min_volume = volume_24h >= self.min_volume_threshold
        
        # Rule 1: Probability Extreme (Underpriced/Overpriced)
        if has_min_volume:
            if prob_yes < self.extreme_prob_low:
                signals.append({
                    "direction": "yes",
                    "trigger_source": "rule_1_extreme_low",
                    "edge": float(self.extreme_prob_low - prob_yes),
                    "context": context
                })
            elif prob_yes > self.extreme_prob_high:
                signals.append({
                    "direction": "no",
                    "trigger_source": "rule_1_extreme_high",
                    "edge": float(prob_yes - self.extreme_prob_high),
                    "context": context
                })
                
        # Rule 2: Probability Momentum
        # Using precomputed 24h momentum from daily_stats
        if daily_stat and daily_stat.momentum_24h_percent is not None:
            momentum = daily_stat.momentum_24h_percent
            if momentum > self.momentum_threshold:
                signals.append({
                    "direction": "yes",
                    "trigger_source": "rule_2_momentum_up",
                    "edge": float(momentum),
                    "context": context
                })
            elif momentum < -self.momentum_threshold:
                signals.append({
                    "direction": "no",
                    "trigger_source": "rule_2_momentum_down",
                    "edge": float(abs(momentum)),
                    "context": context
                })
                
        # Rule 3: Volume Spike
        if daily_stat and daily_stat.volume_7d_avg_usd and daily_stat.volume_7d_avg_usd > 0:
            avg_vol = daily_stat.volume_7d_avg_usd
            if volume_24h > (avg_vol * self.volume_spike_multiplier):
                # Direction follows current momentum if available, or just bias to yes if prob is rising
                direction = "yes"
                if daily_stat.momentum_24h_percent and daily_stat.momentum_24h_percent < 0:
                    direction = "no"
                    
                signals.append({
                    "direction": direction,
                    "trigger_source": "rule_3_volume_spike",
                    "edge": 0.05, # Fixed edge for volume spike as it's harder to quantify
                    "context": context
                })
                
        # Rule 4: Time Decay (Near Expiry)
        if market.end_date:
            hours_to_expiry = (market.end_date - now).total_seconds() / 3600
            if 0 < hours_to_expiry < 48:
                if Decimal("0.40") <= prob_yes <= Decimal("0.60"):
                    # Market is undecided but close to expiry
                    signals.append({
                        "direction": "yes", # Arbitrary for decay, could be both but we just pick yes for now or rely on other factors
                        "trigger_source": "rule_4_time_decay",
                        "edge": 0.05,
                        "context": context
                    })
                    
        return signals
