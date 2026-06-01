<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MarketCategory;
use App\Enums\MarketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Market
 *
 * Represents a single Polymarket prediction market.
 *
 * @property int $id
 * @property string $condition_id
 * @property string|null $slug
 * @property string $question
 * @property string|null $description
 * @property string $category
 * @property string|null $sub_category
 * @property string|null $tags
 * @property string|null $resolution_source
 * @property \Carbon\Carbon|null $start_date
 * @property \Carbon\Carbon|null $end_date
 * @property \Carbon\Carbon|null $resolved_at
 * @property string $status
 * @property float|null $market_probability
 * @property float $volume_usd
 * @property float $liquidity_usd
 * @property int $num_traders
 * @property float|null $ai_probability
 * @property float|null $edge
 * @property \Carbon\Carbon|null $last_synced_at
 * @property bool $is_tracked
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Market extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'condition_id',
        'slug',
        'question',
        'description',
        'category',
        'sub_category',
        'tags',
        'resolution_source',
        'start_date',
        'end_date',
        'resolved_at',
        'status',
        'market_probability',
        'volume_usd',
        'liquidity_usd',
        'num_traders',
        'ai_probability',
        'edge',
        'last_synced_at',
        'is_tracked',
    ];

    protected $casts = [
        'start_date'        => 'datetime',
        'end_date'          => 'datetime',
        'resolved_at'       => 'datetime',
        'last_synced_at'    => 'datetime',
        'market_probability' => 'float',
        'volume_usd'        => 'float',
        'liquidity_usd'     => 'float',
        'ai_probability'    => 'float',
        'edge'              => 'float',
        'is_tracked'        => 'boolean',
        'num_traders'       => 'integer',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * All time-series snapshots collected for this market.
     * Ordered newest-first by default for dashboard use.
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(MarketSnapshot::class)
            ->orderByDesc('snapshotted_at');
    }

    /**
     * The latest snapshot (single record, no collection overhead).
     */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(MarketSnapshot::class)
            ->latestOfMany('snapshotted_at');
    }

    /**
     * The resolution record — exists only for resolved/cancelled markets.
     */
    public function outcome(): HasOne
    {
        return $this->hasOne(MarketOutcome::class);
    }

    /**
     * All AI predictions made for this market.
     */
    public function aiPredictions(): HasMany
    {
        return $this->hasMany(AiPrediction::class)
            ->orderByDesc('predicted_at');
    }

    /**
     * The most recent AI prediction.
     */
    public function latestAiPrediction(): HasOne
    {
        return $this->hasOne(AiPrediction::class)
            ->latestOfMany('predicted_at');
    }

    /**
     * All signals generated for this market.
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class)
            ->orderByDesc('fired_at');
    }

    /**
     * All paper trades taken on this market.
     */
    public function paperTrades(): HasMany
    {
        return $this->hasMany(PaperTrade::class)
            ->orderByDesc('entered_at');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('status', MarketStatus::Active->value);
    }

    public function scopeCrypto($query)
    {
        return $query->where('category', MarketCategory::Crypto->value);
    }

    public function scopeTracked($query)
    {
        return $query->where('is_tracked', true);
    }

    public function scopeWithEdge($query, float $minEdge = 0.05)
    {
        return $query->where('edge', '>=', $minEdge);
    }

    public function scopeExpiringSoon($query, int $hours = 48)
    {
        return $query->where('end_date', '<=', now()->addHours($hours))
            ->where('end_date', '>', now());
    }

    // =========================================================================
    // Accessors / Helpers
    // =========================================================================

    /**
     * Returns market probability as a percentage string for display.
     */
    public function getProbabilityPercentAttribute(): string
    {
        if ($this->market_probability === null) {
            return 'N/A';
        }

        return number_format($this->market_probability * 100, 1) . '%';
    }

    /**
     * Returns edge as a signed percentage string for display.
     */
    public function getEdgePercentAttribute(): string
    {
        if ($this->edge === null) {
            return 'N/A';
        }

        $sign = $this->edge >= 0 ? '+' : '';
        return $sign . number_format($this->edge * 100, 1) . '%';
    }

    /**
     * True when market is active and has a positive edge above threshold.
     */
    public function hasActionableEdge(float $minEdge = 0.05): bool
    {
        return $this->status === MarketStatus::Active->value
            && $this->edge !== null
            && abs($this->edge) >= $minEdge;
    }
}
