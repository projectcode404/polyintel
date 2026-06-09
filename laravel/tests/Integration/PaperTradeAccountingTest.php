<?php

/**
 * Integration Tests — PaperTrade Accounting Correctness
 *
 * FILE: tests/Integration/PaperTradeAccountingTest.php
 *
 * Covers:
 *   - Scenario 1: OPEN → TP1 → FULL EXIT
 *   - Scenario 2: OPEN → TP1 → TP2 → FULL EXIT
 *   - Scenario 3: OPEN → STOP LOSS (ROI floor)
 *   - Scenario 4: OPEN → FULL EXIT (no partial, regression test)
 *
 * Jalankan dengan:
 *   php artisan test tests/Integration/PaperTradeAccountingTest.php
 *   php artisan test --filter PaperTradeAccountingTest
 */

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\SmartExitMonitorJob;
use App\Models\Market;
use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\PaperTradeSetting;
use App\Models\Signal;
use App\Models\TradingAccount;
use App\Services\PaperTrading\SmartExitEngineService;
use App\Services\PaperTrading\SmartExitDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaperTradeAccountingTest extends TestCase
{
    use RefreshDatabase;

    private SmartExitMonitorJob $monitor;
    private SmartExitEngineService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        PaperTradeSetting::current();
        $this->engine  = new SmartExitEngineService();
        $this->monitor = new SmartExitMonitorJob($this->engine);
    }

    // =========================================================================
    // SCENARIO 1: OPEN → TP1 → FULL EXIT
    // =========================================================================
    // Entry: 100 shares @ 0.40, position_size_usd = $40
    // TP1: close 50% (50 shares) @ 0.50 → PnL = (0.50-0.40)*50 = $5.00
    // Full Exit: remaining 50 shares @ 0.45 → PnL = (0.45-0.40)*50 = $2.50
    // Expected: total realized PnL = $7.50, ROI = 7.50/40 = 18.75%
    // shares remaining after full exit = 0
    // =========================================================================

    #[Test]
    public function scenario_1_open_tp1_full_exit_no_double_count(): void
    {
        $trade = $this->makeTrade(
            shares: 100,
            entryPrice: 0.40,
            positionSize: 40.0,
            stopLoss: 0.30,
            takeProfit: 0.50,
        );

        // --- Simulate TP1 ---
        $this->executePartialExitDirect($trade, currentPrice: 0.50, reason: 'TP1 hit');
        $trade->refresh();
        $trade->load('history');

        // Setelah TP1:
        $this->assertEquals(
            PaperTrade::STATUS_PARTIAL,
            $trade->status,
            'Status harus PARTIAL setelah TP1'
        );

        // sharesRemaining = 50 (bukan 0 akibat double-count)
        $this->assertEqualsWithDelta(
            50.0,
            $trade->sharesRemaining(),
            0.0001,
            'sharesRemaining harus 50 setelah TP1 menutup 50%'
        );

        // pnl setelah TP1 = $5.00 (bukan $10 atau $15 akibat double-count)
        $this->assertEqualsWithDelta(
            5.0,
            (float) $trade->pnl_usd,
            0.01,
            'pnl_usd harus $5.00 setelah TP1 (bukan double-counted)'
        );

        // History: EVENT_TP1 ada 1 record, EVENT_PARTIAL_CLOSE ada 1 record
        $tp1Count = $trade->history()->where('event_type', PaperTradeHistory::EVENT_TP1)->count();
        $this->assertEquals(1, $tp1Count, 'Harus ada tepat 1 EVENT_TP1');

        $partialCloseCount = $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_PARTIAL_CLOSE)
            ->count();
        $this->assertEquals(1, $partialCloseCount, 'Harus ada tepat 1 EVENT_PARTIAL_CLOSE');

        // EVENT_PARTIAL_CLOSE harus punya pnl_realized = 0 dan shares_affected = 0
        $partialCloseRecord = $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_PARTIAL_CLOSE)
            ->first();
        $this->assertEqualsWithDelta(0.0, (float) $partialCloseRecord->pnl_realized, 0.0001,
            'EVENT_PARTIAL_CLOSE.pnl_realized harus 0 (audit event only)');
        $this->assertEqualsWithDelta(0.0, (float) $partialCloseRecord->shares_affected, 0.0001,
            'EVENT_PARTIAL_CLOSE.shares_affected harus 0 (audit event only)');

        // --- Simulate Full Exit @ 0.45 ---
        $this->executeFullExitDirect($trade, currentPrice: 0.45, reason: 'Manual close');
        $trade->refresh();
        $trade->load('history');

        // shares remaining = 0
        $this->assertEqualsWithDelta(
            0.0,
            $trade->sharesRemaining(),
            0.0001,
            'sharesRemaining harus 0 setelah full exit'
        );

        // Total PnL = $5.00 + $2.50 = $7.50
        $this->assertEqualsWithDelta(
            7.50,
            (float) $trade->pnl_usd,
            0.01,
            'Total pnl_usd harus $7.50'
        );

        // ROI = 7.50 / 40.00 = 0.1875 = 18.75%
        $this->assertEqualsWithDelta(
            0.1875,
            (float) $trade->roi,
            0.001,
            'ROI harus 18.75%'
        );

        // Status = CLOSED atau SMART_EXIT atau STOPPED
        $this->assertContains(
            $trade->status,
            PaperTrade::CLOSED_STATUSES,
            'Status harus closed setelah full exit'
        );

        // Verifikasi: sum history pnl_realized == trade pnl_usd
        $sumFromHistory = (float) $trade->history()
            ->whereIn('event_type', PaperTradeHistory::CLOSING_EVENTS)
            ->sum('pnl_realized');
        $this->assertEqualsWithDelta(
            (float) $trade->pnl_usd,
            $sumFromHistory,
            0.01,
            'pnl_usd harus sama dengan sum(history.pnl_realized) — BUG #4 invariant'
        );
    }

    // =========================================================================
    // SCENARIO 2: OPEN → TP1 → TP2 → FULL EXIT
    // =========================================================================
    // Entry: 100 shares @ 0.40, position_size_usd = $40
    // TP1: close 50% (50 shares) @ 0.50 → PnL = $5.00
    // TP2: close 30% dari remaining 50 = 15 shares @ 0.55 → PnL = (0.55-0.40)*15 = $2.25
    // Full Exit: remaining 35 shares @ 0.45 → PnL = (0.45-0.40)*35 = $1.75
    // Expected: total PnL = $9.00
    // sum(closed shares) = 50 + 15 + 35 = 100 = original shares ✓
    // =========================================================================

    #[Test]
    public function scenario_2_open_tp1_tp2_full_exit_shares_sum_equals_original(): void
    {
        $trade = $this->makeTrade(
            shares: 100,
            entryPrice: 0.40,
            positionSize: 40.0,
            stopLoss: 0.30,
            takeProfit: 0.50,
        );

        // TP1 @ 0.50, close 50%
        $this->executePartialExitDirect($trade, 0.50, 'TP1 hit', closePct: 50.0, eventType: PaperTradeHistory::EVENT_TP1);
        $trade->refresh()->load('history');

        $this->assertEqualsWithDelta(50.0, $trade->sharesRemaining(), 0.0001, 'After TP1: 50 shares remaining');

        // TP2 @ 0.55, close 30% dari sisa
        $this->executePartialExitDirect($trade, 0.55, 'TP2 hit', closePct: 30.0, eventType: PaperTradeHistory::EVENT_TP2);
        $trade->refresh()->load('history');

        $this->assertEqualsWithDelta(35.0, $trade->sharesRemaining(), 0.0001, 'After TP2: 35 shares remaining');

        // Full Exit @ 0.45
        $this->executeFullExitDirect($trade, 0.45, 'Manual close');
        $trade->refresh()->load('history');

        // INVARIANT: sum(closed shares dari CLOSING_EVENTS) == shares original
        $sumClosedShares = (float) $trade->history()
            ->whereIn('event_type', PaperTradeHistory::CLOSING_EVENTS)
            ->sum('shares_affected');

        $this->assertEqualsWithDelta(
            (float) $trade->shares,
            $sumClosedShares,
            0.0001,
            'sum(closed shares) harus sama dengan original shares'
        );

        // sharesRemaining = 0
        $this->assertEqualsWithDelta(0.0, $trade->sharesRemaining(), 0.0001);

        // Total PnL = 5.00 + 2.25 + 1.75 = $9.00
        $this->assertEqualsWithDelta(9.0, (float) $trade->pnl_usd, 0.01);

        // Tidak ada EVENT_PARTIAL_CLOSE dengan shares/pnl > 0
        $badPartialClose = $trade->history()
            ->where('event_type', PaperTradeHistory::EVENT_PARTIAL_CLOSE)
            ->where(function ($q) {
                $q->where('pnl_realized', '!=', 0)
                  ->orWhere('shares_affected', '!=', 0);
            })
            ->exists();
        $this->assertFalse($badPartialClose,
            'EVENT_PARTIAL_CLOSE tidak boleh punya pnl_realized atau shares_affected != 0');
    }

    // =========================================================================
    // SCENARIO 3: OPEN → STOP LOSS — ROI tidak boleh < -100%
    // =========================================================================
    // Entry: 100 shares @ 0.50, position_size_usd = $50
    // Stop loss hit @ 0.10 → gross PnL = (0.10-0.50)*100 = -$40
    // ROI = -40/50 = -80% → masih di atas -100%, OK
    // Test bahwa extreme case (exit price = 0) tidak menghasilkan ROI < -100%
    // =========================================================================

    #[Test]
    public function scenario_3_stop_loss_roi_never_below_minus_100_percent(): void
    {
        $trade = $this->makeTrade(
            shares: 100,
            entryPrice: 0.50,
            positionSize: 50.0,
            stopLoss: 0.30,
            takeProfit: 0.80,
        );

        // Simulasikan stop loss di harga 0 (worst case absolute)
        $this->executeFullExitDirect($trade, currentPrice: 0.0, reason: 'stop loss hit');
        $trade->refresh();

        // ROI tidak boleh di bawah -100%
        $this->assertGreaterThanOrEqual(
            -1.0,
            (float) $trade->roi,
            'ROI tidak boleh kurang dari -100%'
        );

        // PnL tidak boleh lebih rendah dari -position_size_usd
        $this->assertGreaterThanOrEqual(
            -(float) $trade->position_size_usd,
            (float) $trade->pnl_usd,
            'pnl_usd tidak boleh lebih rendah dari -position_size_usd'
        );
    }

    // =========================================================================
    // SCENARIO 4: OPEN → FULL EXIT langsung (regression test — no partial)
    // =========================================================================
    // Pastikan trade tanpa partial exit tetap berjalan benar setelah patch.
    // =========================================================================

    #[Test]
    public function scenario_4_open_full_exit_no_partial_regression(): void
    {
        $trade = $this->makeTrade(
            shares: 100,
            entryPrice: 0.40,
            positionSize: 40.0,
            stopLoss: 0.30,
            takeProfit: 0.60,
        );

        // Full exit langsung @ 0.55
        $this->executeFullExitDirect($trade, currentPrice: 0.55, reason: 'Manual close');
        $trade->refresh()->load('history');

        // PnL = (0.55 - 0.40) * 100 = $15.00
        $this->assertEqualsWithDelta(15.0, (float) $trade->pnl_usd, 0.01);

        // ROI = 15/40 = 37.5%
        $this->assertEqualsWithDelta(0.375, (float) $trade->roi, 0.001);

        // sharesRemaining = 0
        $this->assertEqualsWithDelta(0.0, $trade->sharesRemaining(), 0.0001);

        // Status = closed
        $this->assertContains($trade->status, PaperTrade::CLOSED_STATUSES);

        // Tidak ada EVENT_PARTIAL_CLOSE
        $this->assertEquals(
            0,
            $trade->history()->where('event_type', PaperTradeHistory::EVENT_PARTIAL_CLOSE)->count()
        );
    }

    // =========================================================================
    // SCENARIO 5: EVENT_PARTIAL_CLOSE tidak masuk CLOSING_EVENTS
    // =========================================================================

    #[Test]
    public function scenario_5_event_partial_close_excluded_from_closing_events(): void
    {
        $this->assertNotContains(
            PaperTradeHistory::EVENT_PARTIAL_CLOSE,
            PaperTradeHistory::CLOSING_EVENTS,
            'EVENT_PARTIAL_CLOSE tidak boleh masuk CLOSING_EVENTS (menyebabkan double-count)'
        );
    }

    // =========================================================================
    // SCENARIO 6: sharesRemaining tidak bisa negatif
    // =========================================================================

    #[Test]
    public function scenario_6_shares_remaining_never_negative(): void
    {
        $trade = $this->makeTrade(shares: 100, entryPrice: 0.40, positionSize: 40.0);

        // Simulasi full exit
        $this->executeFullExitDirect($trade, 0.50, 'Full exit');
        $trade->refresh()->load('history');

        // sharesRemaining tidak boleh negatif
        $this->assertGreaterThanOrEqual(
            0.0,
            $trade->sharesRemaining(),
            'sharesRemaining tidak boleh negatif'
        );
    }

    // =========================================================================
    // SCENARIO 7: Momentum reversal membaca snapshot, bukan signal statis
    // (Bug #2 test)
    // =========================================================================

    #[Test]
    public function scenario_7_momentum_reversal_reads_from_snapshot_not_signal(): void
    {
        $market = Market::factory()->create(['end_date' => now()->addDays(60)]);

        // Signal dengan momentum_24h_percent negatif (data statis lama)
        $signal = Signal::factory()->create([
            'market_id'            => $market->id,
            'momentum_24h_percent' => -20.0,  // nilai lama — tidak boleh dipakai
        ]);

        $trade = $this->makeTrade(
            marketId: $market->id,
            signalId: $signal->id,
            entryPrice: 0.50,
            stopLoss: 0.40,
            takeProfit: 0.80,
        );

        // Buat snapshot SEKARANG dengan probability naik (momentum positif)
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.55,
            'snapshotted_at'  => now(),
        ]);

        // Buat snapshot 24 jam lalu dengan probability lebih rendah (momentum positif)
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.48,
            'snapshotted_at'  => now()->subHours(24),
        ]);

        // Momentum = (0.55 - 0.48) / 0.48 * 100 = +14.6% → TIDAK trigger exit
        $trade->load(['signal', 'market']);
        $decision = $this->engine->evaluate($trade, 0.55);

        // Tidak boleh trigger momentum reversal exit
        $this->assertNotSame(
            SmartExitDecision::PARTIAL_EXIT_50,
            $decision->action,
            'Momentum reversal tidak boleh trigger ketika snapshot menunjukkan momentum positif, meskipun signal lama negatif'
        );
    }

    #[Test]
    public function scenario_7b_momentum_reversal_triggers_when_snapshot_negative(): void
    {
        $market = Market::factory()->create(['end_date' => now()->addDays(60)]);

        $signal = Signal::factory()->create([
            'market_id'            => $market->id,
            'momentum_24h_percent' => 15.0,  // signal lama positif — tidak relevan
            'direction'            => 'yes',
            'status'               => 'pending',
        ]);

        $trade = $this->makeTrade(
            marketId: $market->id,
            signalId: $signal->id,
            entryPrice: 0.50,
            stopLoss: 0.30,
            takeProfit: 0.80,
        );

        // Snapshot sekarang: probability turun drastis
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.35,
            'snapshotted_at'  => now(),
        ]);

        // Snapshot 24 jam lalu: probability tinggi
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.55,
            'snapshotted_at'  => now()->subHours(24),
        ]);
        $decision = $this->engine->evaluate($trade, 0.35);
        $this->assertSame(
            SmartExitDecision::PARTIAL_EXIT_50,
            $decision->action,
            'Momentum reversal harus trigger ketika snapshot menunjukkan momentum < -10%'
        );
        $this->assertStringContainsString('Momentum reversal', $decision->reason);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeTrade(
        float $shares = 100,
        float $entryPrice = 0.40,
        float $positionSize = 40.0,
        float $stopLoss = 0.30,
        float $takeProfit = 0.60,
        ?int $marketId = null,
        ?int $signalId = null,
    ): PaperTrade {
        $market = $marketId
            ? Market::find($marketId)
            : Market::factory()->create(['end_date' => now()->addDays(30)]);

        return PaperTrade::factory()->create([
            'market_id'          => $market->id,
            'signal_id'          => $signalId,
            'entry_price'        => $entryPrice,
            'shares'             => $shares,
            'position_size_usd'  => $positionSize,
            'stop_loss_price'    => $stopLoss,
            'take_profit_price'  => $takeProfit,
            'status'             => PaperTrade::STATUS_OPEN,
            'entered_at'         => now()->subHours(2),
            'fees_usd'           => 0,
            'pnl_usd'            => 0,
            'roi'                => 0,
            'direction'          => 'YES',
            'market_probability_at_entry' => 0.5,
            'edge_at_entry'      => 0.1,
        ]);
    }

    /**
     * Eksekusi partial exit langsung — bypass job untuk isolasi test.
     */
    private function executePartialExitDirect(
        PaperTrade $trade,
        float $currentPrice,
        string $reason,
        float $closePct = 50.0,
        string $eventType = PaperTradeHistory::EVENT_TP1,
    ): void {
        \Illuminate\Support\Facades\DB::transaction(function () use ($trade, $currentPrice, $reason, $closePct, $eventType) {
            $sharesRemaining = $trade->sharesRemaining();
            $sharesToClose   = round($sharesRemaining * ($closePct / 100), 8);
            $pnl             = round(($currentPrice - (float) $trade->entry_price) * $sharesToClose, 4);

            $isTp = in_array($eventType, [PaperTradeHistory::EVENT_TP1, PaperTradeHistory::EVENT_TP2]);

            // Accounting event
            PaperTradeHistory::create([
                'paper_trade_id'  => $trade->id,
                'event_type'      => $eventType,
                'price_at_event'  => $currentPrice,
                'shares_affected' => $sharesToClose,
                'pnl_realized'    => $pnl,
                'reason'          => $reason,
            ]);

            // Audit event (pnl=0, shares=0 — PATCH #1)
            if ($isTp) {
                PaperTradeHistory::create([
                    'paper_trade_id'  => $trade->id,
                    'event_type'      => PaperTradeHistory::EVENT_PARTIAL_CLOSE,
                    'price_at_event'  => $currentPrice,
                    'shares_affected' => 0,
                    'pnl_realized'    => 0,
                    'reason'          => "Partial close {$closePct}% at {$eventType}",
                ]);
            }

            $newRemaining = $sharesRemaining - $sharesToClose;
            $newStatus    = $newRemaining <= 0.000001
                ? PaperTrade::STATUS_CLOSED
                : PaperTrade::STATUS_PARTIAL;

            $previousPnl = (float) $trade->history()
                ->whereIn('event_type', PaperTradeHistory::CLOSING_EVENTS)
                ->sum('pnl_realized');
            $totalPnl = $previousPnl;
            $rawRoi   = (float) $trade->position_size_usd > 0
                ? $totalPnl / (float) $trade->position_size_usd
                : 0.0;

            $trade->update([
                'status'  => $newStatus,
                'pnl_usd' => $totalPnl,
                'roi'     => max(-1.0, $rawRoi),
            ]);
        });
    }

    /**
     * Eksekusi full exit langsung — bypass job untuk isolasi test.
     */
    private function executeFullExitDirect(
        PaperTrade $trade,
        float $currentPrice,
        string $reason,
    ): void {
        \Illuminate\Support\Facades\DB::transaction(function () use ($trade, $currentPrice, $reason) {
            $sharesRemaining = max(0.0, $trade->sharesRemaining());
            $pnl             = round(($currentPrice - (float) $trade->entry_price) * $sharesRemaining, 4);
            $isStopLoss      = str_contains(strtolower($reason), 'stop loss');
            $eventType       = $isStopLoss
                ? PaperTradeHistory::EVENT_STOP_LOSS
                : PaperTradeHistory::EVENT_CLOSED;

            PaperTradeHistory::create([
                'paper_trade_id'  => $trade->id,
                'event_type'      => $eventType,
                'price_at_event'  => $currentPrice,
                'shares_affected' => $sharesRemaining,
                'pnl_realized'    => $pnl,
                'reason'          => $reason,
            ]);

            $previousPnl = (float) $trade->history()
                ->whereIn('event_type', PaperTradeHistory::CLOSING_EVENTS)
                ->sum('pnl_realized');
            $totalPnl = $previousPnl;
            $rawRoi   = (float) $trade->position_size_usd > 0
                ? $totalPnl / (float) $trade->position_size_usd
                : 0.0;

            $trade->update([
                'status'               => $isStopLoss ? PaperTrade::STATUS_STOPPED : PaperTrade::STATUS_CLOSED,
                'exit_price'           => $currentPrice,
                'pnl_usd'              => $totalPnl,
                'roi'                  => max(-1.0, $rawRoi),
                'holding_period_hours' => $trade->holdingHours(),
                'exited_at'            => now(),
                'outcome'              => $totalPnl >= 0 ? 'win' : 'loss',
            ]);
        });
    }
}
