"""
services/external_price_service.py

Fetches external market context data stored alongside every snapshot:
  - BTC spot price (USD)
  - ETH spot price (USD)
  - Crypto Fear & Greed index (0–100)
  - BTC dominance (%)

These values are contextual features for the AI engine (Sprint 4).
They are fetched once per snapshot run — not per market — and attached
to every snapshot row in that run.

Design decisions:
- Uses free public APIs that require no API key. Failures are non-fatal:
  if BTC price is unavailable, we store NULL and snapshot collection
  continues normally. A missing context field is infinitely better than
  a failed snapshot run.
- Results are cached for CACHE_TTL_SECONDS within a single process run.
  At 5-minute snapshot intervals, this prevents 200 API calls to price
  APIs for 200 markets — we make exactly ONE call per context field per
  snapshot run.
- CoinGecko free API has a rate limit of ~30 req/min. Caching ensures
  we stay well within that.

APIs used:
  CoinGecko   — BTC/ETH price, BTC dominance
  Alternative.me — Fear & Greed index
"""

from __future__ import annotations

import time
from dataclasses import dataclass
from decimal import Decimal
from typing import Optional

import httpx

from config.settings import settings
from utils.logger import get_logger
from utils.retry import retry

log = get_logger(__name__)

# Cache TTL in seconds — one snapshot run is ~30s, this covers the whole run
CACHE_TTL_SECONDS = 120


@dataclass
class ExternalPriceContext:
    """
    External context data attached to every snapshot in a given run.
    All fields are Optional — failures are non-fatal.
    """
    btc_price_usd: Optional[Decimal]
    eth_price_usd: Optional[Decimal]
    fear_greed_index: Optional[int]
    btc_dominance: Optional[Decimal]
    fetched_at: float = 0.0    # time.monotonic() timestamp of last fetch


class ExternalPriceService:
    """
    Fetches and caches external price context for snapshot enrichment.

    Usage (called once per snapshot run, not per market):
        price_service = ExternalPriceService()
        context = price_service.get_context()   # cached within TTL
        # attach context fields to every snapshot in this run
    """

    COINGECKO_URL = "https://api.coingecko.com/api/v3"
    FEAR_GREED_URL = "https://api.alternative.me/fng/"

    def __init__(self) -> None:
        timeout = httpx.Timeout(
            timeout=settings.http_read_timeout,
            connect=settings.http_connect_timeout,
        )
        self._client = httpx.Client(timeout=timeout, follow_redirects=True)
        self._cache: Optional[ExternalPriceContext] = None

    def close(self) -> None:
        self._client.close()

    def __enter__(self) -> "ExternalPriceService":
        return self

    def __exit__(self, *_) -> None:
        self.close()

    def get_context(self) -> ExternalPriceContext:
        """
        Returns the current external price context, using cache if fresh.
        Failures on individual APIs return NULL for that field only —
        snapshot collection is never blocked by a price API outage.
        """
        if self._is_cache_valid():
            return self._cache  # type: ignore[return-value]

        log.debug("external_price_fetch_start")

        btc_price, eth_price, btc_dominance = self._fetch_crypto_prices()
        fear_greed = self._fetch_fear_greed()

        context = ExternalPriceContext(
            btc_price_usd=btc_price,
            eth_price_usd=eth_price,
            fear_greed_index=fear_greed,
            btc_dominance=btc_dominance,
            fetched_at=time.monotonic(),
        )
        self._cache = context

        log.info(
            "external_price_fetched",
            btc_price=str(btc_price) if btc_price else None,
            eth_price=str(eth_price) if eth_price else None,
            fear_greed=fear_greed,
            btc_dominance=str(btc_dominance) if btc_dominance else None,
        )

        return context

    # -------------------------------------------------------------------------
    # Private fetch methods — each handles its own failures
    # -------------------------------------------------------------------------

    @retry(exceptions=(httpx.HTTPError, Exception))
    def _fetch_crypto_prices(
        self,
    ) -> tuple[Optional[Decimal], Optional[Decimal], Optional[Decimal]]:
        """
        Fetch BTC price, ETH price, and BTC dominance from CoinGecko.
        Returns (btc_usd, eth_usd, btc_dominance) — all Optional.
        """
        try:
            # Single request fetches both coins
            response = self._client.get(
                f"{self.COINGECKO_URL}/simple/price",
                params={
                    "ids": "bitcoin,ethereum",
                    "vs_currencies": "usd",
                    "include_market_cap": "false",
                },
            )
            response.raise_for_status()
            data = response.json()

            btc = self._safe_decimal(data.get("bitcoin", {}).get("usd"))
            eth = self._safe_decimal(data.get("ethereum", {}).get("usd"))

            # Fetch BTC dominance separately
            dominance = self._fetch_btc_dominance()

            return btc, eth, dominance

        except Exception as exc:
            log.warning("crypto_price_fetch_failed", error=str(exc))
            return None, None, None

    def _fetch_btc_dominance(self) -> Optional[Decimal]:
        """Fetch BTC market dominance from CoinGecko global endpoint."""
        try:
            response = self._client.get(f"{self.COINGECKO_URL}/global")
            response.raise_for_status()
            data = response.json()
            pct = data.get("data", {}).get("market_cap_percentage", {}).get("btc")
            if pct is not None:
                # Convert percentage (e.g. 52.34) to decimal (0.5234)
                return Decimal(str(pct)) / Decimal("100")
            return None
        except Exception as exc:
            log.warning("btc_dominance_fetch_failed", error=str(exc))
            return None

    @retry(exceptions=(httpx.HTTPError, Exception))
    def _fetch_fear_greed(self) -> Optional[int]:
        """
        Fetch the current Crypto Fear & Greed index (0–100).
        API: https://api.alternative.me/fng/
        Returns integer 0–100, or None on failure.
        """
        try:
            response = self._client.get(
                self.FEAR_GREED_URL,
                params={"limit": 1, "format": "json"},
            )
            response.raise_for_status()
            data = response.json()

            value_str = data.get("data", [{}])[0].get("value")
            if value_str is not None:
                value = int(value_str)
                if 0 <= value <= 100:
                    return value
            return None

        except Exception as exc:
            log.warning("fear_greed_fetch_failed", error=str(exc))
            return None

    def _is_cache_valid(self) -> bool:
        if self._cache is None:
            return False
        age = time.monotonic() - self._cache.fetched_at
        return age < CACHE_TTL_SECONDS

    @staticmethod
    def _safe_decimal(value) -> Optional[Decimal]:
        if value is None:
            return None
        try:
            return Decimal(str(value))
        except Exception:
            return None
