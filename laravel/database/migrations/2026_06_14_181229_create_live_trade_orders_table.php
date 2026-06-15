<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live trade orders — the "mailbox" between Laravel (decision making)
     * and Python (execution via py-clob-client).
     *
     * Flow:
     *   1. Laravel inserts a PENDING order (ENTRY or EXIT_FULL/EXIT_PARTIAL)
     *   2. Python's OrderExecutorJob polls PENDING orders, places them via
     *      CLOB API, and updates status + fill results
     *   3. Laravel's ProcessLiveOrderResultsJob picks up FILLED orders and
     *      creates/updates the corresponding live_trades record
     *
     * live_trade_id is nullable because ENTRY orders don't have a
     * live_trades record yet at insertion time — it's created after fill.
     */
    public function up(): void
    {
        Schema::create('live_trade_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('live_trade_id')
                ->nullable()
                ->constrained('live_trades')
                ->nullOnDelete();

            $table->foreignId('trading_account_id');
            $table->foreignId('market_id');
            $table->foreignId('signal_id')->nullable();

            // ENTRY | EXIT_FULL | EXIT_PARTIAL
            $table->string('order_type');
            // BUY | SELL
            $table->string('side');

            // Polymarket CLOB token ID (yes or no side, depending on direction)
            $table->string('token_id');

            // For BUY (ENTRY): target USD amount to spend
            $table->decimal('size_usd', 15, 2)->nullable();
            // For SELL (EXIT): number of shares to sell
            $table->decimal('shares', 18, 8)->nullable();

            // PENDING -> PROCESSING -> FILLED | PARTIAL_FILLED | FAILED
            $table->string('status')->default('PENDING');

            $table->decimal('expected_price', 10, 6)->nullable();
            $table->decimal('avg_fill_price', 10, 6)->nullable();
            $table->decimal('filled_shares', 18, 8)->nullable();
            $table->decimal('fee_usd', 15, 4)->nullable();

            $table->string('clob_order_id')->nullable();
            $table->string('tx_hash')->nullable();
            $table->text('error_message')->nullable();

            // Context for EXIT orders: "TP1 hit", "Stop loss hit", etc.
            // (from SmartExitDecision::reason)
            $table->string('reason')->nullable();

            $table->unsignedInteger('attempts')->default(0);

            // Set by ProcessLiveOrderResultsJob once it has applied this
            // FILLED order's result to live_trades / live_trade_history.
            // Prevents double-processing.
            $table->boolean('processed_by_laravel')->default(false);

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'order_type']);
            $table->index(['status', 'processed_by_laravel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_trade_orders');
    }
};
