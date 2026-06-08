<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrade;
use App\Models\PaperTradeSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SignalRankerService
 *
 * Scores, filters, and ranks signals for paper trading execution.
 *
 * Raw metric columns (momentum_24h_percent, liquidity_usd, volume_24h_usd, spread)
 * are normalized to [0, 1] scores inside this service.
 * Old signals with NULL metrics fallback to 0 — no exception thrown.
 *
 * Formula:
 *   score = 0.35 * edge_score
 *         + 0.25 * confidence_score
 *         + 0.15 * momentum_score
 *         + 0.15 * liquidity_score
 *         + 0.10 * volume_score
 *   then apply spread penalty
 *   then clamp to [0.0, 1.0]
 */
final class SignalRankerService
{
    // -------------------------------------------------------------------------
    // Weights — must sum to 1.0
    // -------------------------------------------------------------------------

    private const WEIGHT_EDGE       = 0.35;
    private const WEIGHT_CONFIDENCE = 0.25;
    private const WEIGHT_MOMENTUM   = 0.15;
    private const WEIGHT_LIQUIDITY  = 0.15;
    private const WEIGHT_VOLUME     = 0.10;

    // -------------------------------------------------------------------------
    // Normalization Tiers
    // -------------------------------------------------------------------------

    // Momentum: absolute percent change over 24h
    private const MOMENTUM_TIERS = [
        ['min' => 20.0, 'score' => 1.0],
        ['min' => 10.0, 'score' => 0.8],
        ['min' => 5.0,  'score' => 0.5],
        ['min' => 0.0,  'score' => 0.2],
    ];

    // Liquidity: total USD available in market
    private const LIQUIDITY_TIERS = [
        ['min' => 200_000, 'score' => 1.0],
        ['min' => 50_000,  'score' => 0.8],
        ['min' => 10_000,  'score' => 0.5],
        ['min' => 0,       'score' => 0.2],
    ];

    // Volume: 24h trading volume USD
    private const VOLUME_TIERS = [
        ['min' => 500_000, 'score' => 1.0],
        ['min' => 100_000, 'score' => 0.8],
        ['min' => 10_000,  'score' => 0.5],
        ['min' => 0,       'score' => 0.2],
    ];

    // Spread penalties
    private const SPREAD_PENALTY_HIGH   = 0.20; // spread > 0.05
    private const SPREAD_PENALTY_MEDIUM = 0.10; // spread > 0.03

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Score, filter, and return ranked signals ready for trade execution.
     *
     * @param  Collection  $signals  Signal Eloquent models or arrays
     * @return Collection  Scored, filtered, sorted — capped to max_signals_per_cycle
     */
    public function rank(Collection $signals, PaperTradeSetting $settings): Collection
    {
        // 1. Score all signals
        $scored = $signals->map(function ($signal) {
            $arr   = is_array($signal) ? $signal : $signal->toArray();
            $score = $this->computeScore($arr);

            return array_merge($arr, ['score' => $score]);
        });

        // 2. Apply minimum score filter
        $filtered = $scored->filter(
            fn ($s) => $s['score'] >= (float) $settings->minimum_signal_score
        );

        // 3. Sort descending by score
        $sorted = $filtered->sortByDesc('score')->values();

        // 4. Cap to max_signals_per_cycle if top signal filter is enabled
        if ($settings->enable_top_signal_filter) {
            $sorted = $sorted->take((int) $settings->max_signals_per_cycle);
        }

        return $sorted;
    }

    /**
     * Compute composite score for a single signal.
     *
     * Accepts Eloquent model attributes as array.
     * NULL raw metric fields → fallback score 0 (backward compatible).
     */
    public function computeScore(array $signal): float
    {
        // Edge and confidence come directly from signal columns
        // edge_at_signal and confidence_at_signal are already in [0, 1]
        $edgeScore       = $this->clamp((float) ($signal['edge_at_signal']        ?? $signal['edge']       ?? 0));
        $confidenceScore = $this->clamp((float) ($signal['confidence_at_signal']  ?? $signal['confidence'] ?? 0));

        // Raw metrics → normalized scores
        // NULL = old signal without metrics → score 0 (no exception)
        $momentumScore  = $this->normalizeMomentum(isset($signal['momentum_24h_percent']) ? (float) $signal['momentum_24h_percent'] : null);
        $liquidityScore = $this->normalizeLiquidity(isset($signal['liquidity_usd']) ? (float) $signal['liquidity_usd'] : null);
        $volumeScore    = $this->normalizeVolume(isset($signal['volume_24h_usd']) ? (float) $signal['volume_24h_usd'] : null);

        // Weighted sum
        $score = (self::WEIGHT_EDGE       * $edgeScore)
               + (self::WEIGHT_CONFIDENCE * $confidenceScore)
               + (self::WEIGHT_MOMENTUM   * $momentumScore)
               + (self::WEIGHT_LIQUIDITY  * $liquidityScore)
               + (self::WEIGHT_VOLUME     * $volumeScore);

        // Apply spread penalty
        $score = $this->applySpreadPenalty($score, isset($signal['spread']) ? (float) $signal['spread'] : null);

        return round($this->clamp($score), 4);
    }

    // =========================================================================
    // Market Constraints
    // =========================================================================

    /**
     * Filter out signals for markets that:
     * 1. Already have an open/partial trade (max_position_per_market)
     * 2. Recently had a STOPPED trade within cooldown window
     */
    public function applyMarketConstraints(
        Collection $signals,
        PaperTradeSetting $settings,
        \App\Models\TradingAccount $account
    ): Collection {
        $openMarketCounts  = $this->getOpenTradeMarketCounts($account->id);
        $cooldownMarketIds = $this->getCooldownMarketIds((int) $settings->market_cooldown_minutes, $account->id);
        return $signals->filter(function ($signal) use ($openMarketCounts, $cooldownMarketIds, $settings) {
            $marketId = $signal['market_id'] ?? null;

            if (! $marketId) {
                return false;
            }

            // Check max positions per market (default 1 = no duplicates)
            $openCount = $openMarketCounts->get($marketId, 0);
            if ($openCount >= (int) $settings->max_position_per_market) {
                return false;
            }

            // Check cooldown after stop loss
            if ($cooldownMarketIds->contains($marketId)) {
                return false;
            }

            return true;
        })->values();
    }

    // =========================================================================
    // Normalization
    // =========================================================================

    /**
     * Normalize momentum_24h_percent to [0, 1].
     * Uses absolute value — both positive and negative momentum count.
     * NULL → 0 (backward compatible with old signals).
     */
    public function normalizeMomentum(?float $percent): float
    {
        if ($percent === null) {
            return 0.0;
        }

        $abs = abs($percent);

        foreach (self::MOMENTUM_TIERS as $tier) {
            if ($abs >= $tier['min']) {
                return $tier['score'];
            }
        }

        return 0.0;
    }

    /**
     * Normalize liquidity_usd to [0, 1].
     * NULL → 0 (backward compatible).
     */
    public function normalizeLiquidity(?float $usd): float
    {
        if ($usd === null) {
            return 0.0;
        }

        foreach (self::LIQUIDITY_TIERS as $tier) {
            if ($usd >= $tier['min']) {
                return $tier['score'];
            }
        }

        return 0.0;
    }

    /**
     * Normalize volume_24h_usd to [0, 1].
     * NULL → 0 (backward compatible).
     */
    public function normalizeVolume(?float $usd): float
    {
        if ($usd === null) {
            return 0.0;
        }

        foreach (self::VOLUME_TIERS as $tier) {
            if ($usd >= $tier['min']) {
                return $tier['score'];
            }
        }

        return 0.0;
    }

    /**
     * Apply spread penalty to score.
     * NULL spread → no penalty.
     */
    public function applySpreadPenalty(float $score, ?float $spread): float
    {
        if ($spread === null) {
            return $score;
        }

        if ($spread > 0.05) {
            return $score - self::SPREAD_PENALTY_HIGH;
        }

        if ($spread > 0.03) {
            return $score - self::SPREAD_PENALTY_MEDIUM;
        }

        return $score;
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Returns map of market_id => open trade count.
     */
    private function getOpenTradeMarketCounts(int $accountId): Collection
    {
        return PaperTrade::whereIn('status', PaperTrade::OPEN_STATUSES)
            ->where('trading_account_id', $accountId)
            ->select('market_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('market_id')
            ->pluck('cnt', 'market_id');
    }

    /**
     * Returns Collection of market_ids stopped within cooldown window.
     */
    private function getCooldownMarketIds(int $cooldownMinutes, int $accountId): Collection
    {
        return PaperTrade::where('status', PaperTrade::STATUS_STOPPED)
            ->where('trading_account_id', $accountId)
            ->where('updated_at', '>=', now()->subMinutes($cooldownMinutes))
            ->pluck('market_id');
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
