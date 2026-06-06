<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Signal;
use App\Services\PaperTrading\PortfolioManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessSignalCycleJob
 *
 * Dispatched on a schedule (e.g. every 5 minutes).
 * Loads all active pending signals and passes them to PortfolioManagerService.
 *
 * Only processes signals that:
 * - status = 'pending'
 * - expires_at is null OR expires_at > now()
 */
final class ProcessSignalCycleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Retry once on failure
    public int $tries = 2;

    // Timeout in seconds
    public int $timeout = 120;

    // =========================================================================
    // Handle
    // =========================================================================

    public function handle(PortfolioManagerService $portfolioManager): void
    {
        Log::info('[ProcessSignalCycleJob] Starting signal cycle');

        $signals = $this->loadActiveSignals();

        if ($signals->isEmpty()) {
            Log::info('[ProcessSignalCycleJob] No active signals found. Cycle skipped.');
            return;
        }

        Log::info('[ProcessSignalCycleJob] Loaded active signals', [
            'count' => $signals->count(),
        ]);

        $results = $portfolioManager->processCycle($signals);

        Log::info('[ProcessSignalCycleJob] Cycle complete', [
            'opened'  => $results['opened'],
            'skipped' => $results['skipped'],
        ]);

        if (! empty($results['reasons'])) {
            Log::debug('[ProcessSignalCycleJob] Skip reasons', $results['reasons']);
        }
    }

    // =========================================================================
    // Private
    // =========================================================================

    /**
     * Load pending signals that have not expired.
     * Includes all raw metric columns for scoring.
     */
    private function loadActiveSignals()
    {
        return Signal::where('status', Signal::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->with('market')
            ->orderBy('fired_at', 'desc')
            ->get()
            ->map(fn ($signal) => $signal->toArray());
    }

    // =========================================================================
    // Error Handling
    // =========================================================================

    public function failed(\Throwable $exception): void
    {
        Log::error('[ProcessSignalCycleJob] Job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}