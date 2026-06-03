<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TradingAccount;
use App\Services\PaperTradingService;
use App\Services\SignalService;
use Illuminate\Console\Command;

class ProcessSignals extends Command
{
    protected $signature = 'trade:process-signals';
    protected $description = 'Process pending signals and execute auto trades';

    public function handle(SignalService $signalService, PaperTradingService $tradingService): int
    {
        $this->info('Starting signal processing...');

        // 1. Expire old signals
        $expired = $signalService->expireOldSignals();
        if ($expired > 0) {
            $this->info("Expired {$expired} old signals.");
        }

        // 2. Get active pending signals
        $signals = $signalService->getPendingSignals();
        if ($signals->isEmpty()) {
            $this->info('No pending signals to process.');
            return self::SUCCESS;
        }

        // 3. Get all accounts with auto-trade enabled
        $autoTradeAccounts = TradingAccount::where('is_auto_trade', true)->get();
        if ($autoTradeAccounts->isEmpty()) {
            $this->info('No accounts have auto-trade enabled. Skipping execution.');
            return self::SUCCESS;
        }

        // 4. Process each signal for each auto-trade account
        $executedCount = 0;
        foreach ($signals as $signal) {
            foreach ($autoTradeAccounts as $account) {
                try {
                    $trade = $tradingService->openTrade($signal, $account);
                    if ($trade) {
                        $this->line("Executed trade {$trade->id} for account {$account->id} on signal {$signal->id}");
                        $executedCount++;
                    }
                } catch (\Exception $e) {
                    $this->error("Failed to execute trade for signal {$signal->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Completed. Executed {$executedCount} trades.");
        return self::SUCCESS;
    }
}
