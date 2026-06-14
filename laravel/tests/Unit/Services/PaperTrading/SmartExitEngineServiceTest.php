<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PaperTrading;

use App\Models\Market;
use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\PaperTradeSetting;
use App\Models\Signal;
use App\Services\PaperTrading\SmartExitDecision;
use App\Services\PaperTrading\SmartExitEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartExitEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    private SmartExitEngineService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SmartExitEngineService();
        PaperTradeSetting::current(); // ensure settings exist
    }

    // =========================================================================
    // Stop Loss
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_full_exit_when_stop_loss_is_hit(): void
    {
        $trade = $this->makeTrade(entry: 0.5, stopLoss: 0.40, takeProfit: 0.60);

        $decision = $this->engine->evaluate($trade, 0.38);

        $this->assertSame(SmartExitDecision::FULL_EXIT, $decision->action);
        $this->assertStringContainsString('Stop loss hit', $decision->reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_trigger_stop_loss_above_sl_price(): void
    {
        $trade = $this->makeTrade(entry: 0.5, stopLoss: 0.40, takeProfit: 0.60);

        $decision = $this->engine->evaluate($trade, 0.45);

        $this->assertNotSame(SmartExitDecision::FULL_EXIT, $decision->action);
    }

    // =========================================================================
    // Take Profit
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_partial_exit_when_tp1_is_hit(): void
    {
        $trade = $this->makeTrade(entry: 0.5, stopLoss: 0.40, takeProfit: 0.60);

        $decision = $this->engine->evaluate($trade, 0.62);

        $this->assertSame(SmartExitDecision::PARTIAL_EXIT_50, $decision->action);
        $this->assertStringContainsString('TP1 hit', $decision->reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_retrigger_tp1_after_it_fired(): void
    {
        $trade = $this->makeTrade(entry: 0.5, stopLoss: 0.40, takeProfit: 0.60);

        // Simulate TP1 already fired
        PaperTradeHistory::create([
            'paper_trade_id'  => $trade->id,
            'event_type'      => PaperTradeHistory::EVENT_TP1,
            'price_at_event'  => 0.61,
            'shares_affected' => 50,
            'pnl_realized'    => 5.0,
        ]);

        $trade->load('history');
        $decision = $this->engine->evaluate($trade, 0.62);

        // Should not be TP1 again — either TP2 or NO_ACTION
        $this->assertNotSame('TP1 hit', $decision->reason ?? '');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_retrigger_tp2_after_it_fired(): void
    {
        // BUG REGRESSION TEST: Previously, TP2 had no hasTp2Fired() guard,
        // so it would re-fire every monitoring cycle as long as
        // currentPrice >= TP2 price, causing repeated partial closes
        // and inflated cumulative realized PnL (observed ROI > 1000%).
        $trade = $this->makeTrade(entry: 0.5, stopLoss: 0.40, takeProfit: 0.60);

        // Simulate TP1 already fired
        PaperTradeHistory::create([
            'paper_trade_id'  => $trade->id,
            'event_type'      => PaperTradeHistory::EVENT_TP1,
            'price_at_event'  => 0.61,
            'shares_affected' => 50,
            'pnl_realized'    => 5.0,
        ]);

        // Simulate TP2 already fired once
        PaperTradeHistory::create([
            'paper_trade_id'  => $trade->id,
            'event_type'      => PaperTradeHistory::EVENT_TP2,
            'price_at_event'  => 0.70,
            'shares_affected' => 15,
            'pnl_realized'    => 3.0,
        ]);

        $trade->load('history');

        // Price still >= TP2 price — without the fix, this would fire TP2 again
        $decision = $this->engine->evaluate($trade, 0.75);

        $this->assertNotSame(SmartExitDecision::PARTIAL_EXIT_50, $decision->action,
            'TP2 must not re-fire once it has already fired once');
        $this->assertNotSame('TP2 hit', $decision->reason ?? '',
            'Decision reason must not be TP2 hit on second evaluation');
    }

    // =========================================================================
    // Breakeven
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_moves_to_breakeven_when_trigger_is_hit(): void
    {
        // takeProfit must be higher than breakeven to avoid TP1 firing first
        // At price 0.61: above breakeven(0.60) but below TP(0.90) → MOVE_TO_BREAKEVEN
        $trade = $this->makeTrade(
            entry: 0.5,
            stopLoss: 0.40,
            takeProfit: 0.90,
            breakeven: 0.60
        );

        $decision = $this->engine->evaluate($trade, 0.61);

        $this->assertSame(SmartExitDecision::MOVE_TO_BREAKEVEN, $decision->action);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_move_breakeven_twice(): void
    {
        $trade = $this->makeTrade(
            entry: 0.5,
            stopLoss: 0.40,
            takeProfit: 0.80,
            breakeven: 0.60
        );

        // Simulate breakeven already moved
        PaperTradeHistory::create([
            'paper_trade_id'  => $trade->id,
            'event_type'      => PaperTradeHistory::EVENT_BREAKEVEN_MOVED,
            'price_at_event'  => 0.61,
            'shares_affected' => 0,
            'pnl_realized'    => 0,
        ]);

        $trade->load('history');
        $decision = $this->engine->evaluate($trade, 0.65);

        $this->assertNotSame(SmartExitDecision::MOVE_TO_BREAKEVEN, $decision->action);
    }

    // =========================================================================
    // Smart Exit — Near Expiry
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_triggers_full_exit_near_expiry(): void
    {
        $market = Market::factory()->create([
            'end_date' => now()->addHours(3),
        ]);

        $trade = $this->makeTrade(
            entry: 0.5,
            stopLoss: 0.40,
            takeProfit: 0.80,
            marketId: $market->id
        );

        $trade->load('market');

        $decision = $this->engine->evaluate($trade, 0.52);

        $this->assertSame(SmartExitDecision::FULL_EXIT, $decision->action);
        $this->assertStringContainsString('expiry', $decision->reason);
    }

    // =========================================================================
    // Smart Exit — Momentum Reversal
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_triggers_partial_exit_on_momentum_reversal(): void
    {
        // Market dengan far end_date agar isNearExpiry tidak trigger duluan
        $market = Market::factory()->create(['end_date' => now()->addDays(60)]);

        // Signal tanpa momentum (tidak relevan setelah patch)
        // direction dipin ke 'yes' agar sama dengan trade direction —
        // mencegah hasOppositeSignal() flaky-true akibat random factory direction
        $signal = Signal::factory()->create([
            'market_id' => $market->id,
            'direction' => 'yes',
            // momentum_24h_percent sengaja tidak diset — patch tidak membacanya
        ]);

        $trade = $this->makeTrade(
            entry: 0.5,
            stopLoss: 0.20,   // jauh dari current price agar stop loss tidak trigger
            takeProfit: 0.80,
            marketId: $market->id,
            signalId: $signal->id
        );

        // Snapshot sekarang: probability turun drastis
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.30,
            'snapshotted_at'  => now(),
        ]);

        // Snapshot 24 jam lalu: probability tinggi
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.55,
            'snapshotted_at'  => now()->subHours(24),
        ]);

        // Momentum = (0.30 - 0.55) / 0.55 * 100 = -45.5% → di bawah -10% → trigger
        $trade->load(['signal', 'market']);
        $decision = $this->engine->evaluate($trade, 0.30);

        $this->assertSame(SmartExitDecision::PARTIAL_EXIT_50, $decision->action);
        $this->assertStringContainsString('Momentum reversal', $decision->reason);
    }

    // =========================================================================
    // No Action
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_no_action_when_no_conditions_met(): void
    {
        // Disable smart exit so smart rules do not interfere
        $settings = PaperTradeSetting::current();
        $settings->enable_smart_exit = false;
        $settings->save();

        // Market with far end_date, price between SL and TP → NO_ACTION
        $market = Market::factory()->create(['end_date' => now()->addDays(60)]);
        $trade  = $this->makeTrade(
            entry: 0.5,
            stopLoss: 0.40,
            takeProfit: 0.80,
            marketId: $market->id
        );

        $trade->load('market');
        $decision = $this->engine->evaluate($trade, 0.52);

        $this->assertSame(SmartExitDecision::NO_ACTION, $decision->action);
    }

    // =========================================================================
    // Priority — Stop Loss before TP
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function stop_loss_takes_priority_over_smart_exit(): void
    {
        $market = Market::factory()->create(['end_date' => now()->addDays(60)]);

        // Buat snapshot negatif yang akan trigger momentum rule
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.20,
            'snapshotted_at'  => now(),
        ]);
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.55,
            'snapshotted_at'  => now()->subHours(24),
        ]);

        $trade = $this->makeTrade(
            entry: 0.5,
            stopLoss: 0.45,
            takeProfit: 0.80,
            marketId: $market->id,
        );

        $trade->load(['signal', 'market']);

        // Price di bawah stop loss — stop loss harus menang dari momentum
        $decision = $this->engine->evaluate($trade, 0.40);

        $this->assertSame(SmartExitDecision::FULL_EXIT, $decision->action);
        $this->assertStringContainsString('Stop loss', $decision->reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_trigger_momentum_exit_when_no_24h_snapshot(): void
    {
        $settings = PaperTradeSetting::current();
        $settings->enable_smart_exit = true;
        $settings->save();

        $market = Market::factory()->create(['end_date' => now()->addDays(60)]);

        // Hanya snapshot terkini, tidak ada snapshot 24h lalu
        \App\Models\MarketSnapshot::factory()->create([
            'market_id'       => $market->id,
            'probability_yes' => 0.20,  // price sangat rendah, tapi tidak ada pembanding
            'snapshotted_at'  => now(),
        ]);

        $trade = $this->makeTrade(
            entry: 0.5,
            stopLoss: 0.10,   // jauh agar stop loss tidak trigger
            takeProfit: 0.80,
            marketId: $market->id,
        );

        $trade->load(['signal', 'market']);
        $decision = $this->engine->evaluate($trade, 0.20);

        // Tidak boleh trigger momentum exit karena tidak ada data 24h
        $this->assertNotSame(SmartExitDecision::PARTIAL_EXIT_50, $decision->action,
            'Momentum exit tidak boleh trigger jika tidak ada snapshot 24h');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeTrade(
        float $entry = 0.5,
        float $stopLoss = 0.40,
        float $takeProfit = 0.60,
        float $breakeven = 0.0,
        ?int  $marketId = null,
        ?int  $signalId = null,
    ): PaperTrade {
        $market = $marketId
            ? Market::find($marketId)
            : Market::factory()->create(['end_date' => now()->addDays(30)]);

        return PaperTrade::factory()->create([
            'market_id'         => $market->id,
            'signal_id'         => $signalId,
            'entry_price'       => $entry,
            'shares'            => 100,
            'position_size_usd' => $entry * 100,
            'stop_loss_price'   => $stopLoss,
            'take_profit_price' => $takeProfit,
            'breakeven_price'   => $breakeven > 0 ? $breakeven : null,
            'status'            => PaperTrade::STATUS_OPEN,
            'entered_at'        => now()->subHours(2),
            'fees_usd'          => 0,
            'edge_at_entry'     => 0.1,
            'market_probability_at_entry' => 0.5,
            'direction'         => 'YES',
        ]);
    }
}
