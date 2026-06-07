<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Signal;
use App\Models\TradingAccount;
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


        $accounts = TradingAccount::where('is_auto_trade', true)->get();

        if ($accounts->isEmpty()) {
            Log::info('[ProcessSignalCycleJob] No accounts with auto-trade enabled. Cycle skipped.');
            return;
        }

        Log::info('[ProcessSignalCycleJob] Processing accounts', [
            'signals'  => $signals->count(),
            'accounts' => $accounts->count(),
        ]);

        $totalOpened  = 0;
        $totalSkipped = 0;

        foreach ($accounts as $account) {
            Log::info('[ProcessSignalCycleJob] Processing account', ['account_id' => $account->id]);

            $results = $portfolioManager->processCycle($signals, $account);

            $totalOpened  += $results['opened'];
            $totalSkipped += $results['skipped'];

            if (! empty($results['reasons'])) {
                Log::debug('[ProcessSignalCycleJob] Skip reasons', $results['reasons']);
            }
        }

        Log::info('[ProcessSignalCycleJob] Cycle complete', [
            'opened'  => $totalOpened,
            'skipped' => $totalSkipped,
        ]);
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
            ->map(function ($signal) {
                $arr = $signal->toArray();
                if (empty($arr['current_price']) || (float) $arr['current_price'] === 0.0) {
                    $snapshot = is_array($arr['snapshot_data']) ? $arr['snapshot_data'] : [];
                    $arr['current_price'] = (float) ($snapshot['price_entry'] ?? 0);
                }
                return $arr;
            });
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