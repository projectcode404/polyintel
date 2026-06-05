"""
services/polymarket_service.py

Single entry point untuk semua HTTP communication dengan Polymarket APIs.
Zero database logic. Zero business logic. Pure API client.

Changelog:
  v1.0 — Sprint 2: offset pagination, basic parsing
  v1.1 — Sprint 2 fix: httpx.Timeout fix, list response handling
  v1.2 — Sprint 3: keyset pagination (/markets/keyset) untuk fetch semua
          crypto markets tanpa batas offset. Filter crypto only tetap aktif.
  v1.3 — Sprint 3 fix: field mapping audit.
          - num_traders: tradesCount/uniqueTraders tidak ada di Gamma API.
            Ganti ke fallback chain: events[].markets_count → 0 (nullable).
            Field dipertahankan di RawMarket agar DB schema tidak berubah.
          - volume_24h_usd: map dari volume24hr (ada di Gamma response).
          - best_bid, best_ask, spread: sudah tersedia di Gamma response
            (bestBid, bestAsk, spread). Di-map langsung — mengurangi
            kebutuhan CLOB call untuk data ini.
          - Tambah: price_change_1h, price_change_1d untuk analytics.

Dua Polymarket APIs:
  Gamma API  (gamma-api.polymarket.com) — market metadata
             /markets        — offset pagination, max ~10k
             /markets/keyset — cursor pagination, unlimited depth
  CLOB API   (clob.polymarket.com)      — live price/probability data
             /markets?condition_id=     — fetch by condition_id
             NOTE: CLOB tetap dipakai untuk live probability di snapshots.
             Gamma bid/ask dipakai sebagai fallback/enrichment saja.
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
    """
    Normalised market metadata dari Gamma API.

    Field notes (post-audit v1.3):
      num_traders:
        Gamma API TIDAK mengembalikan jumlah trader per market.
        Field tradesCount/uniqueTraders tidak ada di response.
        num_traders = 0 secara default. Nullable di DB.
        TODO Sprint 4: isi dari CLOB stats endpoint kalau tersedia.

      volume_24h_usd:
        Diambil dari field `volume24hr` di Gamma response.
        Bukan total volume — ini volume rolling 24 jam.

      best_bid, best_ask, spread:
        Tersedia langsung di Gamma response sebagai bestBid, bestAsk, spread.
        Lebih stale dibanding CLOB (update Gamma ~1-5 menit) tapi cukup
        untuk enrichment di markets table. Snapshot tetap pakai CLOB.

      price_change_1h, price_change_1d:
        Delta probabilitas dalam 1 jam dan 1 hari.
        Berguna untuk signal generation dan anomaly detection.
    """
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

    # Volume fields
    volume_usd: Decimal
    volume_24h_usd: Decimal       # dari volume24hr — rolling 24 jam
    liquidity_usd: Decimal

    # Traders — 0 karena Gamma API tidak expose field ini
    num_traders: int

    # Orderbook snapshot dari Gamma (less real-time than CLOB, tapi gratis)
    best_bid: Decimal | None
    best_ask: Decimal | None
    spread: Decimal | None

    # Price movement
    price_change_1h: Decimal | None
    price_change_1d: Decimal | None

    raw: dict[str, Any] = field(default_factory=dict, repr=False)


@dataclass
class RawOrderbook:
    """
    Live price snapshot dari CLOB API.

    NOTE: volume_usd, liquidity_usd come from Gamma API, not CLOB API.
    CLOB API only provides live prices (probability, bid/ask).
    Use market table (from DB) for volume/liquidity data.
    """
    condition_id: str
    probability_yes: Decimal
    probability_no: Decimal
    best_bid: Decimal | None
    best_ask: Decimal | None
    spread: Decimal | None


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

            if not next_cursor or next_cursor == cursor:
                log.info(
                    "keyset_pagination_complete",
                    total_pages=page_num,
                    total_crypto=total_crypto,
                    reason="no_next_cursor",
                )
                return

            cursor = next_cursor

    @retry(exceptions=(httpx.HTTPError, PolymarketRateLimitError))
    def _fetch_keyset_page(
        self, params: dict[str, Any]
    ) -> tuple[list[dict], str | None]:
        url = f"{settings.polymarket_gamma_url}/markets/keyset"
        try:
            response = self._client.get(url, params=params)
            if response.status_code == 404:
                raise PolymarketAPIError("Keyset endpoint not found", status_code=404, url=url)
            self._raise_for_status(response, url)
            data = response.json()

            if isinstance(data, list):
                markets_list = data

                next_cursor = None
                for header_key, header_val in response.headers.items():
                    if header_key.lower() == "x-next-cursor":
                        next_cursor = header_val
                        break

                if not next_cursor and len(markets_list) > 0:
                    last_item = markets_list[-1]
                    next_cursor = last_item.get("cursor") or None

            elif isinstance(data, dict):
                markets_list = data.get("data") or data.get("markets") or data.get("results") or []
                next_cursor = data.get("next_cursor") or data.get("nextCursor") or data.get("cursor")
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
                    log.info(
                        "offset_pagination_complete",
                        total_pages=page_num - 1,
                        total_crypto=total_crypto,
                        reason="offset_limit",
                    )
                    return
                raise

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
        url_market = f"{settings.polymarket_clob_url}/markets/{condition_id}"
        try:
            response = self._client.get(url_market)
            if response.status_code == 404:
                return None
            self._raise_for_status(response, url_market)
            market_data = response.json()
            if not market_data:
                return None

            tokens = market_data.get("tokens", [])
            first_token_id = None
            if tokens and isinstance(tokens, list):
                first_token = tokens[0]
                if isinstance(first_token, dict):
                    first_token_id = (
                        first_token.get("token")
                        or first_token.get("token_id")
                        or first_token.get("id")
                    )

            orderbook_data = None
            if first_token_id:
                url_book = f"{settings.polymarket_clob_url}/book"
                response_book = self._client.get(url_book, params={"token_id": first_token_id})
                self._raise_for_status(response_book, url_book)
                if response_book.status_code == 200:
                    orderbook_data = response_book.json()

            return self._parse_clob_market(condition_id, market_data, orderbook_data)

        except httpx.TimeoutException as exc:
            raise PolymarketAPIError("CLOB API timeout", url=url_market) from exc
        except httpx.NetworkError as exc:
            raise PolymarketAPIError("CLOB API network error", url=url_market) from exc

    def get_orderbooks_batch(
        self, condition_ids: list[str]
    ) -> dict[str, RawOrderbook | None]:
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

        Field mappings (verified dari audit_raw_api_responses.py):
          conditionId         → condition_id
          question            → question
          slug                → slug
          active/closed       → status
          outcomePrices[0]    → market_probability
          volume / volumeNum  → volume_usd
          volume24hr          → volume_24h_usd  ← rolling 24h volume
          liquidity           → liquidity_usd
          bestBid             → best_bid        ← dari Gamma langsung
          bestAsk             → best_ask        ← dari Gamma langsung
          spread              → spread          ← dari Gamma langsung
          oneHourPriceChange  → price_change_1h
          oneDayPriceChange   → price_change_1d

        num_traders NOTE:
          Field tradesCount dan uniqueTraders TIDAK ADA di Gamma API response.
          Verified by audit 2026-06-05. num_traders = 0 (default).
          DB column dipertahankan nullable untuk diisi source lain di masa depan.
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

            # Volume — total dan 24h rolling
            volume_usd=self._safe_decimal(
                data.get("volumeNum") or data.get("volume") or 0
            ),
            volume_24h_usd=self._safe_decimal(
                data.get("volume24hr") or 0
            ),
            liquidity_usd=self._safe_decimal(
                data.get("liquidityNum") or data.get("liquidity") or 0
            ),

            # Traders — Gamma API tidak expose field ini. Default 0.
            # tradesCount dan uniqueTraders tidak ada di response (verified audit).
            num_traders=0,

            # Orderbook snapshot dari Gamma response
            # Lebih stale dari CLOB (~1-5 min delay) tapi tidak perlu extra API call.
            best_bid=self._safe_decimal_or_none(data.get("bestBid")),
            best_ask=self._safe_decimal_or_none(data.get("bestAsk")),
            spread=self._safe_decimal_or_none(data.get("spread")),

            # Price movement — untuk signal generation dan anomaly detection
            price_change_1h=self._safe_decimal_or_none(data.get("oneHourPriceChange")),
            price_change_1d=self._safe_decimal_or_none(data.get("oneDayPriceChange")),

            raw=data,
        )

    def _parse_clob_market(
        self,
        condition_id: str,
        market_data: dict[str, Any],
        orderbook_data: dict[str, Any] | None = None,
    ) -> RawOrderbook:
        """
        Normalise CLOB API response ke RawOrderbook.

        CLOB API only provides live prices (probability from token price, bid/ask from orderbook).
        Volume/liquidity come from Gamma API (stored in Market table).
        """
        # Probability dari tokens[0].price
        probability_yes: Decimal | None = None
        tokens = market_data.get("tokens", [])

        if tokens and isinstance(tokens, list):
            first_token = tokens[0]
            if isinstance(first_token, dict):
                token_price = first_token.get("price")
                if token_price is not None:
                    probability_yes = self._safe_decimal_or_none(token_price)

        # Fallback ke outcomePrices kalau tokens[0].price tidak ada
        if probability_yes is None:
            probability_yes = self._extract_probability_yes(market_data)

        if probability_yes is None:
            probability_yes = Decimal("0.5")

        probability_yes = max(Decimal("0"), min(Decimal("1"), probability_yes))
        probability_no = Decimal("1") - probability_yes

        # Bid/ask dari orderbook_data
        best_bid: Decimal | None = None
        best_ask: Decimal | None = None

        if orderbook_data and isinstance(orderbook_data, dict):
            bids = orderbook_data.get("bids", [])
            asks = orderbook_data.get("asks", [])

            if bids and isinstance(bids, list):
                first_bid = bids[0]
                if isinstance(first_bid, (list, tuple)):
                    best_bid = self._safe_decimal_or_none(first_bid[0])
                elif isinstance(first_bid, dict):
                    best_bid = self._safe_decimal_or_none(first_bid.get("price"))

            if asks and isinstance(asks, list):
                first_ask = asks[0]
                if isinstance(first_ask, (list, tuple)):
                    best_ask = self._safe_decimal_or_none(first_ask[0])
                elif isinstance(first_ask, dict):
                    best_ask = self._safe_decimal_or_none(first_ask.get("price"))

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
            raise PolymarketRateLimitError("Rate limited", status_code=429, url=url)
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
        if data.get("archived", False):
            return "cancelled"
        if data.get("closed", False):
            resolved_at = data.get("resolvedAt") or data.get("resolved_at")
            return "resolved" if resolved_at else "paused"
        if data.get("active", True):
            return "active"
        return "paused"

    def _is_crypto(self, data: dict[str, Any]) -> bool:
        category = (data.get("category") or "").lower()
        if "crypto" in category:
            return True

        tags = self._extract_tags(data)
        if {t.lower() for t in tags} & self.CRYPTO_TAGS:
            return True

        question = (data.get("question") or "").lower()
        return any(kw in question for kw in self.CRYPTO_QUESTION_KEYWORDS)

    def _classify_market(
        self, data: dict[str, Any], tags: list[str]
    ) -> tuple[str, str | None]:
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
        try:
            if value is None:
                return Decimal("0")
            return Decimal(str(value))
        except (InvalidOperation, Exception):
            return Decimal("0")

    @staticmethod
    def _safe_decimal_or_none(value: Any) -> Decimal | None:
        if value is None:
            return None
        try:
            d = Decimal(str(value))
            return d
        except (InvalidOperation, Exception):
            return None