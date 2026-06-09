<?php

/**
 * PATCH #1 — PaperTradeHistory.php
 *
 * FILE: app/Models/PaperTradeHistory.php
 *
 * ROOT CAUSE
 * ----------
 * EVENT_PARTIAL_CLOSE berada di dalam CLOSING_EVENTS.
 * Pada saat TP1/TP2 dipicu, executePartialExit() menulis DUA history record:
 *   1. EVENT_TP1  (dengan pnl_realized = $pnl, shares_affected = $shares)
 *   2. EVENT_PARTIAL_CLOSE (dengan pnl_realized = $pnl yang SAMA, shares_affected SAMA)
 *
 * Karena keduanya masuk CLOSING_EVENTS:
 *   - getTotalRealizedPnl() menjumlah $pnl dua kali → pnl inflate 2-3x
 *   - sharesRemaining() menjumlah shares_affected dua kali → remaining = 0 sebelum waktunya
 *
 * FIX YANG DIPILIH: OPTION B (safer, backward compatible)
 * -------------------------------------------------------
 * - EVENT_PARTIAL_CLOSE tetap ada sebagai audit/display event
 * - EVENT_PARTIAL_CLOSE DIKELUARKAN dari CLOSING_EVENTS
 * - Konsekuensinya: executePartialExit() harus set pnl_realized = 0, shares_affected = 0
 *   pada EVENT_PARTIAL_CLOSE (hanya metadata, bukan accounting)
 *
 * Mengapa Option B lebih aman dari Option A:
 * - Tidak menghapus event type yang mungkin sudah dipakai di tempat lain (views, queries, reports)
 * - EVENT_PARTIAL_CLOSE masih bisa tampil di trade history UI sebagai label human-readable
 * - Jika ada history lama di DB yang punya EVENT_PARTIAL_CLOSE dengan nilai, tidak crash
 * - Satu-satunya source of truth untuk accounting adalah TP1/TP2/SMART_EXIT/CLOSED/STOP_LOSS
 *
 * DIFF
 * ----
 * SEBELUM:
 *   const CLOSING_EVENTS = [
 *       self::EVENT_PARTIAL_CLOSE,   // ← DIHAPUS dari sini
 *       self::EVENT_TP1,
 *       ...
 *   ];
 *
 * SESUDAH:
 *   const CLOSING_EVENTS = [
 *       // EVENT_PARTIAL_CLOSE sengaja tidak ada di sini — audit event only
 *       self::EVENT_TP1,
 *       ...
 *   ];
 *
 * RISIKO
 * ------
 * LOW: Hanya mengubah constant array. Tidak ada logic baru.
 * Pastikan tidak ada kode lain yang bergantung pada EVENT_PARTIAL_CLOSE
 * masuk CLOSING_EVENTS untuk perhitungan — audit dengan grep sebelum deploy.
 *
 * RESET DATA: YA — historical data sudah corrupt, harus di-truncate setelah patch deploy.
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaperTradeHistory extends Model
{
    // No updated_at — history rows are immutable
    const UPDATED_AT = null;

    protected $table = 'paper_trade_history';

    // -------------------------------------------------------------------------
    // Event Type Constants
    // -------------------------------------------------------------------------

    const EVENT_OPENED          = 'OPENED';
    const EVENT_PARTIAL_CLOSE   = 'PARTIAL_CLOSE';   // audit/display only — NOT in CLOSING_EVENTS
    const EVENT_TP1             = 'TP1';
    const EVENT_TP2             = 'TP2';
    const EVENT_TP3             = 'TP3';
    const EVENT_STOP_LOSS       = 'STOP_LOSS';
    const EVENT_BREAKEVEN_MOVED = 'BREAKEVEN_MOVED';
    const EVENT_SMART_EXIT      = 'SMART_EXIT';
    const EVENT_CLOSED          = 'CLOSED';
    const EVENT_EXPIRED         = 'EXPIRED';

    /**
     * Events that contribute to realized PnL and reduce shares.
     *
     * EVENT_PARTIAL_CLOSE is intentionally excluded.
     * It is an audit/display event written alongside TP1/TP2 with
     * pnl_realized = 0 and shares_affected = 0 (see SmartExitMonitorJob).
     * Including it would cause double-counting in getTotalRealizedPnl()
     * and sharesRemaining().
     */
    const CLOSING_EVENTS = [
        // EVENT_PARTIAL_CLOSE deliberately excluded — audit event only
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
