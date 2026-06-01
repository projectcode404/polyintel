<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * markets
 *
 * Central registry of every Polymarket prediction market we track.
 *
 * Design decisions:
 * - One generic `markets` table (not crypto_markets / sports_markets).
 *   Category is stored as a string enum column so the same table serves
 *   all verticals without schema changes.
 * - `condition_id` is Polymarket's unique identifier (their CLOB market ID).
 *   Stored as VARCHAR so we never rely on our own surrogate key for lookups
 *   against their API.
 * - `resolution_source` stores a plain-text description of who/what resolves
 *   the market (e.g. "Polymarket UMA oracle"). This is informational only.
 * - Probability columns use DECIMAL(8,6) — six decimal places gives us
 *   precision to 0.000001 (0.0001%) which is more than sufficient for any
 *   prediction market. FLOAT would introduce binary rounding errors.
 * - `volume_usd` and `liquidity_usd` are DECIMAL(20,2) — handles billions
 *   with cent-level precision.
 * - Soft deletes: markets can be "removed" from our tracking without losing
 *   historical snapshot data that references them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Polymarket identifiers
            $table->string('condition_id', 100)->unique()->comment('Polymarket CLOB condition ID');
            $table->string('slug', 200)->unique()->nullable()->comment('Polymarket URL slug');

            // Market metadata
            $table->string('question', 500)->comment('The full market question text');
            $table->text('description')->nullable()->comment('Long-form market description');
            $table->string('category', 50)->default('crypto')->comment('Market category: crypto, politics, sports, etc.');
            $table->string('sub_category', 100)->nullable()->comment('e.g. bitcoin, ethereum, defi');
            $table->string('tags')->nullable()->comment('JSON array of tags for filtering');

            // Resolution
            $table->string('resolution_source', 300)->nullable()->comment('Who/what resolves this market');
            $table->timestamp('start_date')->nullable()->comment('When the market opened');
            $table->timestamp('end_date')->nullable()->comment('Scheduled resolution date');
            $table->timestamp('resolved_at')->nullable()->comment('Actual resolution timestamp');

            // Status — using string not enum so adding new values requires no migration
            $table->string('status', 30)->default('active')
                ->comment('active | resolved | cancelled | paused');

            // Current snapshot cache (denormalized for fast dashboard queries)
            // The authoritative data lives in market_snapshots.
            // These are updated by the collector on every fetch.
            $table->decimal('market_probability', 8, 6)->nullable()
                ->comment('Latest YES probability from Polymarket (0.000000 – 1.000000)');
            $table->decimal('volume_usd', 20, 2)->default(0)
                ->comment('Total trading volume in USD');
            $table->decimal('liquidity_usd', 20, 2)->default(0)
                ->comment('Current open liquidity in USD');
            $table->integer('num_traders')->default(0)
                ->comment('Total unique traders');

            // AI fields — NULL until Sprint 4
            $table->decimal('ai_probability', 8, 6)->nullable()
                ->comment('AI-estimated probability (populated in Sprint 4)');
            $table->decimal('edge', 8, 6)->nullable()
                ->comment('ai_probability - market_probability; NULL until AI runs');

            // Tracking
            $table->timestamp('last_synced_at')->nullable()
                ->comment('Last time Python collector fetched this market');
            $table->boolean('is_tracked')->default(true)
                ->comment('False = stop collecting snapshots for this market');

            $table->timestamps();
            $table->softDeletes();
        });

        // === INDEXES ===
        // Covering index for the most common dashboard query:
        // "Show me all active crypto markets sorted by edge"
        Schema::table('markets', function (Blueprint $table) {
            $table->index(['status', 'category', 'is_tracked'], 'markets_status_category_tracked_idx');
            $table->index(['end_date', 'status'], 'markets_end_date_status_idx');
            $table->index('edge', 'markets_edge_idx');
            $table->index('market_probability', 'markets_probability_idx');
            $table->index('last_synced_at', 'markets_last_synced_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('markets');
    }
};
