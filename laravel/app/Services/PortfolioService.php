<?php

declare(strict_types=1);

namespace App\Services;

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
        return TradingAccount::firstOrCreate(
            ['user_id' => $user->id],
            [
                'balance' => 1000.0,
                'is_auto_trade' => false,
                'is_auto_close' => false,
            ]
        );
    }

    /**
     * Update trading account settings
     */
    public function updateSettings(TradingAccount $account, array $data): TradingAccount
    {
        $account->update([
            'is_auto_trade' => $data['is_auto_trade'] ?? $account->is_auto_trade,
            'is_auto_close' => $data['is_auto_close'] ?? $account->is_auto_close,
        ]);
        return $account;
    }

    /**
     * Get the trading fee percentage configured in the system.
     */
    public function getTradingFeePercentage(): float
    {
        return SystemSetting::get('trading_fee_percentage', 0.2) / 100.0;
    }
}
