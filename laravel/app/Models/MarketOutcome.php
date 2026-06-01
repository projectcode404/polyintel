<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MarketOutcome
 *
 * Ground truth resolution for a market. One record per market, created
 * when the market resolves. Used to score AI predictions and measure signal accuracy.
 */
final class MarketOutcome extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'winning_side',
        'resolution_price',
        'final_probability_before_resolution',
        'peak_probability_yes',
        'low_probability_yes',
        'total_volume_usd',
        'resolved_by',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'resolution_price'                    => 'float',
        'final_probability_before_resolution' => 'float',
        'peak_probability_yes'                => 'float',
        'low_probability_yes'                 => 'float',
        'total_volume_usd'                    => 'float',
        'resolved_at'                         => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** True if the market resolved YES. */
    public function resolvedYes(): bool
    {
        return $this->winning_side === 'yes';
    }

    /** True if the market was cancelled (voided — no PnL). */
    public function wasCancelled(): bool
    {
        return $this->winning_side === 'cancelled';
    }
}
