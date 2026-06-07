<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Signal
 *
 * Represents a trading signal generated from market analysis.
 * Contains raw metric values (not normalized scores) for self-contained
 * historical context. Normalization happens in SignalRankerService.
 */
final class Signal extends Model
{
    use HasFactory;
    use SoftDeletes;

    // -------------------------------------------------------------------------
    // Status Constants
    // -------------------------------------------------------------------------

    const STATUS_PENDING  = 'pending';
    const STATUS_FIRED    = 'fired';
    const STATUS_EXPIRED  = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    // -------------------------------------------------------------------------
    // Fillable
    // -------------------------------------------------------------------------

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
        'snapshot_data',
        'resolved_outcome',
        'is_correct',
        'realized_roi',
        'resolved_at',
        // Phase 2: raw market metrics at signal time
        'momentum_24h_percent',
        'liquidity_usd',
        'volume_24h_usd',
        'spread',
    ];

    protected $casts = [
        'market_probability_at_signal' => 'decimal:6',
        'ai_probability_at_signal'     => 'decimal:6',
        'edge_at_signal'               => 'decimal:6',
        'confidence_at_signal'         => 'decimal:6',
        'min_edge_threshold'           => 'decimal:6',
        'is_correct'                   => 'boolean',
        'realized_roi'                 => 'decimal:4',
        'momentum_24h_percent'         => 'decimal:6',
        'liquidity_usd'                => 'decimal:2',
        'volume_24h_usd'               => 'decimal:2',
        'spread'                       => 'decimal:6',
        'snapshot_data'                => 'array',
        'fired_at'                     => 'datetime',
        'expires_at'                   => 'datetime',
        'resolved_at'                  => 'datetime',
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

    public function paperTrades(): HasMany
    {
        return $this->hasMany(PaperTrade::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
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

    public function hasRawMetrics(): bool
    {
        return $this->momentum_24h_percent !== null
            || $this->liquidity_usd !== null
            || $this->volume_24h_usd !== null
            || $this->spread !== null;
    }
}