<?php

namespace App\Services\PaperTrading;

use App\Models\PaperTradeSetting;

class PositionSizerService
{
    /**
     * Dynamic sizing thresholds.
     * score => percent of equity to allocate
     */
    private const DYNAMIC_TIERS = [
        ['min_score' => 0.90, 'percent' => 10.0],
        ['min_score' => 0.80, 'percent' => 7.0],
        ['min_score' => 0.70, 'percent' => 5.0],
    ];

    /**
     * Calculate position size in USD given settings, current equity, and signal score.
     *
     * Returns null if the trade should be skipped (score too low for dynamic mode).
     */
    public function calculate(
        PaperTradeSetting $settings,
        float $currentEquity,
        float $signalScore
    ): ?float {
        return match ($settings->position_size_mode) {
            'fixed_amount'  => $this->fixedAmount($settings),
            'fixed_percent' => $this->fixedPercent($settings, $currentEquity),
            'dynamic'       => $this->dynamic($settings, $currentEquity, $signalScore),
            default         => null,
        };
    }

    // -------------------------------------------------------------------------

    private function fixedAmount(PaperTradeSetting $settings): ?float
    {
        $amount = (float) $settings->fixed_amount;

        if ($amount <= 0) {
            return null;
        }

        return $amount;
    }

    private function fixedPercent(PaperTradeSetting $settings, float $currentEquity): ?float
    {
        $pct = (float) $settings->fixed_percent;

        if ($pct <= 0 || $currentEquity <= 0) {
            return null;
        }

        return round($currentEquity * ($pct / 100), 2);
    }

    private function dynamic(
        PaperTradeSetting $settings,
        float $currentEquity,
        float $signalScore
    ): ?float {
        foreach (self::DYNAMIC_TIERS as $tier) {
            if ($signalScore >= $tier['min_score']) {
                return round($currentEquity * ($tier['percent'] / 100), 2);
            }
        }

        // Score below minimum threshold → skip trade
        return null;
    }

    /**
     * Convert a USD position size to shares at a given entry price.
     */
    public function toShares(float $positionSize, float $entryPrice): float
    {
        if ($entryPrice <= 0) {
            return 0.0;
        }

        return round($positionSize / $entryPrice, 8);
    }
}
