<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add raw market metric columns to signals table.
     *
     * These are raw values (not normalized scores).
     * Normalization happens in SignalRankerService at query time.
     *
     * All columns are nullable for backward compatibility.
     * Old signals without these values will fallback to score = 0 per component.
     */
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            // Price momentum over last 24h as a percentage
            // Example: 12.50 means price moved +12.5% in 24h
            $table->decimal('momentum_24h_percent', 10, 6)
                ->nullable()
                ->after('confidence_at_signal');

            // Total liquidity available in the market (USD)
            // Sourced from market snapshot at time of signal
            $table->decimal('liquidity_usd', 20, 2)
                ->nullable()
                ->after('momentum_24h_percent');

            // 24h trading volume (USD)
            $table->decimal('volume_24h_usd', 20, 2)
                ->nullable()
                ->after('liquidity_usd');

            // Bid-ask spread at time of signal
            // Example: 0.03 means 3% spread
            $table->decimal('spread', 8, 6)
                ->nullable()
                ->after('volume_24h_usd');

            // Indexes for future analytics queries
            $table->index('momentum_24h_percent');
            $table->index('volume_24h_usd');
            $table->index('liquidity_usd');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropIndex(['momentum_24h_percent']);
            $table->dropIndex(['volume_24h_usd']);
            $table->dropIndex(['liquidity_usd']);

            $table->dropColumn([
                'momentum_24h_percent',
                'liquidity_usd',
                'volume_24h_usd',
                'spread',
            ]);
        });
    }
};