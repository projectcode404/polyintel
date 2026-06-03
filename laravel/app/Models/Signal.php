<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Signal
 *
 * A trading signal produced when edge analysis identifies an opportunity.
 * Signals are decoupled from paper trades — a signal fires analytically;
 * paper trade execution is a separate decision.
 */
final class Signal extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'market_id',
        'ai_prediction_id',
        'direction',
        'market_probability_at_signal',
        'ai_probability_at_signal',
        'edge_at_signal',
        'confidence_at_signal',
        'min_edge_threshold',
        'trigger_source',
        'status',
        'notes',
        'fired_at',
        'expires_at',
    ];

    protected $casts = [
        'market_probability_at_signal' => 'float',
        'ai_probability_at_signal'     => 'float',
        'edge_at_signal'               => 'float',
        'confidence_at_signal'         => 'float',
        'min_edge_threshold'           => 'float',
        'fired_at'                     => 'datetime',
        'expires_at'                   => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function aiPrediction(): BelongsTo
    {
        return $this->belongsTo(AiPrediction::class);
    }

    /**
     * All paper trades spawned from this signal.
     */
    public function paperTrades(): HasMany
    {
        return $this->hasMany(PaperTrade::class);
    }

    /**
     * The primary paper trade for this signal (first one created).
     */
    public function primaryPaperTrade(): HasOne
    {
        return $this->hasOne(PaperTrade::class)->oldestOfMany('entered_at');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function getEdgePercentAttribute(): string
    {
        $sign = $this->edge_at_signal >= 0 ? '+' : '';
        return $sign . number_format($this->edge_at_signal * 100, 1) . '%';
    }
}
