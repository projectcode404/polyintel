"""
services/polymarket_service.py

Single entry point untuk semua HTTP communication dengan Polymarket APIs.
Zero database logic. Zero business logic. Pure API client.

Bugs yang difix di Sprint 2 refactor:
  1. iter_active_crypto_markets — Gamma API return list langsung, bukan
     {"data": [...]}. Cursor pagination tidak ada, pakai offset.
  2. httpx.Timeout — harus include default parameter.
  3. _fetch_gamma_markets return type — bisa list atau dict.
  4. get_orderbook — CLOB /markets/{id} tidak ada, pakai /markets?condition_id=
  5. _parse_clob_market — field mapping disesuaikan dengan response CLOB aktual.

Dua Polymarket APIs:
  Gamma API  (gamma-api.polymarket.com) — market metadata, list markets
  CLOB API   (clob.polymarket.com)      — live price/probability data
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation
from typing import Any, Iterator

import httpx

from config.settings import settings
from utils.logger import get_logger
from utils.retry import retry

log = get_logger(__name__)


# ---------------------------------------------------------------------------
# Exceptions
# ---------------------------------------------------------------------------

class PolymarketAPIError(Exception):
    def __init__(self, message: str, status_code: int | None = None, url: str | None = None) -> None:
        super().__init__(message)
        self.status_code = status_code
        self.url = url


class PolymarketRateLimitError(PolymarketAPIError):
    pass


# ---------------------------------------------------------------------------
# Data shapes
# ---------------------------------------------------------------------------

@dataclass
class RawMarket:
    """Normalised market metadata dari Gamma API."""
    condition_id: str
    question: str
    slug: str | None
    description: str | None
    category: str
    sub_category: str | None
    tags: list[str]
    resolution_source: str | None
    start_date: str | None
    end_date: str | None
    resolved_at: str | None
    status: str
    market_probability: Decimal | None
    volume_usd: Decimal
    liquidity_usd: Decimal
    num_traders: int
    raw: dict[str, Any] = field(default_factory=dict, repr=False)


@dataclass
class RawOrderbook:
    """Live price snapshot dari CLOB API."""
    condition_id: str
    probability_yes: Decimal
    probability_no: Decimal
    best_bid: Decimal | None
    best_ask: Decimal | None
    spread: Decimal | None
    volume_usd: Decimal
    volume_24h_usd: Decimal
    liquidity_usd: Decimal


# ---------------------------------------------------------------------------
# PolymarketService
# ---------------------------------------------------------------------------

class PolymarketService:
    """
    HTTP client untuk Polymarket Gamma dan CLOB APIs.

    Usage:
        service = PolymarketService()
        for page in service.iter_active_crypto_markets():
            for raw_market in page:
                ...
        orderbook = service.get_orderbook(condition_id)
    """

    CRYPTO_TAGS = frozenset({
        "crypto", "bitcoin", "ethereum", "defi", "altcoins",
        "btc", "eth", "solana", "bnb", "xrp", "cryptocurrency",
    })

    def __init__(self) -> None:
        # FIX: httpx.Timeout harus punya default atau set semua 4 parameter
        timeout = httpx.Timeout(
            timeout=settings.http_read_timeout,   # default untuk semua
            connect=settings.http_connect_timeout,
        )
        self._client = httpx.Client(
            timeout=timeout,
            headers=self._build_headers(),
            follow_redirects=True,
        )

    def close(self) -> None:
        self._client.close()

    def __enter__(self) -> "PolymarketService":
        return self

    def __exit__(self, *_: Any) -> None:
        self.close()

    # -------------------------------------------------------------------------
    # Gamma API — market metadata
    # -------------------------------------------------------------------------

    def iter_active_crypto_markets(self) -> Iterator[list[RawMarket]]:
        """
        Generator yang yield pages of active crypto markets.

        FIX: Gamma API return list langsung (bukan {"data": [...]}).
        FIX: Pakai offset pagination, bukan cursor.
        """
        offset = 0
        page_num = 0

        while True:
            page_num += 1
            params = {
                "active": "true",
                "limit": settings.gamma_page_size,
                "offset": offset,
                "order": "volume",
                "ascending": "false",
            }

            log.debug(
                "gamma_api_request",
                page=page_num,
                offset=offset,
                page_size=settings.gamma_page_size,
            )

            raw_response = self._fetch_gamma_markets(params)

            # FIX: Gamma API bisa return list langsung atau {"data": [...]}
            if isinstance(raw_response, list):
                markets_data = raw_response
            elif isinstance(raw_response, dict):
                markets_data = raw_response.get("data") or raw_response.get("results") or []
            else:
                log.warning("gamma_unexpected_response_type", type=type(raw_response).__name__)
                return

            if not markets_data:
                log.info("gamma_pagination_complete", total_pages=page_num - 1)
                return

            # Filter crypto sebelum parse — hemat memory
            crypto_data = [m for m in markets_data if self._is_crypto(m)]

            if crypto_data:
                parsed = []
                for m in crypto_data:
                    try:
                        parsed.append(self._parse_gamma_market(m))
                    except Exception as exc:
                        log.warning(
                            "gamma_market_parse_error",
                            condition_id=m.get("conditionId", "unknown"),
                            error=str(exc),
                        )
                if parsed:
                    log.debug(
                        "gamma_page_parsed",
                        page=page_num,
                        total_on_page=len(markets_data),
                        crypto_on_page=len(parsed),
                    )
                    yield parsed

            # Kalau hasil < page_size, ini halaman terakhir
            if len(markets_data) < settings.gamma_page_size:
                log.info("gamma_pagination_complete", total_pages=page_num)
                return

            offset += settings.gamma_page_size

    @retry(exceptions=(httpx.HTTPError, PolymarketAPIError, PolymarketRateLimitError))
    def _fetch_gamma_markets(self, params: dict[str, Any]) -> Any:
        """
        Single request ke Gamma /markets.
        Return type Any karena API bisa return list atau dict.
        """
        url = f"{settings.polymarket_gamma_url}/markets"
        try:
            response = self._client.get(url, params=params)
            self._raise_for_status(response, url)
            return response.json()
        except httpx.TimeoutException as exc:
            raise PolymarketAPIError(f"Gamma API timeout", url=url) from exc
        except httpx.NetworkError as exc:
            raise PolymarketAPIError(f"Gamma API network error: {exc}", url=url) from exc

    # -------------------------------------------------------------------------
    # CLOB API — live price data
    # -------------------------------------------------------------------------

    @retry(exceptions=(httpx.HTTPError, PolymarketAPIError, PolymarketRateLimitError))
    def get_orderbook(self, condition_id: str) -> RawOrderbook | None:
        """
        Fetch live probability untuk satu market dari CLOB API.

        FIX: CLOB /markets/{id} tidak ada.
        Gunakan /markets?condition_id= untuk fetch by condition_id.
        Return None kalau market tidak ditemukan (404 atau empty).
        """
        url = f"{settings.polymarket_clob_url}/markets"
        params = {"condition_id": condition_id}

        try:
            response = self._client.get(url, params=params)

            if response.status_code == 404:
                log.warning("clob_market_not_found", condition_id=condition_id)
                return None

            self._raise_for_status(response, url)
            data = response.json()

            # CLOB bisa return dict langsung atau {"data": [...]}
            if isinstance(data, dict) and "data" in data:
                markets_list = data["data"]
                if not markets_list:
                    return None
                market_data = markets_list[0]
            elif isinstance(data, list):
                if not data:
                    return None
                market_data = data[0]
            elif isinstance(data, dict):
                market_data = data
            else:
                log.warning("clob_unexpected_response", condition_id=condition_id)
                return None

            return self._parse_clob_market(condition_id, market_data)

        except httpx.TimeoutException as exc:
            raise PolymarketAPIError(
                f"CLOB API timeout untuk {condition_id}", url=url
            ) from exc
        except httpx.NetworkError as exc:
            raise PolymarketAPIError(
                f"CLOB API network error: {exc}", url=url
            ) from exc

    def get_orderbooks_batch(
        self, condition_ids: list[str]
    ) -> dict[str, RawOrderbook | None]:
        """
        Fetch orderbooks untuk multiple markets.
        Failures per-market di-log dan di-skip — tidak abort seluruh batch.
        """
        results: dict[str, RawOrderbook | None] = {}
        for condition_id in condition_ids:
            try:
                results[condition_id] = self.get_orderbook(condition_id)
            except PolymarketAPIError as exc:
                log.error(
                    "orderbook_fetch_failed",
                    condition_id=condition_id,
                    error=str(exc),
                )
                results[condition_id] = None
        return results

    # -------------------------------------------------------------------------
    # Parsers
    # -------------------------------------------------------------------------

    def _parse_gamma_market(self, data: dict[str, Any]) -> RawMarket:
        """
        Normalise Gamma API market response ke RawMarket.

        Field mappings (Gamma API → schema kita):
          conditionId        → condition_id
          question           → question
          slug               → slug
          active/closed      → status
          outcomePrices[0]   → market_probability (YES token price)
          volume             → volume_usd
          liquidity          → liquidity_usd
        """
        status = self._derive_status(data)
        probability_yes = self._extract_probability_yes(data)
        tags = self._extract_tags(data)
        category, sub_category = self._classify_market(data, tags)

        return RawMarket(
            condition_id=str(data.get("conditionId") or data.get("condition_id") or ""),
            question=str(data.get("question") or "")[:500],
            slug=data.get("slug"),
            description=data.get("description"),
            category=category,
            sub_category=sub_category,
            tags=tags,
            resolution_source=data.get("resolutionSource") or data.get("resolution_source"),
            start_date=data.get("startDate") or data.get("start_date"),
            end_date=data.get("endDate") or data.get("end_date"),
            resolved_at=data.get("resolvedAt") or data.get("resolved_at"),
            status=status,
            market_probability=probability_yes,
            volume_usd=self._safe_decimal(
                data.get("volume") or data.get("volumeNum") or 0
            ),
            liquidity_usd=self._safe_decimal(
                data.get("liquidity") or data.get("liquidityNum") or 0
            ),
            num_traders=int(data.get("tradesCount") or data.get("uniqueTraders") or 0),
            raw=data,
        )

    def _parse_clob_market(self, condition_id: str, data: dict[str, Any]) -> RawOrderbook:
        """
        Normalise CLOB API market response ke RawOrderbook.

        CLOB API response structure:
          tokens          — list of YES/NO token data
          outcomePrices   — ["0.63", "0.37"] (YES price, NO price)
          volume          — total volume
          volume24hr      — 24h rolling volume
          liquidity       — current liquidity
        """
        # Ambil probability dari outcomePrices (paling reliable)
        probability_yes = self._extract_probability_yes(data)
        if probability_yes is None:
            # Fallback: coba dari tokens[0].price
            tokens = data.get("tokens", [])
            if tokens and isinstance(tokens, list):
                yes_token = tokens[0] if tokens else {}
                probability_yes = self._safe_decimal_or_none(yes_token.get("price"))
        if probability_yes is None:
            probability_yes = Decimal("0.5")

        # Clamp ke [0, 1]
        probability_yes = max(Decimal("0"), min(Decimal("1"), probability_yes))
        probability_no = Decimal("1") - probability_yes

        # Bid/ask dari orderbook kalau ada
        best_bid: Decimal | None = None
        best_ask: Decimal | None = None

        orderbook = data.get("orderbook", {})
        if isinstance(orderbook, dict) and orderbook:
            bids = orderbook.get("bids", [])
            asks = orderbook.get("asks", [])
            if bids:
                best_bid = self._safe_decimal_or_none(bids[0].get("price") if isinstance(bids[0], dict) else bids[0])
            if asks:
                best_ask = self._safe_decimal_or_none(asks[0].get("price") if isinstance(asks[0], dict) else asks[0])

        spread = (best_ask - best_bid) if (best_bid is not None and best_ask is not None) else None

        return RawOrderbook(
            condition_id=condition_id,
            probability_yes=probability_yes,
            probability_no=probability_no,
            best_bid=best_bid,
            best_ask=best_ask,
            spread=spread,
            volume_usd=self._safe_decimal(
                data.get("volume") or data.get("volumeNum") or 0
            ),
            volume_24h_usd=self._safe_decimal(
                data.get("volume24hr") or data.get("volume24h") or data.get("volume_24h") or 0
            ),
            liquidity_usd=self._safe_decimal(
                data.get("liquidity") or data.get("liquidityNum") or 0
            ),
        )

    # -------------------------------------------------------------------------
    # Private helpers
    # -------------------------------------------------------------------------

    def _build_headers(self) -> dict[str, str]:
        headers = {
            "Accept": "application/json",
            "User-Agent": f"PolymarketIntelligence/{settings.collector_version}",
        }
        if settings.polymarket_api_key:
            headers["Authorization"] = f"Bearer {settings.polymarket_api_key}"
        return headers

    def _raise_for_status(self, response: httpx.Response, url: str) -> None:
        if response.status_code == 429:
            raise PolymarketRateLimitError("Rate limited", status_code=429, url=url)
        if response.status_code >= 500:
            raise PolymarketAPIError(
                f"Server error {response.status_code}", status_code=response.status_code, url=url
            )
        if response.status_code >= 400:
            raise PolymarketAPIError(
                f"Client error {response.status_code}: {response.text[:200]}",
                status_code=response.status_code,
                url=url,
            )

    def _derive_status(self, data: dict[str, Any]) -> str:
        if data.get("archived", False):
            return "cancelled"
        if data.get("closed", False):
            resolved_at = data.get("resolvedAt") or data.get("resolved_at")
            return "resolved" if resolved_at else "paused"
        if data.get("active", True):
            return "active"
        return "paused"

    def _is_crypto(self, data: dict[str, Any]) -> bool:
        """True kalau market ini adalah crypto market."""
        # Cek field category eksplisit
        category = (data.get("category") or "").lower()
        if "crypto" in category:
            return True
        # Cek tags
        tags = self._extract_tags(data)
        tags_lower = {t.lower() for t in tags}
        if tags_lower & self.CRYPTO_TAGS:
            return True
        # Cek question — kalau ada "BTC", "ETH", "Bitcoin", "Ethereum"
        question = (data.get("question") or "").lower()
        crypto_keywords = {"btc", "eth", "bitcoin", "ethereum", "crypto", "solana", "sol"}
        return any(kw in question for kw in crypto_keywords)

    def _classify_market(
        self, data: dict[str, Any], tags: list[str]
    ) -> tuple[str, str | None]:
        tags_lower = [t.lower() for t in tags]
        question = (data.get("question") or "").lower()

        sub_category: str | None = None
        if any(t in tags_lower for t in ["bitcoin", "btc"]) or "bitcoin" in question or "btc" in question:
            sub_category = "bitcoin"
        elif any(t in tags_lower for t in ["ethereum", "eth"]) or "ethereum" in question or " eth " in question:
            sub_category = "ethereum"
        elif any(t in tags_lower for t in ["solana", "sol"]) or "solana" in question:
            sub_category = "solana"
        elif "defi" in tags_lower or "defi" in question:
            sub_category = "defi"

        return "crypto", sub_category

    def _extract_tags(self, data: dict[str, Any]) -> list[str]:
        """Parse tags dari berbagai format yang Polymarket kembalikan."""
        raw_tags = data.get("tags", [])
        if isinstance(raw_tags, str):
            try:
                raw_tags = json.loads(raw_tags)
            except (ValueError, json.JSONDecodeError):
                return []
        if isinstance(raw_tags, list):
            result = []
            for t in raw_tags:
                if isinstance(t, str):
                    result.append(t)
                elif isinstance(t, dict):
                    label = t.get("label") or t.get("slug") or t.get("id") or t.get("name", "")
                    if label:
                        result.append(str(label))
            return result
        return []

    def _extract_probability_yes(self, data: dict[str, Any]) -> Decimal | None:
        """
        Extract YES token price dari outcomePrices.
        outcomePrices bisa berupa:
          - string JSON: '["0.63", "0.37"]'
          - list langsung: ["0.63", "0.37"]
          - list of numbers: [0.63, 0.37]
        """
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

    @staticmethod
    def _safe_decimal(value: Any) -> Decimal:
        """Convert value ke Decimal, return 0 kalau gagal."""
        try:
            if value is None:
                return Decimal("0")
            return Decimal(str(value))
        except (InvalidOperation, Exception):
            return Decimal("0")

    @staticmethod
    def _safe_decimal_or_none(value: Any) -> Decimal | None:
        """Convert value ke Decimal, return None kalau gagal."""
        if value is None:
            return None
        try:
            return Decimal(str(value))
        except (InvalidOperation, Exception):
            return None
