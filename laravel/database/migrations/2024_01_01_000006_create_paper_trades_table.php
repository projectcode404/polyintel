<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * paper_trades
 *
 * Simulated trades executed from signals. Every metric is calculated
 * exactly as it would be in real trading. No fake numbers.
 *
 * Design decisions:
 * - Every paper trade MUST have entry_price. This is enforced at the DB level.
 * - exit_price is nullable because open trades haven't exited yet.
 *   Closed trades MUST have exit_price (enforced in the service layer).
 * - `shares` = how many YES/NO tokens we "bought" (Polymarket uses shares).
 * - `position_size_usd` = shares × entry_price = actual USD deployed.
 * - `pnl_usd` = (exit_price − entry_price) × shares (positive = profit).
 * - `roi` = pnl_usd / position_size_usd (stored as decimal, e.g. 0.25 = 25%).
 * - `fees_usd` is estimated. Polymarket charges ~2% maker/taker.
 *   We track this to make PnL realistic, not optimistic.
 * - `outcome` enum: win | loss | breakeven | cancelled — 'cancelled' for
 *   markets that were voided by Polymarket (no PnL, money returned).
 * - `max_adverse_excursion` is the worst mark-to-market during the trade —
 *   key for realistic drawdown calculation.
 * - `holding_period_hours` is computed on trade close and stored for
 *   quick Sharpe ratio calculations.
 * - Soft deletes to preserve history even if we want to "hide" bad runs.
 *
 * PnL formula (stored and verified on every update):
 *   pnl_usd = (exit_price - entry_price) * shares - fees_usd
 *   roi      = pnl_usd / position_size_usd
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_trades', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->foreignId('signal_id')
                ->nullable()
                ->constrained('signals')
                ->nullOnDelete()
                ->comment('Signal that triggered this trade; NULL = manually created');

            // Trade direction
            $table->string('direction', 10)
                ->comment('yes | no — which token we bought');

            // === PRICE DATA — ALL REQUIRED FOR VALID PAPER TRADING ===
            $table->decimal('entry_price', 8, 6)
                ->comment('Price of YES/NO token at entry (0–1)');
            $table->decimal('exit_price', 8, 6)->nullable()
                ->comment('Price at exit; NULL while position is open');

            // Position sizing
            $table->decimal('shares', 20, 6)
                ->comment('Number of tokens purchased');
            $table->decimal('position_size_usd', 20, 2)
                ->comment('USD deployed = shares × entry_price');
            $table->decimal('fees_usd', 10, 4)->default(0)
                ->comment('Estimated trading fees (2% Polymarket fee)');

            // === PnL — CALCULATED AND STORED, NOT VIRTUAL ===
            $table->decimal('pnl_usd', 20, 4)->nullable()
                ->comment('(exit_price - entry_price) × shares - fees_usd');
            $table->decimal('roi', 10, 6)->nullable()
                ->comment('pnl_usd / position_size_usd (e.g. 0.25 = 25% return)');

            // Mark-to-market tracking (updated each snapshot cycle while open)
            $table->decimal('current_price', 8, 6)->nullable()
                ->comment('Current market price (updated while open)');
            $table->decimal('unrealized_pnl_usd', 20, 4)->nullable()
                ->comment('Unrealized PnL for open positions');
            $table->decimal('max_adverse_excursion', 10, 6)->nullable()
                ->comment('Worst price movement against our position (for drawdown)');
            $table->decimal('max_favorable_excursion', 10, 6)->nullable()
                ->comment('Best price movement in our favour');

            // Trade context at entry
            $table->decimal('market_probability_at_entry', 8, 6)
                ->comment('Market probability when we entered');
            $table->decimal('ai_probability_at_entry', 8, 6)->nullable()
                ->comment('AI probability at entry');
            $table->decimal('edge_at_entry', 8, 6)
                ->comment('Edge when we entered the position');

            // Lifecycle
            $table->string('status', 30)->default('open')
                ->comment('open | closed | cancelled');
            $table->string('outcome', 20)->nullable()
                ->comment('win | loss | breakeven | cancelled — set on close');
            $table->decimal('holding_period_hours', 10, 4)->nullable()
                ->comment('Duration of trade in hours — set on close');

            $table->text('notes')->nullable();
            $table->timestamp('entered_at')
                ->comment('When we entered the paper trade');
            $table->timestamp('exited_at')->nullable()
                ->comment('When we closed the paper trade');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('paper_trades', function (Blueprint $table) {
            $table->index(['market_id', 'status'], 'trades_market_status_idx');
            $table->index(['status', 'entered_at'], 'trades_status_entered_idx');
            $table->index('outcome', 'trades_outcome_idx');
            $table->index('roi', 'trades_roi_idx');
            $table->index('pnl_usd', 'trades_pnl_idx');
            $table->index('edge_at_entry', 'trades_edge_idx');
            $table->index('entered_at', 'trades_entered_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trades');
    }
};
