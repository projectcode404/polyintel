<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source of truth for paper trade lifecycle events.
     *
     * Every state transition is recorded here.
     * Partial closes, TP hits, SL hits — all are rows, never JSON blobs.
     * sharesRemaining is derived by summing shares_affected from this table.
     */
    public function up(): void
    {
        Schema::create('paper_trade_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('paper_trade_id')
                ->constrained('paper_trades')
                ->onDelete('cascade');

            $table->enum('event_type', [
                'OPENED',
                'PARTIAL_CLOSE',
                'TP1',
                'TP2',
                'TP3',
                'STOP_LOSS',
                'BREAKEVEN_MOVED',
                'SMART_EXIT',
                'CLOSED',
                'EXPIRED',
            ]);

            // Market price at the moment this event occurred
            $table->decimal('price_at_event', 15, 8);

            // Shares affected by this event (0 for BREAKEVEN_MOVED)
            $table->decimal('shares_affected', 15, 8)->default(0);

            // Realized PnL from this event only (0 for OPENED, BREAKEVEN_MOVED)
            $table->decimal('pnl_realized', 15, 2)->default(0);

            // Human-readable description of why this event occurred
            $table->text('reason')->nullable();

            // No updated_at — history rows are immutable
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('paper_trade_id');
            $table->index('event_type');
            $table->index('created_at');
            $table->index(['paper_trade_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trade_history');
    }
};