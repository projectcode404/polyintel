<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaperTradeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth middleware handles access
    }

    public function rules(): array
    {
        return [
            // Section 1 — General
            'preset'                         => ['required', 'in:conservative,balanced,aggressive,custom'],
            'initial_capital'                => ['required', 'numeric', 'min:10', 'max:1000000'],

            // Section 2 — Portfolio
            'max_portfolio_exposure_percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'max_concurrent_trades'          => ['required', 'integer', 'min:1', 'max:100'],
            'reserve_cash_percent'           => ['required', 'numeric', 'min:0', 'max:90'],
            'max_position_per_market'        => ['required', 'integer', 'min:1', 'max:10'],
            'market_cooldown_minutes'        => ['required', 'integer', 'min:0', 'max:10080'],

            // Section 3 — Position Sizing
            'position_size_mode'             => ['required', 'in:fixed_amount,fixed_percent,dynamic'],
            'fixed_amount'                   => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'fixed_percent'                  => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'enable_dynamic_position_size'   => ['boolean'],

            // Section 4 — Signal Filters
            'enable_top_signal_filter'       => ['boolean'],
            'max_signals_per_cycle'          => ['required', 'integer', 'min:1', 'max:100'],
            'minimum_signal_score'           => ['required', 'numeric', 'min:0', 'max:1'],

            // Section 5 — Take Profit
            'enable_take_profit'             => ['boolean'],
            'take_profit_mode'               => ['required', 'in:fixed_percent,r_multiple'],
            'take_profit_r1'                 => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'take_profit_r2'                 => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'take_profit_r3'                 => ['nullable', 'numeric', 'min:0.1', 'max:100'],

            // Section 6 — Stop Loss
            'enable_stop_loss'               => ['boolean'],
            'stop_loss_mode'                 => ['required', 'in:fixed_percent,r_multiple'],
            'stop_loss_value'                => ['required', 'numeric', 'min:0.1', 'max:100'],

            // Section 7 — Breakeven
            'enable_move_to_breakeven'       => ['boolean'],
            'breakeven_trigger_r'            => ['required', 'numeric', 'min:0.1', 'max:100'],

            // Section 8 — Partial Take Profit
            'enable_partial_take_profit'     => ['boolean'],
            'partial_tp1_percent'            => ['nullable', 'numeric', 'min:1', 'max:100'],
            'partial_tp2_percent'            => ['nullable', 'numeric', 'min:1', 'max:100'],
            'partial_tp3_percent'            => ['nullable', 'numeric', 'min:1', 'max:100'],

            // Section 9 — Smart Exit
            'enable_smart_exit'              => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'preset.required'                         => 'Please select a preset.',
            'preset.in'                               => 'Invalid preset value.',
            'initial_capital.min'                     => 'Initial capital must be at least $10.',
            'max_portfolio_exposure_percent.max'      => 'Portfolio exposure cannot exceed 100%.',
            'reserve_cash_percent.max'                => 'Reserve cash cannot exceed 90%.',
            'minimum_signal_score.min'                => 'Minimum signal score must be between 0 and 1.',
            'minimum_signal_score.max'                => 'Minimum signal score must be between 0 and 1.',
            'position_size_mode.in'                   => 'Invalid position size mode.',
            'take_profit_mode.in'                     => 'Invalid take profit mode.',
            'stop_loss_mode.in'                       => 'Invalid stop loss mode.',
        ];
    }

    /**
     * Normalize checkbox booleans — unchecked boxes are absent from POST.
     */
    protected function prepareForValidation(): void
    {
        $boolFields = [
            'enable_dynamic_position_size',
            'enable_top_signal_filter',
            'enable_take_profit',
            'enable_stop_loss',
            'enable_move_to_breakeven',
            'enable_partial_take_profit',
            'enable_smart_exit',
        ];

        $merged = [];
        foreach ($boolFields as $field) {
            $merged[$field] = $this->has($field) ? 1 : 0;
        }

        $this->merge($merged);
    }
}
