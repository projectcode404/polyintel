<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\PaperTradeSetting;
use App\Models\TradingAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PortfolioManagerService
 *
 * Orchestrates the full signal-to-trade pipeline for one processing cycle.
 *
 * Workflow per cycle:
 *   1. Load settings
 *   2. Calculate portfolio state (PortfolioMetricsService)
 *   3. Score + rank signals (SignalRankerService)
 *   4. Apply market constraints (cooldown + duplicate check)
 *   5. For each eligible signal, attempt to open trade:
 *      a. Check max concurrent trades
 *      b. Calculate position size (PositionSizerService)
 *      c. Check exposure limit
 *      d. Check reserve cash floor
 *      e. Validate entry price
 *      f. Calculate TP / SL / breakeven (ExitStrategyService)
 *      g. Open PaperTrade in DB transaction
 *      h. Write OPENED event to PaperTradeHistory
 *   6. Return summary
 */
final class PortfolioManagerService
{
    public function __construct(
        private readonly PortfolioMetricsService $metricsService,
        private readonly SignalRankerService      $rankerService,
        private readonly PositionSizerService     $sizerService,
        private readonly ExitStrategyService      $exitStrategyService,
    ) {}

    // =========================================================================
    // Main Entry Point
    // =========================================================================

    /**
     * Process a collection of pending signals and open qualifying paper trades.
     *
     * Called by ProcessSignalCycleJob.
     *
     * @param  Collection  $signals  Signal Eloquent models or arrays
     * @return array{opened: int, skipped: int, reasons: array<int, array{signal_id: int|null, reason: string}>}
     */
    public function processCycle(Collection $signals, TradingAccount $account): array
    {
        $settings = $account->settings ?? PaperTradeSetting::current();
        $state    = $this->metricsService->getPortfolioState($settings, $account->id);
        $results  = ['opened' => 0, 'skipped' => 0, 'reasons' => []];

        Log::info('[PortfolioManager] Cycle started', [
            'raw_signals'    => $signals->count(),
            'current_equity' => $state['current_equity'],
            'exposure_pct'   => $state['exposure_percent'],
            'open_trades'    => $state['open_trades_count'],
        ]);

        // Step 1: Score + rank signals
        $ranked = $this->rankerService->rank($signals, $settings);

        Log::info('[PortfolioManager] After ranking', [
            'ranked_count' => $ranked->count(),
        ]);

        // Step 2: Apply market constraints (cooldown + duplicate market check)
        $eligible = $this->rankerService->applyMarketConstraints($ranked, $settings, $account);

        Log::info('[PortfolioManager] After market constraints', [
            'eligible_count' => $eligible->count(),
        ]);

        // Step 3: Pre-fetch market end_dates to avoid N+1 in guard #6
        $marketIds  = $eligible->pluck('market_id')->filter()->unique()->values()->all();
        $marketEndDates = \App\Models\Market::whereIn('id', $marketIds)
            ->pluck('end_date', 'id');

        // Step 4: Attempt to open each eligible signal in score order
        foreach ($eligible as $signal) {
            $outcome = $this->attemptOpen($signal, $settings, $state, $account, $marketEndDates);

            if ($outcome['opened']) {
                $results['opened']++;
                // Recalculate state so subsequent signals see updated exposure
                $state = $this->metricsService->getPortfolioState($settings, $account->id);
            } else {
                $results['skipped']++;
                $results['reasons'][] = [
                    'signal_id' => $signal['id'] ?? null,
                    'reason'    => $outcome['reason'],
                ];
            }
        }

        Log::info('[PortfolioManager] Cycle complete', [
            'opened'  => $results['opened'],
            'skipped' => $results['skipped'],
        ]);

        return $results;
    }

    // =========================================================================
    // Single Signal Attempt
    // =========================================================================

    /**
     * Attempt to open one paper trade for a signal.
     * All portfolio constraint checks happen here in order.
     *
     * @return array{opened: bool, reason: string|null}
     */
    private function attemptOpen(array $signal, PaperTradeSetting $settings, array $state, TradingAccount $account, \Illuminate\Support\Collection $marketEndDates = new \Illuminate\Support\Collection): array
    {
        // Guard 1: max concurrent trades
        if ($state['open_trades_count'] >= (int) $settings->max_concurrent_trades) {
            return $this->skip(
                "Max concurrent trades reached ({$settings->max_concurrent_trades})"
            );
        }

        // Guard 2: calculate position size (may return null = skip)
        $positionSize = $this->sizerService->calculate(
            $settings,
            $state['current_equity'],
            (float) ($signal['score'] ?? 0)
        );

        if ($positionSize === null || $positionSize <= 0) {
            return $this->skip('Position size is zero or below dynamic threshold');
        }

        // Guard 3: portfolio exposure limit
        $newAllocated = $state['allocated_capital'] + $positionSize;
        $newExposure  = $state['current_equity'] > 0
            ? ($newAllocated / $state['current_equity']) * 100
            : 0.0;

        if ($newExposure > (float) $settings->max_portfolio_exposure_percent) {
            return $this->skip(sprintf(
                'Exposure limit: %.2f%% would exceed %.2f%%',
                $newExposure,
                (float) $settings->max_portfolio_exposure_percent
            ));
        }

        // Guard 4: reserve cash floor
        $reserveRequired = (float) $settings->initial_capital
            * ((float) $settings->reserve_cash_percent / 100);
        $cashAfterTrade = $state['cash_balance'] - $positionSize;

        if ($cashAfterTrade < $reserveRequired) {
            return $this->skip(
                'Reserve cash breach: $' . number_format($reserveRequired, 2) . ' required'
            );
        }

        // Guard 5: valid entry price
        $entryPrice = (float) ($signal['current_price'] ?? $signal['entry_price'] ?? 0);

        if ($entryPrice <= 0) {
            return $this->skip('Invalid entry price: ' . $entryPrice);
        }

        // Guard 6: skip market expiring within 6 hours
        // Prevents opening a trade that SmartExit will immediately close
        $endDateRaw = $marketEndDates->get($signal['market_id'] ?? null);
        if ($endDateRaw) {
            $endDate = \Carbon\Carbon::parse($endDateRaw);
            $hoursRemaining = now()->diffInHours($endDate, absolute: false);
            if ($endDate->isPast() || $hoursRemaining < 6) {
                return $this->skip(
                    'Market expires within 6 hours — skipping to avoid immediate SmartExit'
                );
            }
        }

        // Calculate exit levels and shares
        $exitLevels = $this->exitStrategyService->calculateExitLevels($settings, $entryPrice);
        $shares     = $this->sizerService->toShares($positionSize, $entryPrice);

        // Open trade in atomic transaction
        try {
            DB::transaction(function () use ($signal, $settings, $entryPrice, $positionSize, $shares, $exitLevels, $account) {
                TradingAccount::where('id', $account->id)
                    ->decrement('balance', $positionSize);

                $trade = PaperTrade::create([
                    'trading_account_id'           => $account->id,
                    'market_id'                    => $signal['market_id'],
                    'signal_id'                    => $signal['id'] ?? null,
                    'direction'                    => $signal['direction'] ?? 'YES',
                    'entry_price'                  => $entryPrice,
                    'shares'                       => $shares,
                    'position_size_usd'            => $positionSize,
                    'fees_usd'                     => 0,
                    'pnl_usd'                      => 0,
                    'roi'                          => 0,
                    'current_price'                => $entryPrice,
                    'unrealized_pnl_usd'           => 0,
                    'market_probability_at_entry'  => $signal['market_probability_at_signal'] ?? $signal['market_probability'] ?? 0,
                    'ai_probability_at_entry'      => $signal['ai_probability_at_signal']     ?? $signal['ai_probability']     ?? null,
                    'edge_at_entry'                => $signal['edge_at_signal']               ?? $signal['edge']               ?? 0,
                    'signal_score'                 => $signal['score']                        ?? null,
                    'position_size_mode'           => $settings->position_size_mode,
                    'take_profit_price'            => $exitLevels['take_profit_price'],
                    'stop_loss_price'              => $exitLevels['stop_loss_price'],
                    'breakeven_price'              => $exitLevels['breakeven_price'],
                    'status'                       => PaperTrade::STATUS_OPEN,
                    'entered_at'                   => now(),
                ]);

                PaperTradeHistory::create([
                    'paper_trade_id'  => $trade->id,
                    'event_type'      => PaperTradeHistory::EVENT_OPENED,
                    'price_at_event'  => $entryPrice,
                    'shares_affected' => $shares,
                    'pnl_realized'    => 0,
                    'reason'          => sprintf(
                        'Signal score: %.4f. Position size: $%s. Mode: %s. Edge: %.4f.',
                        (float) ($signal['score'] ?? 0),
                        number_format($positionSize, 2),
                        $settings->position_size_mode,
                        (float) ($signal['edge_at_signal'] ?? $signal['edge'] ?? 0)
                    ),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('[PortfolioManager] Failed to open trade', [
                'signal_id' => $signal['id'] ?? null,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->skip('DB error: ' . $e->getMessage());
        }

        return ['opened' => true, 'reason' => null];
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function skip(string $reason): array
    {
        Log::debug('[PortfolioManager] Signal skipped', ['reason' => $reason]);

        return ['opened' => false, 'reason' => $reason];
    }
}