<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live trades table.
     *
     * Mirrors paper_trades structure for fields consumed by
     * SmartExitEngineService::evaluate() and SignalRankerService,
     * so those services work unmodified against LiveTrade models.
     *
     * Additional columns capture live-execution-specific data:
     * actual fill prices, slippage vs expected, CLOB order references,
     * and on-chain transaction hashes.
     */
    public function up(): void
    {
        Schema::create('live_trades', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trading_account_id');
            $table->foreignId('market_id');
            $table->foreignId('signal_id')->nullable();

            // --- Core fields (mirror paper_trades) ---
            $table->string('direction'); // YES | NO
            $table->decimal('entry_price', 10, 6);
            $table->decimal('exit_price', 10, 6)->nullable();
            $table->decimal('current_price', 10, 6)->nullable();
            $table->decimal('shares', 18, 8);
            $table->decimal('position_size_usd', 15, 2);
            $table->decimal('fees_usd', 15, 4)->default(0);
            $table->decimal('pnl_usd', 15, 4)->default(0);
            $table->decimal('unrealized_pnl_usd', 15, 4)->default(0);
            $table->decimal('roi', 10, 6)->default(0);

            // --- Exit levels (mirror paper_trades) ---
            $table->decimal('stop_loss_price', 10, 6)->nullable();
            $table->decimal('take_profit_price', 10, 6)->nullable();
            $table->decimal('breakeven_price', 10, 6)->nullable();
            $table->string('position_size_mode')->nullable();
            $table->decimal('signal_score', 8, 4)->nullable();
            $table->decimal('edge_at_entry', 10, 6)->nullable();
            $table->decimal('market_probability_at_entry', 10, 6)->nullable();
            $table->decimal('ai_probability_at_entry', 10, 6)->nullable();

            // --- Status & lifecycle (mirror paper_trades) ---
            $table->string('status'); // OPEN | PARTIAL | CLOSED | STOPPED | SMART_EXIT
            $table->string('outcome')->nullable(); // win | loss | breakeven
            $table->string('exit_reason')->nullable();
            $table->string('smart_exit_reason')->nullable();
            $table->decimal('holding_period_hours', 10, 4)->nullable();
            $table->decimal('max_adverse_excursion', 10, 6)->nullable();
            $table->decimal('max_favorable_excursion', 10, 6)->nullable();

            // --- Live execution specific ---
            // Expected price from signal/snapshot before order placement
            $table->decimal('expected_entry_price', 10, 6)->nullable();
            $table->decimal('expected_exit_price', 10, 6)->nullable();

            // Slippage = (actual_fill - expected) / expected
            $table->decimal('slippage_percent_entry', 10, 6)->nullable();
            $table->decimal('slippage_percent_exit', 10, 6)->nullable();

            // CLOB order references
            $table->string('clob_order_id_entry')->nullable();
            $table->string('clob_order_id_exit')->nullable();
            $table->string('clob_token_id')->nullable(); // token_id used for this trade (yes/no side)

            // On-chain transaction hashes
            $table->string('tx_hash_entry')->nullable();
            $table->string('tx_hash_exit')->nullable();

            $table->timestamp('entered_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['trading_account_id', 'status']);
            $table->index(['market_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_trades');
    }
};
