<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 *
 * Status values match varchar(30) column — no enum migration required.
 * sharesRemaining() is derived from paper_trade_history — never stored.
 */
final class PaperTrade extends Model
{
    use HasFactory;
    use SoftDeletes;

    // -------------------------------------------------------------------------
    // Status Constants
    // Matches varchar(30) column default 'open'
    // -------------------------------------------------------------------------

    const STATUS_OPEN        = 'OPEN';
    const STATUS_PARTIAL     = 'PARTIAL';
    const STATUS_CLOSED      = 'CLOSED';
    const STATUS_STOPPED     = 'STOPPED';
    const STATUS_TAKE_PROFIT = 'TAKE_PROFIT';
    const STATUS_SMART_EXIT  = 'SMART_EXIT';
    const STATUS_EXPIRED     = 'EXPIRED';

    const OPEN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PARTIAL,
    ];

    const CLOSED_STATUSES = [
        self::STATUS_CLOSED,
        self::STATUS_STOPPED,
        self::STATUS_TAKE_PROFIT,
        self::STATUS_SMART_EXIT,
        self::STATUS_EXPIRED,
    ];

    // -------------------------------------------------------------------------
    // Fillable
    // Maps exactly to database columns — existing + Phase 2 additions
    // -------------------------------------------------------------------------

    protected $fillable = [
        // Existing columns
        'trading_account_id',
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
        // Phase 2 additions
        'signal_score',
        'position_size_mode',
        'take_profit_price',
        'stop_loss_price',
        'breakeven_price',
        'exit_reason',
        'smart_exit_reason',
    ];

    protected $casts = [
        'entry_price'                  => 'decimal:6',
        'exit_price'                   => 'decimal:6',
        'shares'                       => 'decimal:6',
        'position_size_usd'            => 'decimal:2',
        'fees_usd'                     => 'decimal:4',
        'pnl_usd'                      => 'decimal:4',
        'roi'                          => 'decimal:6',
        'current_price'                => 'decimal:6',
        'unrealized_pnl_usd'           => 'decimal:4',
        'max_adverse_excursion'        => 'decimal:6',
        'max_favorable_excursion'      => 'decimal:6',
        'market_probability_at_entry'  => 'decimal:6',
        'ai_probability_at_entry'      => 'decimal:6',
        'edge_at_entry'                => 'decimal:6',
        'holding_period_hours'         => 'decimal:4',
        'signal_score'                 => 'decimal:4',
        'take_profit_price'            => 'decimal:6',
        'stop_loss_price'              => 'decimal:6',
        'breakeven_price'              => 'decimal:6',
        'entered_at'                   => 'datetime',
        'exited_at'                    => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(PaperTradeHistory::class)->orderBy('created_at');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeClosed($query)
    {
        return $query->whereIn('status', self::CLOSED_STATUSES);
    }

    public function scopeForMarket($query, int $marketId)
    {
        return $query->where('market_id', $marketId);
    }

    // =========================================================================
    // Computed Properties
    // =========================================================================

    /**
     * Shares remaining after partial closes.
     * Derived from history events — NOT stored in database.
     */
    public function sharesRemaining(): float
    {
        $closedShares = $this->history()
            ->whereIn('event_type', [
                PaperTradeHistory::EVENT_PARTIAL_CLOSE,
                PaperTradeHistory::EVENT_TP1,
                PaperTradeHistory::EVENT_TP2,
                PaperTradeHistory::EVENT_TP3,
                PaperTradeHistory::EVENT_STOP_LOSS,
                PaperTradeHistory::EVENT_SMART_EXIT,
                PaperTradeHistory::EVENT_CLOSED,
                PaperTradeHistory::EVENT_EXPIRED,
            ])
            ->sum('shares_affected');

        return max(0.0, (float) $this->shares - (float) $closedShares);
    }

    /**
     * Holding period in hours from entered_at to now (or exited_at).
     */
    public function holdingHours(): float
    {
        $end = $this->exited_at ?? now();
        return round($this->entered_at->diffInMinutes($end) / 60, 2);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function getRoiPercentAttribute(): string
    {
        if ($this->roi === null) {
            return 'Open';
        }
        $sign = $this->roi >= 0 ? '+' : '';
        return $sign . number_format((float) $this->roi * 100, 2) . '%';
    }

    public function getPnlFormattedAttribute(): string
    {
        if ($this->pnl_usd === null) {
            return 'Open';
        }
        $sign = $this->pnl_usd >= 0 ? '+$' : '-$';
        return $sign . number_format(abs((float) $this->pnl_usd), 2);
    }

    /**
     * Verify PnL integrity against formula.
     * Used in tests and audit checks.
     */
    public function verifyPnlIntegrity(): bool
    {
        if ($this->exit_price === null || $this->pnl_usd === null) {
            return true;
        }

        $expectedPnl = ((float) $this->exit_price - (float) $this->entry_price)
            * (float) $this->shares
            - (float) $this->fees_usd;

        $expectedRoi = (float) $this->position_size_usd > 0
            ? $expectedPnl / (float) $this->position_size_usd
            : 0;

        return abs($expectedPnl - (float) $this->pnl_usd) < 0.0001
            && abs($expectedRoi - (float) $this->roi) < 0.000001;
    }
}