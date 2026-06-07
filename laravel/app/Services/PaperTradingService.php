<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaperTrade;
use App\Models\Signal;
use App\Models\TradingAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PaperTradingService
{
    public function __construct(
        private readonly PortfolioService $portfolioService,
        private readonly SignalService $signalService
    ) {}

    /**
     * Execute a paper trade based on a signal.
     */
    public function openTrade(Signal $signal, TradingAccount $account): ?PaperTrade
    {
        if ($signal->status !== 'pending' || $signal->isExpired()) {
            return null;
        }

        // We need the market's current probability.
        // If the signal was just fired, we use edge_at_signal context, but strictly we should 
        // use the latest market snapshot or the market's current probability.
        $entryPrice = $signal->direction === 'yes' 
            ? (float) $signal->market->market_probability 
            : 1.0 - (float) $signal->market->market_probability;
            
        if ($entryPrice <= 0 || $entryPrice >= 1) {
            Log::warning("Cannot open trade for signal {$signal->id}: invalid entry price {$entryPrice}");
            return null;
        }

        return DB::transaction(function () use ($signal, $account, $entryPrice) {
            // Re-fetch account with lock to prevent race conditions
            $account = TradingAccount::where('id', $account->id)->lockForUpdate()->first();
            
            // Calculate position size: 2% of remaining balance
            $positionSizeUsd = $account->balance * 0.02;
            
            if ($positionSizeUsd < 1.0) {
                Log::warning("Account {$account->id} balance too low to open trade.");
                return null;
            }
            
            // Deduct balance
            $account->decrement('balance', $positionSizeUsd);
            
            // Calculate shares
            $shares = $positionSizeUsd / $entryPrice;
            
            // Calculate fees (simulated maker/taker fee)
            $feeRate = $this->portfolioService->getTradingFeePercentage();
            $feesUsd = $positionSizeUsd * $feeRate;

            $trade = PaperTrade::create([
                'trading_account_id'          => $account->id,
                'market_id'                   => $signal->market_id,
                'signal_id'                   => $signal->id,
                'direction'                   => $signal->direction,
                'entry_price'                 => $entryPrice,
                'shares'                      => $shares,
                'position_size_usd'           => $positionSizeUsd,
                'fees_usd'                    => $feesUsd,
                'market_probability_at_entry' => $signal->market_probability_at_signal,
                'ai_probability_at_entry'     => $signal->ai_probability_at_signal,
                'edge_at_entry'               => $signal->edge_at_signal,
                'status'                      => PaperTrade::STATUS_OPEN,
                'entered_at'                  => now(),
            ]);

            // Mark signal as active
            $this->signalService->markAsActive($signal);

            return $trade;
        });
    }

    /**
     * Close a paper trade, calculate PnL, and return capital to balance.
     */
    public function closeTrade(PaperTrade $trade, float $exitPrice, string $outcome = null): PaperTrade
    {
        if (! $trade->isOpen()) {
            return $trade;
        }

        return DB::transaction(function () use ($trade, $exitPrice, $outcome) {
            $trade = PaperTrade::where('id', $trade->id)->lockForUpdate()->first();
            
            if (! $trade->isOpen()) {
                return $trade;
            }

            // Calculate PnL
            // PnL = (exit_price - entry_price) * shares - fees
            $grossPnl = ($exitPrice - (float) $trade->entry_price) * (float) $trade->shares;
            
            // Add exit fee
            $feeRate = $this->portfolioService->getTradingFeePercentage();
            $exitFeeUsd = ((float) $trade->shares * $exitPrice) * $feeRate;
            $totalFees = (float) $trade->fees_usd + $exitFeeUsd;
            
            $netPnlUsd = $grossPnl - $totalFees;
            
            // ROI
            $roi = $netPnlUsd / (float) $trade->position_size_usd;
            
            // Holding period
            $holdingPeriodHours = now()->diffInSeconds($trade->entered_at) / 3600.0;
            
            // Auto determine outcome if not provided
            if (!$outcome) {
                if ($netPnlUsd > 0) $outcome = 'win';
                elseif ($netPnlUsd < 0) $outcome = 'loss';
                else $outcome = 'breakeven';
            }

            $trade->update([
                'exit_price'           => $exitPrice,
                'fees_usd'             => $totalFees,
                'pnl_usd'              => $netPnlUsd,
                'roi'                  => $roi,
                'status'               => PaperTrade::STATUS_CLOSED,
                'outcome'              => $outcome,
                'holding_period_hours' => $holdingPeriodHours,
                'exited_at'            => now(),
            ]);

            // Return capital + PnL to balance
            $returnedAmount = (float) $trade->position_size_usd + $netPnlUsd;
            TradingAccount::where('id', $trade->trading_account_id)->increment('balance', $returnedAmount);

            return $trade;
        });
    }

    /**
     * Update mark-to-market metrics for all open trades.
     */
    public function updateUnrealizedPnl(): void
    {
        $openTrades = PaperTrade::open()->with('market')->get();
        
        foreach ($openTrades as $trade) {
            $market = $trade->market;
            $currentPrice = $trade->direction === 'yes' 
                ? (float) $market->market_probability 
                : 1.0 - (float) $market->market_probability;
                
            $unrealizedGross = ($currentPrice - (float) $trade->entry_price) * (float) $trade->shares;
            
            // Estimate exit fee
            $feeRate = $this->portfolioService->getTradingFeePercentage();
            $estimatedExitFee = ((float) $trade->shares * $currentPrice) * $feeRate;
            
            $unrealizedNet = $unrealizedGross - (float) $trade->fees_usd - $estimatedExitFee;
            
            $trade->current_price = $currentPrice;
            $trade->unrealized_pnl_usd = $unrealizedNet;
            
            // Update excursions
            $mae = $trade->max_adverse_excursion ?? 0;
            $mfe = $trade->max_favorable_excursion ?? 0;
            
            $priceDiff = $currentPrice - (float) $trade->entry_price;
            if ($priceDiff < $mae) {
                $trade->max_adverse_excursion = $priceDiff;
            }
            if ($priceDiff > $mfe) {
                $trade->max_favorable_excursion = $priceDiff;
            }
            
            $trade->save();
        }
    }
}
