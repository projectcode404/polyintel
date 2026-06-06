<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\Signal;

/**
 * SmartExitEngineService
 *
 * Evaluates a single open trade against smart exit rules.
 * Returns a SmartExitDecision — never executes directly.
 * Execution is handled by SmartExitMonitorJob.
 *
 * Priority order (return immediately after first match):
 *   1. Stop Loss
 *   2. Take Profit (TP1, TP2)
 *   3. Breakeven move
 *   4. Smart Exit rules
 *
 * Smart Exit Rules (no AI — simple rules only):
 *   Rule 1: Momentum reversal   < -10%        → PARTIAL_EXIT_50
 *   Rule 2: Liquidity < 50% of entry          → PARTIAL_EXIT_50
 *   Rule 3: Spread > 2x entry spread          → PARTIAL_EXIT_50
 *   Rule 4: Expiry within 6 hours             → FULL_EXIT
 *   Rule 5: Opposite signal with higher score → FULL_EXIT
 */
final class SmartExitEngineService
{
    // =========================================================================
    // Main Evaluation
    // =========================================================================

    /**
     * Evaluate a trade against all exit conditions.
     * Returns the highest-priority action found, or NO_ACTION.
     *
     * @param  PaperTrade  $trade         Trade with current_price already updated
     * @param  float       $currentPrice  Latest market price
     */
    public function evaluate(PaperTrade $trade, float $currentPrice): SmartExitDecision
    {
        $entryPrice  = (float) $trade->entry_price;
        $stopLoss    = $trade->stop_loss_price  ? (float) $trade->stop_loss_price  : null;
        $takeProfit  = $trade->take_profit_price ? (float) $trade->take_profit_price : null;
        $breakeven   = $trade->breakeven_price   ? (float) $trade->breakeven_price   : null;

        // --- Priority 1: Stop Loss ---
        if ($stopLoss !== null && $currentPrice <= $stopLoss) {
            return SmartExitDecision::fullExit(
                "Stop loss hit: price {$currentPrice} <= SL {$stopLoss}"
            );
        }

        // --- Priority 2: Take Profit ---
        if ($takeProfit !== null && $currentPrice >= $takeProfit) {
            // Check if TP1 already fired — if so, check TP2
            if ($this->hasTp1Fired($trade)) {
                $tp2 = $this->getTp2Price($trade, $entryPrice);
                if ($tp2 !== null && $currentPrice >= $tp2) {
                    return SmartExitDecision::partialExit(
                        "TP2 hit: price {$currentPrice} >= TP2 {$tp2}"
                    );
                }
            } else {
                return SmartExitDecision::partialExit(
                    "TP1 hit: price {$currentPrice} >= TP1 {$takeProfit}"
                );
            }
        }

        // --- Priority 3: Move to Breakeven ---
        if ($breakeven !== null
            && $currentPrice >= $breakeven
            && ! $this->hasBreakevenMoved($trade)
            && $stopLoss !== null
            && $stopLoss < $entryPrice
        ) {
            return SmartExitDecision::moveToBreakeven(
                "Breakeven trigger hit: price {$currentPrice} >= trigger {$breakeven}"
            );
        }

        // --- Priority 4: Smart Exit Rules ---
        if ($this->isSmartExitEnabled($trade)) {
            return $this->evaluateSmartRules($trade, $currentPrice);
        }

        return SmartExitDecision::noAction();
    }

    // =========================================================================
    // Smart Exit Rules
    // =========================================================================

    private function evaluateSmartRules(PaperTrade $trade, float $currentPrice): SmartExitDecision
    {
        // Rule 4: Near expiry (highest urgency among smart rules)
        if ($this->isNearExpiry($trade)) {
            return SmartExitDecision::fullExit('Near expiry: less than 6 hours remaining');
        }

        // Rule 5: Signal reversal
        if ($this->hasOppositeSignal($trade)) {
            return SmartExitDecision::fullExit('Signal reversal: opposite signal with higher score detected');
        }

        // Rule 1: Momentum reversal
        if ($this->isMomentumReversing($trade)) {
            return SmartExitDecision::partialExit('Momentum reversal: momentum < -10%');
        }

        // Rule 2: Liquidity deterioration
        if ($this->isLiquidityDeteriorating($trade)) {
            return SmartExitDecision::partialExit('Liquidity deterioration: < 50% of entry liquidity');
        }

        // Rule 3: Spread widening
        if ($this->isSpreadWidening($trade)) {
            return SmartExitDecision::partialExit('Spread widening: > 2x entry spread');
        }

        return SmartExitDecision::noAction();
    }

    // =========================================================================
    // Rule Implementations
    // =========================================================================

    /**
     * Rule 1: Momentum < -10% indicates reversal.
     * Reads current momentum from market's latest snapshot via signal.
     */
    private function isMomentumReversing(PaperTrade $trade): bool
    {
        $signal = $trade->signal;
        if (! $signal || $signal->momentum_24h_percent === null) {
            return false;
        }

        return (float) $signal->momentum_24h_percent < -10.0;
    }

    /**
     * Rule 2: Liquidity < 50% of liquidity at signal time.
     */
    private function isLiquidityDeteriorating(PaperTrade $trade): bool
    {
        $signal = $trade->signal;
        if (! $signal || $signal->liquidity_usd === null) {
            return false;
        }

        $entryLiquidity   = (float) $signal->liquidity_usd;
        $currentLiquidity = $this->getCurrentLiquidity($trade);

        if ($currentLiquidity === null || $entryLiquidity <= 0) {
            return false;
        }

        return $currentLiquidity < ($entryLiquidity * 0.50);
    }

    /**
     * Rule 3: Spread > 2x spread at signal time.
     */
    private function isSpreadWidening(PaperTrade $trade): bool
    {
        $signal = $trade->signal;
        if (! $signal || $signal->spread === null) {
            return false;
        }

        $entrySpread   = (float) $signal->spread;
        $currentSpread = $this->getCurrentSpread($trade);

        if ($currentSpread === null || $entrySpread <= 0) {
            return false;
        }

        return $currentSpread > ($entrySpread * 2.0);
    }

    /**
     * Rule 4: Market expires within 6 hours.
     */
    private function isNearExpiry(PaperTrade $trade): bool
    {
        $market = $trade->market;
        if (! $market || empty($market->end_date)) {
            return false;
        }

        $endDate = \Carbon\Carbon::parse($market->end_date);
        return $endDate->diffInHours(now()) < 6;
    }

    /**
     * Rule 5: Opposite direction signal exists with higher score.
     * Checks signals table for same market, opposite direction, status pending.
     */
    private function hasOppositeSignal(PaperTrade $trade): bool
    {
        $currentSignal = $trade->signal;
        $currentScore  = $currentSignal ? (float) ($currentSignal->score ?? 0) : 0;

        $oppositeDirection = strtolower((string) $trade->direction) === 'yes' ? 'no' : 'yes';

        return Signal::where('market_id', $trade->market_id)
            ->where('direction', $oppositeDirection)
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    // =========================================================================
    // History Checks (prevent duplicate actions)
    // =========================================================================

    public function hasTp1Fired(PaperTrade $trade): bool
    {
        return $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_TP1)
            ->exists();
    }

    public function hasTp2Fired(PaperTrade $trade): bool
    {
        return $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_TP2)
            ->exists();
    }

    public function hasBreakevenMoved(PaperTrade $trade): bool
    {
        return $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_BREAKEVEN_MOVED)
            ->exists();
    }

    // =========================================================================
    // Market Data Helpers
    // =========================================================================

    /**
     * Get current liquidity from latest market snapshot.
     * Returns null if no snapshot available.
     */
    private function getCurrentLiquidity(PaperTrade $trade): ?float
    {
        $snapshot = $trade->market?->snapshots()?->latest()->first();
        return $snapshot ? (float) ($snapshot->liquidity_usd ?? 0) : null;
    }

    /**
     * Get current spread from latest market snapshot.
     */
    private function getCurrentSpread(PaperTrade $trade): ?float
    {
        $snapshot = $trade->market?->snapshots()?->latest()->first();
        return $snapshot ? (float) ($snapshot->spread ?? 0) : null;
    }

    /**
     * Calculate TP2 price from trade history and settings.
     * TP2 = entry + (risk_per_share * take_profit_r2)
     */
    private function getTp2Price(PaperTrade $trade, float $entryPrice): ?float
    {
        $settings = \App\Models\PaperTradeSetting::current();

        if (! $settings->enable_take_profit || $settings->take_profit_r2 === null) {
            return null;
        }

        $stopLoss = $trade->stop_loss_price ? (float) $trade->stop_loss_price : null;
        if ($stopLoss === null) {
            return null;
        }

        $riskPerShare = $entryPrice - $stopLoss;
        if ($riskPerShare <= 0) {
            return null;
        }

        return round($entryPrice + ($riskPerShare * (float) $settings->take_profit_r2), 8);
    }

    /**
     * Check if smart exit is enabled in settings.
     */
    private function isSmartExitEnabled(PaperTrade $trade): bool
    {
        return (bool) \App\Models\PaperTradeSetting::current()->enable_smart_exit;
    }
}
