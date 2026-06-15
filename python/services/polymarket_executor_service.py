"""
services/polymarket_executor_service.py

Thin wrapper around py-clob-client for live order execution.

Design principles:
  - If POLYMARKET_WALLET_PRIVATE_KEY is not configured, this service
    operates in DISABLED mode: is_enabled() returns False, and any
    execution method raises PolymarketExecutorDisabled. Callers
    (OrderExecutorJob) must check is_enabled() first and skip/log
    gracefully rather than crash — this lets the rest of the
    collector/scheduler keep running while live trading is not
    yet configured.

  - signature_type=0 (default, direct EOA) — the wallet's own private
    key signs orders directly. No proxy/funder address needed.

  - client.create_or_derive_api_creds() requires a network call to
    clob.polymarket.com and is therefore only attempted lazily, on
    first real use — not at import time or service construction.
"""
from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal
from typing import Any

from config.settings import settings
from utils.logger import get_logger

log = get_logger(__name__)


class PolymarketExecutorDisabled(Exception):
    """Raised when live trading is attempted but no wallet is configured."""


@dataclass
class OrderFillResult:
    """Normalised result of a placed order, regardless of fill type."""

    avg_fill_price: Decimal
    filled_shares: Decimal
    fee_usd: Decimal
    clob_order_id: str | None
    tx_hash: str | None
    partial: bool


class PolymarketExecutorService:
    """
    Lazily-initialized ClobClient wrapper.

    Usage:
        executor = PolymarketExecutorService()
        if not executor.is_enabled():
            log.warning("live_trading_disabled_no_wallet")
            return
        balance = executor.get_balance()
        result = executor.place_market_order(token_id, side="BUY", size_usd=Decimal("3.00"))
    """

    def __init__(self) -> None:
        self._client: Any | None = None
        self._creds_set = False

    # =========================================================================
    # Status
    # =========================================================================

    def is_enabled(self) -> bool:
        """True if a wallet private key is configured."""
        return bool(settings.polymarket_wallet_private_key)

    # =========================================================================
    # Client lifecycle
    # =========================================================================

    def _get_client(self):
        """
        Lazily construct and authenticate the ClobClient.

        Raises PolymarketExecutorDisabled if no private key configured.
        """
        if not self.is_enabled():
            raise PolymarketExecutorDisabled(
                "POLYMARKET_WALLET_PRIVATE_KEY is not set — live trading disabled"
            )

        if self._client is None:
            from py_clob_client.client import ClobClient

            self._client = ClobClient(
                settings.polymarket_clob_url,
                key=settings.polymarket_wallet_private_key,
                chain_id=settings.polymarket_chain_id,
                # signature_type=0 (default): direct EOA, no funder needed
            )
            log.info(
                "clob_client_initialized",
                chain_id=settings.polymarket_chain_id,
                wallet_address=settings.polymarket_wallet_address or "(derived)",
            )

        if not self._creds_set:
            # Network call — only happens on first real use, not at
            # service construction.
            creds = self._client.create_or_derive_api_creds()
            self._client.set_api_creds(creds)
            self._creds_set = True
            log.info("clob_api_creds_derived")

        return self._client

    # =========================================================================
    # Balance
    # =========================================================================

    def get_balance(self) -> Decimal:
        """
        Return USDC balance available for trading, in USD.

        Raises PolymarketExecutorDisabled if not configured.
        """
        client = self._get_client()

        # py-clob-client exposes collateral balance via get_balance_allowance
        # for the COLLATERAL asset type.
        from py_clob_client.clob_types import AssetType, BalanceAllowanceParams

        params = BalanceAllowanceParams(asset_type=AssetType.COLLATERAL)
        result = client.get_balance_allowance(params)

        # Result balance is in smallest units (6 decimals for USDC)
        raw_balance = Decimal(str(result.get("balance", "0")))
        return raw_balance / Decimal("1000000")

    # =========================================================================
    # Order placement
    # =========================================================================

    def place_market_order(
        self,
        token_id: str,
        side: str,
        *,
        size_usd: Decimal | None = None,
        shares: Decimal | None = None,
    ) -> OrderFillResult:
        """
        Place a market order.

        For BUY: size_usd is the USD amount to spend.
        For SELL: shares is the number of outcome tokens to sell.

        Exactly one of size_usd / shares must be provided, matching the
        expected param for the given side.

        Raises:
          PolymarketExecutorDisabled — wallet not configured.
          ValueError — invalid argument combination.
          Exception — any error from py-clob-client / network; caller
                       (OrderExecutorJob) is responsible for catching
                       and recording via mark_failed().
        """
        if side not in ("BUY", "SELL"):
            raise ValueError(f"Invalid side: {side!r}")

        # Hard safety cap — last line of defense regardless of what
        # Laravel queued.
        if size_usd is not None and float(size_usd) > settings.live_trading_max_order_usd:
            raise ValueError(
                f"size_usd {size_usd} exceeds LIVE_TRADING_MAX_ORDER_USD "
                f"({settings.live_trading_max_order_usd}) — refusing to place order"
            )

        client = self._get_client()

        from py_clob_client.clob_types import MarketOrderArgs, OrderType
        from py_clob_client.order_builder.constants import BUY, SELL

        clob_side = BUY if side == "BUY" else SELL

        if side == "BUY":
            if size_usd is None:
                raise ValueError("size_usd is required for BUY orders")
            order_args = MarketOrderArgs(
                token_id=token_id,
                amount=float(size_usd),
                side=clob_side,
            )
        else:
            if shares is None:
                raise ValueError("shares is required for SELL orders")
            order_args = MarketOrderArgs(
                token_id=token_id,
                amount=float(shares),
                side=clob_side,
            )

        signed_order = client.create_market_order(order_args)
        response = client.post_order(signed_order, OrderType.FOK)

        return self._parse_order_response(response)

    # =========================================================================
    # Response parsing
    # =========================================================================

    def _parse_order_response(self, response: dict[str, Any]) -> OrderFillResult:
        """
        Normalise py-clob-client's post_order response into OrderFillResult.

        NOTE: exact response shape should be verified against a real
        FOK fill once a funded wallet is available — this parsing is
        based on documented response fields and may need adjustment.
        """
        status = str(response.get("status", "")).lower()
        partial = status in ("partial", "partially_filled")

        avg_price = Decimal(str(response.get("avg_price") or response.get("price") or "0"))
        filled_size = Decimal(str(response.get("size_matched") or response.get("matched_size") or "0"))
        fee = Decimal(str(response.get("fee") or "0"))

        clob_order_id = response.get("orderID") or response.get("order_id")
        tx_hash = response.get("transactionHash") or response.get("tx_hash")

        return OrderFillResult(
            avg_fill_price=avg_price,
            filled_shares=filled_size,
            fee_usd=fee,
            clob_order_id=str(clob_order_id) if clob_order_id else None,
            tx_hash=str(tx_hash) if tx_hash else None,
            partial=partial,
        )
