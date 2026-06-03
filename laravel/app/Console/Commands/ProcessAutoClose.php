<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaperTrade;
use App\Services\PaperTradingService;
use Illuminate\Console\Command;

class ProcessAutoClose extends Command
{
    protected $signature = 'trade:auto-close';
    protected $description = 'Automatically close paper trades for resolved markets';

    public function handle(PaperTradingService $tradingService): int
    {
        $this->info('Checking for trades to auto-close...');

        // Update unrealized PnL for all open trades first
        $tradingService->updateUnrealizedPnl();
        $this->info('Unrealized PnL updated.');

        // Find open trades whose market has an outcome
        $openTrades = PaperTrade::where('status', 'open')
            ->whereHas('market', function ($query) {
                $query->where('status', 'resolved')
                      ->has('outcome');
            })
            ->with(['market.outcome', 'tradingAccount'])
            ->get();

        if ($openTrades->isEmpty()) {
            $this->info('No trades ready for auto-close.');
            return self::SUCCESS;
        }

        $closedCount = 0;
        foreach ($openTrades as $trade) {
            // Only auto close if the account has auto-close enabled
            if (!$trade->tradingAccount->is_auto_close) {
                continue;
            }

            try {
                $outcome = $trade->market->outcome;
                
                if ($outcome->was_cancelled()) {
                    $exitPrice = (float) $trade->entry_price; // Return original price
                    $tradeOutcome = 'cancelled';
                } else {
                    $resolvedYes = $outcome->resolved_yes();
                    // If we bet YES and it resolved YES -> exit price = 1.0
                    // If we bet NO and it resolved NO -> exit price = 1.0 (since we bought NO tokens)
                    // If we bet YES and it resolved NO -> exit price = 0.0
                    // If we bet NO and it resolved YES -> exit price = 0.0
                    
                    if ($trade->direction === 'yes') {
                        $exitPrice = $resolvedYes ? 1.0 : 0.0;
                    } else {
                        $exitPrice = $resolvedYes ? 0.0 : 1.0;
                    }
                    $tradeOutcome = null; // Let service determine win/loss based on net PnL
                }

                $closed = $tradingService->closeTrade($trade, $exitPrice, $tradeOutcome);
                $this->line("Auto-closed trade {$closed->id}. PnL: {$closed->pnl_usd}");
                $closedCount++;
                
            } catch (\Exception $e) {
                $this->error("Failed to auto-close trade {$trade->id}: " . $e->getMessage());
            }
        }

        $this->info("Completed. Auto-closed {$closedCount} trades.");
        return self::SUCCESS;
    }
}
