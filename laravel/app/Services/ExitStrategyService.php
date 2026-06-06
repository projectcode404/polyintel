<?php

namespace App\Services\PaperTrading;

use App\Models\PaperTradeSetting;

class ExitStrategyService
{
    /**
     * Calculate all exit levels at the moment a trade is opened.
     *
     * @return array{
     *   take_profit_price: float|null,
     *   stop_loss_price: float|null,
     *   breakeven_price: float|null,
     *   risk_per_share: float|null,
     *   r_value: float|null,
     * }
     */
    public function calculateExitLevels(
        PaperTradeSetting $settings,
        float $entryPrice
    ): array {
        $stopLossPrice  = null;
        $takeProfitPrice = null;
        $breakevenPrice  = null;
        $riskPerShare    = null;
        $rValue          = null;

        // --- Stop Loss ---
        if ($settings->enable_stop_loss) {
            $stopLossPrice = $this->calculateStopLoss($settings, $entryPrice);
            $riskPerShare  = $entryPrice - $stopLossPrice;  // R = 1 unit of risk
            $rValue        = $riskPerShare;                  // 1R in price terms
        }

        // --- Take Profit (TP1 only stored on trade; TP2/TP3 handled by monitor) ---
        if ($settings->enable_take_profit && $riskPerShare !== null) {
            $takeProfitPrice = $this->calculateTakeProfit(
                $settings,
                $entryPrice,
                $riskPerShare,
                r: 1   // TP1
            );
        }

        // --- Breakeven (price at which SL moves to entry) ---
        if ($settings->enable_move_to_breakeven && $riskPerShare !== null) {
            $triggerR       = (float) $settings->breakeven_trigger_r;
            $breakevenPrice = round($entryPrice + ($riskPerShare * $triggerR), 8);
        }

        return [
            'take_profit_price'  => $takeProfitPrice,
            'stop_loss_price'    => $stopLossPrice,
            'breakeven_price'    => $breakevenPrice,
            'risk_per_share'     => $riskPerShare,
            'r_value'            => $rValue,
        ];
    }

    /**
     * Calculate TP price for a given R multiple.
     */
    public function calculateTakeProfit(
        PaperTradeSetting $settings,
        float $entryPrice,
        float $riskPerShare,
        int $r = 1
    ): float {
        if ($settings->take_profit_mode === 'fixed_percent') {
            // Use stored R values as percent directly
            $pct = match ($r) {
                1 => (float) ($settings->take_profit_r1 ?? 50),
                2 => (float) ($settings->take_profit_r2 ?? 100),
                3 => (float) ($settings->take_profit_r3 ?? 200),
                default => 50.0,
            };
            return round($entryPrice * (1 + $pct / 100), 8);
        }

        // r_multiple mode: TP = entry + (R * multiple)
        $multiple = match ($r) {
            1 => (float) ($settings->take_profit_r1 ?? 1.0),
            2 => (float) ($settings->take_profit_r2 ?? 2.0),
            3 => (float) ($settings->take_profit_r3 ?? 3.0),
            default => 1.0,
        };

        return round($entryPrice + ($riskPerShare * $multiple), 8);
    }

    /**
     * Calculate stop loss price.
     */
    public function calculateStopLoss(
        PaperTradeSetting $settings,
        float $entryPrice
    ): float {
        if ($settings->stop_loss_mode === 'fixed_percent') {
            $pct = (float) $settings->stop_loss_value;
            return round($entryPrice * (1 - $pct / 100), 8);
        }

        // r_multiple: SL = entry - (stop_loss_value * some base risk)
        // For prediction markets, price is in [0,1].
        // Use a base risk of entryPrice * 0.20 as default 1R for binary markets.
        $baseRisk    = $this->estimateBaseRisk($entryPrice);
        $rMultiple   = (float) $settings->stop_loss_value;

        return round(max(0.001, $entryPrice - ($baseRisk * $rMultiple)), 8);
    }

    /**
     * Check if current price has reached or exceeded stop loss.
     */
    public function isStopLossHit(float $currentPrice, float $stopLossPrice): bool
    {
        return $currentPrice <= $stopLossPrice;
    }

    /**
     * Check if current price has reached or exceeded a TP level.
     */
    public function isTakeProfitHit(float $currentPrice, float $takeProfitPrice): bool
    {
        return $currentPrice >= $takeProfitPrice;
    }

    /**
     * Check if trade has reached breakeven trigger price.
     */
    public function isBreakevenTriggerHit(float $currentPrice, float $breakevenPrice): bool
    {
        return $currentPrice >= $breakevenPrice;
    }

    /**
     * Calculate realized PnL for a partial or full close.
     *
     * For prediction markets:
     *   PnL = (exit_price - entry_price) * shares_closed
     */
    public function calculatePnl(
        float $entryPrice,
        float $exitPrice,
        float $sharesClosed
    ): float {
        return round(($exitPrice - $entryPrice) * $sharesClosed, 2);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Estimate 1R base risk for prediction market prices in [0, 1].
     * Conservative: 20% of entry price.
     */
    private function estimateBaseRisk(float $entryPrice): float
    {
        return $entryPrice * 0.20;
    }
}
