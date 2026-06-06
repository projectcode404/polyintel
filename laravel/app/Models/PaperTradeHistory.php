<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaperTradeHistory
 *
 * Immutable event log for paper trade lifecycle.
 * Source of truth for partial closes and shares remaining.
 * Never updated — only inserted.
 */
final class PaperTradeHistory extends Model
{
    // No updated_at — history rows are immutable
    const UPDATED_AT = null;

    protected $table = 'paper_trade_history';

    // -------------------------------------------------------------------------
    // Event Type Constants
    // -------------------------------------------------------------------------

    const EVENT_OPENED          = 'OPENED';
    const EVENT_PARTIAL_CLOSE   = 'PARTIAL_CLOSE';
    const EVENT_TP1             = 'TP1';
    const EVENT_TP2             = 'TP2';
    const EVENT_TP3             = 'TP3';
    const EVENT_STOP_LOSS       = 'STOP_LOSS';
    const EVENT_BREAKEVEN_MOVED = 'BREAKEVEN_MOVED';
    const EVENT_SMART_EXIT      = 'SMART_EXIT';
    const EVENT_CLOSED          = 'CLOSED';
    const EVENT_EXPIRED         = 'EXPIRED';

    // Events that reduce shares (used by sharesRemaining())
    const CLOSING_EVENTS = [
        self::EVENT_PARTIAL_CLOSE,
        self::EVENT_TP1,
        self::EVENT_TP2,
        self::EVENT_TP3,
        self::EVENT_STOP_LOSS,
        self::EVENT_SMART_EXIT,
        self::EVENT_CLOSED,
        self::EVENT_EXPIRED,
    ];

    // -------------------------------------------------------------------------
    // Fillable
    // -------------------------------------------------------------------------

    protected $fillable = [
        'paper_trade_id',
        'event_type',
        'price_at_event',
        'shares_affected',
        'pnl_realized',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'price_at_event'  => 'decimal:8',
        'shares_affected' => 'decimal:8',
        'pnl_realized'    => 'decimal:2',
        'created_at'      => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function paperTrade(): BelongsTo
    {
        return $this->belongsTo(PaperTrade::class);
    }

    // =========================================================================
    // Boot
    // =========================================================================

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }
}