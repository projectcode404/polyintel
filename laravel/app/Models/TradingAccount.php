<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TradingAccount
 *
 * Represents a user's paper trading portfolio and settings.
 */
final class TradingAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'balance',
        'is_auto_trade',
        'is_auto_close',
    ];

    protected $casts = [
        'balance'       => 'float',
        'is_auto_trade' => 'boolean',
        'is_auto_close' => 'boolean',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paperTrades(): HasMany
    {
        return $this->hasMany(PaperTrade::class);
    }
}
