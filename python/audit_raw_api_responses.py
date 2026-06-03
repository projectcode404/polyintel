#!/usr/bin/env python3
"""
audit_raw_api_responses.py

Script untuk menangkap raw API responses dari Polymarket API.
Berguna untuk debug data ingestion issues.

Usage:
  python audit_raw_api_responses.py
  
Output:
  - Raw responses dari Gamma API (first 2 markets)
  - Raw responses dari CLOB API (first 2 markets)
  - Field mappings analysis
"""

import sys
import json
from decimal import Decimal

# Add app root to path
sys.path.insert(0, "/app")

from services.polymarket_service import PolymarketService
from utils.logger import get_logger

log = get_logger(__name__)


def audit_gamma_api():
    """Fetch dan display raw Gamma API response."""
    print("=" * 80)
    print("GAMMA API AUDIT - Market Metadata")
    print("=" * 80)
    
    service = PolymarketService()
    
    try:
        market_count = 0
        for page in service.iter_active_crypto_markets():
            for market in page[:2]:  # First 2 markets per page
                market_count += 1
                
                print(f"\n--- Market {market_count} ---")
                print(f"Condition ID: {market.condition_id}")
                print(f"Question: {market.question[:100]}")
                
                print("\nRaw API Fields (from raw dict):")
                raw = market.raw
                
                # Show all fields in raw response
                print(f"  All keys: {list(raw.keys())}")
                
                # Show specific fields we care about
                fields_of_interest = [
                    'outcomePrices', 'outcomesNum', 'outcomes',
                    'volume', 'volumeNum', 'totalVolume',
                    'liquidity', 'liquidityNum', 'totalLiquidity',
                    'tradesCount', 'uniqueTraders', 'traderCount',
                    'active', 'closed', 'status',
                    'tokens'
                ]
                
                print("\n  Relevant fields:")
                for field in fields_of_interest:
                    if field in raw:
                        value = raw[field]
                        if isinstance(value, str) and len(value) > 200:
                            print(f"    {field}: {value[:200]}...")
                        else:
                            print(f"    {field}: {value}")
                
                print("\nParsed Values:")
                print(f"  Probability: {market.market_probability}")
                print(f"  Volume: {market.volume_usd}")
                print(f"  Liquidity: {market.liquidity_usd}")
                print(f"  Traders: {market.num_traders}")
                
                if market_count >= 2:
                    break
            
            if market_count >= 2:
                break
                
    except Exception as exc:
        log.error("gamma_audit_failed", error=str(exc))
        print(f"ERROR: {exc}")
    finally:
        service.close()


def audit_clob_api():
    """Fetch dan display raw CLOB API response."""
    print("\n" + "=" * 80)
    print("CLOB API AUDIT - Live Orderbook Data")
    print("=" * 80)
    
    service = PolymarketService()
    
    try:
        from utils.db import get_session
        from models.market import Market
        from sqlalchemy import select, limit
        
        # Get first 2 market condition IDs from DB
        with get_session() as session:
            markets = session.execute(
                select(Market)
                .where(Market.status == "active", Market.is_tracked.is_(True))
                .limit(2)
            ).scalars().all()
            
            if not markets:
                print("No active tracked markets in database")
                return
            
            for idx, market in enumerate(markets, 1):
                print(f"\n--- Market {idx} ---")
                print(f"Condition ID: {market.condition_id}")
                print(f"Question: {market.question[:100]}")
                
                # Fetch orderbook
                orderbook = service.get_orderbook(market.condition_id)
                
                if orderbook is None:
                    print("  (Not found in CLOB API)")
                    continue
                
                print("\nParsed Orderbook Values:")
                print(f"  Probability YES: {orderbook.probability_yes}")
                print(f"  Probability NO: {orderbook.probability_no}")
                print(f"  Volume: {orderbook.volume_usd}")
                print(f"  Volume 24h: {orderbook.volume_24h_usd}")
                print(f"  Liquidity: {orderbook.liquidity_usd}")
                print(f"  Best Bid: {orderbook.best_bid}")
                print(f"  Best Ask: {orderbook.best_ask}")
                print(f"  Spread: {orderbook.spread}")
                
    except Exception as exc:
        log.error("clob_audit_failed", error=str(exc))
        print(f"ERROR: {exc}")
    finally:
        service.close()


def analyze_field_mapping():
    """Show expected vs actual field mappings."""
    print("\n" + "=" * 80)
    print("FIELD MAPPING ANALYSIS")
    print("=" * 80)
    
    print("\nGamma API - Expected Field Names:")
    print("""
  probability:
    - outcomePrices: ["0.63", "0.37"]  (YES=first, NO=second)
    
  volume:
    - volume: 15500.50
    - volumeNum: 15500.50
    
  liquidity:
    - liquidity: 8200.75
    - liquidityNum: 8200.75
    
  traders:
    - tradesCount: 150
    - uniqueTraders: 150
""")
    
    print("\nCLOB API - Expected Field Names:")
    print("""
  probability:
    - outcomePrices: ["0.63", "0.37"]
    - tokens[0].price: "0.63"
    - best_bid/best_ask from orderbook
    
  volume:
    - volume: 3200.00
    - volumeNum: 3200.00
    
  liquidity:
    - liquidity: 1800.50
    - liquidityNum: 1800.50
    - orderbook.bids/asks depth
""")
    
    print("\nWhat to Check if Values are Wrong:")
    print("""
  1. If probability = 1.0 for all markets:
     → Check if outcomePrices = ["1", "0"] (binary instead of probability)
     → Check if outcomePrices is being parsed as string "1.0"
     
  2. If volume/liquidity = 0 for all markets:
     → Check if field names are different (totalVolume, totalLiquidity, etc)
     → Check if fields are missing from response
     → Check if _safe_decimal is failing silently
     
  3. If traders = 0 for all markets:
     → Check if field name is different (traderCount, activeTradersCount, etc)
     → Check if field is missing from response
""")


if __name__ == "__main__":
    try:
        audit_gamma_api()
        audit_clob_api()
        analyze_field_mapping()
    except KeyboardInterrupt:
        print("\nAudit cancelled")
    except Exception as exc:
        log.exception("audit_failed", error=str(exc))
        print(f"FATAL ERROR: {exc}")
