#!/usr/bin/env python3
"""
Quick API endpoint tester - find where volume/liquidity data comes from
"""
import sys
import json
sys.path.insert(0, "/app")

import httpx
from services.polymarket_service import PolymarketService
from config.settings import settings

def test_market_endpoint():
    """Test /markets/{condition_id} to see actual response structure"""
    print("=" * 80)
    print("STEP 1: Test /markets/{condition_id} endpoint")
    print("=" * 80)
    
    # Get first active market
    service = PolymarketService()
    
    try:
        for page in service.iter_active_crypto_markets():
            if page:
                market = page[0]
                print(f"\nMarket: {market.question[:60]}")
                print(f"Condition ID: {market.condition_id}")
                
                # Show raw response structure
                raw = market.raw
                print(f"\nFields in /markets/{{condition_id}} response:")
                for key in sorted(raw.keys()):
                    value = raw[key]
                    if isinstance(value, (dict, list)):
                        print(f"  {key}: {type(value).__name__} (length: {len(value)})")
                    elif isinstance(value, str) and len(str(value)) > 100:
                        print(f"  {key}: {str(value)[:100]}...")
                    else:
                        print(f"  {key}: {value}")
                
                # Extract token_id if available
                tokens = raw.get("tokens", [])
                if tokens:
                    token_id = tokens[0].get("token_id") or tokens[0].get("id")
                    print(f"\n✓ Found token_id: {token_id}")
                    print(f"  Token data: {tokens[0]}")
                    
                    return market.condition_id, token_id
                else:
                    print("\n✗ No tokens found")
                    return None, None
    finally:
        service.close()

def test_book_endpoint(token_id):
    """Test /book?token_id= endpoint"""
    print("\n" + "=" * 80)
    print("STEP 2: Test /book?token_id={} endpoint".format(token_id))
    print("=" * 80)
    
    client = httpx.Client(timeout=30)
    
    # Try different endpoints
    endpoints = [
        f"{settings.polymarket_clob_url}/book?token_id={token_id}",
        f"{settings.polymarket_clob_url}/books?token_id={token_id}",
        f"{settings.polymarket_clob_url}/order-book?token_id={token_id}",
    ]
    
    for url in endpoints:
        print(f"\nTrying: {url}")
        try:
            response = client.get(url)
            print(f"Status: {response.status_code}")
            
            if response.status_code == 200:
                data = response.json()
                print(f"✓ SUCCESS")
                print(f"Response type: {type(data).__name__}")
                print(f"Response keys/fields:")
                
                if isinstance(data, dict):
                    for key in sorted(data.keys()):
                        value = data[key]
                        if isinstance(value, (dict, list)):
                            print(f"  {key}: {type(value).__name__}")
                            if isinstance(value, list) and value:
                                print(f"    [0]: {value[0]}")
                        else:
                            print(f"  {key}: {value}")
                elif isinstance(data, list):
                    print(f"  Array with {len(data)} items")
                    if data:
                        print(f"  [0]: {data[0]}")
                
                print(f"\nFull response (first 1000 chars):")
                print(json.dumps(data, indent=2)[:1000])
                return data
        except Exception as e:
            print(f"✗ Failed: {e}")
    
    client.close()
    return None

def test_markets_list():
    """Test if /markets endpoint has volume/liquidity"""
    print("\n" + "=" * 80)
    print("STEP 3: Check /markets endpoint for volume/liquidity")
    print("=" * 80)
    
    client = httpx.Client(timeout=30)
    url = f"{settings.polymarket_clob_url}/markets"
    
    print(f"\nTrying: {url}?limit=1")
    try:
        response = client.get(url, params={"limit": 1})
        print(f"Status: {response.status_code}")
        
        if response.status_code == 200:
            data = response.json()
            print(f"Response type: {type(data).__name__}")
            
            if isinstance(data, list) and data:
                market = data[0]
                print(f"\nFirst market fields:")
                for key in sorted(market.keys()):
                    value = market[key]
                    if isinstance(value, (dict, list)):
                        print(f"  {key}: {type(value).__name__}")
                    else:
                        print(f"  {key}: {value}")
            elif isinstance(data, dict):
                print(f"Response keys: {list(data.keys())}")
    except Exception as e:
        print(f"✗ Failed: {e}")
    
    client.close()

if __name__ == "__main__":
    condition_id, token_id = test_market_endpoint()
    
    if token_id:
        book_data = test_book_endpoint(token_id)
        if book_data:
            print("\n" + "=" * 80)
            print("FINDINGS:")
            print("=" * 80)
            print(f"✓ /book?token_id= endpoint works")
            print(f"  Contains: {list(book_data.keys()) if isinstance(book_data, dict) else 'array'}")
    
    test_markets_list()
