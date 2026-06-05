<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — Signal Evaluation Fields
 *
 * Extends the existing `signals` table with fields needed to track
 * whether a signal was correct after market resolution.
 *
 * Design decisions:
 *   - NO new table. All evaluation data lives on the signals row.
 *   - resolved_outcome copies winning_side from market_outcomes for
 *     denormalization — avoids joins on every analytics query.
 *   - realized_roi uses Numeric(10,4): supports values like +233.3333
 *     (YES signal at 30% that wins) and -100.0000 (full loss).
 *   - resolved_at is copied from market_outcomes.resolved_at, NOT
 *     the timestamp this evaluation ran — we want the actual market
 *     resolution time for time-series analysis.
 *   - All new columns nullable: existing pending signals have no
 *     evaluation data yet. NULL = not yet evaluated.
 *
 * Indexes added:
 *   - signals_is_correct_idx       : filter winning/losing signals
 *   - signals_resolved_at_idx      : time-range queries on resolved signals
 *   - signals_roi_idx              : sort by ROI for leaderboard
 *   - signals_source_correct_idx   : rule performance grouping (composite)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {

            // --- Evaluation result fields ---

            $table->string('resolved_outcome', 20)
                ->nullable()
                ->after('status')
                ->comment('yes | no | cancelled — copied from market_outcomes.winning_side');

            $table->boolean('is_correct')
                ->nullable()
                ->after('resolved_outcome')
                ->comment('True if signal direction matched winning_side. NULL = not yet evaluated.');

            $table->decimal('realized_roi', 10, 4)
                ->nullable()
                ->after('is_correct')
                ->comment('ROI % based on entry probability and outcome. NULL = not yet evaluated.');

            $table->timestamp('resolved_at')
                ->nullable()
                ->after('realized_roi')
                ->comment('Copied from market_outcomes.resolved_at — actual market resolution time.');

            // --- Indexes ---

            $table->index('is_correct', 'signals_is_correct_idx');
            $table->index('resolved_at', 'signals_resolved_at_idx');
            $table->index('realized_roi', 'signals_roi_idx');

            // Composite: rule performance grouping
            // Used by: SELECT trigger_source, AVG(realized_roi) WHERE is_correct IS NOT NULL
            $table->index(
                ['trigger_source', 'is_correct'],
                'signals_source_correct_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropIndex('signals_source_correct_idx');
            $table->dropIndex('signals_roi_idx');
            $table->dropIndex('signals_resolved_at_idx');
            $table->dropIndex('signals_is_correct_idx');

            $table->dropColumn([
                'resolved_outcome',
                'is_correct',
                'realized_roi',
                'resolved_at',
            ]);
        });
    }
};
