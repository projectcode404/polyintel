# Data Ingestion Pipeline Audit - Complete Analysis

**Status:** Comprehensive debug logging added
**Date:** 2026-06-03
**Problem:** All markets stored with: probability=1.0, volume=0, liquidity=0, traders=0

---

## Part 1: Problem Diagnosis

### Symptoms
- 904 active markets with identical problematic values
- Gamma API initial ingestion → All fields populated incorrectly
- Market snapshots collector shows same bad values
- Signal engine sees bad data (but that's not where the bug is)

### Expected Normal State
```
Market 1 (should be):
  condition_id: "0x123abc..."
  probability: 0.6302  (or any value 0-1)
  volume: 15500.50
  liquidity: 8200.75
  traders: 150

Market 1 (currently is):
  condition_id: "0x123abc..."
  probability: 1.0     ← WRONG
  volume: 0            ← WRONG
  liquidity: 0         ← WRONG
  traders: 0           ← WRONG
```

### Impact Chain
```
Gamma API Response (raw JSON)
    ↓
PolymarketService._parse_gamma_market() ← SUSPECT: Field extraction
    ↓
RawMarket dataclass (parsed values)
    ↓
MarketRepository.upsert_from_api() 
    ↓
Database markets table (bad values stored)
    ↓
SnapshotsCollector reads markets
    ↓
SnapshotsCollector.get_orderbooks_batch() ← Secondary source (CLOB API)
    ↓
Snapshot data (also bad because based on bad market baseline)
```

---

## Part 2: Root Cause Hypotheses

### Hypothesis A: Probability Field Extraction
**Code Location:** `polymarket_service.py` → `_extract_probability_yes()`

**How It Works:**
```python
def _extract_probability_yes(self, data: dict) -> Decimal | None:
    outcome_prices = data.get("outcomePrices")
    # Tries to parse as JSON if string
    # Returns first element if list
    # Returns None if not found
```

**Possible Issues:**
1. **API returns binary instead of probability:**
   ```
   WRONG: ["1", "0"]           # YES market yes, NO market no (binary)
   RIGHT: ["0.6302", "0.3698"] # 63.02% YES, 36.98% NO (probability)
   ```

2. **API returns probability as string:**
   ```
   WRONG: "1.0" or "1"         # Always parsed to 1.0
   RIGHT: "0.6302"              # Properly parsed to 0.6302
   ```

3. **Field name is different:**
   ```
   WRONG: outcomePrices not in response
   RIGHT: Some other field contains probability
   ```

4. **Parsing logic is wrong:**
   ```
   Possible: JSON parse fails, falls back to 0.5 → No, should be None
   Possible: First element extraction wrong
   Possible: Clamping logic broken
   ```

### Hypothesis B: Volume & Liquidity
**Code Location:** `polymarket_service.py` → `_parse_gamma_market()` → fields extraction

**How It Works:**
```python
volume_usd=self._safe_decimal(
    data.get("volume") or data.get("volumeNum") or 0
),
```

**Possible Issues:**
1. **Fields not in response at all:**
   ```
   API response doesn't have: volume, volumeNum, totalVolume, etc.
   Falls back to 0 as default
   ```

2. **Fields have different names:**
   ```
   WRONG: Looking for "volume" but API has "totalVolume" or "volumeUsd"
   RIGHT: API provides field name we're looking for
   ```

3. **Fields are None or null:**
   ```
   data.get("volume") returns None
   or None = Falls through to next option
   None or 0 = Falls through to 0
   ```

4. **Safe_decimal is silently failing:**
   ```python
   def _safe_decimal(value: Any) -> Decimal:
       try:
           return Decimal(str(value))
       except:
           return Decimal("0")  # Silent failure
   ```

### Hypothesis C: Traders Count
**Code Location:** `polymarket_service.py` → `_parse_gamma_market()` → traders extraction

**How It Works:**
```python
num_traders=int(
    data.get("tradesCount") or data.get("uniqueTraders") or 0
),
```

**Possible Issues:**
1. **Field names don't match:**
   ```
   API has: tradeCount, tradersCount, activeTradersCount
   Code looks for: tradesCount, uniqueTraders
   ```

2. **Field not in response:**
   ```
   Falls back to 0
   ```

---

## Part 3: Debug Logging Instrumentation

### What Was Added

#### 3.1 Gamma API Raw Response Logging
**File:** `polymarket_service.py:_fetch_keyset_page()`

```python
if self._keyset_fetch_count == 1 and markets_list:
    first_market = markets_list[0]
    log.info("DEBUG_GAMMA_API_RAW_FIRST_MARKET", ...)
    log.info("DEBUG_GAMMA_API_OUTCOME_PRICES", ...)
```

**Output:**
```
DEBUG_GAMMA_API_OUTCOME_PRICES:
  first_market_outcome_prices: ['0.63', '0.37']  # What API sends
  first_market_volume: 1500.50                    # What API sends
  first_market_liquidity: 8200.75                 # What API sends
```

#### 3.2 Probability Extraction Logging (Detail)
**File:** `polymarket_service.py:_extract_probability_yes()`

Three events per call:
- `DEBUG_EXTRACT_PROBABILITY` - Raw input value
- `DEBUG_EXTRACT_PROBABILITY_AFTER_JSON_PARSE` - After string→JSON conversion
- `DEBUG_EXTRACT_PROBABILITY_FINAL` - Final clamped value

**Output:**
```
Call 1:
  raw_outcome_prices: ["0.63", "0.37"]
  outcome_prices_type: list
  first_element: "0.63"
  as_decimal: 0.63
  clamped_value: 0.63

vs if bug:
  raw_outcome_prices: ["1", "0"]
  first_element: "1"
  clamped_value: 1.0  ← SUSPECT: Why always YES market?
```

#### 3.3 Parsing Results Logging
**File:** `polymarket_service.py:_parse_page()`

```python
if idx < 5:
    log.info("DEBUG_GAMMA_PARSE", {
        market_num: 1-5,
        raw_outcome_prices: (from API),
        raw_volume: (from API),
        raw_liquidity: (from API),
        parsed_probability: (calculated),
        parsed_volume: (calculated),
        parsed_liquidity: (calculated),
    })
```

**Output:**
```
Market 1:
  raw_outcome_prices: ['0.63', '0.37']
  raw_volume: 1500.50
  parsed_probability: 0.63
  parsed_volume: 0 ← MISMATCH: Why 0 instead of 1500.50?
```

#### 3.4 CLOB API Logging
**File:** `polymarket_service.py:get_orderbook()`

```python
if self._clob_fetch_count <= 2:
    log.info("DEBUG_CLOB_API_RAW_RESPONSE", {
        market_data_keys: list(keys),
        market_data_sample: (first 800 chars),
    })
```

**Output:**
```
DEBUG_CLOB_API_RAW_RESPONSE (Market 1):
  market_data_keys: ['outcomePrices', 'volume', 'liquidity', 'orderbook', ...]
  market_data_sample: {"outcomePrices": ["0.63", "0.37"], ...}
```

#### 3.5 CLOB Parsing Results
**File:** `polymarket_service.py:get_orderbooks_batch()`

```python
if idx < 5:
    log.info("DEBUG_CLOB_PARSE", {
        parsed_probability_yes: (value),
        parsed_volume: (value),
        parsed_liquidity: (value),
    })
```

---

## Part 4: How to Run the Audit

### Step 1: Deploy Updated Code
```bash
cd /path/to/polyintel

# Update code with debug logging
git pull  # or copy updated files

# Rebuild Python container
docker-compose up -d --build python
```

### Step 2: Trigger Collection Runs

#### Option A: Direct Script Execution
```bash
# Gamma API audit (market metadata)
docker-compose exec python python /app/audit_raw_api_responses.py

# Market collection with debug logging
docker-compose exec python python -c "
from collectors.markets_collector import MarketsCollector
collector = MarketsCollector()
result = collector.run()
print(f'Inserted: {result.inserted}, Updated: {result.updated}')
collector.close()
"

# Snapshot collection with debug logging
docker-compose exec python python -c "
from collectors.snapshots_collector import SnapshotsCollector
collector = SnapshotsCollector()
result = collector.run()
print(f'Snapshots written: {result.snapshots_written}')
collector.close()
"
```

#### Option B: Check Scheduler Logs
```bash
# Real-time
docker-compose logs -f python | grep DEBUG_

# Save to file
docker-compose logs python > logs.txt
grep DEBUG_ logs.txt > debug_logs.txt
```

### Step 3: Analyze Logs

Create a file with grep:
```bash
docker-compose logs python | grep "DEBUG_GAMMA_API_OUTCOME_PRICES\|DEBUG_EXTRACT_PROBABILITY_FINAL\|DEBUG_GAMMA_PARSE" > analysis.txt
```

Look for patterns:
```
✓ GOOD pattern:
  raw_outcome_prices: ['0.63', '0.37']
  parsed_probability: 0.63
  raw_volume: 1500.50
  parsed_volume: 1500.50

✗ BAD pattern (all markets identical):
  raw_outcome_prices: ['1', '0']
  parsed_probability: 1.0  ← Always 1.0
  raw_volume: 0
  parsed_volume: 0
```

---

## Part 5: Decision Tree for Fix

### If Probability = 1.0 for all

```
Check: raw_outcome_prices value in DEBUG_GAMMA_API_OUTCOME_PRICES
  
  If ["1", "0"]:
    → API returns binary YES/NO instead of probability
    → Fix: Need to determine actual probability from different field
    → Search for: isProbability field, hasPriceData, explicit probability field
    
  If "1.0" or "1":
    → API returns string "1.0" instead of ["0.63", "0.37"]
    → Fix: Different field contains probability
    
  If None:
    → outcomePrices field missing
    → Falls back to 0.5 (should be 0.5, not 1.0)
    → Check if _extract_probability_yes logic has bug
```

### If Volume/Liquidity = 0 for all

```
Check: raw_volume and raw_liquidity in DEBUG_GAMMA_API_OUTCOME_PRICES

  If None:
    → Fields not in API response
    → Fix: Find correct field names (totalVolume, volumeUsd, etc)
    → Action: Fetch raw API response to see what fields are available
    
  If 0:
    → Fields in response but value is actually 0
    → Possible reasons:
        a) New crypto markets have no trading yet (legitimate)
        b) API doesn't return volume data for certain markets
    
  If positive (e.g., 1500.50):
    → Raw value is correct
    → But parsed_volume is 0
    → Fix: Bug in _safe_decimal or field extraction logic
```

### If Traders = 0 for all

```
Similar to volume/liquidity:
  
  If raw_trades_count/raw_unique_traders not shown in logs:
    → Fields not in API response
    → Fix: Find correct field name
    
  If raw value is present but positive:
    → Parsing bug in int() conversion
```

---

## Part 6: New Audit Script

Created: `python/audit_raw_api_responses.py`

This script:
1. Fetches first 2 markets from Gamma API
2. Shows all raw fields in response
3. Shows parsed values
4. Fetches first 2 markets from CLOB API
5. Shows field mapping expectations

**Run it:**
```bash
docker-compose exec python python /app/audit_raw_api_responses.py
```

---

## Part 7: Key Files to Check

| File | Purpose | Modified |
|------|---------|----------|
| `polymarket_service.py` | API client + parsing | ✓ Debug logging added |
| `market_repository.py` | DB persistence | (no change needed) |
| `markets_collector.py` | Orchestration | (no change needed) |
| `snapshots_collector.py` | Live data | (no change needed) |
| `audit_raw_api_responses.py` | NEW audit tool | ✓ Created |
| `AUDIT_DEBUG_LOG_GUIDE.md` | NEW documentation | ✓ Created |

---

## Part 8: Expected Next Steps

1. **Run audit script** → `audit_raw_api_responses.py`
   - Shows raw API response structure
   - Shows which fields are available

2. **Collect debug logs** → Run market collection
   - Shows raw vs parsed value comparison
   - First 5 markets detailed breakdown

3. **Analyze patterns** → Compare all debug log entries
   - If all markets have identical bad values → Systematic issue
   - If some markets have correct values → Partial parsing issue

4. **Identify root cause** → Match pattern to hypothesis
   - Field name mismatch?
   - Wrong data type?
   - Silent error in conversion?

5. **Fix parsing logic** → Update extraction functions
   - May need to change field names being searched
   - May need to handle new data format
   - May need to add fallback logic

6. **Validate fix** → Test with full run
   - Verify new values in database
   - Check several markets for variation
   - Confirm volume/liquidity are non-zero

7. **Remove debug logging** → Clean up code
   - Remove `DEBUG_*` log events
   - Remove counter variables
   - Keep code production-ready

---

## Part 9: Checklist

- [x] Debug logging added for all parsing functions
- [x] Raw API response logging added
- [x] Probability extraction detailed logging added
- [x] Field comparison logging added (raw vs parsed)
- [x] Audit script created (`audit_raw_api_responses.py`)
- [x] Documentation created (`AUDIT_DEBUG_LOG_GUIDE.md`)
- [ ] Run audit script to see raw API structure
- [ ] Collect debug logs from market sync run
- [ ] Collect debug logs from snapshot sync run
- [ ] Analyze and identify root cause
- [ ] Implement fix to parsing logic
- [ ] Test fix with manual collection run
- [ ] Validate database values are correct
- [ ] Remove temporary debug logging
- [ ] Run full diagnostic again

---

## Part 10: Support Information

**Debug Logging Counters:**
- `_extract_prob_count` - Tracks calls to probability extraction (logs first 5+)
- `_keyset_fetch_count` - Tracks API page fetches (logs first page only)
- `_clob_fetch_count` - Tracks CLOB fetches (logs first 2)
- `_clob_parse_count` - Tracks CLOB parsing (logs first 5)

**Log Levels Used:**
- INFO - All debug events (will show in default config)
- WARNING - Partial failures
- ERROR - Critical failures

**Search Tips:**
```bash
# All debug events
docker-compose logs python | grep DEBUG_

# Specific event type
docker-compose logs python | grep DEBUG_GAMMA_PARSE

# With line numbers
docker-compose logs python | grep -n DEBUG_

# Count occurrences
docker-compose logs python | grep DEBUG_ | wc -l

# Save and analyze
docker-compose logs python > full_logs.txt
grep DEBUG_ full_logs.txt > debug_only.txt
```

