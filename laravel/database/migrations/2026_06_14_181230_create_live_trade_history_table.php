<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live trade history — mirrors paper_trade_history structure.
     *
     * Same event types and accounting rules apply (see PaperTradeHistory
     * CLOSING_EVENTS / EVENT_PARTIAL_CLOSE distinction — those bugs were
     * fixed for paper trading and the same constraints apply here).
     *
     * Additional column: live_trade_order_id links each accounting event
     * to the order execution record that produced it (for audit trail
     * back to actual CLOB fills).
     */
    public function up(): void
    {
        Schema::create('live_trade_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('live_trade_id')
                ->constrained('live_trades')
                ->cascadeOnDelete();

            // live_trade_orders is created before this table (timestamp ordering),
            // so the FK can be declared directly here.
            $table->foreignId('live_trade_order_id')
                ->nullable()
                ->constrained('live_trade_orders')
                ->nullOnDelete();

            $table->string('event_type'); // OPENED, TP1, TP2, TP3, PARTIAL_CLOSE,
                                            // STOP_LOSS, BREAKEVEN_MOVED, SMART_EXIT,
                                            // CLOSED, EXPIRED — same as PaperTradeHistory
            $table->decimal('price_at_event', 10, 6)->nullable();
            $table->decimal('shares_affected', 18, 8)->default(0);
            $table->decimal('pnl_realized', 15, 4)->default(0);
            $table->string('reason')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['live_trade_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_trade_history');
    }
};
