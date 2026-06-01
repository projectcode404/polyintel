<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MarketSnapshot
 *
 * Immutable time-series record of market data at a point in time.
 * Rows in this table are NEVER updated after creation.
 *
 * @property int $id
 * @property int $market_id
 * @property float $probability_yes
 * @property float $probability_no
 * @property float|null $best_bid
 * @property float|null $best_ask
 * @property float|null $spread
 * @property float $volume_usd
 * @property float $volume_24h_usd
 * @property float $liquidity_usd
 * @property float|null $btc_price_usd
 * @property float|null $eth_price_usd
 * @property int|null $fear_greed_index
 * @property float|null $btc_dominance
 * @property string|null $collector_version
 * @property \Carbon\Carbon $snapshotted_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class MarketSnapshot extends Model
{
    use HasFactory;

    /**
     * Snapshots are immutable — disable mass-assignment protection
     * for the collector which fills all fields at once.
     */
    protected $fillable = [
        'market_id',
        'probability_yes',
        'probability_no',
        'best_bid',
        'best_ask',
        'spread',
        'volume_usd',
        'volume_24h_usd',
        'liquidity_usd',
        'btc_price_usd',
        'eth_price_usd',
        'fear_greed_index',
        'btc_dominance',
        'collector_version',
        'snapshotted_at',
    ];

    protected $casts = [
        'probability_yes'  => 'float',
        'probability_no'   => 'float',
        'best_bid'         => 'float',
        'best_ask'         => 'float',
        'spread'           => 'float',
        'volume_usd'       => 'float',
        'volume_24h_usd'   => 'float',
        'liquidity_usd'    => 'float',
        'btc_price_usd'    => 'float',
        'eth_price_usd'    => 'float',
        'fear_greed_index' => 'integer',
        'btc_dominance'    => 'float',
        'snapshotted_at'   => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * YES probability as display percentage.
     */
    public function getProbabilityYesPercentAttribute(): string
    {
        return number_format($this->probability_yes * 100, 2) . '%';
    }

    /**
     * Implied spread in percentage points.
     */
    public function getSpreadBpsAttribute(): ?float
    {
        if ($this->spread === null) {
            return null;
        }

        return round($this->spread * 10000, 2); // Convert to basis points
    }
}
