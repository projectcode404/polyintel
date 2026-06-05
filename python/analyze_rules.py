#!/usr/bin/env python3
"""
analyze_rules.py

Sprint 3 — Rule Performance CLI Report

Usage:
    python analyze_rules.py
    python analyze_rules.py --evaluate   # run evaluator first, then report
    python analyze_rules.py --json       # output as JSON

Example output:
    ==================================================
    POLYINTEL RULE PERFORMANCE REPORT
    Generated: 2026-06-06 12:00:00 UTC

    OVERALL
      Total Signals : 603
      Evaluated     : 410
      Pending       : 193
      Win Rate      : 54.9%
      Avg ROI       : +8.3%
      Profit Factor : 1.42

    ──────────────────────────────────────────────────
    RULE LEADERBOARD
    ──────────────────────────────────────────────────
    Rule                    Signals  Eval  WinRate  AvgROI  MedianROI  PF
    rule_1_extreme_low          248   180   62.2%  +12.4%     +8.1%  1.87  ✅
    rule_2_momentum_up          190   140   55.0%   +4.8%     +2.3%  1.21  ✅
    rule_4_time_decay            94    56   46.4%   -1.7%     -3.1%  0.89  ⚠️
    rule_3_volume_spike          71    34   41.2%   -3.2%     -5.0%  0.71  ❌

    ──────────────────────────────────────────────────
    VERDICT
    ──────────────────────────────────────────────────
    ✅ KEEP   rule_1_extreme_low  (PF=1.87, WR=62.2%)
    ✅ KEEP   rule_2_momentum_up  (PF=1.21, WR=55.0%)
    ⚠️  WATCH  rule_4_time_decay   (PF=0.89, WR=46.4%)
    ❌ DISABLE rule_3_volume_spike (PF=0.71, WR=41.2%)
    ==================================================
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone

# Ensure project root is on path when running as script
import os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from utils.db import get_session
from utils.logger import configure_logging
from repositories.signal_analytics_repository import (
    SignalAnalyticsRepository,
    RulePerformance,
    OverallPerformance,
)


# ---------------------------------------------------------------------------
# Verdict thresholds — adjust as data accumulates
# ---------------------------------------------------------------------------
PROFIT_FACTOR_KEEP    = 1.10   # PF >= 1.10 → KEEP
PROFIT_FACTOR_WATCH   = 0.90   # PF >= 0.90 → WATCH
WIN_RATE_MINIMUM      = 0.50   # Below 50% WR is a yellow flag
MIN_EVALUATED         = 20     # Need at least 20 evaluated signals for verdict


def _verdict(rule: RulePerformance) -> tuple[str, str]:
    """
    Return (symbol, label) verdict for a rule.

    Logic:
      Not enough data → INSUFFICIENT DATA
      PF >= 1.10      → KEEP
      PF >= 0.90      → WATCH
      PF <  0.90      → DISABLE
    """
    if rule.evaluated < MIN_EVALUATED:
        return "❓", "INSUFFICIENT DATA"
    if rule.profit_factor >= PROFIT_FACTOR_KEEP:
        return "✅", "KEEP"
    if rule.profit_factor >= PROFIT_FACTOR_WATCH:
        return "⚠️ ", "WATCH"
    return "❌", "DISABLE"


def _fmt_roi(value: float | None) -> str:
    if value is None:
        return "   N/A"
    sign = "+" if value >= 0 else ""
    return f"{sign}{value:.1f}%"


def _fmt_pf(value: float) -> str:
    if value >= 999990:
        return "  ∞"
    return f"{value:.2f}"


def print_report(rules: list[RulePerformance], overall: OverallPerformance) -> None:
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")
    sep = "=" * 60

    print(f"\n{sep}")
    print("POLYINTEL RULE PERFORMANCE REPORT")
    print(f"Generated: {now}")

    # --- Overall ---
    print(f"\n{'OVERALL':}")
    print(f"  Total Signals  : {overall.total_signals:,}")
    print(f"  Evaluated      : {overall.evaluated:,}")
    print(f"  Pending        : {overall.total_signals - overall.evaluated:,}")
    print(f"  Cancelled      : {overall.cancelled:,}")
    print(f"  Win Rate       : {overall.win_rate * 100:.1f}%")
    print(f"  Avg ROI        : {_fmt_roi(overall.avg_roi)}")
    print(f"  Median ROI     : {_fmt_roi(overall.median_roi)}")
    print(f"  Profit Factor  : {_fmt_pf(overall.profit_factor)}")
    print(f"  Max Drawdown   : {_fmt_roi(overall.max_drawdown)}")
    if overall.net_roi is not None:
        print(f"  Net ROI (sum)  : {_fmt_roi(overall.net_roi)}")

    # --- Rule leaderboard ---
    print(f"\n{'─' * 60}")
    print("RULE LEADERBOARD")
    print(f"{'─' * 60}")

    header = (
        f"{'Rule':<28} {'Signals':>7} {'Eval':>5} "
        f"{'WinRate':>8} {'AvgROI':>8} {'Median':>8} {'PF':>6}"
    )
    print(header)
    print("─" * 60)

    for rule in rules:
        sym, _ = _verdict(rule)
        print(
            f"{rule.rule_name:<28} "
            f"{rule.total_signals:>7} "
            f"{rule.evaluated:>5} "
            f"{rule.win_rate * 100:>7.1f}% "
            f"{_fmt_roi(rule.avg_roi):>8} "
            f"{_fmt_roi(rule.median_roi):>8} "
            f"{_fmt_pf(rule.profit_factor):>6}  {sym}"
        )

    # --- Verdict ---
    print(f"\n{'─' * 60}")
    print("VERDICT")
    print(f"{'─' * 60}")

    for rule in rules:
        sym, label = _verdict(rule)
        print(
            f"{sym} {label:<20} {rule.rule_name:<28} "
            f"(PF={_fmt_pf(rule.profit_factor)}, WR={rule.win_rate * 100:.1f}%)"
        )

    print(f"\n{sep}\n")


def print_json(rules: list[RulePerformance], overall: OverallPerformance) -> None:
    output = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "overall": {
            "total_signals": overall.total_signals,
            "evaluated": overall.evaluated,
            "winning": overall.winning,
            "losing": overall.losing,
            "cancelled": overall.cancelled,
            "win_rate": overall.win_rate,
            "avg_roi": overall.avg_roi,
            "median_roi": overall.median_roi,
            "profit_factor": overall.profit_factor,
            "max_drawdown": overall.max_drawdown,
            "net_roi": overall.net_roi,
        },
        "rules": [
            {
                "rule_name": r.rule_name,
                "total_signals": r.total_signals,
                "evaluated": r.evaluated,
                "winning": r.winning,
                "losing": r.losing,
                "cancelled": r.cancelled,
                "win_rate": r.win_rate,
                "avg_roi": r.avg_roi,
                "median_roi": r.median_roi,
                "profit_factor": r.profit_factor,
                "max_drawdown": r.max_drawdown,
                "best_trade_roi": r.best_trade_roi,
                "worst_trade_roi": r.worst_trade_roi,
                "verdict": _verdict(r)[1],
            }
            for r in rules
        ],
    }
    print(json.dumps(output, indent=2))


def main() -> None:
    configure_logging()

    parser = argparse.ArgumentParser(
        description="Polyintel Rule Performance Report",
    )
    parser.add_argument(
        "--evaluate",
        action="store_true",
        help="Run SignalEvaluator first to process any pending resolved signals",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Output as JSON instead of formatted table",
    )
    args = parser.parse_args()

    # Optionally run evaluator first
    if args.evaluate:
        from services.signal_evaluator import SignalEvaluator
        print("Running signal evaluator...")
        evaluator = SignalEvaluator()
        eval_result = evaluator.run()
        print(
            f"Evaluated {eval_result.evaluated} signals "
            f"({eval_result.correct} correct, {eval_result.incorrect} incorrect, "
            f"{eval_result.cancelled} cancelled)\n"
        )

    # Load analytics
    repo = SignalAnalyticsRepository()
    with get_session() as session:
        rules   = repo.get_rule_performance(session)
        overall = repo.get_overall_performance(session)

    if not rules:
        print("No signal data found. Wait for markets to resolve and signals to be evaluated.")
        sys.exit(0)

    if args.json:
        print_json(rules, overall)
    else:
        print_report(rules, overall)


if __name__ == "__main__":
    main()
