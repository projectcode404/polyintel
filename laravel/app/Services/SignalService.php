<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Signal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

final class SignalService
{
    /**
     * Get active pending signals that are not expired.
     */
    public function getPendingSignals(): Collection
    {
        return Signal::pending()
            ->notExpired()
            ->with(['market'])
            ->get();
    }

    /**
     * Mark a signal as expired if its time has passed.
     */
    public function expireOldSignals(): int
    {
        return Signal::pending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'cancelled', 'notes' => 'Expired automatically']);
    }

    /**
     * Mark signal as active (meaning it has been traded).
     */
    public function markAsActive(Signal $signal): void
    {
        $signal->update(['status' => 'active']);
    }

    /**
     * Ignore/reject a signal manually.
     */
    public function ignoreSignal(Signal $signal, string $reason = 'Manually ignored'): void
    {
        $signal->update([
            'status' => 'cancelled',
            'notes' => $reason
        ]);
    }
}
