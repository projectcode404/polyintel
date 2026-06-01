<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * market_snapshots
 *
 * Time-series record of every data point we collect for a market.
 * This is our core analytical dataset — every probability, volume, and
 * price observation over time.
 *
 * Design decisions:
 * - Immutable by design: once a snapshot is written, it is never updated.
 *   This preserves the integrity of our historical dataset.
 * - `snapshotted_at` is the actual observation timestamp (from our collector),
 *   NOT created_at. The distinction matters: created_at is when Postgres wrote
 *   the row; snapshotted_at is when we observed the data.
 * - External price data (BTC, ETH, fear_greed) is stored here at snapshot time
 *   so the AI can correlate market probability with market conditions. If we
 *   store it only in a separate prices table, joins become expensive.
 * - No soft deletes: snapshots are immutable historical records.
 * - Partitioning: at scale (millions of rows) this table should be partitioned
 *   by month. For Sprint 1 a standard table is correct; add PARTITION BY RANGE
 *   (snapshotted_at) when row count exceeds ~5M.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete()
                ->comment('FK to markets.id');

            // Core probability observation
            $table->decimal('probability_yes', 8, 6)
                ->comment('YES token probability at snapshot time (0–1)');
            $table->decimal('probability_no', 8, 6)
                ->comment('NO token probability at snapshot time (0–1)');

            // Market depth at snapshot time
            $table->decimal('best_bid', 8, 6)->nullable()
                ->comment('Best bid price for YES token');
            $table->decimal('best_ask', 8, 6)->nullable()
                ->comment('Best ask price for YES token');
            $table->decimal('spread', 8, 6)->nullable()
                ->comment('best_ask - best_bid; computed on insert');

            // Volume and liquidity at snapshot time
            $table->decimal('volume_usd', 20, 2)->default(0);
            $table->decimal('volume_24h_usd', 20, 2)->default(0)
                ->comment('Rolling 24-hour volume');
            $table->decimal('liquidity_usd', 20, 2)->default(0);

            // External market context — stored here for ML feature building
            $table->decimal('btc_price_usd', 20, 2)->nullable()
                ->comment('BTC spot price at snapshot time');
            $table->decimal('eth_price_usd', 20, 2)->nullable()
                ->comment('ETH spot price at snapshot time');
            $table->smallInteger('fear_greed_index')->nullable()
                ->comment('Crypto Fear & Greed index 0–100');
            $table->decimal('btc_dominance', 6, 4)->nullable()
                ->comment('BTC market dominance % as decimal e.g. 0.5234');

            // Collector metadata
            $table->string('collector_version', 20)->nullable()
                ->comment('Python collector version that created this row');
            $table->timestamp('snapshotted_at')
                ->comment('The actual observation time (NOT created_at)');

            $table->timestamps(); // created_at = when Postgres wrote it
        });

        // === INDEXES ===
        // Primary analytical query: time-series for a specific market
        Schema::table('market_snapshots', function (Blueprint $table) {
            $table->index(['market_id', 'snapshotted_at'], 'snapshots_market_time_idx');

            // Range queries: all snapshots in a time window (for charts)
            $table->index('snapshotted_at', 'snapshots_time_idx');

            // Probability analysis: find markets where probability shifted dramatically
            $table->index(['market_id', 'probability_yes'], 'snapshots_market_prob_idx');

            // Volume analysis
            $table->index(['market_id', 'volume_24h_usd'], 'snapshots_volume_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
    }
};
