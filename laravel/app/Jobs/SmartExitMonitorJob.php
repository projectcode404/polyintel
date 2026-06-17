<?php

/**
 * PATCH #1 (lanjutan) — SmartExitMonitorJob.php
 *
 * FILE: app/Jobs/SmartExitMonitorJob.php
 *   (atau app/Console/Commands/SmartExitMonitorJob.php sesuai lokasi aktual)
 *
 * ROOT CAUSE
 * ----------
 * executePartialExit() menulis EVENT_PARTIAL_CLOSE dengan:
 *   - shares_affected = $sharesToClose  (sama dengan EVENT_TP1)
 *   - pnl_realized    = $pnl            (sama dengan EVENT_TP1)
 *
 * Setelah EVENT_PARTIAL_CLOSE dikeluarkan dari CLOSING_EVENTS (Patch #1),
 * nilai pnl_realized dan shares_affected di EVENT_PARTIAL_CLOSE menjadi
 * "dead weight" — tidak dihitung tapi menyesatkan jika dibaca manual.
 *
 * FIX
 * ---
 * EVENT_PARTIAL_CLOSE ditulis dengan pnl_realized = 0, shares_affected = 0.
 * Fungsinya murni sebagai audit trail / label tampilan di UI.
 * Accounting sepenuhnya ada di EVENT_TP1 / EVENT_TP2.
 *
 * DIFF executePartialExit() — hanya bagian yang berubah:
 *
 * SEBELUM:
 *   if ($isTp1 || $isTp2) {
 *       PaperTradeHistory::create([
 *           ...
 *           'shares_affected' => $sharesToClose,  // ← inflate sharesRemaining()
 *           'pnl_realized'    => $pnl,             // ← inflate getTotalRealizedPnl()
 *           ...
 *       ]);
 *   }
 *
 * SESUDAH:
 *   if ($isTp1 || $isTp2) {
 *       PaperTradeHistory::create([
 *           ...
 *           'shares_affected' => 0,   // ← tidak ikut sharesRemaining()
 *           'pnl_realized'    => 0,   // ← tidak ikut getTotalRealizedPnl()
 *           ...
 *       ]);
 *   }
 *
 * RISIKO
 * ------
 * LOW: Hanya nilai yang ditulis ke DB yang berubah, bukan logic pencabangan.
 * UI yang menampilkan history rows masih bisa menampilkan EVENT_PARTIAL_CLOSE
 * sebagai label "Partial close X% at TP1" tanpa bergantung pada nilai numeriknya.
 *
 * JUGA DIPERBAIKI DI FILE INI
 * ---------------------------
 * - BUG #4: Tambahkan ROI floor -100% pada executePartialExit dan executeFullExit
 * - BUG #4: Tambahkan log warning jika sharesRemaining() < 0 (tidak mungkin terjadi
 *           setelah fix, tapi sebagai safety net)
 * - Duplicate key 'exit_price' dan 'holding_period_hours' di array update dihapus
 *   (typo yang ada di kode asli)
 */

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\PaperTradeSetting;
use App\Models\TradingAccount;
use App\Services\PaperTrading\SmartExitEngineService;
use App\Services\PortfolioService;
use App\Services\PaperTrading\SmartExitDecision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SmartExitMonitorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        private readonly PortfolioService $portfolioService = new PortfolioService()
    ) {}

    // =========================================================================
    // Handle
    // =========================================================================

    public function handle(SmartExitEngineService $engine): void
    {
        $trades = PaperTrade::whereIn('status', PaperTrade::OPEN_STATUSES)
            ->with(['signal', 'market', 'market.latestSnapshot', 'history'])
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
        $currentPrice = $this->getCurrentPrice($trade);

        if ($currentPrice <= 0) {
            Log::debug('[SmartExitMonitor] Skipping trade — no current price', [
                'trade_id' => $trade->id,
            ]);
            return false;
        }

        $this->updateCurrentPrice($trade, $currentPrice);

        $decision = $engine->evaluate($trade, $currentPrice);

        if ($decision->isNoAction()) {
            return false;
        }

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
            $trade->load('history'); // refresh to include any new events in this cycle
            $sharesRemaining = $trade->sharesRemaining();

            // BUG #4 INVARIANT: sharesRemaining tidak boleh negatif
            if ($sharesRemaining < 0) {
                Log::warning('[SmartExitMonitor] INVARIANT VIOLATION: sharesRemaining < 0', [
                    'trade_id'        => $trade->id,
                    'sharesRemaining' => $sharesRemaining,
                    'shares_original' => $trade->shares,
                ]);
                $sharesRemaining = 0.0;
            }

            $grossPnl   = $this->calcPnl($trade, $currentPrice, $sharesRemaining);
            $exitFee    = $this->calcExitFee($sharesRemaining, $currentPrice);
            $pnl        = round($grossPnl - $exitFee, 4);
            $isStopLoss = str_contains(strtolower($reason), 'stop loss');
            $isSmart    = str_contains(strtolower($reason), 'momentum')
                || str_contains(strtolower($reason), 'liquidity')
                || str_contains(strtolower($reason), 'spread')
                || str_contains(strtolower($reason), 'expiry')
                || str_contains(strtolower($reason), 'signal reversal');

            $eventType = $isStopLoss ? PaperTradeHistory::EVENT_STOP_LOSS
                       : ($isSmart   ? PaperTradeHistory::EVENT_SMART_EXIT
                                     : PaperTradeHistory::EVENT_CLOSED);

            $newStatus = $isStopLoss ? PaperTrade::STATUS_STOPPED
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

            // BUG FIX: getTotalRealizedPnl() queries paper_trade_history AFTER
            // the create() above, so it already includes this exit's $pnl.
            // Previously this added $pnl a second time, double-counting the
            // final exit's PnL (observed: SMART_EXIT pnl_realized=4.89 counted
            // twice, inflating total from $6.41 to $11.30, ROI from ~128% to 226%).
            $totalPnl = $this->getTotalRealizedPnl($trade);

            // BUG #4 INVARIANT: ROI tidak boleh di bawah -100%
            $rawRoi = (float) $trade->position_size_usd > 0
                ? $totalPnl / (float) $trade->position_size_usd
                : 0.0;
            $roi = max(-1.0, $rawRoi);

            if ($rawRoi < -1.0) {
                Log::warning('[SmartExitMonitor] INVARIANT VIOLATION: ROI < -100%', [
                    'trade_id'  => $trade->id,
                    'raw_roi'   => $rawRoi,
                    'clamped'   => $roi,
                    'total_pnl' => $totalPnl,
                ]);
            }

            $trade->update([
                'exit_price'           => $currentPrice,
                'pnl_usd'              => $totalPnl,
                'roi'                  => $roi,
                'status'               => $newStatus,
                'exit_reason'          => $eventType,
                'smart_exit_reason'    => $isSmart ? $reason : null,
                'holding_period_hours' => $trade->holdingHours(),
                'exited_at'            => now(),
                'outcome'              => $totalPnl >= 0 ? 'win' : 'loss',
                'fees_usd'             => (float) $trade->fees_usd + $exitFee,
            ]);

            // FIX #1: Kembalikan capital + net PnL ke balance akun
            // (fee sudah dikurangi dari $pnl di atas)
            $returnedAmount = ($sharesRemaining * (float) $trade->entry_price) + $pnl;
            TradingAccount::where('id', $trade->trading_account_id)
                ->increment('balance', $returnedAmount);
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
            $trade->load('history'); // refresh to include any new events in this cycle
            $sharesRemaining = $trade->sharesRemaining();
            $isTp1           = str_contains($reason, 'TP1');
            $isTp2           = str_contains($reason, 'TP2');

            // BUG #4 INVARIANT: sharesRemaining tidak boleh negatif
            if ($sharesRemaining < 0) {
                Log::warning('[SmartExitMonitor] INVARIANT VIOLATION: sharesRemaining < 0 on partial exit', [
                    'trade_id'        => $trade->id,
                    'sharesRemaining' => $sharesRemaining,
                ]);
                $sharesRemaining = 0.0;
            }

            if ($isTp1) {
                $closePct  = (float) ($settings->partial_tp1_percent ?? 50);
                $eventType = PaperTradeHistory::EVENT_TP1;
            } elseif ($isTp2) {
                $closePct  = (float) ($settings->partial_tp2_percent ?? 30);
                $eventType = PaperTradeHistory::EVENT_TP2;
            } else {
                $closePct  = 50.0;
                $eventType = PaperTradeHistory::EVENT_SMART_EXIT;
            }

            $sharesToClose = round($sharesRemaining * ($closePct / 100), 8);
            if ($sharesToClose <= 0) {
                return;
            }
            // If remaining position is dust, close it all instead of partial
            if ($sharesRemaining < 0.05) {
                $this->executeFullExit($trade, $currentPrice, 'Dust position — forced full exit');
                return;
            }

            $grossPnl = $this->calcPnl($trade, $currentPrice, $sharesToClose);
            $exitFee  = $this->calcExitFee($sharesToClose, $currentPrice);
            $pnl      = round($grossPnl - $exitFee, 4);

            // --- Accounting event: TP1 / TP2 / SMART_EXIT ---
            // Ini satu-satunya record yang masuk CLOSING_EVENTS.
            // pnl_realized dan shares_affected dipakai oleh:
            //   - getTotalRealizedPnl()
            //   - sharesRemaining()
            PaperTradeHistory::create([
                'paper_trade_id'  => $trade->id,
                'event_type'      => $eventType,
                'price_at_event'  => $currentPrice,
                'shares_affected' => $sharesToClose,
                'pnl_realized'    => $pnl,
                'reason'          => $reason,
            ]);

            // --- Audit/display event: PARTIAL_CLOSE (TP1/TP2 only) ---
            // PATCH #1 FIX: pnl_realized = 0, shares_affected = 0
            // EVENT_PARTIAL_CLOSE tidak masuk CLOSING_EVENTS.
            // Record ini hanya untuk tampilan di trade history UI.
            // JANGAN ubah nilai 0 ini — ini adalah fix untuk double-count bug.
            if ($isTp1 || $isTp2) {
                PaperTradeHistory::create([
                    'paper_trade_id'  => $trade->id,
                    'event_type'      => PaperTradeHistory::EVENT_PARTIAL_CLOSE,
                    'price_at_event'  => $currentPrice,
                    'shares_affected' => 0,   // FIX: was $sharesToClose — caused double-count in sharesRemaining()
                    'pnl_realized'    => 0,   // FIX: was $pnl — caused double-count in getTotalRealizedPnl()
                    'reason'          => "Partial close {$closePct}% at {$eventType}",
                ]);
            }

            // Cek apakah posisi sudah habis setelah partial ini
            $newRemaining = $sharesRemaining - $sharesToClose;
            $newStatus    = $newRemaining <= 0.000001
                ? PaperTrade::STATUS_CLOSED
                : PaperTrade::STATUS_PARTIAL;

            // BUG FIX: getTotalRealizedPnl() queries paper_trade_history AFTER
            // the TP1/TP2/SMART_EXIT create() above (which already wrote this
            // leg's $pnl), so it already includes this leg's PnL. Previously
            // this added $pnl a second time, double-counting every partial
            // exit leg (TP1, TP2, partial SMART_EXIT) — not just the final exit.
            $totalPnl = $this->getTotalRealizedPnl($trade);

            // BUG #4 INVARIANT: ROI tidak boleh di bawah -100%
            $rawRoi = (float) $trade->position_size_usd > 0
                ? $totalPnl / (float) $trade->position_size_usd
                : 0.0;
            $roi = max(-1.0, $rawRoi);

            if ($rawRoi < -1.0) {
                Log::warning('[SmartExitMonitor] INVARIANT VIOLATION: ROI < -100% on partial exit', [
                    'trade_id'  => $trade->id,
                    'raw_roi'   => $rawRoi,
                    'total_pnl' => $totalPnl,
                ]);
            }

            $trade->update([
                'status'   => $newStatus,
                'pnl_usd'  => $totalPnl,
                'roi'      => $roi,
                'fees_usd' => (float) $trade->fees_usd + $exitFee,
                ...($newStatus !== PaperTrade::STATUS_PARTIAL ? [
                    'exit_price'           => $currentPrice,
                    'holding_period_hours' => $trade->holdingHours(),
                    'outcome'              => $totalPnl >= 0 ? 'win' : 'loss',
                    'exited_at'            => now(),
                ] : []),
            ]);

            // FIX #1b: Kembalikan capital portion dari shares yang ditutup + PnL partial
            // (fee sudah dikurangi dari $pnl di atas)
            $returnedAmount = ($sharesToClose * (float) $trade->entry_price) + $pnl;
            TradingAccount::where('id', $trade->trading_account_id)
                ->increment('balance', $returnedAmount);
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

            $trade->update([
                'stop_loss_price' => $entryPrice,
            ]);
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getCurrentPrice(PaperTrade $trade): float
    {
        $snapshot = $trade->market?->latestSnapshot;

        if ($snapshot && (float) $snapshot->probability_yes > 0) {
            return (float) $snapshot->probability_yes;
        }

        return (float) ($trade->current_price ?? 0);
    }

    private function updateCurrentPrice(PaperTrade $trade, float $currentPrice): void
    {
        $sharesRemaining = $trade->sharesRemaining();
        $unrealizedPnl   = ($currentPrice - (float) $trade->entry_price) * $sharesRemaining;

        $trade->update([
            'current_price'      => $currentPrice,
            'unrealized_pnl_usd' => round($unrealizedPnl, 4),
        ]);
    }

    private function calcPnl(PaperTrade $trade, float $exitPrice, float $shares): float
    {
        return round(($exitPrice - (float) $trade->entry_price) * $shares, 4);
    }

    /**
     * H3 FIX: Calculate exit fee for a given number of shares at exit price.
     * Previously SmartExitMonitor charged NO fee on TP1/TP2/SmartExit/StopLoss
     * exits, while PaperTradingService::closeTrade() did — inconsistent and
     * unrealistic (Polymarket charges fee on both entry and exit).
     */
    private function calcExitFee(float $shares, float $exitPrice): float
    {
        $feeRate = $this->portfolioService->getTradingFeePercentage();
        return round($shares * $exitPrice * $feeRate, 4);
    }

    private function getTotalRealizedPnl(PaperTrade $trade): float
    {
        return (float) $trade->history()
            ->whereIn('event_type', PaperTradeHistory::CLOSING_EVENTS)
            ->sum('pnl_realized');
    }
}
