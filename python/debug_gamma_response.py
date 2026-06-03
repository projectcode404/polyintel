#!/usr/bin/env python3
"""
Debug: Check if Gamma API /markets/{condition_id} has volume/liquidity
"""
import sys
sys.path.insert(0, "/app")

from utils.db import get_session
from models.market import Market
from sqlalchemy import select
import httpx
from config.settings import settings

def debug_gamma_endpoint():
    """Fetch actual market response from Gamma API"""
    
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
    
    # Try different Gamma API endpoints
    endpoints = [
        f"{settings.polymarket_gamma_url}/markets/{condition_id}",
        f"{settings.polymarket_gamma_url}/markets?condition_id={condition_id}",
    ]
    
    for url in endpoints:
        print(f"\n{'='*80}")
        print(f"Fetching: {url}")
        print(f"{'='*80}")
        
        try:
            response = httpx.get(url, timeout=10)
            print(f"Status: {response.status_code}")
            
            if response.status_code == 200:
                data = response.json()
                
                # Handle array response
                if isinstance(data, list):
                    if data:
                        data = data[0]
                    else:
                        print("Empty array response")
                        continue
                
                # Handle object response with "data" wrapper
                if isinstance(data, dict) and "data" in data:
                    data = data["data"]
                
                print(f"\nResponse type: {type(data)}")
                print(f"Keys: {list(data.keys())[:15]}...")
                
                # Check for volume/liquidity
                print("\n" + "-"*80)
                print("VOLUME/LIQUIDITY FIELDS:")
                print("-"*80)
                
                for key in ["volume", "volumeNum", "volume24h", "volume_24h", "volume24hr",
                           "liquidity", "liquidityNum", "volume_usd", "liquidity_usd", "tradesCount", "traders"]:
                    value = data.get(key)
                    if value is not None:
                        print(f"{key:<20} = {value}")
                
        except Exception as e:
            print(f"Error: {e}")

if __name__ == "__main__":
    debug_gamma_endpoint()
