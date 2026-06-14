"""
services/polymarket_service.py

Changelog:
  v1.4 — fix CLOB closed market handling.
          get_orderbook() sekarang cek field `closed` dari CLOB /markets response.
          Kalau closed=True → return ClosedMarketSignal bukan None.
          SnapshotsCollector pakai sinyal ini untuk mark market as resolved di DB,
          bukan hanya skip snapshot — sehingga market tidak di-retry tiap 5 menit.

          Juga fix token_id parsing: field yang benar adalah `token_id`
          (bukan `token`, `id`, atau key lain).
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
        num_traders = 0 secara default.

      volume_24h_usd:
        Dari field `volume24hr` di Gamma response.

      best_bid, best_ask, spread:
        Tersedia langsung di Gamma response.

      price_change_1h, price_change_1d:
        Delta probabilitas dalam 1 jam dan 1 hari.
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
    volume_usd: Decimal
    volume_24h_usd: Decimal
    liquidity_usd: Decimal
    num_traders: int
    best_bid: Decimal | None
    best_ask: Decimal | None
    spread: Decimal | None
    price_change_1h: Decimal | None
    price_change_1d: Decimal | None
    clob_token_id_yes: str | None
    clob_token_id_no: str | None
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


@dataclass
class ClosedMarketSignal:
    """
    Sentinel dikembalikan oleh get_orderbook() ketika CLOB melaporkan
    market sudah closed (closed=True atau 404 pada /book).

    SnapshotsCollector memakai ini untuk mark market as resolved di DB
    sehingga market tidak di-snapshot ulang setiap 5 menit.

    Berbeda dari None (= API error / network failure, perlu retry).
    """
    condition_id: str
    reason: str  # "clob_closed" | "no_orderbook"


# ---------------------------------------------------------------------------
# PolymarketService
# ---------------------------------------------------------------------------

class PolymarketService:
    """HTTP client untuk Polymarket Gamma dan CLOB APIs."""

    CRYPTO_TAGS = frozenset({
        "crypto", "bitcoin", "ethereum", "defi", "altcoins",
        "btc", "eth", "solana", "bnb", "xrp", "cryptocurrency",
        "base", "arbitrum", "polygon", "avalanche", "cardano",
        "ripple", "dogecoin", "doge", "shib", "pepe",
    })

    # IMPORTANT: keywords pakai spasi atau prefix $ untuk avoid substring match.
    # Contoh bug: "eth" match "Gaethje", "defi" match "trade deficit", "token" match apapun.
    # Semua entry di sini harus spesifik dan tidak ambigu.
    CRYPTO_QUESTION_KEYWORDS = frozenset({
        "bitcoin", "ethereum",
        " btc ", "$btc", "btc ", "btc$",
        " eth ", "$eth", "eth ", "eth$",
        " sol ", "$sol", "solana",
        " bnb ", "$bnb",
        " xrp ", "$xrp",
        " doge ", "$doge", "dogecoin",
        " usdc", " usdt",
        "cryptocurrency", " crypto ",
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
        self._client.close()

    def __enter__(self) -> "PolymarketService":
        return self

    def __exit__(self, *_: Any) -> None:
        self.close()

    # =========================================================================
    # Gamma API — market metadata
    # =========================================================================

    def iter_active_crypto_markets(self, full_scan: bool = False) -> Iterator[list[RawMarket]]:
        """
        Iterate active crypto markets from Gamma API.

        Gamma keyset pagination is broken — cursor repeats after page 1,
        returning identical data. Offset pagination works correctly.

        full_scan=False (default, incremental):
            Fetch only first 500 markets by volume (offsets 0-499).
            Used for hourly sync — captures all high-volume markets.
            Runtime: ~2s.

        full_scan=True (daily):
            Fetch all ~10,000+ active markets across all offsets.
            Used for daily sync — discovers new/low-volume crypto markets.
            Runtime: ~35s.
        """
        log.info("gamma_pagination_start", mode="offset", full_scan=full_scan)
        yield from self._iter_offset_pagination(full_scan=full_scan)

    # Stop fetching pages when all markets on a page are below this volume.
    # Markets with < $500 volume have no meaningful liquidity for paper trading.
    MIN_PAGE_VOLUME_USD = 500.0

    def _iter_keyset_pagination(self) -> Iterator[list[RawMarket]]:
        cursor: str | None = None
        page_num = 0
        total_crypto = 0
        seen_cursors: set[str] = set()

        while True:
            page_num += 1
            params: dict[str, Any] = {
                "active": "true",
                "limit": settings.gamma_page_size,
                "order": "volume",
                "ascending": "false",
            }
            if cursor:
                params["cursor"] = cursor

            log.debug("keyset_page_request", page=page_num, cursor=cursor[:20] if cursor else None)

            response_data, next_cursor = self._fetch_keyset_page(params)

            if not response_data:
                log.info("keyset_pagination_complete", total_pages=page_num - 1, total_crypto=total_crypto, reason="empty_page")
                return

            # Stop when all markets on page are below volume threshold.
            # Markets are ordered by volume desc — once a full page is low-volume,
            # all subsequent pages will be too.
            page_max_volume = max(
                float(m.get("volumeNum") or m.get("volume") or 0)
                for m in response_data
            )
            if page_max_volume < self.MIN_PAGE_VOLUME_USD:
                log.info(
                    "keyset_pagination_complete",
                    total_pages=page_num,
                    total_crypto=total_crypto,
                    reason="below_volume_threshold",
                    page_max_volume=page_max_volume,
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

            if not next_cursor:
                log.info("keyset_pagination_complete", total_pages=page_num, total_crypto=total_crypto, reason="no_next_cursor")
                return

            # Safety: detect cursor loop (API bug / wrap-around)
            if next_cursor in seen_cursors:
                log.warning(
                    "keyset_cursor_loop_detected",
                    total_pages=page_num,
                    total_crypto=total_crypto,
                    cursor=next_cursor[:20],
                )
                return
            seen_cursors.add(next_cursor)

            cursor = next_cursor

    @retry(exceptions=(httpx.HTTPError, PolymarketRateLimitError))
    def _fetch_keyset_page(self, params: dict[str, Any]) -> tuple[list[dict], str | None]:
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
                if not next_cursor and markets_list:
                    next_cursor = markets_list[-1].get("cursor") or None
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

    # Incremental sync: only fetch top N markets by volume.
    # Covers all markets with meaningful liquidity without full scan overhead.
    INCREMENTAL_OFFSET_LIMIT = 500

    def _iter_offset_pagination(self, full_scan: bool = False) -> Iterator[list[RawMarket]]:
        offset = 0
        page_num = 0
        total_crypto = 0
        offset_limit = None if full_scan else self.INCREMENTAL_OFFSET_LIMIT

        while True:
            # Incremental mode: stop after INCREMENTAL_OFFSET_LIMIT markets
            if offset_limit is not None and offset >= offset_limit:
                log.info(
                    "offset_pagination_complete",
                    total_pages=page_num,
                    total_crypto=total_crypto,
                    reason="incremental_limit_reached",
                    offset=offset,
                )
                return

            page_num += 1
            params = {
                "active": "true",
                "limit": 100,  # Gamma API max limit is 100
                "offset": offset,
                "order": "volume",
                "ascending": "false",
            }
            log.debug("offset_page_request", page=page_num, offset=offset)

            try:
                raw_response = self._fetch_offset_page(params)
            except PolymarketAPIError as exc:
                if exc.status_code == 422:
                    log.info("offset_pagination_complete", total_pages=page_num - 1, total_crypto=total_crypto, reason="offset_limit")
                    return
                raise

            if isinstance(raw_response, list):
                markets_data = raw_response
            elif isinstance(raw_response, dict):
                markets_data = raw_response.get("markets") or raw_response.get("data") or raw_response.get("results") or []
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

            if len(markets_data) < 100:  # Gamma API max limit is 100
                log.info("offset_pagination_complete", total_pages=page_num, total_crypto=total_crypto)
                return

            offset += 100  # Gamma API max limit is 100

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

    @retry(
            exceptions=(httpx.HTTPError, PolymarketRateLimitError, PolymarketAPIError),
            exclude_on=lambda exc: getattr(exc, 'status_code', None) == 404
    )
    def get_orderbook(self, condition_id: str) -> RawOrderbook | ClosedMarketSignal | None:
        """
        Fetch live orderbook untuk satu market.

        Return values:
          RawOrderbook         — sukses, ada data live
          ClosedMarketSignal   — market sudah closed di CLOB (bukan error)
          None                 — API/network error, perlu retry nanti

        FIX v1.4:
          1. Cek field `closed` dari /markets response sebelum fetch /book.
             Kalau closed=True → langsung return ClosedMarketSignal.
          2. Kalau /book return 404 → juga return ClosedMarketSignal
             (orderbook sudah tidak ada, bukan network error).
          3. token_id diambil dari field `token_id` (verified dari audit).
             Field `token`, `id` dihapus dari fallback chain.
        """
        url_market = f"{settings.polymarket_clob_url}/markets/{condition_id}"
        try:
            response = self._client.get(url_market)

            if response.status_code == 404:
                # Market tidak dikenal CLOB sama sekali
                log.warning("clob_market_not_found", condition_id=condition_id)
                return ClosedMarketSignal(condition_id=condition_id, reason="clob_not_found")

            self._raise_for_status(response, url_market)
            market_data = response.json()

            if not market_data:
                return None

            # FIX: Cek closed status dari CLOB sebelum fetch orderbook.
            # CLOB bisa closed=True sementara Gamma masih active=True (lag).
            if market_data.get("closed", False):
                log.info(
                    "clob_market_closed",
                    condition_id=condition_id,
                    active=market_data.get("active"),
                )
                return ClosedMarketSignal(condition_id=condition_id, reason="clob_closed")

            # FIX: field yang benar adalah `token_id` (verified dari audit)
            tokens = market_data.get("tokens", [])
            token_id: str | None = None
            if tokens and isinstance(tokens, list):
                first_token = tokens[0]
                if isinstance(first_token, dict):
                    token_id = first_token.get("token_id")

            if not token_id:
                log.warning("clob_no_token_id", condition_id=condition_id)
                return None

            # Fetch orderbook
            url_book = f"{settings.polymarket_clob_url}/book"
            response_book = self._client.get(url_book, params={"token_id": token_id})

            # FIX: 404 pada /book = orderbook sudah tutup, bukan network error.
            # Return ClosedMarketSignal bukan raise exception (tidak di-retry).
            if response_book.status_code == 404:
                log.info(
                    "clob_orderbook_closed",
                    condition_id=condition_id,
                    token_id=token_id[:20],
                )
                return ClosedMarketSignal(condition_id=condition_id, reason="no_orderbook")

            self._raise_for_status(response_book, url_book)
            orderbook_data = response_book.json() if response_book.status_code == 200 else None

            return self._parse_clob_market(condition_id, market_data, orderbook_data)

        except httpx.TimeoutException as exc:
            raise PolymarketAPIError("CLOB API timeout", url=url_market) from exc
        except httpx.NetworkError as exc:
            raise PolymarketAPIError("CLOB API network error", url=url_market) from exc

    def get_orderbooks_batch(
        self, condition_ids: list[str]
    ) -> dict[str, RawOrderbook | ClosedMarketSignal | None]:
        results: dict[str, RawOrderbook | ClosedMarketSignal | None] = {}
        for condition_id in condition_ids:
            try:
                results[condition_id] = self.get_orderbook(condition_id)
            except PolymarketAPIError as exc:
                log.error("orderbook_fetch_failed", condition_id=condition_id, error=str(exc))
                results[condition_id] = None
        return results

    # =========================================================================
    # Parsers
    # =========================================================================

    def _parse_page(self, raw_markets: list[dict]) -> list[RawMarket]:
        parsed = []
        for m in raw_markets:
            try:
                parsed.append(self._parse_gamma_market(m))
            except Exception as exc:
                log.warning("market_parse_error", condition_id=m.get("conditionId", "unknown"), error=str(exc))
        return parsed

    def _extract_clob_token_ids(self, data: dict[str, Any]) -> tuple[str | None, str | None]:
        """
        Extract (yes_token_id, no_token_id) from Gamma API's `clobTokenIds`
        field, matched against `outcomes` by label rather than array index
        — outcome ordering is not guaranteed to be Yes-first for every
        market (e.g. "Up"/"Down" markets).

        clobTokenIds and outcomes are both JSON-encoded string arrays in
        the Gamma response, e.g.:
          clobTokenIds = '["1234...", "5678..."]'
          outcomes     = '["Yes", "No"]'

        Returns (None, None) if fields are missing or malformed —
        callers must handle this gracefully (live trading not possible
        for this market until token IDs are available).
        """
        raw_token_ids = data.get("clobTokenIds")
        raw_outcomes = data.get("outcomes")

        if not raw_token_ids or not raw_outcomes:
            return None, None

        try:
            token_ids = json.loads(raw_token_ids) if isinstance(raw_token_ids, str) else raw_token_ids
            outcomes = json.loads(raw_outcomes) if isinstance(raw_outcomes, str) else raw_outcomes
        except (json.JSONDecodeError, TypeError):
            return None, None

        if not isinstance(token_ids, list) or not isinstance(outcomes, list):
            return None, None

        if len(token_ids) != len(outcomes):
            return None, None

        yes_token_id: str | None = None
        no_token_id: str | None = None

        for token_id, outcome in zip(token_ids, outcomes):
            outcome_lower = str(outcome).strip().lower()
            if outcome_lower == "yes":
                yes_token_id = str(token_id)
            elif outcome_lower == "no":
                no_token_id = str(token_id)

        # Fallback for binary markets with non-Yes/No labels (e.g. "Up"/"Down"):
        # treat index 0 as the "yes side" and index 1 as the "no side",
        # consistent with how probability_yes is derived from outcomePrices[0].
        if yes_token_id is None and no_token_id is None and len(token_ids) == 2:
            yes_token_id = str(token_ids[0])
            no_token_id = str(token_ids[1])

        return yes_token_id, no_token_id

    def _parse_gamma_market(self, data: dict[str, Any]) -> RawMarket:
        """
        Normalise Gamma API market response ke RawMarket.

        Field mappings (verified dari audit 2026-06-05):
          conditionId         → condition_id
          outcomePrices[0]    → market_probability
          volume / volumeNum  → volume_usd
          volume24hr          → volume_24h_usd
          liquidity           → liquidity_usd
          bestBid             → best_bid
          bestAsk             → best_ask
          spread              → spread
          oneHourPriceChange  → price_change_1h
          oneDayPriceChange   → price_change_1d

        num_traders = 0: tradesCount/uniqueTraders tidak ada di Gamma response.
        """
        status = self._derive_status(data)
        probability_yes = self._extract_probability_yes(data)
        tags = self._extract_tags(data)
        category, sub_category = self._classify_market(data, tags)
        clob_token_id_yes, clob_token_id_no = self._extract_clob_token_ids(data)

        return RawMarket(
            condition_id=str(data.get("conditionId") or data.get("condition_id") or ""),
            question=str(data.get("question") or "")[:500],
            slug=data.get("slug"),
            description=data.get("description"),
            category=category,
            sub_category=sub_category,
            tags=tags,
            resolution_source=(data.get("resolutionSource") or data.get("resolution_source")),
            start_date=data.get("startDate") or data.get("start_date"),
            end_date=data.get("endDate") or data.get("end_date"),
            resolved_at=data.get("resolvedAt") or data.get("resolved_at"),
            status=status,
            market_probability=probability_yes,
            volume_usd=self._safe_decimal(data.get("volumeNum") or data.get("volume") or 0),
            volume_24h_usd=self._safe_decimal(data.get("volume24hr") or 0),
            liquidity_usd=self._safe_decimal(data.get("liquidityNum") or data.get("liquidity") or 0),
            num_traders=0,  # Gamma API tidak expose traders count
            best_bid=self._safe_decimal_or_none(data.get("bestBid")),
            best_ask=self._safe_decimal_or_none(data.get("bestAsk")),
            spread=self._safe_decimal_or_none(data.get("spread")),
            price_change_1h=self._safe_decimal_or_none(data.get("oneHourPriceChange")),
            price_change_1d=self._safe_decimal_or_none(data.get("oneDayPriceChange")),
            clob_token_id_yes=clob_token_id_yes,
            clob_token_id_no=clob_token_id_no,
            raw=data,
        )

    def _parse_clob_market(
        self,
        condition_id: str,
        market_data: dict[str, Any],
        orderbook_data: dict[str, Any] | None = None,
    ) -> RawOrderbook:
        # Probability dari tokens[0].price
        probability_yes: Decimal | None = None
        tokens = market_data.get("tokens", [])

        if tokens and isinstance(tokens, list):
            first_token = tokens[0]
            if isinstance(first_token, dict):
                token_price = first_token.get("price")
                if token_price is not None:
                    probability_yes = self._safe_decimal_or_none(token_price)

        if probability_yes is None:
            probability_yes = self._extract_probability_yes(market_data)
        if probability_yes is None:
            probability_yes = Decimal("0.5")

        probability_yes = max(Decimal("0"), min(Decimal("1"), probability_yes))
        probability_no = Decimal("1") - probability_yes

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

        spread = (best_ask - best_bid) if (best_bid is not None and best_ask is not None) else None

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
            raise PolymarketAPIError(f"Server error {response.status_code}", status_code=response.status_code, url=url)
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

    # Micro market patterns — always excluded regardless of crypto keywords.
    # These are 5-minute direction markets with no exploitable edge.
    MICRO_MARKET_PATTERNS = (
        "up or down",
    )

    def _is_crypto(self, data: dict[str, Any]) -> bool:
        question = (data.get("question") or "").lower()

        # Exclude micro/noise markets first
        if any(p in question for p in self.MICRO_MARKET_PATTERNS):
            return False

        category = (data.get("category") or "").lower()
        if "crypto" in category:
            return True
        tags = self._extract_tags(data)
        if {t.lower() for t in tags} & self.CRYPTO_TAGS:
            return True
        return any(kw in question for kw in self.CRYPTO_QUESTION_KEYWORDS)

    def _classify_market(self, data: dict[str, Any], tags: list[str]) -> tuple[str, str | None]:
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

        # Only return crypto if there's actual crypto signal
        if sub_category is not None:
            return "crypto", sub_category
        # Fallback: check tags for crypto signals
        crypto_tags = {"bitcoin", "btc", "ethereum", "eth", "solana", "sol",
                       "bnb", "binance", "xrp", "ripple", "defi", "crypto",
                       "cryptocurrency"}
        if tags_lower & crypto_tags:
            return "crypto", sub_category
        # Not crypto
        return "other", None

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
                    label = t.get("label") or t.get("slug") or t.get("id") or t.get("name") or ""
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
            return Decimal(str(value))
        except (InvalidOperation, Exception):
            return None