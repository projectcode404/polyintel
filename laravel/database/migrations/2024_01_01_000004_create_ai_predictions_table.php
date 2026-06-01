<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_predictions
 *
 * Stores AI probability estimates for markets.
 * Architecture is PREPARED but AI is NOT implemented in Sprint 1.
 *
 * Design decisions:
 * - Each row is one AI prediction run for a market at a point in time.
 *   Multiple predictions per market are expected (re-run as new data arrives).
 * - `model_name` + `model_version` identify the AI backend (OpenAI, Claude,
 *   Gemini, or a custom model). This allows A/B comparison between models.
 * - `probability_estimate` is our AI's calculated probability (0–1).
 * - `confidence` is how certain the model is about its estimate (0–1).
 * - `edge` is computed: ai_probability − market_probability at prediction time.
 *   Stored (not computed on-the-fly) so we can query "all high-edge predictions".
 * - `features_snapshot` stores the JSON of all input features used for this
 *   prediction. This is essential for debugging and model improvement —
 *   we always know exactly what data the AI saw.
 * - `market_probability_at_prediction` is the market price when the AI ran —
 *   required to compute edge and to later evaluate prediction accuracy.
 * - `is_scored` + `brier_score` are filled in after market resolution for
 *   calibration tracking (Brier score = (forecast − outcome)²).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_predictions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            // AI model identification
            $table->string('model_name', 100)
                ->comment('e.g. gpt-4o, claude-opus-4, gemini-2-pro, custom-v1');
            $table->string('model_version', 50)->nullable()
                ->comment('Specific version or checkpoint');
            $table->string('engine_version', 20)->nullable()
                ->comment('Our ProbabilityEngine wrapper version');

            // Core prediction output
            $table->decimal('probability_estimate', 8, 6)
                ->comment('AI estimated probability for YES (0–1)');
            $table->decimal('confidence', 8, 6)
                ->comment('Model confidence in its estimate (0–1)');
            $table->decimal('market_probability_at_prediction', 8, 6)
                ->comment('Market price when AI ran — needed to compute edge');
            $table->decimal('edge', 8, 6)
                ->comment('probability_estimate − market_probability_at_prediction');

            // Input features used — stored as JSON for full reproducibility
            $table->json('features_snapshot')
                ->comment('All input features: btc_price, eth_price, funding_rate, etc.');

            // Scoring — filled after market resolves
            $table->boolean('is_scored')->default(false)
                ->comment('True after market_outcomes record exists');
            $table->decimal('brier_score', 8, 6)->nullable()
                ->comment('(probability_estimate − outcome)² — lower is better');
            $table->boolean('was_correct')->nullable()
                ->comment('True if AI edge direction matched actual outcome');

            // Raw model output for debugging
            $table->text('raw_response')->nullable()
                ->comment('Full API response from AI provider');
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();

            $table->timestamp('predicted_at')
                ->comment('When the AI prediction was generated');
            $table->timestamps();
        });

        Schema::table('ai_predictions', function (Blueprint $table) {
            $table->index(['market_id', 'predicted_at'], 'ai_pred_market_time_idx');
            $table->index(['model_name', 'is_scored'], 'ai_pred_model_scored_idx');
            $table->index('edge', 'ai_pred_edge_idx');
            $table->index('brier_score', 'ai_pred_brier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_predictions');
    }
};
