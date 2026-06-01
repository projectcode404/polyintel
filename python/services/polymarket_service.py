"""
services/polymarket_service.py

Single entry point untuk semua HTTP communication dengan Polymarket APIs.
Zero database logic. Zero business logic. Pure API client.

Changelog:
  v1.0 — Sprint 2: offset pagination, basic parsing
  v1.1 — Sprint 2 fix: httpx.Timeout fix, list response handling
  v1.2 — Sprint 3: keyset pagination (/markets/keyset) untuk fetch semua
          crypto markets tanpa batas offset. Filter crypto only tetap aktif.

Dua Polymarket APIs:
  Gamma API  (gamma-api.polymarket.com) — market metadata
             /markets        — offset pagination, max ~10k
             /markets/keyset — cursor pagination, unlimited depth
  CLOB API   (clob.polymarket.com)      — live price/probability data
             /markets?condition_id=     — fetch by condition_id
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
    def __init__(
        self,
        message: str,
        status_code: int | None = None,
        url: str | None = None,
    ) -> None:
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

    Pagination strategy:
      Tahap 1 — /markets/keyset dengan filter active=true
        Yield crypto markets saja (filter _is_crypto sebelum parse)
        Berhenti ketika next_cursor kosong (truly last page)
      Tahap 2 — fallback ke /markets offset kalau keyset gagal

    Usage:
        service = PolymarketService()
        for page in service.iter_active_crypto_markets():
            for raw_market in page:
                ...
        orderbook = service.get_orderbook(condition_id)
    """

    # Keywords crypto untuk filter — dipakai di _is_crypto()
    CRYPTO_TAGS = frozenset({
        "crypto", "bitcoin", "ethereum", "defi", "altcoins",
        "btc", "eth", "solana", "bnb", "xrp", "cryptocurrency",
        "base", "arbitrum", "polygon", "avalanche", "cardano",
        "ripple", "dogecoin", "doge", "shib", "pepe",
    })

    CRYPTO_QUESTION_KEYWORDS = frozenset({
        "btc", "eth", "bitcoin", "ethereum", "crypto", "solana",
        "sol", "bnb", "xrp", "doge", "usdc", "usdt", "defi",
        "altcoin", "blockchain", "nft", "web3", "token",
    })

    def __init__(self) -> None:
        timeout = httpx.Timeout(
            timeout=settings.http_read_timeout,
            connect=settings.http_connect_timeout,
        )
        self._client = httpx.Client(
            timeout=timeout,
            headers=self._build_headers(),
            follow_redirects=True,
        )

    def close(self) -> None:
        """Tutup HTTP client. Dipanggil saat scheduler shutdown."""
        self._client.close()

    def __enter__(self) -> "PolymarketService":
        return self

    def __exit__(self, *_: Any) -> None:
        self.close()

    # =========================================================================
    # Gamma API — market metadata
    # =========================================================================

    def iter_active_crypto_markets(self) -> Iterator[list[RawMarket]]:
        """
        Generator yang yield pages of active crypto markets.

        Menggunakan /markets/keyset untuk deep pagination tanpa batas offset.
        Setiap page di-filter ke crypto only sebelum di-parse.
        Fallback ke offset pagination kalau keyset endpoint tidak tersedia.

        Yields list[RawMarket] per page — collector commit per page.
        """
        log.info("gamma_pagination_start", mode="keyset")

        try:
            yield from self._iter_keyset_pagination()
        except PolymarketAPIError as exc:
            # Keyset endpoint tidak tersedia — fallback ke offset
            log.warning(
                "keyset_pagination_unavailable",
                error=str(exc),
                fallback="offset_pagination",
            )
            yield from self._iter_offset_pagination()

    # -------------------------------------------------------------------------
    # Keyset pagination — /markets/keyset (unlimited depth)
    # -------------------------------------------------------------------------

    def _iter_keyset_pagination(self) -> Iterator[list[RawMarket]]:
        """
        Iterasi semua markets via /markets/keyset cursor pagination.

        Gamma API /markets/keyset response:
          [
            { ...market data... },
            { ...market data... },
          ]
        Header atau field terakhir mengandung next cursor.

        Polymarket keyset API mengembalikan list markets langsung.
        Next cursor ada di response header "X-Next-Cursor" atau
        di field terakhir data sebagai sentinel value.

        Strategi:
          - Request pertama tanpa cursor
          - Setiap response, ambil next_cursor dari response body atau header
          - Berhenti kalau next_cursor kosong atau sama dengan cursor sebelumnya
          - Berhenti kalau hasil < page_size (halaman terakhir)
        """
        cursor: str | None = None
        page_num = 0
        total_crypto = 0

        while True:
            page_num += 1

            params: dict[str, Any] = {
                "active": "true",
                "limit": settings.gamma_page_size,
                "order": "volume",
                "ascending": "false",
            }
            if cursor:
                params["next_cursor"] = cursor

            log.debug(
                "keyset_page_request",
                page=page_num,
                cursor=cursor[:20] if cursor else None,
            )

            response_data, next_cursor = self._fetch_keyset_page(params)

            if not response_data:
                log.info(
                    "keyset_pagination_complete",
                    total_pages=page_num - 1,
                    total_crypto=total_crypto,
                )
                return

            # Filter crypto sebelum parse — hemat CPU dan memory
            crypto_raw = [m for m in response_data if self._is_crypto(m)]

            if crypto_raw:
                parsed = self._parse_page(crypto_raw)
                if parsed:
                    total_crypto += len(parsed)
                    log.debug(
                        "keyset_page_parsed",
                        page=page_num,
                        total_on_page=len(response_data),
                        crypto_on_page=len(parsed),
                        total_crypto_so_far=total_crypto,
                    )
                    yield parsed

            # Cek kondisi stop
            if not next_cursor or next_cursor == cursor:
                log.info(
                    "keyset_pagination_complete",
                    total_pages=page_num,
                    total_crypto=total_crypto,
                    reason="no_next_cursor",
                )
                return

            if len(response_data) < settings.gamma_page_size:
                log.info(
                    "keyset_pagination_complete",
                    total_pages=page_num,
                    total_crypto=total_crypto,
                    reason="last_page",
                )
                return

            cursor = next_cursor

    @retry(exceptions=(httpx.HTTPError, PolymarketRateLimitError))
    def _fetch_keyset_page(
        self, params: dict[str, Any]
    ) -> tuple[list[dict], str | None]:
        """
        Fetch satu page dari /markets/keyset.
        Return (markets_list, next_cursor).

        Raise PolymarketAPIError kalau endpoint tidak ada (404/422 tanpa keyset hint).
        """
        url = f"{settings.polymarket_gamma_url}/markets/keyset"

        try:
            response = self._client.get(url, params=params)

            # 404 = keyset endpoint tidak ada di API version ini
            if response.status_code == 404:
                raise PolymarketAPIError(
                    "Keyset endpoint not found",
                    status_code=404,
                    url=url,
                )

            self._raise_for_status(response, url)
            data = response.json()

            # Parse response — bisa list atau {"data": [], "next_cursor": "..."}
            if isinstance(data, list):
                markets_list = data
                # Coba ambil next cursor dari response header
                next_cursor = response.headers.get("X-Next-Cursor")
            elif isinstance(data, dict):
                markets_list = (
                    data.get("data")
                    or data.get("markets")
                    or data.get("results")
                    or []
                )
                next_cursor = (
                    data.get("next_cursor")
                    or data.get("nextCursor")
                    or data.get("cursor")
                )
            else:
                markets_list = []
                next_cursor = None

            return markets_list, next_cursor

        except httpx.TimeoutException as exc:
            raise PolymarketAPIError("Keyset API timeout", url=url) from exc
        except httpx.NetworkError as exc:
            raise PolymarketAPIError(f"Keyset API network error: {exc}", url=url) from exc

    # -------------------------------------------------------------------------
    # Offset pagination — /markets (fallback, max ~10k rows)
    # -------------------------------------------------------------------------

    def _iter_offset_pagination(self) -> Iterator[list[RawMarket]]:
        """
        Fallback: offset-based pagination di /markets.
        Berhenti di 422 (offset too large) atau hasil kosong.
        """
        offset = 0
        page_num = 0
        total_crypto = 0

        while True:
            page_num += 1
            params = {
                "active": "true",
                "limit": settings.gamma_page_size,
                "offset": offset,
                "order": "volume",
                "ascending": "false",
            }

            log.debug("offset_page_request", page=page_num, offset=offset)

            try:
                raw_response = self._fetch_offset_page(params)
            except PolymarketAPIError as exc:
                if exc.status_code == 422:
                    # Offset terlalu besar — ini akhir data yang bisa diambil
                    log.info(
                        "offset_pagination_complete",
                        total_pages=page_num - 1,
                        total_crypto=total_crypto,
                        reason="offset_limit",
                    )
                    return
                raise

            # Normalise response
            if isinstance(raw_response, list):
                markets_data = raw_response
            elif isinstance(raw_response, dict):
                markets_data = raw_response.get("data") or raw_response.get("results") or []
            else:
                return

            if not markets_data:
                log.info("offset_pagination_complete", total_pages=page_num - 1)
                return

            crypto_raw = [m for m in markets_data if self._is_crypto(m)]
            if crypto_raw:
                parsed = self._parse_page(crypto_raw)
                if parsed:
                    total_crypto += len(parsed)
                    yield parsed

            if len(markets_data) < settings.gamma_page_size:
                log.info(
                    "offset_pagination_complete",
                    total_pages=page_num,
                    total_crypto=total_crypto,
                )
                return

            offset += settings.gamma_page_size

    @retry(exceptions=(httpx.HTTPError, PolymarketRateLimitError))
    def _fetch_offset_page(self, params: dict[str, Any]) -> Any:
        """Fetch satu page dari /markets dengan offset pagination."""
        url = f"{settings.polymarket_gamma_url}/markets"
        try:
            response = self._client.get(url, params=params)
            self._raise_for_status(response, url)
            return response.json()
        except httpx.TimeoutException as exc:
            raise PolymarketAPIError("Gamma API timeout", url=url) from exc
        except httpx.NetworkError as exc:
            raise PolymarketAPIError(f"Gamma API network error: {exc}", url=url) from exc

    # =========================================================================
    # CLOB API — live price data
    # =========================================================================

    @retry(exceptions=(httpx.HTTPError, PolymarketRateLimitError))
    def get_orderbook(self, condition_id: str) -> RawOrderbook | None:
        """
        Fetch live probability untuk satu market dari CLOB API.
        Return None kalau market tidak ditemukan.

        CLOB endpoint: GET /markets?condition_id={condition_id}
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

            # Normalise response shape
            if isinstance(data, dict) and "data" in data:
                items = data["data"]
                market_data = items[0] if items else None
            elif isinstance(data, list):
                market_data = data[0] if data else None
            elif isinstance(data, dict):
                market_data = data
            else:
                return None

            if not market_data:
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
        Fetch orderbooks untuk multiple markets secara sequential.
        Individual failures di-log dan di-skip — tidak abort batch.
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

    # =========================================================================
    # Parsers
    # =========================================================================

    def _parse_page(self, raw_markets: list[dict]) -> list[RawMarket]:
        """Parse satu page of raw dicts menjadi list[RawMarket]. Skip yang error."""
        parsed = []
        for m in raw_markets:
            try:
                parsed.append(self._parse_gamma_market(m))
            except Exception as exc:
                log.warning(
                    "market_parse_error",
                    condition_id=m.get("conditionId", "unknown"),
                    error=str(exc),
                )
        return parsed

    def _parse_gamma_market(self, data: dict[str, Any]) -> RawMarket:
        """
        Normalise Gamma API market response ke RawMarket.

        Field mappings:
          conditionId      → condition_id
          question         → question
          slug             → slug
          active/closed    → status
          outcomePrices[0] → market_probability
          volume           → volume_usd
          liquidity        → liquidity_usd
          tradesCount      → num_traders
        """
        status = self._derive_status(data)
        probability_yes = self._extract_probability_yes(data)
        tags = self._extract_tags(data)
        category, sub_category = self._classify_market(data, tags)

        return RawMarket(
            condition_id=str(
                data.get("conditionId") or data.get("condition_id") or ""
            ),
            question=str(data.get("question") or "")[:500],
            slug=data.get("slug"),
            description=data.get("description"),
            category=category,
            sub_category=sub_category,
            tags=tags,
            resolution_source=(
                data.get("resolutionSource") or data.get("resolution_source")
            ),
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
            num_traders=int(
                data.get("tradesCount") or data.get("uniqueTraders") or 0
            ),
            raw=data,
        )

    def _parse_clob_market(self, condition_id: str, data: dict[str, Any]) -> RawOrderbook:
        """
        Normalise CLOB API response ke RawOrderbook.

        Priority untuk probability_yes:
          1. outcomePrices[0] — paling reliable
          2. tokens[0].price  — fallback
          3. 0.5              — last resort
        """
        # Probability dari outcomePrices
        probability_yes = self._extract_probability_yes(data)

        # Fallback ke tokens[0].price
        if probability_yes is None:
            tokens = data.get("tokens", [])
            if tokens and isinstance(tokens, list):
                yes_token = tokens[0]
                if isinstance(yes_token, dict):
                    probability_yes = self._safe_decimal_or_none(
                        yes_token.get("price")
                    )

        if probability_yes is None:
            probability_yes = Decimal("0.5")

        # Clamp [0, 1]
        probability_yes = max(Decimal("0"), min(Decimal("1"), probability_yes))
        probability_no = Decimal("1") - probability_yes

        # Bid/ask dari orderbook
        best_bid: Decimal | None = None
        best_ask: Decimal | None = None

        orderbook = data.get("orderbook", {})
        if isinstance(orderbook, dict) and orderbook:
            bids = orderbook.get("bids", [])
            asks = orderbook.get("asks", [])
            if bids:
                first_bid = bids[0]
                best_bid = self._safe_decimal_or_none(
                    first_bid.get("price") if isinstance(first_bid, dict) else first_bid
                )
            if asks:
                first_ask = asks[0]
                best_ask = self._safe_decimal_or_none(
                    first_ask.get("price") if isinstance(first_ask, dict) else first_ask
                )

        spread = (
            (best_ask - best_bid)
            if (best_bid is not None and best_ask is not None)
            else None
        )

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
                data.get("volume24hr")
                or data.get("volume24h")
                or data.get("volume_24h")
                or 0
            ),
            liquidity_usd=self._safe_decimal(
                data.get("liquidity") or data.get("liquidityNum") or 0
            ),
        )

    # =========================================================================
    # Private helpers
    # =========================================================================

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
            raise PolymarketRateLimitError(
                "Rate limited", status_code=429, url=url
            )
        if response.status_code >= 500:
            raise PolymarketAPIError(
                f"Server error {response.status_code}",
                status_code=response.status_code,
                url=url,
            )
        if response.status_code >= 400:
            raise PolymarketAPIError(
                f"Client error {response.status_code}: {response.text[:300]}",
                status_code=response.status_code,
                url=url,
            )

    def _derive_status(self, data: dict[str, Any]) -> str:
        """Map Polymarket boolean flags ke status string kita."""
        if data.get("archived", False):
            return "cancelled"
        if data.get("closed", False):
            resolved_at = data.get("resolvedAt") or data.get("resolved_at")
            return "resolved" if resolved_at else "paused"
        if data.get("active", True):
            return "active"
        return "paused"

    def _is_crypto(self, data: dict[str, Any]) -> bool:
        """
        True kalau market ini adalah crypto market.
        Cek 3 sumber: category field, tags, dan kata kunci di question.
        """
        # 1. Category field eksplisit
        category = (data.get("category") or "").lower()
        if "crypto" in category:
            return True

        # 2. Tags
        tags = self._extract_tags(data)
        if {t.lower() for t in tags} & self.CRYPTO_TAGS:
            return True

        # 3. Question keywords — fallback untuk market tanpa tags lengkap
        question = (data.get("question") or "").lower()
        return any(kw in question for kw in self.CRYPTO_QUESTION_KEYWORDS)

    def _classify_market(
        self, data: dict[str, Any], tags: list[str]
    ) -> tuple[str, str | None]:
        """Tentukan (category, sub_category) dari tags dan question."""
        tags_lower = {t.lower() for t in tags}
        question = (data.get("question") or "").lower()

        sub_category: str | None = None

        if tags_lower & {"bitcoin", "btc"} or "bitcoin" in question or " btc " in question:
            sub_category = "bitcoin"
        elif tags_lower & {"ethereum", "eth"} or "ethereum" in question or " eth " in question:
            sub_category = "ethereum"
        elif tags_lower & {"solana", "sol"} or "solana" in question or " sol " in question:
            sub_category = "solana"
        elif tags_lower & {"bnb", "binance"} or " bnb " in question:
            sub_category = "bnb"
        elif tags_lower & {"xrp", "ripple"} or " xrp " in question:
            sub_category = "xrp"
        elif "defi" in tags_lower or "defi" in question:
            sub_category = "defi"

        return "crypto", sub_category

    def _extract_tags(self, data: dict[str, Any]) -> list[str]:
        """Parse tags dari berbagai format response Polymarket."""
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
                    label = (
                        t.get("label")
                        or t.get("slug")
                        or t.get("id")
                        or t.get("name")
                        or ""
                    )
                    if label:
                        result.append(str(label))
            return result
        return []

    def _extract_probability_yes(self, data: dict[str, Any]) -> Decimal | None:
        """
        Extract YES token price dari outcomePrices.
        Format yang mungkin:
          '["0.63", "0.37"]'  — JSON string
          ["0.63", "0.37"]    — list of strings
          [0.63, 0.37]        — list of floats
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
        """Convert ke Decimal, return 0 kalau gagal."""
        try:
            if value is None:
                return Decimal("0")
            return Decimal(str(value))
        except (InvalidOperation, Exception):
            return Decimal("0")

    @staticmethod
    def _safe_decimal_or_none(value: Any) -> Decimal | None:
        """Convert ke Decimal, return None kalau gagal."""
        if value is None:
            return None
        try:
            return Decimal(str(value))
        except (InvalidOperation, Exception):
            return None
