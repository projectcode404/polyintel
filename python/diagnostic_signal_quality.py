#!/usr/bin/env python3
"""
diagnostic_signal_quality.py

Sprint 3 — Signal Data Quality Diagnostics

Usage:
    python diagnostic_signal_quality.py
    python diagnostic_signal_quality.py --fix   # auto-fix expired signals

Checks:
  1. Signals without market_outcome (pending on resolved markets)
  2. Resolved markets without evaluated signals (missed evaluations)
  3. Signals with missing ROI on resolved status
  4. Invalid status values
  5. Signals past expires_at still pending
  6. ROI values outside expected range (sanity check)

Output:
    ============================================================
    POLYINTEL SIGNAL DATA QUALITY REPORT
    Generated: 2026-06-06 12:00:00 UTC

    [PASS] No invalid status values found
    [WARN] 12 signals pending on resolved markets (run --evaluate to fix)
    [WARN]  3 resolved signals missing ROI value
    [PASS] No ROI values outside expected range
    [INFO]  8 signals past expiry still pending
    ============================================================
"""

from __future__ import annotations

import argparse
import sys
from datetime import datetime, timezone

import os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from sqlalchemy import text
from utils.db import get_session
from utils.logger import configure_logging


# ---------------------------------------------------------------------------
# Check functions — each returns (level, message, count)
# level: PASS | WARN | FAIL | INFO
# ---------------------------------------------------------------------------

def check_invalid_statuses(session) -> tuple[str, str, int]:
    valid = ("pending", "resolved", "expired", "cancelled")
    placeholders = ", ".join(f"'{s}'" for s in valid)
    result = session.execute(text(f"""
        SELECT COUNT(*) FROM signals
        WHERE status NOT IN ({placeholders})
          AND deleted_at IS NULL
    """)).scalar()
    count = int(result or 0)
    if count == 0:
        return "PASS", "No invalid status values found", 0
    return "FAIL", f"{count} signals have invalid status values", count


def check_pending_on_resolved_markets(session) -> tuple[str, str, int]:
    """
    Signals still 'pending' but their market has a resolved outcome.
    These should have been evaluated by SignalEvaluator.
    """
    result = session.execute(text("""
        SELECT COUNT(*)
        FROM signals s
        JOIN market_outcomes mo ON mo.market_id = s.market_id
        WHERE s.status = 'pending'
          AND s.deleted_at IS NULL
    """)).scalar()
    count = int(result or 0)
    if count == 0:
        return "PASS", "No pending signals on resolved markets", 0
    return "WARN", (
        f"{count} signals pending on resolved markets "
        f"(run: python analyze_rules.py --evaluate)"
    ), count


def check_resolved_markets_without_evaluated_signals(session) -> tuple[str, str, int]:
    """
    Markets that are resolved but ALL their signals are still pending.
    Indicates evaluator has never run or is broken.
    """
    result = session.execute(text("""
        SELECT COUNT(DISTINCT mo.market_id)
        FROM market_outcomes mo
        WHERE EXISTS (
            SELECT 1 FROM signals s
            WHERE s.market_id = mo.market_id
              AND s.status = 'pending'
              AND s.deleted_at IS NULL
        )
        AND NOT EXISTS (
            SELECT 1 FROM signals s
            WHERE s.market_id = mo.market_id
              AND s.status = 'resolved'
              AND s.deleted_at IS NULL
        )
    """)).scalar()
    count = int(result or 0)
    if count == 0:
        return "PASS", "All resolved markets have evaluated signals (or no signals)", 0
    return "WARN", f"{count} resolved markets have ZERO evaluated signals", count


def check_resolved_missing_roi(session) -> tuple[str, str, int]:
    """
    Signals with status='resolved' but realized_roi IS NULL.
    This is a data integrity problem — evaluator should always set ROI.
    """
    result = session.execute(text("""
        SELECT COUNT(*)
        FROM signals
        WHERE status = 'resolved'
          AND realized_roi IS NULL
          AND deleted_at IS NULL
    """)).scalar()
    count = int(result or 0)
    if count == 0:
        return "PASS", "All resolved signals have realized_roi set", 0
    return "FAIL", f"{count} resolved signals missing realized_roi — evaluator bug", count


def check_resolved_missing_is_correct(session) -> tuple[str, str, int]:
    """
    Signals with status='resolved' but is_correct IS NULL and
    resolved_outcome != 'cancelled'.
    Cancelled signals legitimately have is_correct=NULL.
    """
    result = session.execute(text("""
        SELECT COUNT(*)
        FROM signals
        WHERE status = 'resolved'
          AND is_correct IS NULL
          AND resolved_outcome != 'cancelled'
          AND resolved_outcome IS NOT NULL
          AND deleted_at IS NULL
    """)).scalar()
    count = int(result or 0)
    if count == 0:
        return "PASS", "All non-cancelled resolved signals have is_correct set", 0
    return "FAIL", f"{count} non-cancelled resolved signals missing is_correct — evaluator bug", count


def check_roi_range(session) -> tuple[str, str, int]:
    """
    ROI sanity check: values should be between -100 and +10000.
    Beyond +10000 is theoretically possible (0.01 entry YES win = +9900%)
    but suspicious in practice.
    Negative beyond -100 is mathematically impossible.
    """
    result = session.execute(text("""
        SELECT COUNT(*)
        FROM signals
        WHERE realized_roi IS NOT NULL
          AND (realized_roi < -100.01 OR realized_roi > 10000)
          AND deleted_at IS NULL
    """)).scalar()
    count = int(result or 0)
    if count == 0:
        return "PASS", "All ROI values within expected range (-100 to +10000)", 0
    return "WARN", f"{count} signals have ROI outside expected range — check entry prices", count


def check_expired_still_pending(session) -> tuple[str, str, int]:
    """
    Signals past their expires_at that are still 'pending'.
    These should be marked 'expired' — they will never be evaluated
    unless the market resolves.
    """
    result = session.execute(text("""
        SELECT COUNT(*)
        FROM signals
        WHERE status = 'pending'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
          AND deleted_at IS NULL
    """)).scalar()
    count = int(result or 0)
    if count == 0:
        return "PASS", "No pending signals past expiry", 0
    return "INFO", (
        f"{count} pending signals past expires_at "
        f"(run with --fix to mark as expired)"
    ), count


def fix_expired_signals(session) -> int:
    """Mark pending signals past expiry as 'expired'."""
    result = session.execute(text("""
        UPDATE signals
        SET status = 'expired',
            updated_at = NOW()
        WHERE status = 'pending'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
          AND deleted_at IS NULL
    """))
    return result.rowcount


def check_summary_stats(session) -> dict:
    """Return summary counts for display."""
    row = session.execute(text("""
        SELECT
            COUNT(*)                                             AS total,
            COUNT(*) FILTER (WHERE status = 'pending')          AS pending,
            COUNT(*) FILTER (WHERE status = 'resolved')         AS resolved,
            COUNT(*) FILTER (WHERE status = 'expired')          AS expired,
            COUNT(*) FILTER (WHERE status = 'cancelled')        AS cancelled,
            COUNT(*) FILTER (WHERE is_correct = true)           AS correct,
            COUNT(*) FILTER (WHERE is_correct = false)          AS incorrect
        FROM signals
        WHERE deleted_at IS NULL
    """)).mappings().first()
    return dict(row)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

LEVEL_COLORS = {
    "PASS": "\033[92m",   # green
    "WARN": "\033[93m",   # yellow
    "FAIL": "\033[91m",   # red
    "INFO": "\033[94m",   # blue
}
RESET = "\033[0m"


def main() -> None:
    configure_logging()

    parser = argparse.ArgumentParser(
        description="Polyintel Signal Data Quality Diagnostics",
    )
    parser.add_argument(
        "--fix",
        action="store_true",
        help="Auto-fix: mark expired pending signals as expired",
    )
    parser.add_argument(
        "--no-color",
        action="store_true",
        help="Disable ANSI colors in output",
    )
    args = parser.parse_args()

    use_color = not args.no_color

    def colored(level: str, text: str) -> str:
        if not use_color:
            return f"[{level}] {text}"
        color = LEVEL_COLORS.get(level, "")
        return f"{color}[{level}]{RESET} {text}"

    sep = "=" * 60
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")

    print(f"\n{sep}")
    print("POLYINTEL SIGNAL DATA QUALITY REPORT")
    print(f"Generated: {now}")
    print(sep)

    checks = [
        check_invalid_statuses,
        check_pending_on_resolved_markets,
        check_resolved_markets_without_evaluated_signals,
        check_resolved_missing_roi,
        check_resolved_missing_is_correct,
        check_roi_range,
        check_expired_still_pending,
    ]

    has_failures = False
    fix_applied  = False

    with get_session() as session:

        # Summary stats
        stats = check_summary_stats(session)
        print(f"\nSIGNAL COUNTS")
        print(f"  Total     : {stats['total']:,}")
        print(f"  Pending   : {stats['pending']:,}")
        print(f"  Resolved  : {stats['resolved']:,}")
        print(f"  Expired   : {stats['expired']:,}")
        print(f"  Cancelled : {stats['cancelled']:,}")
        print(f"  Correct   : {stats['correct']:,}")
        print(f"  Incorrect : {stats['incorrect']:,}")
        if stats["correct"] or stats["incorrect"]:
            decided = int(stats["correct"]) + int(stats["incorrect"])
            wr = int(stats["correct"]) / decided * 100
            print(f"  Win Rate  : {wr:.1f}%")

        print(f"\nCHECKS")
        for check_fn in checks:
            level, message, count = check_fn(session)
            print(f"  {colored(level, message)}")
            if level == "FAIL":
                has_failures = True

        # Auto-fix
        if args.fix:
            fixed = fix_expired_signals(session)
            fix_applied = True
            print(f"\n  {colored('INFO', f'Fixed: {fixed} expired signals marked as expired')}")

    print(f"\n{sep}")

    if has_failures:
        print("\n⚠️  Data quality issues found. Review FAIL items above.")
        sys.exit(1)
    elif not fix_applied:
        print("\n✅ No critical issues found.")
    print()


if __name__ == "__main__":
    main()
