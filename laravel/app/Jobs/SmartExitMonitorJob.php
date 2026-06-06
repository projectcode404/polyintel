<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\PaperTradeSetting;
use App\Services\PaperTrading\SmartExitDecision;
use App\Services\PaperTrading\SmartExitEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SmartExitMonitorJob
 *
 * Runs every minute via scheduler.
 * Monitors all OPEN and PARTIAL trades and executes exit actions.
 *
 * Per trade priority:
 *   1. Stop Loss   → FULL_EXIT  → status: STOPPED
 *   2. TP1         → PARTIAL    → status: PARTIAL + TP1 event
 *   3. TP2         → PARTIAL    → status: PARTIAL + TP2 event
 *   4. Breakeven   → UPDATE SL  → BREAKEVEN_MOVED event
 *   5. Smart Exit  → PARTIAL or FULL → SMART_EXIT event
 *
 * Never executes multiple actions per trade per cycle.
 * Never skips history recording.
 */
final class SmartExitMonitorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        private readonly SmartExitEngineService $engine = new SmartExitEngineService()
    ) {}

    // =========================================================================
    // Handle
    // =========================================================================

    public function handle(SmartExitEngineService $engine): void
    {
        $trades = PaperTrade::whereIn('status', PaperTrade::OPEN_STATUSES)
            ->with(['signal', 'market', 'history'])
            ->get();

        if ($trades->isEmpty()) {
            Log::info('[SmartExitMonitor] No open trades to monitor.');
            return;
        }

        Log::info('[SmartExitMonitor] Monitoring trades', ['count' => $trades->count()]);

        $stats = ['acted' => 0, 'skipped' => 0];

        foreach ($trades as $trade) {
            try {
                $acted = $this->processTrade($trade, $engine);
                $acted ? $stats['acted']++ : $stats['skipped']++;
            } catch (\Throwable $e) {
                Log::error('[SmartExitMonitor] Error processing trade', [
                    'trade_id' => $trade->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SmartExitMonitor] Cycle complete', $stats);
    }

    // =========================================================================
    // Per-Trade Processing
    // =========================================================================

    private function processTrade(PaperTrade $trade, SmartExitEngineService $engine): bool
    {
        // Step 1: Get current price
        $currentPrice = $this->getCurrentPrice($trade);

        if ($currentPrice <= 0) {
            Log::debug('[SmartExitMonitor] Skipping trade — no current price', [
                'trade_id' => $trade->id,
            ]);
            return false;
        }

        // Step 2: Update current_price and unrealized PnL on trade
        $this->updateCurrentPrice($trade, $currentPrice);

        // Step 3: Evaluate exit conditions
        $decision = $engine->evaluate($trade, $currentPrice);

        if ($decision->isNoAction()) {
            return false;
        }

        // Step 4: Execute decision
        Log::info('[SmartExitMonitor] Executing exit decision', [
            'trade_id' => $trade->id,
            'action'   => $decision->action,
            'reason'   => $decision->reason,
            'price'    => $currentPrice,
        ]);

        $this->executeDecision($trade, $decision, $currentPrice, $engine);

        return true;
    }

    // =========================================================================
    // Decision Execution
    // =========================================================================

    private function executeDecision(
        PaperTrade $trade,
        SmartExitDecision $decision,
        float $currentPrice,
        SmartExitEngineService $engine
    ): void {
        match ($decision->action) {
            SmartExitDecision::FULL_EXIT         => $this->executeFullExit($trade, $currentPrice, $decision->reason),
            SmartExitDecision::PARTIAL_EXIT_50   => $this->executePartialExit($trade, $currentPrice, $decision->reason, $engine),
            SmartExitDecision::MOVE_TO_BREAKEVEN => $this->executeMoveToBreakeven($trade, $currentPrice, $decision->reason),
            default                              => null,
        };
    }

    // -------------------------------------------------------------------------

    private function executeFullExit(PaperTrade $trade, float $currentPrice, string $reason): void
    {
        DB::transaction(function () use ($trade, $currentPrice, $reason) {
            $sharesRemaining = $trade->sharesRemaining();
            $pnl             = $this->calcPnl($trade, $currentPrice, $sharesRemaining);
            $isStopLoss      = str_contains(strtolower($reason), 'stop loss');
            $isSmart         = str_contains(strtolower($reason), 'momentum')
                || str_contains(strtolower($reason), 'liquidity')
                || str_contains(strtolower($reason), 'spread')
                || str_contains(strtolower($reason), 'expiry')
                || str_contains(strtolower($reason), 'signal reversal');

            $eventType  = $isStopLoss ? PaperTradeHistory::EVENT_STOP_LOSS
                        : ($isSmart   ? PaperTradeHistory::EVENT_SMART_EXIT
                                      : PaperTradeHistory::EVENT_CLOSED);

            $newStatus  = $isStopLoss ? PaperTrade::STATUS_STOPPED
                        : ($isSmart   ? PaperTrade::STATUS_SMART_EXIT
                                      : PaperTrade::STATUS_CLOSED);

            PaperTradeHistory::create([
                'paper_trade_id'  => $trade->id,
                'event_type'      => $eventType,
                'price_at_event'  => $currentPrice,
                'shares_affected' => $sharesRemaining,
                'pnl_realized'    => $pnl,
                'reason'          => $reason,
            ]);

            $totalPnl = $this->getTotalRealizedPnl($trade) + $pnl;
            $roi      = (float) $trade->position_size_usd > 0
                ? $totalPnl / (float) $trade->position_size_usd
                : 0;

            $trade->update([
                'exit_price'          => $currentPrice,
                'pnl_usd'             => $totalPnl,
                'roi'                 => $roi,
                'status'              => $newStatus,
                'exit_reason'         => $eventType,
                'smart_exit_reason'   => $isSmart ? $reason : null,
                'holding_period_hours'=> $trade->holdingHours(),
                'exited_at'           => now(),
            ]);
        });
    }

    // -------------------------------------------------------------------------

    private function executePartialExit(
        PaperTrade $trade,
        float $currentPrice,
        string $reason,
        SmartExitEngineService $engine
    ): void {
        DB::transaction(function () use ($trade, $currentPrice, $reason, $engine) {
            $settings        = PaperTradeSetting::current();
            $sharesRemaining = $trade->sharesRemaining();
            $isTp1           = str_contains($reason, 'TP1');
            $isTp2           = str_contains($reason, 'TP2');

            // Determine close percent based on trigger
            if ($isTp1) {
                $closePct  = (float) ($settings->partial_tp1_percent ?? 50);
                $eventType = PaperTradeHistory::EVENT_TP1;
            } elseif ($isTp2) {
                $closePct  = (float) ($settings->partial_tp2_percent ?? 30);
                $eventType = PaperTradeHistory::EVENT_TP2;
            } else {
                // Smart exit partial
                $closePct  = 50.0;
                $eventType = PaperTradeHistory::EVENT_SMART_EXIT;
            }

            $sharesToClose = round($sharesRemaining * ($closePct / 100), 8);
            if ($sharesToClose <= 0) {
                return;
            }

            $pnl = $this->calcPnl($trade, $currentPrice, $sharesToClose);

            // Write primary event (TP1, TP2, or SMART_EXIT)
            PaperTradeHistory::create([
                'paper_trade_id'  => $trade->id,
                'event_type'      => $eventType,
                'price_at_event'  => $currentPrice,
                'shares_affected' => $sharesToClose,
                'pnl_realized'    => $pnl,
                'reason'          => $reason,
            ]);

            // Write PARTIAL_CLOSE event for TP1/TP2
            if ($isTp1 || $isTp2) {
                PaperTradeHistory::create([
                    'paper_trade_id'  => $trade->id,
                    'event_type'      => PaperTradeHistory::EVENT_PARTIAL_CLOSE,
                    'price_at_event'  => $currentPrice,
                    'shares_affected' => $sharesToClose,
                    'pnl_realized'    => $pnl,
                    'reason'          => "Partial close {$closePct}% at {$eventType}",
                ]);
            }

            // Check if fully closed after this partial
            $newRemaining = $sharesRemaining - $sharesToClose;
            $newStatus    = $newRemaining <= 0.000001
                ? PaperTrade::STATUS_CLOSED
                : PaperTrade::STATUS_PARTIAL;

            $totalPnl = $this->getTotalRealizedPnl($trade) + $pnl;
            $roi      = (float) $trade->position_size_usd > 0
                ? $totalPnl / (float) $trade->position_size_usd
                : 0;

            $trade->update([
                'status'  => $newStatus,
                'pnl_usd' => $totalPnl,
                'roi'     => $roi,
                ...($newStatus !== PaperTrade::STATUS_PARTIAL ? [
                    'exit_price'           => $currentPrice,
                    'holding_period_hours' => $trade->holdingHours(),
                    'exited_at'            => now(),
                ] : []),
            ]);
        });
    }

    // -------------------------------------------------------------------------

    private function executeMoveToBreakeven(PaperTrade $trade, float $currentPrice, string $reason): void
    {
        DB::transaction(function () use ($trade, $currentPrice, $reason) {
            $entryPrice = (float) $trade->entry_price;

            PaperTradeHistory::create([
                'paper_trade_id'  => $trade->id,
                'event_type'      => PaperTradeHistory::EVENT_BREAKEVEN_MOVED,
                'price_at_event'  => $currentPrice,
                'shares_affected' => 0,
                'pnl_realized'    => 0,
                'reason'          => $reason,
            ]);

            // Move stop loss to entry price
            $trade->update([
                'stop_loss_price' => $entryPrice,
            ]);
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get current price from latest market snapshot.
     * Falls back to trade's stored current_price.
     */
    private function getCurrentPrice(PaperTrade $trade): float
    {
        $snapshot = $trade->market?->snapshots()?->latest()->first();

        if ($snapshot && isset($snapshot->best_ask) && (float) $snapshot->best_ask > 0) {
            return (float) $snapshot->best_ask;
        }

        if ($snapshot && isset($snapshot->price) && (float) $snapshot->price > 0) {
            return (float) $snapshot->price;
        }

        return (float) ($trade->current_price ?? 0);
    }

    /**
     * Update current_price and unrealized PnL without triggering exit logic.
     */
    private function updateCurrentPrice(PaperTrade $trade, float $currentPrice): void
    {
        $sharesRemaining = $trade->sharesRemaining();
        $unrealizedPnl   = ($currentPrice - (float) $trade->entry_price) * $sharesRemaining;

        $trade->update([
            'current_price'     => $currentPrice,
            'unrealized_pnl_usd' => round($unrealizedPnl, 4),
        ]);
    }

    /**
     * Calculate PnL for a given number of shares at exit price.
     */
    private function calcPnl(PaperTrade $trade, float $exitPrice, float $shares): float
    {
        return round(($exitPrice - (float) $trade->entry_price) * $shares, 4);
    }

    /**
     * Sum of all realized PnL from history (excluding current event).
     */
    private function getTotalRealizedPnl(PaperTrade $trade): float
    {
        return (float) $trade->history()
            ->whereIn('event_type', PaperTradeHistory::CLOSING_EVENTS)
            ->sum('pnl_realized');
    }
}
