<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MarketDailyStat
 *
 * Precomputed stats for daily signal generation lookups.
 */
final class MarketDailyStat extends Model
{
    protected $fillable = [
        'market_id',
        'stat_date',
        'volume_7d_avg_usd',
        'oi_change_percent',
        'momentum_24h_percent',
    ];

    protected $casts = [
        'stat_date'            => 'date',
        'volume_7d_avg_usd'    => 'float',
        'oi_change_percent'    => 'float',
        'momentum_24h_percent' => 'float',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
