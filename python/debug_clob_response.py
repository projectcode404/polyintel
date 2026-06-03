#!/usr/bin/env python3
"""
Debug: Inspect actual CLOB API /markets/{condition_id} response
"""
import sys
sys.path.insert(0, "/app")

from utils.db import get_session
from models.market import Market
from sqlalchemy import select
import httpx
from config.settings import settings

def debug_market_endpoint():
    """Fetch actual market response and show all fields"""
    
    # Get first active market from DB
    with get_session() as s:
        market = s.execute(
            select(Market).where(Market.status == "active").limit(1)
        ).scalar()
        
        if not market:
            print("No active markets in database")
            return
        
        condition_id = market.condition_id
        print(f"Testing with market: {market.question[:60]}...")
        print(f"Condition ID: {condition_id}\n")
    
    # Fetch from CLOB API
    url = f"{settings.polymarket_clob_url}/markets/{condition_id}"
    print(f"Fetching: {url}\n")
    
    try:
        response = httpx.get(url, timeout=10)
        print(f"Status: {response.status_code}")
        
        if response.status_code == 200:
            data = response.json()
            print(f"\nResponse keys: {list(data.keys())}\n")
            
            # Show structure
            print("=" * 80)
            print("FULL RESPONSE STRUCTURE:")
            print("=" * 80)
            import json
            print(json.dumps(data, indent=2, default=str)[:2000])
            
            # Check for volume/liquidity fields
            print("\n" + "=" * 80)
            print("VOLUME/LIQUIDITY FIELDS:")
            print("=" * 80)
            
            for key in ["volume", "volumeNum", "volume24h", "volume_24h", "volume24hr",
                       "liquidity", "liquidityNum", "volume_usd", "liquidity_usd"]:
                value = data.get(key)
                print(f"{key:<20} = {value}")
            
            # Check tokens structure
            print("\n" + "=" * 80)
            print("TOKENS STRUCTURE:")
            print("=" * 80)
            
            tokens = data.get("tokens", [])
            if tokens:
                print(f"Number of tokens: {len(tokens)}")
                if isinstance(tokens, list) and tokens:
                    print(f"\nFirst token keys: {list(tokens[0].keys())}")
                    print(f"First token (sample): {tokens[0]}")
        else:
            print(f"Error: {response.text}")
    
    except Exception as e:
        print(f"Exception: {e}")

if __name__ == "__main__":
    debug_market_endpoint()
