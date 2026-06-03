"""
diagnostic.py

Run inside the Python container on VPS:
  docker compose exec python python diagnostic.py

Produces a comprehensive audit of the data pipeline.
"""

import sys
import json
from datetime import datetime, timezone, timedelta
from decimal import Decimal

# Add app root to path
sys.path.insert(0, "/app")

from sqlalchemy import select, func, text, case, and_
from utils.db import get_session
from models.market import Market, MarketSnapshot
from models.stats import MarketDailyStat
from models.signal import Signal

def run_diagnostic():
    print("=" * 70)
    print("POLYINTEL PIPELINE DIAGNOSTIC")
    print(f"Run at: {datetime.now(timezone.utc).isoformat()}")
    print("=" * 70)

    now = datetime.now(timezone.utc)
    yesterday = now - timedelta(days=1)
    seven_days_ago = now - timedelta(days=7)

    with get_session() as session:
        # =====================================================================
        # AUDIT 3: Database Validation - Market Status Distribution
        # =====================================================================
        print("\n--- AUDIT 3: Market Status Distribution ---")

        status_counts = session.execute(
            select(Market.status, func.count(Market.id))
            .group_by(Market.status)
        ).all()

        total_markets = 0
        for status, count in status_counts:
            print(f"  {status}: {count}")
            total_markets += count
        print(f"  TOTAL: {total_markets}")

        # is_tracked distribution
        tracked_counts = session.execute(
            select(Market.is_tracked, func.count(Market.id))
            .group_by(Market.is_tracked)
        ).all()
        print(f"\n  is_tracked distribution:")
        for tracked, count in tracked_counts:
            print(f"    tracked={tracked}: {count}")

        # Active + tracked (what snapshot collector sees)
        active_tracked = session.execute(
            select(func.count(Market.id))
            .where(Market.status == "active", Market.is_tracked.is_(True), Market.deleted_at.is_(None))
        ).scalar()
        print(f"\n  Active + Tracked (snapshotable): {active_tracked}")

        # =====================================================================
        # Sample 10 recent markets
        # =====================================================================
        print("\n--- Sample 10 Recent Markets ---")
        recent_markets = session.execute(
            select(Market)
            .order_by(Market.updated_at.desc())
            .limit(10)
        ).scalars().all()

        for m in recent_markets:
            print(f"  [{m.status}] id={m.id} prob={m.market_probability} "
                  f"vol=${m.volume_usd} liq=${m.liquidity_usd} "
                  f"traders={m.num_traders} end={m.end_date} "
                  f"Q: {(m.question or '')[:60]}")

        # =====================================================================
        # AUDIT 6: Data Completeness
        # =====================================================================
        print("\n--- AUDIT 6: Data Completeness (markets table) ---")

        completeness_query = session.execute(text("""
            SELECT
                COUNT(*) as total,
                COUNT(market_probability) as prob_populated,
                COUNT(CASE WHEN market_probability > 0 AND market_probability < 1 THEN 1 END) as prob_not_extreme,
                COUNT(CASE WHEN market_probability >= 0.85 THEN 1 END) as prob_high,
                COUNT(CASE WHEN market_probability <= 0.15 THEN 1 END) as prob_low,
                COUNT(CASE WHEN volume_usd > 0 THEN 1 END) as vol_populated,
                COUNT(CASE WHEN liquidity_usd > 0 THEN 1 END) as liq_populated,
                COUNT(CASE WHEN num_traders > 0 THEN 1 END) as traders_populated,
                COUNT(end_date) as end_date_populated,
                AVG(volume_usd) as avg_volume,
                AVG(liquidity_usd) as avg_liquidity
            FROM markets
            WHERE status = 'active' AND deleted_at IS NULL
        """)).first()

        if completeness_query:
            total = completeness_query[0] or 1
            print(f"  Total active markets: {total}")
            print(f"  probability populated:     {completeness_query[1]}/{total} ({completeness_query[1]*100//total}%)")
            print(f"  probability 0<p<1:         {completeness_query[2]}/{total} ({completeness_query[2]*100//total}%)")
            print(f"  probability >= 85%:        {completeness_query[3]}")
            print(f"  probability <= 15%:        {completeness_query[4]}")
            print(f"  volume_usd > 0:            {completeness_query[5]}/{total} ({completeness_query[5]*100//total}%)")
            print(f"  liquidity_usd > 0:         {completeness_query[6]}/{total} ({completeness_query[6]*100//total}%)")
            print(f"  num_traders > 0:            {completeness_query[7]}/{total} ({completeness_query[7]*100//total}%)")
            print(f"  end_date populated:        {completeness_query[8]}/{total} ({completeness_query[8]*100//total}%)")
            print(f"  avg volume_usd:            ${completeness_query[9]}")
            print(f"  avg liquidity_usd:         ${completeness_query[10]}")

        # =====================================================================
        # Snapshot Analysis
        # =====================================================================
        print("\n--- Snapshot Analysis ---")

        total_snapshots = session.execute(
            select(func.count(MarketSnapshot.id))
        ).scalar()
        print(f"  Total snapshots: {total_snapshots}")

        recent_snapshots = session.execute(
            select(func.count(MarketSnapshot.id))
            .where(MarketSnapshot.snapshotted_at >= yesterday)
        ).scalar()
        print(f"  Snapshots last 24h: {recent_snapshots}")

        distinct_markets_snapshotted = session.execute(
            select(func.count(func.distinct(MarketSnapshot.market_id)))
            .where(MarketSnapshot.snapshotted_at >= yesterday)
        ).scalar()
        print(f"  Distinct markets snapshotted last 24h: {distinct_markets_snapshotted}")

        # Sample snapshots with volume_24h
        print("\n  Sample 5 recent snapshots (volume_24h analysis):")
        sample_snaps = session.execute(
            select(MarketSnapshot)
            .order_by(MarketSnapshot.snapshotted_at.desc())
            .limit(5)
        ).scalars().all()

        for s in sample_snaps:
            print(f"    market_id={s.market_id} prob_yes={s.probability_yes} "
                  f"vol_24h=${s.volume_24h_usd} vol_total=${s.volume_usd} "
                  f"liq=${s.liquidity_usd} at={s.snapshotted_at}")

        # Volume 24h distribution in snapshots
        vol_dist = session.execute(text("""
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN volume_24h_usd > 0 THEN 1 END) as has_vol24h,
                COUNT(CASE WHEN volume_24h_usd >= 1000 THEN 1 END) as vol24h_1k,
                COUNT(CASE WHEN volume_24h_usd >= 10000 THEN 1 END) as vol24h_10k,
                AVG(volume_24h_usd) as avg_vol24h,
                MAX(volume_24h_usd) as max_vol24h
            FROM market_snapshots
            WHERE snapshotted_at >= NOW() - INTERVAL '24 hours'
        """)).first()

        if vol_dist:
            total_s = vol_dist[0] or 1
            print(f"\n  Volume 24h distribution (last 24h snapshots):")
            print(f"    vol_24h > $0:      {vol_dist[1]}/{total_s} ({vol_dist[1]*100//total_s}%)")
            print(f"    vol_24h >= $1k:    {vol_dist[2]}/{total_s}")
            print(f"    vol_24h >= $10k:   {vol_dist[3]}/{total_s}")
            print(f"    avg vol_24h:       ${vol_dist[4]}")
            print(f"    max vol_24h:       ${vol_dist[5]}")

        # =====================================================================
        # AUDIT 4: Signal Pipeline
        # =====================================================================
        print("\n--- AUDIT 4: Signal Pipeline ---")

        # Market Daily Stats
        total_stats = session.execute(
            select(func.count(MarketDailyStat.id))
        ).scalar()
        today_stats = session.execute(
            select(func.count(MarketDailyStat.id))
            .where(MarketDailyStat.stat_date == now.date())
        ).scalar()
        print(f"  market_daily_stats total:   {total_stats}")
        print(f"  market_daily_stats today:   {today_stats}")

        if today_stats and today_stats > 0:
            sample_stats = session.execute(
                select(MarketDailyStat)
                .where(MarketDailyStat.stat_date == now.date())
                .limit(5)
            ).scalars().all()
            print(f"  Sample daily stats:")
            for st in sample_stats:
                print(f"    market_id={st.market_id} vol_7d_avg=${st.volume_7d_avg_usd} "
                      f"momentum={st.momentum_24h_percent}")

        # Signals
        total_signals = session.execute(
            select(func.count(Signal.id))
        ).scalar()
        pending_signals = session.execute(
            select(func.count(Signal.id))
            .where(Signal.status == "pending")
        ).scalar()
        print(f"\n  signals total:     {total_signals}")
        print(f"  signals pending:   {pending_signals}")

        # =====================================================================
        # AUDIT 5: Rule Threshold Simulation
        # =====================================================================
        print("\n--- AUDIT 5: Rule Threshold Simulation ---")

        # Rule 1: Extreme Probability
        # Original: prob < 0.15 OR prob > 0.85, AND volume_24h >= 10000
        extreme_with_vol = session.execute(text("""
            SELECT COUNT(DISTINCT ms.market_id)
            FROM market_snapshots ms
            JOIN markets m ON m.id = ms.market_id
            WHERE m.status = 'active'
              AND ms.snapshotted_at >= NOW() - INTERVAL '1 hour'
              AND (ms.probability_yes < 0.15 OR ms.probability_yes > 0.85)
              AND ms.volume_24h_usd >= 10000
        """)).scalar()

        extreme_no_vol = session.execute(text("""
            SELECT COUNT(DISTINCT ms.market_id)
            FROM market_snapshots ms
            JOIN markets m ON m.id = ms.market_id
            WHERE m.status = 'active'
              AND ms.snapshotted_at >= NOW() - INTERVAL '1 hour'
              AND (ms.probability_yes < 0.15 OR ms.probability_yes > 0.85)
        """)).scalar()

        extreme_lower_vol = session.execute(text("""
            SELECT COUNT(DISTINCT ms.market_id)
            FROM market_snapshots ms
            JOIN markets m ON m.id = ms.market_id
            WHERE m.status = 'active'
              AND ms.snapshotted_at >= NOW() - INTERVAL '1 hour'
              AND (ms.probability_yes < 0.15 OR ms.probability_yes > 0.85)
              AND ms.volume_24h_usd >= 1000
        """)).scalar()

        print(f"  Rule 1 (Extreme Probability):")
        print(f"    Prob extreme (no vol filter):    {extreme_no_vol}")
        print(f"    + vol_24h >= $1,000:             {extreme_lower_vol}")
        print(f"    + vol_24h >= $10,000 (current):  {extreme_with_vol}")

        # Rule 2: Momentum (requires daily_stats)
        momentum_matches = 0
        if today_stats and today_stats > 0:
            momentum_matches = session.execute(text("""
                SELECT COUNT(*)
                FROM market_daily_stats mds
                JOIN markets m ON m.id = mds.market_id
                WHERE m.status = 'active'
                  AND mds.stat_date = CURRENT_DATE
                  AND ABS(mds.momentum_24h_percent) > 0.10
            """)).scalar() or 0

        print(f"  Rule 2 (Momentum > 10%):")
        print(f"    Matches:                         {momentum_matches}")
        print(f"    (requires daily_stats populated: {'YES' if today_stats else 'NO - TABLE EMPTY'})")

        # Rule 3: Volume Spike
        vol_spike_matches = 0
        if today_stats and today_stats > 0:
            vol_spike_matches = session.execute(text("""
                SELECT COUNT(*)
                FROM market_daily_stats mds
                JOIN markets m ON m.id = mds.market_id
                JOIN LATERAL (
                    SELECT volume_24h_usd FROM market_snapshots
                    WHERE market_id = m.id
                    ORDER BY snapshotted_at DESC LIMIT 1
                ) latest_snap ON true
                WHERE m.status = 'active'
                  AND mds.stat_date = CURRENT_DATE
                  AND mds.volume_7d_avg_usd > 0
                  AND latest_snap.volume_24h_usd > mds.volume_7d_avg_usd * 3
            """)).scalar() or 0

        print(f"  Rule 3 (Volume Spike > 3x):")
        print(f"    Matches:                         {vol_spike_matches}")

        # Rule 4: Time Decay
        time_decay_matches = session.execute(text("""
            SELECT COUNT(*)
            FROM markets m
            JOIN LATERAL (
                SELECT probability_yes FROM market_snapshots
                WHERE market_id = m.id
                ORDER BY snapshotted_at DESC LIMIT 1
            ) latest_snap ON true
            WHERE m.status = 'active'
              AND m.end_date IS NOT NULL
              AND m.end_date > NOW()
              AND m.end_date < NOW() + INTERVAL '48 hours'
              AND latest_snap.probability_yes BETWEEN 0.40 AND 0.60
        """)).scalar() or 0

        print(f"  Rule 4 (Time Decay, expiry < 48h, prob 40-60%):")
        print(f"    Matches:                         {time_decay_matches}")

        # =====================================================================
        # Probability distribution for active markets
        # =====================================================================
        print("\n--- Probability Distribution (Active Markets) ---")
        prob_dist = session.execute(text("""
            SELECT
                CASE
                    WHEN market_probability IS NULL THEN 'NULL'
                    WHEN market_probability = 0 THEN '0%'
                    WHEN market_probability < 0.15 THEN '1-14%'
                    WHEN market_probability < 0.40 THEN '15-39%'
                    WHEN market_probability < 0.60 THEN '40-59%'
                    WHEN market_probability < 0.85 THEN '60-84%'
                    WHEN market_probability < 1.0 THEN '85-99%'
                    WHEN market_probability = 1.0 THEN '100%'
                    ELSE 'OTHER'
                END as bucket,
                COUNT(*) as cnt
            FROM markets
            WHERE status = 'active' AND deleted_at IS NULL
            GROUP BY bucket
            ORDER BY bucket
        """)).all()

        for bucket, cnt in prob_dist:
            print(f"  {bucket:>10}: {cnt}")

    print("\n" + "=" * 70)
    print("DIAGNOSTIC COMPLETE")
    print("=" * 70)


if __name__ == "__main__":
    run_diagnostic()
