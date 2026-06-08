<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaperTradeSetting;
use App\Models\SystemSetting;
use App\Models\TradingAccount;
use App\Models\User;

final class PortfolioService
{
    /**
     * Get or create a trading account for a user.
     * Default starting balance is $1000.
     */
    public function getAccountForUser(User $user): TradingAccount
    {
        $account = TradingAccount::firstOrCreate(
            ['user_id' => $user->id],
            [
                'balance'       => 1000.0,
                'is_auto_trade' => false,
                'is_auto_close' => false,
            ]
        );

        if (! $account->paper_trade_setting_id) {
            $settings = PaperTradeSetting::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'initial_capital'                => 1000.0,
                    'max_portfolio_exposure_percent' => 80.0,
                    'max_concurrent_trades'          => 20,
                    'reserve_cash_percent'           => 20.0,
                    'max_position_per_market'        => 1,
                    'market_cooldown_minutes'        => 60,
                    'position_size_mode'             => 'fixed_percent',
                    'fixed_percent'                  => 5.0,
                    'enable_dynamic_position_size'   => false,
                    'enable_top_signal_filter'       => true,
                    'max_signals_per_cycle'          => 10,
                    'minimum_signal_score'           => 0.2,
                    'enable_take_profit'             => true,
                    'take_profit_mode'               => 'r_multiple',
                    'take_profit_r1'                 => 1.0,
                    'enable_stop_loss'               => true,
                    'stop_loss_mode'                 => 'r_multiple',
                    'stop_loss_value'                => 1.0,
                    'enable_move_to_breakeven'       => true,
                    'breakeven_trigger_r'            => 1.0,
                    'enable_partial_take_profit'     => false,
                    'partial_tp1_percent'            => 50.0,
                    'partial_tp2_percent'            => 30.0,
                    'partial_tp3_percent'            => 20.0,
                    'enable_smart_exit'              => true,
                    'preset'                         => 'balanced',
                ]
            );
            $account->update(['paper_trade_setting_id' => $settings->id]);
        }

        return $account->refresh();
    }

    /**
     * Update trading account settings.
     * Expects explicit boolean values for both flags (caller must send 0/1 for OFF/ON).
     */
    public function updateSettings(TradingAccount $account, array $data): TradingAccount
    {
        $account->update([
            'is_auto_trade' => (bool) $data['is_auto_trade'],
            'is_auto_close' => (bool) $data['is_auto_close'],
        ]);

        return $account->refresh();
    }

    /**
     * Get the trading fee percentage configured in the system.
     */
    public function getTradingFeePercentage(): float
    {
        return SystemSetting::get('trading_fee_percentage', 0.2) / 100.0;
    }
}
