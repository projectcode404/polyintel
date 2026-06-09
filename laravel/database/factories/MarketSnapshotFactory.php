<?php

namespace Database\Factories;

use App\Models\Market;
use App\Models\MarketSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketSnapshotFactory extends Factory
{
    protected $model = MarketSnapshot::class;

    public function definition(): array
    {
        $probYes = $this->faker->randomFloat(2, 0.05, 0.95);

        return [
            'market_id'        => Market::factory(),
            'probability_yes'  => $probYes,
            'probability_no'   => round(1 - $probYes, 2),
            'best_bid'         => $this->faker->randomFloat(3, 0.01, 0.95),
            'best_ask'         => $this->faker->randomFloat(3, 0.05, 0.99),
            'spread'           => $this->faker->randomFloat(4, 0.001, 0.05),
            'volume_usd'       => $this->faker->randomFloat(2, 100, 100000),
            'volume_24h_usd'   => $this->faker->randomFloat(2, 1000, 500000),
            'liquidity_usd'    => $this->faker->randomFloat(2, 500, 200000),
            'btc_price_usd'    => $this->faker->randomFloat(2, 20000, 80000),
            'eth_price_usd'    => $this->faker->randomFloat(2, 1000, 5000),
            'fear_greed_index' => $this->faker->numberBetween(0, 100),
            'btc_dominance'    => $this->faker->randomFloat(2, 30, 70),
            'collector_version'=> '1.0.0',
            'snapshotted_at'   => now(),
        ];
    }
}
