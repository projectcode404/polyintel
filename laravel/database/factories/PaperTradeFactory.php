<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Market;
use App\Models\PaperTrade;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaperTradeFactory extends Factory
{
    protected $model = PaperTrade::class;

    public function definition(): array
    {
        $entry    = $this->faker->randomFloat(6, 0.1, 0.9);
        $shares   = $this->faker->randomFloat(6, 10, 200);
        $position = round($entry * $shares, 2);

        return [
            'market_id'                    => Market::factory(),
            'signal_id'                    => null,
            'trading_account_id'           => null,
            'direction'                    => 'YES',
            'entry_price'                  => $entry,
            'exit_price'                   => null,
            'shares'                       => $shares,
            'position_size_usd'            => $position,
            'fees_usd'                     => 0,
            'pnl_usd'                      => 0,
            'roi'                          => 0,
            'current_price'                => $entry,
            'unrealized_pnl_usd'           => 0,
            'max_adverse_excursion'        => null,
            'max_favorable_excursion'      => null,
            'market_probability_at_entry'  => $this->faker->randomFloat(6, 0.1, 0.9),
            'ai_probability_at_entry'      => null,
            'edge_at_entry'                => $this->faker->randomFloat(6, 0.05, 0.30),
            'status'                       => PaperTrade::STATUS_OPEN,
            'outcome'                      => null,
            'holding_period_hours'         => null,
            'notes'                        => null,
            'entered_at'                   => now()->subHours(2),
            'exited_at'                    => null,
            'signal_score'                 => null,
            'position_size_mode'           => 'fixed_percent',
            'take_profit_price'            => null,
            'stop_loss_price'              => null,
            'breakeven_price'              => null,
            'exit_reason'                  => null,
            'smart_exit_reason'            => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'status'    => PaperTrade::STATUS_OPEN,
            'exit_price' => null,
            'exited_at'  => null,
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn () => [
            'status' => PaperTrade::STATUS_PARTIAL,
        ]);
    }

    public function withExitLevels(
        float $entry,
        float $stopLoss,
        float $takeProfit,
        ?float $breakeven = null
    ): static {
        return $this->state(fn () => [
            'entry_price'       => $entry,
            'stop_loss_price'   => $stopLoss,
            'take_profit_price' => $takeProfit,
            'breakeven_price'   => $breakeven,
            'current_price'     => $entry,
            'position_size_usd' => round($entry * 100, 2),
            'shares'            => 100,
        ]);
    }
}
