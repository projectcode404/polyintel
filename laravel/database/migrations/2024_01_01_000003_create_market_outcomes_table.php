<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * market_outcomes
 *
 * Records the final resolution of every market.
 * This is the ground truth that lets us score AI predictions and
 * measure signal accuracy.
 *
 * Design decisions:
 * - Separate from `markets` table to keep the resolution record clean
 *   and auditable. A market can only have one outcome, enforced by
 *   the unique constraint on market_id.
 * - `winning_side` is 'yes' | 'no' | 'cancelled' — not a boolean,
 *   because markets can be cancelled (voided) by Polymarket.
 * - `resolution_price` is the final settlement price of the YES token
 *   (1.000000 = resolved YES, 0.000000 = resolved NO).
 * - `final_probability_before_resolution` captures the last market
 *   probability before resolution — key for calibration analysis.
 * - `resolved_by` documents the oracle/source that triggered resolution.
 * - No soft deletes: outcomes are permanent historical facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_outcomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->unique() // One outcome per market
                ->constrained('markets')
                ->cascadeOnDelete();

            // Resolution result
            $table->string('winning_side', 20)
                ->comment('yes | no | cancelled');
            $table->decimal('resolution_price', 8, 6)
                ->comment('Final YES token settlement price: 1.0 = YES, 0.0 = NO');

            // Pre-resolution state — critical for calibration analysis
            $table->decimal('final_probability_before_resolution', 8, 6)->nullable()
                ->comment('Last observed market_probability before resolution');
            $table->decimal('peak_probability_yes', 8, 6)->nullable()
                ->comment('Highest YES probability observed during market lifetime');
            $table->decimal('low_probability_yes', 8, 6)->nullable()
                ->comment('Lowest YES probability observed during market lifetime');

            // Volume at resolution
            $table->decimal('total_volume_usd', 20, 2)->default(0)
                ->comment('Total volume traded over market lifetime');

            // Source of resolution
            $table->string('resolved_by', 200)->nullable()
                ->comment('Oracle / resolver identifier from Polymarket');
            $table->text('resolution_notes')->nullable()
                ->comment('Any additional context about the resolution');
            $table->timestamp('resolved_at')
                ->comment('Timestamp of official resolution');

            $table->timestamps();
        });

        Schema::table('market_outcomes', function (Blueprint $table) {
            $table->index('winning_side', 'outcomes_winning_side_idx');
            $table->index('resolved_at', 'outcomes_resolved_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_outcomes');
    }
};
