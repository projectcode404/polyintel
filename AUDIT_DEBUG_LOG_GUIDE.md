# Data Pipeline Audit - Debug Logging Guide

## Status
Added comprehensive debug logging ke `polymarket_service.py` untuk menangkap raw API responses dan parsing values untuk market pertama.

## Log Events Yang Ditambahkan

### 1. Gamma API Raw Response (Markets Metadata)
**Location:** `_fetch_keyset_page()` - First page call
**Events:**
- `DEBUG_GAMMA_API_RAW_FIRST_MARKET` - Keys dan sample data dari first market raw response
- `DEBUG_GAMMA_API_OUTCOME_PRICES` - Specific field values: outcomePrices, volume, liquidity

**Expected Output:**
```
market_keys: ['conditionId', 'question', 'outcomePrices', 'volume', 'liquidity', 'tradesCount', ...]
first_market_outcome_prices: ['0.63', '0.37'] atau "0.63" atau None
first_market_volume: 1500.50 atau 0 atau None
first_market_volume_num: sama
first_market_liquidity: 2000.75 atau 0 atau None
first_market_liquidity_num: sama
```

### 2. Gamma API Parsing (5 Markets)
**Location:** `_parse_page()` - First 5 markets
**Event:** `DEBUG_GAMMA_PARSE`

**Tracks:**
- market_num: 1-5
- condition_id: Market identifier
- raw_outcome_prices: Raw value dari API
- raw_volume, raw_volume_num: All volume field variants
- raw_liquidity, raw_liquidity_num: All liquidity field variants
- raw_trades_count, raw_unique_traders: All trader count field variants
- parsed_probability: Final probability value
- parsed_volume: Final volume value
- parsed_liquidity: Final liquidity value
- parsed_traders: Final traders value

**What to Look For:**
```
Market 1:
  raw_outcome_prices: ['0.63', '0.37']     ← Should have YES/NO prices
  raw_volume: 0 atau 1500.50               ← Volume from API
  raw_liquidity: 0 atau 2000.75            ← Liquidity from API
  parsed_probability: 0.63 atau 1.0        ← SUSPECT: Why 1.0?
  parsed_volume: 0 atau 1500.50            ← SUSPECT: Why 0?
  parsed_liquidity: 0 atau 2000.75         ← SUSPECT: Why 0?
```

### 3. Probability Extraction Detail (5+ Markets)
**Location:** `_extract_probability_yes()` - All calls up to 5
**Events:**
- `DEBUG_EXTRACT_PROBABILITY` - Raw input
- `DEBUG_EXTRACT_PROBABILITY_AFTER_JSON_PARSE` - After JSON parsing (if string)
- `DEBUG_EXTRACT_PROBABILITY_FINAL` - Final value after clamping

**Tracks:**
```
call_num: 1-5+
raw_outcome_prices: Input value (may be string, list, None)
outcome_prices_type: Type (str, list, NoneType, etc)
first_element: outcome_prices[0]
as_decimal: String representation
clamped_value: Final value (0.0 to 1.0)
```

**Critical Check:**
If `raw_outcome_prices` is string:
```
'["1", "0"]'     ← YES=1, NO=0 (WRONG - should be probability)
'["0.63", "0.37"]' ← YES=0.63, NO=0.37 (CORRECT)
```

### 4. CLOB API Raw Response (Live Prices)
**Location:** `get_orderbook()` - First 2 calls
**Event:** `DEBUG_CLOB_API_RAW_RESPONSE`

**Tracks:**
```
fetch_num: 1-2
condition_id: Market ID
market_data_keys: All fields in response
market_data_sample: First 800 chars of response
```

### 5. CLOB API Parsing (5 Markets)
**Location:** `_parse_clob_market()` - First 5 calls
**Event:** `DEBUG_CLOB_PARSE` (in get_orderbooks_batch)

**Tracks:**
```
market_num: 1-5
condition_id: Market ID
parsed_probability_yes: YES probability
parsed_probability_no: NO probability (should = 1 - YES)
parsed_volume: Volume from CLOB
parsed_liquidity: Liquidity from CLOB
best_bid, best_ask: Orderbook bid/ask prices
```

---

## How to Run the Audit

### 1. Deploy Updated Code
```bash
cd /path/to/polyintel
docker-compose up -d
```

### 2. Trigger Market Sync (Gamma API only)
```bash
docker-compose exec python python -c "
from collectors.markets_collector import MarketsCollector
collector = MarketsCollector()
result = collector.run()
print(f'Result: {result}')
collector.close()
"
```

### 3. Trigger Snapshot Sync (CLOB API)
```bash
docker-compose exec python python -c "
from collectors.snapshots_collector import SnapshotsCollector
collector = SnapshotsCollector()
result = collector.run()
print(f'Result: {result}')
collector.close()
"
```

### 4. View Logs
```bash
# Real-time
docker-compose logs -f python | grep DEBUG_

# Or save to file
docker-compose logs python | grep DEBUG_ > debug_logs.txt
```

---

## Hypothesis Testing

### Test 1: Probability Always 1.0
**If `outcomePrices` is missing or malformed:**
- Check: `DEBUG_EXTRACT_PROBABILITY` shows `raw_outcome_prices: None`
- Result: Falls back to `Decimal("0.5")` — should NOT be 1.0

**If `outcomePrices = ["1", "0"]`:**
- Check: `DEBUG_EXTRACT_PROBABILITY_FINAL` shows `clamped_value: 1.0`
- Hypothesis: API is returning "1" instead of probability (e.g., binary YES/NO)

**If `outcomePrices = "1.0"` or similar:**
- Check: `DEBUG_EXTRACT_PROBABILITY_AFTER_JSON_PARSE` shows parsed value
- Hypothesis: API field contains boolean or binary instead of probability

### Test 2: Volume & Liquidity Always 0
**Check in `DEBUG_GAMMA_API_OUTCOME_PRICES`:**
- If `first_market_volume: None` atau `first_market_volume_num: None`
  → API tidak mengirim volume field
- If `first_market_volume: 0`
  → API mengirim 0 (tidak ada trading)

**Check in `DEBUG_GAMMA_PARSE`:**
- Compare `raw_volume` vs `parsed_volume`
- If parsing is wrong: `raw_volume: 1500` but `parsed_volume: 0`

### Test 3: Traders Always 0
**Check field names:**
- Gamma API mungkin menggunakan field name lain: `traderCount`, `activeTradersCount`, etc.
- Current code looks for: `tradesCount` atau `uniqueTraders`

---

## Expected Normal Output Example

```
DEBUG_GAMMA_PARSE (Market 1):
  condition_id: "0x123abc"
  raw_outcome_prices: ['0.6302', '0.3698']
  raw_volume: 15500.50
  raw_liquidity: 8200.75
  parsed_probability: 0.6302
  parsed_volume: 15500.50
  parsed_liquidity: 8200.75

DEBUG_CLOB_PARSE (Market 1):
  condition_id: "0x123abc"
  parsed_probability_yes: 0.6295
  parsed_volume: 3200.00
  parsed_liquidity: 1800.50
```

---

## Next Steps After Audit

1. Collect debug logs untuk 5-10 markets
2. Analyze dan identifikasi pattern
3. Update `_parse_gamma_market()` atau `_extract_probability_yes()` sesuai actual API response format
4. Remove debug logging setelah problem identified
5. Test fix dengan full collector run
6. Verify database values updated correctly

---

## Files Modified
- `python/services/polymarket_service.py`
  - `_fetch_keyset_page()` - Added raw response logging
  - `_extract_probability_yes()` - Added probability extraction logging
  - `_parse_gamma_market()` via `_parse_page()` - Added field comparison logging
  - `_parse_clob_market()` - Added raw response logging
  - `get_orderbook()` - Added raw response logging
  - `get_orderbooks_batch()` - Added parsed result logging
