<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AiPrediction
 *
 * AI-generated probability estimate for a market.
 * Architecture is prepared but AI implementation is Sprint 4.
 *
 * Multiple predictions per market are allowed (re-run over time).
 * Each prediction stores ALL input features for full reproducibility.
 */
final class AiPrediction extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'model_name',
        'model_version',
        'engine_version',
        'probability_estimate',
        'confidence',
        'market_probability_at_prediction',
        'edge',
        'features_snapshot',
        'is_scored',
        'brier_score',
        'was_correct',
        'raw_response',
        'prompt_tokens',
        'completion_tokens',
        'predicted_at',
    ];

    protected $casts = [
        'probability_estimate'              => 'float',
        'confidence'                        => 'float',
        'market_probability_at_prediction'  => 'float',
        'edge'                              => 'float',
        'features_snapshot'                 => 'array',  // JSON → PHP array auto-cast
        'is_scored'                         => 'boolean',
        'brier_score'                       => 'float',
        'was_correct'                       => 'boolean',
        'prompt_tokens'                     => 'integer',
        'completion_tokens'                 => 'integer',
        'predicted_at'                      => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * Signals that were generated based on this AI prediction.
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Edge as signed percentage for display.
     */
    public function getEdgePercentAttribute(): string
    {
        $sign = $this->edge >= 0 ? '+' : '';
        return $sign . number_format($this->edge * 100, 1) . '%';
    }

    /**
     * True if this prediction had a positive (favourable) edge.
     */
    public function hasFavourableEdge(): bool
    {
        return $this->edge > 0;
    }
}
