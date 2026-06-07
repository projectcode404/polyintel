<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Market;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MarketFactory extends Factory
{
    protected $model = Market::class;

    public function definition(): array
    {
        $prob = $this->faker->randomFloat(6, 0.1, 0.9);

        return [
            'condition_id'       => '0x' . Str::random(62),
            'slug'               => $this->faker->slug(4),
            'question'           => $this->faker->sentence(8) . '?',
            'description'        => $this->faker->paragraph(),
            'category'           => 'crypto',
            'sub_category'       => null,
            'tags'               => null,
            'resolution_source'  => null,
            'start_date'         => now()->subDays(7),
            'end_date'           => now()->addDays(30),
            'resolved_at'        => null,
            'status'             => 'active',
            'market_probability' => $prob,
            'volume_usd'         => $this->faker->randomFloat(2, 1000, 500000),
            'liquidity_usd'      => $this->faker->randomFloat(2, 5000, 200000),
            'num_traders'        => $this->faker->numberBetween(10, 5000),
            'ai_probability'     => null,
            'edge'               => null,
            'last_synced_at'     => now(),
            'is_tracked'         => true,
            'volume_24h_usd'     => $this->faker->randomFloat(2, 100, 50000),
            'best_bid'           => round($prob - 0.01, 6),
            'best_ask'           => round($prob + 0.01, 6),
            'spread'             => 0.02,
            'price_change_1h'    => $this->faker->randomFloat(6, -0.05, 0.05),
            'price_change_1d'    => $this->faker->randomFloat(6, -0.15, 0.15),
        ];
    }

    public function expiringSoon(): static
    {
        return $this->state(fn () => [
            'end_date' => now()->addHours(3),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'end_date'    => now()->subHours(1),
            'resolved_at' => now()->subHours(1),
            'status'      => 'resolved',
        ]);
    }
}
