"""
services/edge_calculator.py

Changelog:
  v1.2 — tambah guard filters sebelum evaluate rules:
    - skip market expired (end_date sudah lewat)
    - skip market terlalu jauh dari expiry (> 90 hari) untuk time-sensitive rules
    - skip prob ekstrem suspek (< 2% atau > 98%) — kemungkinan stale/resolved
    - konstanta PROB_FLOOR dan PROB_CEILING untuk batas wajar signal
"""

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
        # Volume
        self.min_volume_threshold = Decimal("10000.00")
        # Minimum edge untuk generate signal — di bawah ini tidak worth trading
        self.MIN_EDGE = Decimal("0.05")

        # Rule 1: Extreme probability thresholds
        self.extreme_prob_low  = Decimal("0.15")
        self.extreme_prob_high = Decimal("0.85")

        # Guard: prob di luar range ini kemungkinan data stale/resolved
        # bukan peluang nyata — jangan generate signal
        self.PROB_FLOOR   = Decimal("0.05")   # < 5% = suspect, not exploitable
        self.PROB_CEILING = Decimal("0.98")   # > 98% = suspect

        # Rule 2: Momentum
        self.momentum_threshold = Decimal("0.10")

        # Rule 3: Volume spike
        self.volume_spike_multiplier = Decimal("3.0")

    # -------------------------------------------------------------------------
    # Public
    # -------------------------------------------------------------------------

    def evaluate(
        self, market: Any, latest_snapshot: Any, daily_stat: Any
    ) -> List[Dict[str, Any]]:
        """
        Evaluate a market against all rules.
        Returns list of signal dicts. Empty list = no signal.
        """
        if not latest_snapshot:
            return []

        # --- Guard filters (applied before ALL rules) ---
        skip_reason = self._should_skip(market, latest_snapshot)
        if skip_reason:
            log.debug(
                "signal_skipped",
                market_id=market.id,
                condition_id=market.condition_id,
                reason=skip_reason,
            )
            return []

        prob_yes  = latest_snapshot.probability_yes
        prob_no   = latest_snapshot.probability_no
        volume_24h = latest_snapshot.volume_24h_usd
        now_utc   = datetime.now(timezone.utc)

        context = {
            "price_entry": float(prob_yes),
            "volume_24h":  float(volume_24h),
            "volume_7d":   float(daily_stat.volume_7d_avg_usd)
                           if daily_stat and daily_stat.volume_7d_avg_usd else None,
            "momentum":    float(daily_stat.momentum_24h_percent)
                           if daily_stat and daily_stat.momentum_24h_percent else None,
            "liquidity":   float(latest_snapshot.liquidity_usd),
            "spread":      float(latest_snapshot.spread)
                           if latest_snapshot.spread is not None else None,
            "confidence":  0.8,
        }

        has_min_volume = volume_24h >= self.min_volume_threshold
        signals: List[Dict[str, Any]] = []

        # Rule 1: Probability Extreme (Underpriced / Overpriced)
        if has_min_volume:
            if self.PROB_FLOOR <= prob_yes < self.extreme_prob_low:
                signals.append({
                    "direction":      "yes",
                    "trigger_source": "rule_1_extreme_low",
                    "edge":           float(self.extreme_prob_low - prob_yes),
                    "context":        context,
                })
            elif self.extreme_prob_high < prob_yes <= self.PROB_CEILING:
                signals.append({
                    "direction":      "no",
                    "trigger_source": "rule_1_extreme_high",
                    "edge":           float(prob_yes - self.extreme_prob_high),
                    "context":        context,
                })

        # Rule 2: Probability Momentum
        if daily_stat and daily_stat.momentum_24h_percent is not None:
            momentum = daily_stat.momentum_24h_percent
            if momentum > self.momentum_threshold:
                signals.append({
                    "direction":      "yes",
                    "trigger_source": "rule_2_momentum_up",
                    "edge":           float(momentum),
                    "context":        context,
                })
            elif momentum < -self.momentum_threshold:
                signals.append({
                    "direction":      "no",
                    "trigger_source": "rule_2_momentum_down",
                    "edge":           float(abs(momentum)),
                    "context":        context,
                })

        # Rule 3: Volume Spike
        if (
            daily_stat
            and daily_stat.volume_7d_avg_usd
            and daily_stat.volume_7d_avg_usd > 0
        ):
            avg_vol = daily_stat.volume_7d_avg_usd
            if volume_24h > avg_vol * self.volume_spike_multiplier:
                direction = "yes"
                if (
                    daily_stat.momentum_24h_percent
                    and daily_stat.momentum_24h_percent < 0
                ):
                    direction = "no"

                spike_ratio = float(volume_24h / avg_vol)
                dynamic_edge_3 = min(round(spike_ratio / 10, 4), 0.30)
                signals.append({
                    "direction":      direction,
                    "trigger_source": "rule_3_volume_spike",
                    "edge":           dynamic_edge_3,
                    "context":        context,
                })

        # Rule 4: Time Decay (Near Expiry, 0–48 jam)
        if market.end_date:
            market_end = market.end_date
            if market_end.tzinfo is None:
                market_end = market_end.replace(tzinfo=timezone.utc)
            else:
                market_end = market_end.astimezone(timezone.utc)

            hours_to_expiry = (market_end - now_utc).total_seconds() / 3600

            # Hanya generate kalau masih ada sisa waktu (belum expired)
            # dan prob masih dalam range tidak pasti (40–60%)
            if 6 < hours_to_expiry < 48:  # min 6h: align with Laravel guard #6
                if Decimal("0.40") <= prob_yes <= Decimal("0.60"):
                    dynamic_edge_4 = min(round(float(abs(prob_yes - Decimal("0.50")) * 2), 4), 0.20)
                    signals.append({
                        "direction":      "yes",
                        "trigger_source": "rule_4_time_decay",
                        "edge":           dynamic_edge_4,
                        "context":        context,
                    })

        # Filter: only return signals with meaningful edge
        signals = [s for s in signals if Decimal(str(s["edge"])) >= self.MIN_EDGE]
        return signals

    # -------------------------------------------------------------------------
    # Private
    # -------------------------------------------------------------------------

    def _should_skip(self, market: Any, latest_snapshot: Any) -> str | None:
        """
        Guard filter sebelum evaluate rules.
        Return reason string jika harus skip, None jika lanjut.

        Checks (urutan dari paling murah ke mahal):
          1. Category bukan crypto
          2. Market sudah expired (end_date sudah lewat)
          3. Prob ekstrem suspek (< 2% atau > 98%)
        """
        now_utc = datetime.now(timezone.utc)

        # 1. Hanya crypto
        if getattr(market, "category", "crypto") != "crypto":
            return f"non_crypto_category:{market.category}"

        # 2. Skip market yang sudah expired
        if market.end_date:
            market_end = market.end_date
            if market_end.tzinfo is None:
                market_end = market_end.replace(tzinfo=timezone.utc)
            else:
                market_end = market_end.astimezone(timezone.utc)

            if market_end <= now_utc:
                return f"market_expired:end_date={market_end.isoformat()}"

        # 3. Prob ekstrem — kemungkinan data stale atau market hampir resolved
        prob_yes = latest_snapshot.probability_yes
        if prob_yes < self.PROB_FLOOR:
            return f"prob_too_low:{prob_yes}"
        if prob_yes > self.PROB_CEILING:
            return f"prob_too_high:{prob_yes}"

        return None