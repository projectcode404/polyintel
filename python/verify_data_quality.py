#!/usr/bin/env python3
"""
Verification: Check if we're now getting real volume/liquidity/bid/ask data
"""
import sys
sys.path.insert(0, "/app")

from utils.db import get_session
from models.market import Market
from sqlalchemy import select, desc

def show_top_markets_by_metric(metric_name, sort_field):
    """Show top 20 markets by a specific metric"""
    with get_session() as session:
        markets = session.execute(
            select(Market)
            .where(Market.status == "active")
            .order_by(desc(sort_field))
            .limit(20)
        ).scalars().all()
        
        print(f"\n{'='*100}")
        print(f"TOP 20 MARKETS BY {metric_name}")
        print(f"{'='*100}")
        print(f"{'Question':<50} {metric_name:>15} {'Probability':>12}")
        print(f"{'-'*100}")
        
        for m in markets:
            value = getattr(m, sort_field.name, 0)
            prob = m.market_probability or 0
            question = (m.question or "")[:49]
            print(f"{question:<50} {value:>15.2f} {prob:>12.4f}")

if __name__ == "__main__":
    print("\nVERIFICATION: Real data in database")
    
    show_top_markets_by_metric("VOLUME_USD", Market.volume_usd)
    show_top_markets_by_metric("LIQUIDITY_USD", Market.liquidity_usd)
    
    # Show summary statistics
    print(f"\n{'='*100}")
    print("SUMMARY STATISTICS")
    print(f"{'='*100}")
    
    with get_session() as session:
        active = session.execute(
            select(Market).where(Market.status == "active")
        ).scalars().all()
        
        volumes = [m.volume_usd for m in active if m.volume_usd and m.volume_usd > 0]
        liquidities = [m.liquidity_usd for m in active if m.liquidity_usd and m.liquidity_usd > 0]
        probs = [float(m.market_probability) for m in active if m.market_probability and 0 < m.market_probability < 1]
        
        print(f"Total active markets: {len(active)}")
        print(f"Markets with volume > 0: {len(volumes)}")
        print(f"Markets with liquidity > 0: {len(liquidities)}")
        print(f"Markets with probability 0 < p < 1: {len(probs)}")
        
        if volumes:
            print(f"\nVolume statistics:")
            print(f"  Max: ${max(volumes):,.2f}")
            print(f"  Min: ${min(volumes):,.2f}")
            print(f"  Avg: ${sum(volumes)/len(volumes):,.2f}")
        
        if liquidities:
            print(f"\nLiquidity statistics:")
            print(f"  Max: ${max(liquidities):,.2f}")
            print(f"  Min: ${min(liquidities):,.2f}")
            print(f"  Avg: ${sum(liquidities)/len(liquidities):,.2f}")
        
        if probs:
            print(f"\nProbability statistics:")
            print(f"  Max: {max(probs):.4f}")
            print(f"  Min: {min(probs):.4f}")
            print(f"  Avg: {sum(probs)/len(probs):.4f}")
