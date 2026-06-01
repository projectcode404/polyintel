<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PaperTrade
 *
 * A simulated trade executed from a signal. All PnL metrics are calculated
 * with the same precision as a real trade. No fake numbers.
 *
 * PnL formula:
 *   pnl_usd = (exit_price - entry_price) * shares - fees_usd
 *   roi      = pnl_usd / position_size_usd
 */
final class PaperTrade extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'market_id',
        'signal_id',
        'direction',
        'entry_price',
        'exit_price',
        'shares',
        'position_size_usd',
        'fees_usd',
        'pnl_usd',
        'roi',
        'current_price',
        'unrealized_pnl_usd',
        'max_adverse_excursion',
        'max_favorable_excursion',
        'market_probability_at_entry',
        'ai_probability_at_entry',
        'edge_at_entry',
        'status',
        'outcome',
        'holding_period_hours',
        'notes',
        'entered_at',
        'exited_at',
    ];

    protected $casts = [
        'entry_price'                  => 'float',
        'exit_price'                   => 'float',
        'shares'                       => 'float',
        'position_size_usd'            => 'float',
        'fees_usd'                     => 'float',
        'pnl_usd'                      => 'float',
        'roi'                          => 'float',
        'current_price'                => 'float',
        'unrealized_pnl_usd'           => 'float',
        'max_adverse_excursion'        => 'float',
        'max_favorable_excursion'      => 'float',
        'market_probability_at_entry'  => 'float',
        'ai_probability_at_entry'      => 'float',
        'edge_at_entry'                => 'float',
        'holding_period_hours'         => 'float',
        'entered_at'                   => 'datetime',
        'exited_at'                    => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeWinners($query)
    {
        return $query->where('outcome', 'win');
    }

    public function scopeLosers($query)
    {
        return $query->where('outcome', 'loss');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function getRoiPercentAttribute(): string
    {
        if ($this->roi === null) {
            return 'Open';
        }

        $sign = $this->roi >= 0 ? '+' : '';
        return $sign . number_format($this->roi * 100, 2) . '%';
    }

    public function getPnlFormattedAttribute(): string
    {
        if ($this->pnl_usd === null) {
            return 'Open';
        }

        $sign = $this->pnl_usd >= 0 ? '+$' : '-$';
        return $sign . number_format(abs($this->pnl_usd), 2);
    }

    /**
     * Verify PnL calculation integrity.
     * Used in tests and the service layer before saving.
     */
    public function verifyPnlIntegrity(): bool
    {
        if ($this->exit_price === null || $this->pnl_usd === null) {
            return true; // Open trade — nothing to verify yet
        }

        $expectedPnl = ($this->exit_price - $this->entry_price) * $this->shares - $this->fees_usd;
        $expectedRoi = $this->position_size_usd > 0
            ? $expectedPnl / $this->position_size_usd
            : 0;

        return abs($expectedPnl - $this->pnl_usd) < 0.0001
            && abs($expectedRoi - $this->roi) < 0.000001;
    }
}
