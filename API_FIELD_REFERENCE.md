# API Response Field Reference & Potential Fixes

**This document shows the current field extraction logic and potential fixes based on common Polymarket API response patterns.**

---

## 1. Probability Extraction Analysis

### Current Code
[polymarket_service.py:_extract_probability_yes()]

```python
def _extract_probability_yes(self, data: dict[str, Any]) -> Decimal | None:
    outcome_prices = data.get("outcomePrices")
    if outcome_prices is None:
        return None
    if isinstance(outcome_prices, str):
        try:
            outcome_prices = json.loads(outcome_prices)
        except (ValueError, json.JSONDecodeError):
            return None
    if isinstance(outcome_prices, list) and outcome_prices:
        try:
            p = Decimal(str(outcome_prices[0]))
            return max(Decimal("0"), min(Decimal("1"), p))
        except (InvalidOperation, Exception):
            return None
    return None
```

### Expected API Responses

#### Format A: List of Decimal Strings ✓ (Should work)
```json
{
  "outcomePrices": ["0.6302", "0.3698"]
}
```
**Result:** probability = 0.6302 ✓

#### Format B: List of Floats ✓ (Should work)
```json
{
  "outcomePrices": [0.6302, 0.3698]
}
```
**Result:** probability = 0.6302 ✓

#### Format C: JSON String ✓ (Should work with current parsing)
```json
{
  "outcomePrices": "[\"0.6302\", \"0.3698\"]"
}
```
**Result:** probability = 0.6302 ✓

#### Format D: Binary YES/NO ✗ (Current code fails)
```json
{
  "outcomePrices": ["1", "0"]  OR  [1, 0]
}
```
**Current Result:** probability = 1.0 ✗ (SUSPECT - matches observed bug!)
**Why:** Takes first element which is always YES outcome
**Fix Needed:** Detect if this is binary and handle differently

#### Format E: No outcomePrices field ✗ (Fallback missing)
```json
{
  "tokens": [
    {"name": "YES", "price": "0.6302"},
    {"name": "NO", "price": "0.3698"}
  ]
}
```
**Current Result:** probability = None (no fallback)
**Fix Needed:** Add fallback to tokens[0].price if outcomePrices missing

### Possible Fix - Add Fallback Logic

```python
def _extract_probability_yes(self, data: dict[str, Any]) -> Decimal | None:
    # Try 1: outcomePrices (primary)
    outcome_prices = data.get("outcomePrices")
    if outcome_prices is not None:
        if isinstance(outcome_prices, str):
            try:
                outcome_prices = json.loads(outcome_prices)
            except:
                outcome_prices = None
        
        if isinstance(outcome_prices, list) and len(outcome_prices) >= 2:
            try:
                # Check if this looks like binary (1,0 or "1","0")
                first = str(outcome_prices[0]).strip('"')
                if first in ("1", "0"):
                    # Looks like binary, might need different handling
                    # For now, return it as-is
                    pass
                
                p = Decimal(str(outcome_prices[0]))
                return max(Decimal("0"), min(Decimal("1"), p))
            except:
                pass
    
    # Try 2: tokens[0].price (fallback)
    tokens = data.get("tokens", [])
    if isinstance(tokens, list) and tokens:
        try:
            price = tokens[0].get("price") if isinstance(tokens[0], dict) else None
            if price is not None:
                p = Decimal(str(price))
                return max(Decimal("0"), min(Decimal("1"), p))
        except:
            pass
    
    # Try 3: midPrice (if available)
    mid_price = data.get("midPrice") or data.get("mid_price")
    if mid_price is not None:
        try:
            p = Decimal(str(mid_price))
            return max(Decimal("0"), min(Decimal("1"), p))
        except:
            pass
    
    return None
```

---

## 2. Volume Extraction Analysis

### Current Code
[polymarket_service.py:_parse_gamma_market()]

```python
volume_usd=self._safe_decimal(
    data.get("volume") or data.get("volumeNum") or 0
),
```

### _safe_decimal Helper
```python
@staticmethod
def _safe_decimal(value: Any) -> Decimal:
    try:
        if value is None:
            return Decimal("0")
        return Decimal(str(value))
    except (InvalidOperation, Exception):
        return Decimal("0")
```

### Expected API Responses

#### Common Field Names for Volume
```
Gamma API might use:
- "volume"              ← Currently checked ✓
- "volumeNum"           ← Currently checked ✓
- "volumeUsd"           ← NOT checked ✗
- "totalVolume"         ← NOT checked ✗
- "amount"              ← NOT checked ✗
- "dailyVolume"         ← NOT checked ✗
- "volume24h"           ← NOT checked ✗

CLOB API might use:
- "volume"              ✓
- "volumeNum"           ✓
- "volume24h"           (checked via volume24hr)
- "usdVolume"           ✗
- "outstandingShares"   ✗
```

### Possible Fix - Add More Field Names

```python
def _safe_decimal_with_fallbacks(self, 
    data: dict, 
    field_names: list[str]
) -> Decimal:
    """Try multiple field names in order."""
    for field in field_names:
        value = data.get(field)
        if value is not None and value != "":
            try:
                return self._safe_decimal(value)
            except:
                continue
    return Decimal("0")

# Usage:
volume_usd = self._safe_decimal_with_fallbacks(
    data,
    [
        "volume",
        "volumeNum", 
        "volumeUsd",
        "totalVolume",
        "dailyVolume",
        "usdVolume",
    ]
)

liquidity_usd = self._safe_decimal_with_fallbacks(
    data,
    [
        "liquidity",
        "liquidityNum",
        "liquidityUsd",
        "totalLiquidity",
        "amountAvailable",
        "marketDepth",
    ]
)
```

---

## 3. Traders Count Analysis

### Current Code
[polymarket_service.py:_parse_gamma_market()]

```python
num_traders=int(
    data.get("tradesCount") or data.get("uniqueTraders") or 0
),
```

### Common Field Names for Traders

```
Polymarket Gamma API might use:
- "tradesCount"         ← Currently checked ✓
- "uniqueTraders"       ← Currently checked ✓
- "tradeCount"          ← NOT checked ✗
- "tradersCount"        ← NOT checked ✗
- "activeTradersCount"  ← NOT checked ✗
- "uniqueTraderCount"   ← NOT checked ✗
- "users"               ← NOT checked ✗
- "numberOfTrades"      ← NOT checked ✗
```

### Possible Fix

```python
num_traders = self._extract_int_with_fallbacks(
    data,
    [
        "tradesCount",
        "uniqueTraders",
        "tradeCount",
        "tradersCount",
        "activeTradersCount",
        "users",
        "numberOfTrades",
    ]
)

@staticmethod
def _extract_int_with_fallbacks(
    data: dict,
    field_names: list[str]
) -> int:
    """Try multiple field names for integer values."""
    for field in field_names:
        value = data.get(field)
        if value is not None:
            try:
                return int(value)
            except:
                continue
    return 0
```

---

## 4. CLOB API Response Variations

### Current Response Handling
[polymarket_service.py:get_orderbook()]

```python
if isinstance(data, dict) and "data" in data:
    items = data["data"]
    market_data = items[0] if items else None
elif isinstance(data, list):
    market_data = data[0] if data else None
elif isinstance(data, dict):
    market_data = data
else:
    return None
```

### Expected Response Formats

#### Format A: Single Market as Dict ✓
```json
{
  "conditionId": "0x123abc",
  "outcomePrices": ["0.63", "0.37"],
  "volume": 3200.00,
  "liquidity": 1800.50,
  "orderbook": {
    "bids": [{"price": "0.63", "size": "100"}],
    "asks": [{"price": "0.64", "size": "100"}]
  }
}
```

#### Format B: Wrapped in Data Array ✓
```json
{
  "data": [
    {
      "conditionId": "0x123abc",
      "outcomePrices": ["0.63", "0.37"],
      ...
    }
  ]
}
```

#### Format C: Array of Markets ✓
```json
[
  {
    "conditionId": "0x123abc",
    "outcomePrices": ["0.63", "0.37"],
    ...
  }
]
```

### Edge Cases

#### Orderbook Missing
```json
{
  "conditionId": "0x123abc",
  "outcomePrices": ["0.63", "0.37"],
  "volume": 3200.00,
  // No orderbook field - bid/ask will be None
}
```

#### Orderbook Format Different
```json
{
  "orderbook": {
    "bids": [["0.63", "100"]],  // Array instead of dict
    "asks": [["0.64", "100"]]
  }
}

OR

{
  "book": {  // Different name
    "buy": [...],
    "sell": [...]
  }
}
```

---

## 5. Debug Output Interpretation Guide

### Log Entry: DEBUG_GAMMA_API_OUTCOME_PRICES

```
DEBUG_GAMMA_API_OUTCOME_PRICES {
  first_market_outcome_prices: ['0.63', '0.37'],
  first_market_volume: 1500.50,
  first_market_liquidity: 8200.75,
  first_market_volume_num: None,
  first_market_liquidity_num: None,
  first_market_trades_count: None,
  first_market_unique_traders: None
}
```

**Interpretation:**
- ✓ outcomePrices is present and correct format
- ✓ volume found (try "volume" field)
- ✓ liquidity found (try "liquidity" field)
- ✗ volumeNum not found (fallback field not needed)
- ✗ tradesCount not found (check if field has different name)

### Log Entry: DEBUG_EXTRACT_PROBABILITY_FINAL

```
DEBUG_EXTRACT_PROBABILITY_FINAL {
  first_element: '0.63',
  as_decimal: 0.63,
  clamped_value: 0.63
}
```

**Good signs:**
- first_element is decimal string, not "1" or "0"
- as_decimal shows correct parsing
- clamped_value same as as_decimal (no boundary issues)

**Bad signs:**
- first_element is "1" (binary instead of probability)
- clamped_value is 0 or 1 (extreme value)
- Type mismatch (expected string, got something else)

---

## 6. Diagnostic Queries

### Check Current Database Values

```sql
-- Show distribution of probability values
SELECT 
  market_probability,
  COUNT(*) as market_count
FROM markets
WHERE status = 'active'
GROUP BY market_probability
ORDER BY market_probability;

-- Show markets with zero volume/liquidity
SELECT 
  id, condition_id, question,
  market_probability, volume_usd, liquidity_usd
FROM markets
WHERE status = 'active'
  AND (volume_usd = 0 OR liquidity_usd = 0)
LIMIT 10;

-- Show volume distribution
SELECT 
  CASE 
    WHEN volume_usd = 0 THEN 'zero'
    WHEN volume_usd < 100 THEN 'very_low'
    WHEN volume_usd < 1000 THEN 'low'
    WHEN volume_usd < 10000 THEN 'medium'
    ELSE 'high'
  END as volume_category,
  COUNT(*) as market_count
FROM markets
WHERE status = 'active'
GROUP BY volume_category
ORDER BY market_count DESC;
```

---

## 7. Next Steps

1. **Run Debug Logs** → See what API actually returns
2. **Match Pattern** → Compare to formats above
3. **Identify Missing Fields** → Check field names used by API
4. **Implement Fix** → Add fallback field names or new parsing logic
5. **Test** → Verify values change to expected ranges
6. **Clean Up** → Remove debug logging

---

## 8. Common Pitfalls

| Issue | Symptom | Fix |
|-------|---------|-----|
| outcomePrices is binary [1,0] | probability always 1.0 | Detect and handle binary format |
| Field name changed in API | Always returns 0 or None | Add alternative field names |
| Field is null in response | Falls through to default 0 | Check for null explicitly |
| Type mismatch (str vs int) | Silent failure in converter | Improve error handling |
| Nested field structure changed | Can't find data | Update path traversal |
| API returns different on specific markets | Some good, some bad | Check for market-type-specific fields |

