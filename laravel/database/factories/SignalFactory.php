<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Market;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

class SignalFactory extends Factory
{
    protected $model = Signal::class;

    public function definition(): array
    {
        return [
            'market_id'                    => Market::factory(),
            'ai_prediction_id'             => null,
            'direction'                    => $this->faker->randomElement(['yes', 'no']),
            'market_probability_at_signal' => $this->faker->randomFloat(6, 0.1, 0.9),
            'ai_probability_at_signal'     => null,
            'edge_at_signal'               => $this->faker->randomFloat(6, 0.05, 0.40),
            'confidence_at_signal'         => $this->faker->randomFloat(6, 0.5, 0.95),
            'min_edge_threshold'           => 0.05,
            'trigger_source'               => 'rule_edge_threshold',
            'status'                       => 'pending',
            'notes'                        => null,
            'fired_at'                     => now(),
            'expires_at'                   => now()->addDay(),
            'snapshot_data'                => [
                'price_entry' => $this->faker->randomFloat(6, 0.1, 0.9),
            ],
            'resolved_outcome'             => null,
            'is_correct'                   => null,
            'realized_roi'                 => null,
            'resolved_at'                  => null,
            'momentum_24h_percent'         => null,
            'liquidity_usd'                => null,
            'volume_24h_usd'               => null,
            'spread'                       => null,
        ];
    }

    public function withMomentumReversal(): static
    {
        return $this->state(fn () => [
            'momentum_24h_percent' => -15.0,
        ]);
    }

    public function withLowLiquidity(float $entryLiquidity = 100000): static
    {
        return $this->state(fn () => [
            'liquidity_usd' => $entryLiquidity * 0.30, // 30% of entry = below 50% threshold
        ]);
    }

    public function withWideSpread(float $entrySpread = 0.02): static
    {
        return $this->state(fn () => [
            'spread' => $entrySpread * 3, // 3x entry spread = above 2x threshold
        ]);
    }
}
