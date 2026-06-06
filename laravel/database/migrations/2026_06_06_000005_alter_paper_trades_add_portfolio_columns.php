<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend paper_trades with Phase 2 columns only.
     *
     * Existing columns preserved as-is:
     *   id, market_id, signal_id, direction, entry_price, exit_price,
     *   shares, position_size_usd, fees_usd, pnl_usd, roi, current_price,
     *   unrealized_pnl_usd, max_adverse_excursion, max_favorable_excursion,
     *   market_probability_at_entry, ai_probability_at_entry, edge_at_entry,
     *   status (varchar 30), outcome, holding_period_hours, notes,
     *   entered_at, exited_at, created_at, updated_at, deleted_at,
     *   trading_account_id
     *
     * DO NOT touch status column — already exists as varchar(30).
     * Status values will be standardized via application constants only.
     */
    public function up(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            // Signal quality snapshot at open time
            $table->decimal('signal_score', 8, 4)
                ->nullable()
                ->after('edge_at_entry');

            $table->string('position_size_mode', 30)
                ->nullable()
                ->after('signal_score');

            // Pre-calculated exit levels at time of open
            // decimal(8,6) matches entry_price precision in existing table
            $table->decimal('take_profit_price', 8, 6)
                ->nullable()
                ->after('entry_price');

            $table->decimal('stop_loss_price', 8, 6)
                ->nullable()
                ->after('take_profit_price');

            $table->decimal('breakeven_price', 8, 6)
                ->nullable()
                ->after('stop_loss_price');

            // Exit metadata
            $table->string('exit_reason', 50)
                ->nullable()
                ->after('exited_at');

            $table->text('smart_exit_reason')
                ->nullable()
                ->after('exit_reason');

            // Indexes
            $table->index('signal_score');
            $table->index('exit_reason');
        });
    }

    public function down(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->dropIndex(['signal_score']);
            $table->dropIndex(['exit_reason']);

            $table->dropColumn([
                'signal_score',
                'position_size_mode',
                'take_profit_price',
                'stop_loss_price',
                'breakeven_price',
                'exit_reason',
                'smart_exit_reason',
            ]);
        });
    }
};