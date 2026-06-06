<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PaperTrading;

use App\Models\PaperTradeSetting;
use App\Services\PaperTrading\SignalRankerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SignalRankerServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignalRankerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignalRankerService();
    }

    // =========================================================================
    // normalizeMomentum
    // =========================================================================

    /** @test */
    public function it_returns_zero_for_null_momentum(): void
    {
        $this->assertSame(0.0, $this->service->normalizeMomentum(null));
    }

    /** @test */
    public function it_normalizes_momentum_by_tier(): void
    {
        $this->assertSame(0.2, $this->service->normalizeMomentum(2.0));
        $this->assertSame(0.5, $this->service->normalizeMomentum(7.0));
        $this->assertSame(0.8, $this->service->normalizeMomentum(15.0));
        $this->assertSame(1.0, $this->service->normalizeMomentum(25.0));
    }

    /** @test */
    public function it_uses_absolute_value_for_negative_momentum(): void
    {
        $this->assertSame(0.8, $this->service->normalizeMomentum(-15.0));
        $this->assertSame(1.0, $this->service->normalizeMomentum(-25.0));
    }

    // =========================================================================
    // normalizeLiquidity
    // =========================================================================

    /** @test */
    public function it_returns_zero_for_null_liquidity(): void
    {
        $this->assertSame(0.0, $this->service->normalizeLiquidity(null));
    }

    /** @test */
    public function it_normalizes_liquidity_by_tier(): void
    {
        $this->assertSame(0.2, $this->service->normalizeLiquidity(5_000));
        $this->assertSame(0.5, $this->service->normalizeLiquidity(25_000));
        $this->assertSame(0.8, $this->service->normalizeLiquidity(100_000));
        $this->assertSame(1.0, $this->service->normalizeLiquidity(500_000));
    }

    // =========================================================================
    // normalizeVolume
    // =========================================================================

    /** @test */
    public function it_returns_zero_for_null_volume(): void
    {
        $this->assertSame(0.0, $this->service->normalizeVolume(null));
    }

    /** @test */
    public function it_normalizes_volume_by_tier(): void
    {
        $this->assertSame(0.2, $this->service->normalizeVolume(5_000));
        $this->assertSame(0.5, $this->service->normalizeVolume(50_000));
        $this->assertSame(0.8, $this->service->normalizeVolume(200_000));
        $this->assertSame(1.0, $this->service->normalizeVolume(600_000));
    }

    // =========================================================================
    // applySpreadPenalty
    // =========================================================================

    /** @test */
    public function it_applies_no_penalty_for_null_spread(): void
    {
        $this->assertSame(0.8, $this->service->applySpreadPenalty(0.8, null));
    }

    /** @test */
    public function it_applies_no_penalty_for_tight_spread(): void
    {
        $this->assertSame(0.8, $this->service->applySpreadPenalty(0.8, 0.02));
    }

    /** @test */
    public function it_applies_medium_penalty_for_moderate_spread(): void
    {
        $result = $this->service->applySpreadPenalty(0.8, 0.04);
        $this->assertEqualsWithDelta(0.7, $result, 0.0001);
    }

    /** @test */
    public function it_applies_high_penalty_for_wide_spread(): void
    {
        $result = $this->service->applySpreadPenalty(0.8, 0.06);
        $this->assertEqualsWithDelta(0.6, $result, 0.0001);
    }

    // =========================================================================
    // computeScore
    // =========================================================================

    /** @test */
    public function it_computes_score_with_all_null_raw_metrics(): void
    {
        $signal = [
            'edge_at_signal'       => 0.3,
            'confidence_at_signal' => 0.4,
            // null metrics → backward compatible
        ];

        $score = $this->service->computeScore($signal);

        // score = 0.35 * 0.3 + 0.25 * 0.4 + 0 + 0 + 0 = 0.105 + 0.10 = 0.205
        $this->assertEqualsWithDelta(0.205, $score, 0.0001);
    }

    /** @test */
    public function it_computes_full_score_with_all_metrics(): void
    {
        $signal = [
            'edge_at_signal'       => 0.5,
            'confidence_at_signal' => 0.8,
            'momentum_24h_percent' => 25.0,  // → 1.0
            'liquidity_usd'        => 500_000, // → 1.0
            'volume_24h_usd'       => 600_000, // → 1.0
            'spread'               => 0.01,    // no penalty
        ];

        $score = $this->service->computeScore($signal);

        // 0.35*0.5 + 0.25*0.8 + 0.15*1.0 + 0.15*1.0 + 0.10*1.0
        // = 0.175 + 0.200 + 0.150 + 0.150 + 0.100 = 0.775
        $this->assertEqualsWithDelta(0.775, $score, 0.0001);
    }

    /** @test */
    public function it_clamps_score_to_zero_minimum(): void
    {
        $signal = [
            'edge_at_signal'       => 0.0,
            'confidence_at_signal' => 0.0,
            'spread'               => 0.99, // heavy penalty
        ];

        $score = $this->service->computeScore($signal);
        $this->assertGreaterThanOrEqual(0.0, $score);
    }

    /** @test */
    public function it_does_not_exceed_one(): void
    {
        $signal = [
            'edge_at_signal'       => 1.0,
            'confidence_at_signal' => 1.0,
            'momentum_24h_percent' => 100.0,
            'liquidity_usd'        => 999_999,
            'volume_24h_usd'       => 999_999,
            'spread'               => 0.0,
        ];

        $score = $this->service->computeScore($signal);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    // =========================================================================
    // rank()
    // =========================================================================

    /** @test */
    public function it_filters_signals_below_minimum_score(): void
    {
        $settings = $this->makeSettings(['minimum_signal_score' => 0.60]);

        $signals = collect([
            $this->makeSignal(edge: 0.1, confidence: 0.1), // score ~0.08 → below threshold
            $this->makeSignal(edge: 0.8, confidence: 0.8), // score ~0.48 → above threshold
        ]);

        $ranked = $this->service->rank($signals, $settings);

        $this->assertCount(1, $ranked);
    }

    /** @test */
    public function it_sorts_signals_descending_by_score(): void
    {
        $settings = $this->makeSettings(['minimum_signal_score' => 0.0]);

        $signals = collect([
            $this->makeSignal(edge: 0.2, confidence: 0.2, id: 1),
            $this->makeSignal(edge: 0.9, confidence: 0.9, id: 2),
            $this->makeSignal(edge: 0.5, confidence: 0.5, id: 3),
        ]);

        $ranked = $this->service->rank($signals, $settings);

        $this->assertSame(2, $ranked->first()['id']);
    }

    /** @test */
    public function it_caps_to_max_signals_per_cycle(): void
    {
        $settings = $this->makeSettings([
            'minimum_signal_score'  => 0.0,
            'enable_top_signal_filter' => true,
            'max_signals_per_cycle' => 2,
        ]);

        $signals = collect([
            $this->makeSignal(edge: 0.9, id: 1),
            $this->makeSignal(edge: 0.8, id: 2),
            $this->makeSignal(edge: 0.7, id: 3),
        ]);

        $ranked = $this->service->rank($signals, $settings);

        $this->assertCount(2, $ranked);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSettings(array $overrides = []): PaperTradeSetting
    {
        return new PaperTradeSetting(array_merge([
            'initial_capital'                => 1000.00,
            'minimum_signal_score'           => 0.70,
            'enable_top_signal_filter'       => true,
            'max_signals_per_cycle'          => 10,
            'max_position_per_market'        => 1,
            'market_cooldown_minutes'        => 60,
            'position_size_mode'             => 'fixed_percent',
            'fixed_percent'                  => 2.0,
            'max_portfolio_exposure_percent' => 50.0,
            'max_concurrent_trades'          => 10,
            'reserve_cash_percent'           => 20.0,
        ], $overrides));
    }

    private function makeSignal(
        float $edge = 0.3,
        float $confidence = 0.5,
        int   $id = 1,
        int   $marketId = 1
    ): array {
        return [
            'id'                    => $id,
            'market_id'             => $marketId,
            'edge_at_signal'        => $edge,
            'confidence_at_signal'  => $confidence,
            'direction'             => 'YES',
            'current_price'         => 0.45,
            'market_probability_at_signal' => 0.45,
        ];
    }
}