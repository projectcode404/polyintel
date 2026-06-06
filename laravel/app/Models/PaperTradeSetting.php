<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTradeSetting extends Model
{
    protected $table = 'paper_trade_settings';

    protected $fillable = [
        'initial_capital',
        'max_portfolio_exposure_percent',
        'max_concurrent_trades',
        'reserve_cash_percent',
        'max_position_per_market',
        'market_cooldown_minutes',
        'position_size_mode',
        'fixed_amount',
        'fixed_percent',
        'enable_dynamic_position_size',
        'enable_top_signal_filter',
        'max_signals_per_cycle',
        'minimum_signal_score',
        'enable_take_profit',
        'take_profit_mode',
        'take_profit_r1',
        'take_profit_r2',
        'take_profit_r3',
        'enable_stop_loss',
        'stop_loss_mode',
        'stop_loss_value',
        'enable_move_to_breakeven',
        'breakeven_trigger_r',
        'enable_partial_take_profit',
        'partial_tp1_percent',
        'partial_tp2_percent',
        'partial_tp3_percent',
        'enable_smart_exit',
        'preset',
    ];

    protected $casts = [
        'initial_capital'                 => 'decimal:2',
        'max_portfolio_exposure_percent'  => 'decimal:2',
        'max_concurrent_trades'           => 'integer',
        'reserve_cash_percent'            => 'decimal:2',
        'max_position_per_market'         => 'integer',
        'market_cooldown_minutes'         => 'integer',
        'fixed_amount'                    => 'decimal:2',
        'fixed_percent'                   => 'decimal:2',
        'enable_dynamic_position_size'    => 'boolean',
        'enable_top_signal_filter'        => 'boolean',
        'max_signals_per_cycle'           => 'integer',
        'minimum_signal_score'            => 'decimal:4',
        'enable_take_profit'              => 'boolean',
        'take_profit_r1'                  => 'decimal:2',
        'take_profit_r2'                  => 'decimal:2',
        'take_profit_r3'                  => 'decimal:2',
        'enable_stop_loss'                => 'boolean',
        'stop_loss_value'                 => 'decimal:2',
        'enable_move_to_breakeven'        => 'boolean',
        'breakeven_trigger_r'             => 'decimal:2',
        'enable_partial_take_profit'      => 'boolean',
        'partial_tp1_percent'             => 'decimal:2',
        'partial_tp2_percent'             => 'decimal:2',
        'partial_tp3_percent'             => 'decimal:2',
        'enable_smart_exit'               => 'boolean',
    ];

    /**
     * Always return the single active settings row.
     * Creates with defaults if none exists.
     */
    public static function current(): static
    {
        return static::firstOrCreate(
            [],
            static::defaults()
        );
    }

    /**
     * Apply a named preset and persist.
     */
    public function applyPreset(string $preset): void
    {
        $presets = static::presetValues();

        if (! isset($presets[$preset])) {
            throw new \InvalidArgumentException("Unknown preset: {$preset}");
        }

        $this->fill(array_merge($presets[$preset], ['preset' => $preset]));
        $this->save();
    }

    // -------------------------------------------------------------------------
    // Defaults & Presets
    // -------------------------------------------------------------------------

    public static function defaults(): array
    {
        return static::presetValues()['balanced'];
    }

    public static function presetValues(): array
    {
        return [
            'conservative' => [
                'max_portfolio_exposure_percent' => 30.00,
                'max_concurrent_trades'          => 5,
                'reserve_cash_percent'           => 20.00,
                'position_size_mode'             => 'fixed_percent',
                'fixed_percent'                  => 2.00,
                'enable_top_signal_filter'       => true,
                'max_signals_per_cycle'          => 5,
                'minimum_signal_score'           => 0.75,
                'enable_take_profit'             => true,
                'take_profit_mode'               => 'r_multiple',
                'take_profit_r1'                 => 1.00,
                'take_profit_r2'                 => null,
                'take_profit_r3'                 => null,
                'enable_stop_loss'               => true,
                'stop_loss_mode'                 => 'r_multiple',
                'stop_loss_value'                => 1.00,
                'enable_move_to_breakeven'       => true,
                'breakeven_trigger_r'            => 1.00,
                'enable_partial_take_profit'     => false,
                'enable_smart_exit'              => true,
                'preset'                         => 'conservative',
            ],
            'balanced' => [
                'max_portfolio_exposure_percent' => 50.00,
                'max_concurrent_trades'          => 10,
                'reserve_cash_percent'           => 20.00,
                'position_size_mode'             => 'fixed_percent',
                'fixed_percent'                  => 2.00,
                'enable_top_signal_filter'       => true,
                'max_signals_per_cycle'          => 10,
                'minimum_signal_score'           => 0.70,
                'enable_take_profit'             => true,
                'take_profit_mode'               => 'r_multiple',
                'take_profit_r1'                 => 1.00,
                'take_profit_r2'                 => 2.00,
                'take_profit_r3'                 => null,
                'enable_stop_loss'               => true,
                'stop_loss_mode'                 => 'r_multiple',
                'stop_loss_value'                => 1.00,
                'enable_move_to_breakeven'       => true,
                'breakeven_trigger_r'            => 1.00,
                'enable_partial_take_profit'     => true,
                'partial_tp1_percent'            => 50.00,
                'partial_tp2_percent'            => 30.00,
                'partial_tp3_percent'            => 20.00,
                'enable_smart_exit'              => true,
                'preset'                         => 'balanced',
            ],
            'aggressive' => [
                'max_portfolio_exposure_percent' => 80.00,
                'max_concurrent_trades'          => 20,
                'reserve_cash_percent'           => 10.00,
                'position_size_mode'             => 'dynamic',
                'fixed_percent'                  => 5.00,
                'enable_dynamic_position_size'   => true,
                'enable_top_signal_filter'       => true,
                'max_signals_per_cycle'          => 20,
                'minimum_signal_score'           => 0.65,
                'enable_take_profit'             => true,
                'take_profit_mode'               => 'r_multiple',
                'take_profit_r1'                 => 2.00,
                'take_profit_r2'                 => 4.00,
                'take_profit_r3'                 => null,
                'enable_stop_loss'               => true,
                'stop_loss_mode'                 => 'r_multiple',
                'stop_loss_value'                => 1.00,
                'enable_move_to_breakeven'       => true,
                'breakeven_trigger_r'            => 1.00,
                'enable_partial_take_profit'     => true,
                'partial_tp1_percent'            => 50.00,
                'partial_tp2_percent'            => 30.00,
                'partial_tp3_percent'            => 20.00,
                'enable_smart_exit'              => true,
                'preset'                         => 'aggressive',
            ],
        ];
    }
}
