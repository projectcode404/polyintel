<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrade;
use App\Models\PaperTradeSetting;
use Illuminate\Support\Facades\DB;

/**
 * PortfolioMetricsService
 *
 * All portfolio state is derived from source-of-truth data.
 * Nothing is stored — everything is calculated from:
 *   - initial_capital (settings)
 *   - pnl_usd on closed trades (realized PnL)
 *   - current_price + entry_price + shares on open trades (unrealized PnL)
 *   - position_size_usd on open trades (allocated capital)
 *
 * NOTE: shares_remaining is NOT a database column.
 * Allocated capital and unrealized PnL use position_size_usd for open trades
 * as the source of truth until partial closes are tracked by SmartExitEngine (Phase 3).
 * After Phase 3, these queries will be updated to use paper_trade_history sums.
 */
final class PortfolioMetricsService
{
    /**
     * Calculate full portfolio state.
     *
     * @return array{
     *   initial_capital: float,
     *   realized_pnl: float,
     *   unrealized_pnl: float,
     *   current_equity: float,
     *   allocated_capital: float,
     *   cash_balance: float,
     *   exposure_percent: float,
     *   open_trades_count: int,
     *   closed_trades_count: int,
     * }
     */
    public function getPortfolioState(PaperTradeSetting $settings): array
    {
        $initialCapital = (float) $settings->initial_capital;
        $realizedPnl    = $this->getRealizedPnl();
        $unrealizedPnl  = $this->getUnrealizedPnl();
        $allocatedCap   = $this->getAllocatedCapital();

        $currentEquity = $initialCapital + $realizedPnl + $unrealizedPnl;
        $cashBalance   = $initialCapital + $realizedPnl - $allocatedCap;
        $exposurePct   = $currentEquity > 0
            ? round(($allocatedCap / $currentEquity) * 100, 2)
            : 0.0;

        return [
            'initial_capital'     => $initialCapital,
            'realized_pnl'        => $realizedPnl,
            'unrealized_pnl'      => $unrealizedPnl,
            'current_equity'      => $currentEquity,
            'allocated_capital'   => $allocatedCap,
            'cash_balance'        => $cashBalance,
            'exposure_percent'    => $exposurePct,
            'open_trades_count'   => $this->getOpenTradesCount(),
            'closed_trades_count' => $this->getClosedTradesCount(),
        ];
    }

    // =========================================================================
    // Core Metrics
    // =========================================================================

    /**
     * Sum of pnl_usd from all closed/resolved trades.
     */
    public function getRealizedPnl(): float
    {
        return (float) PaperTrade::whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->sum('pnl_usd');
    }

    /**
     * Sum of unrealized PnL from all open/partial trades.
     *
     * Formula: (current_price - entry_price) * shares
     *
     * Uses full shares — not shares_remaining — until Phase 3 partial
     * close tracking is implemented. This is intentionally conservative.
     * When current_price is NULL, falls back to entry_price (unrealized = 0).
     */
    public function getUnrealizedPnl(): float
    {
        $result = PaperTrade::whereIn('status', PaperTrade::OPEN_STATUSES)
            ->selectRaw('
                COALESCE(
                    SUM(
                        (COALESCE(current_price, entry_price) - entry_price) * shares
                    ),
                    0
                ) AS unrealized_pnl
            ')
            ->value('unrealized_pnl');

        return (float) $result;
    }

    /**
     * Total capital currently allocated to open positions.
     * Uses position_size_usd as the definitive allocated amount.
     * This is the actual USD deployed, not entry_price * shares.
     */
    public function getAllocatedCapital(): float
    {
        return (float) PaperTrade::whereIn('status', PaperTrade::OPEN_STATUSES)
            ->sum('position_size_usd');
    }

    public function getOpenTradesCount(): int
    {
        return PaperTrade::whereIn('status', PaperTrade::OPEN_STATUSES)->count();
    }

    public function getClosedTradesCount(): int
    {
        return PaperTrade::whereIn('status', PaperTrade::CLOSED_STATUSES)->count();
    }

    // =========================================================================
    // Performance Metrics
    // =========================================================================

    /**
     * Win rate = closed winning trades / total closed trades (percentage)
     */
    public function getWinRate(): float
    {
        $closed = PaperTrade::whereIn('status', PaperTrade::CLOSED_STATUSES)->count();

        if ($closed === 0) {
            return 0.0;
        }

        $wins = PaperTrade::whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->where('pnl_usd', '>', 0)
            ->count();

        return round(($wins / $closed) * 100, 2);
    }

    /**
     * Profit factor = gross profit / gross loss
     * Returns 999.0 if no losses exist and profit > 0.
     */
    public function getProfitFactor(): float
    {
        $grossProfit = (float) PaperTrade::whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->where('pnl_usd', '>', 0)
            ->sum('pnl_usd');

        $grossLoss = abs(
            (float) PaperTrade::whereIn('status', PaperTrade::CLOSED_STATUSES)
                ->where('pnl_usd', '<', 0)
                ->sum('pnl_usd')
        );

        if ($grossLoss == 0) {
            return $grossProfit > 0 ? 999.0 : 0.0;
        }

        return round($grossProfit / $grossLoss, 2);
    }

    /**
     * Max drawdown percentage from peak equity.
     * Reconstructed from paper_trade_history realized PnL events.
     */
    public function getMaxDrawdown(float $initialCapital): float
    {
        $equityCurve = $this->buildEquityCurve($initialCapital);

        if (empty($equityCurve)) {
            return 0.0;
        }

        $peak        = $initialCapital;
        $maxDrawdown = 0.0;

        foreach ($equityCurve as $equity) {
            if ($equity > $peak) {
                $peak = $equity;
            }

            $drawdown = $peak > 0
                ? (($peak - $equity) / $peak) * 100
                : 0.0;

            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        return round($maxDrawdown, 2);
    }

    // =========================================================================
    // Portfolio Constraint Check
    // =========================================================================

    /**
     * Validate whether a new trade can be opened.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canOpenTrade(PaperTradeSetting $settings, float $positionSize): array
    {
        $state = $this->getPortfolioState($settings);

        // 1. Max concurrent trades
        if ($state['open_trades_count'] >= (int) $settings->max_concurrent_trades) {
            return [
                'allowed' => false,
                'reason'  => "Max concurrent trades reached ({$settings->max_concurrent_trades})",
            ];
        }

        // 2. Reserve cash floor
        $reserveRequired = (float) $settings->initial_capital
            * ((float) $settings->reserve_cash_percent / 100);
        $cashAfterTrade = $state['cash_balance'] - $positionSize;

        if ($cashAfterTrade < $reserveRequired) {
            return [
                'allowed' => false,
                'reason'  => 'Insufficient cash after reserve: $'
                    . number_format($reserveRequired, 2) . ' reserve required',
            ];
        }

        // 3. Max portfolio exposure
        $newAllocated   = $state['allocated_capital'] + $positionSize;
        $newExposurePct = $state['current_equity'] > 0
            ? ($newAllocated / $state['current_equity']) * 100
            : 0.0;

        if ($newExposurePct > (float) $settings->max_portfolio_exposure_percent) {
            return [
                'allowed' => false,
                'reason'  => "Exposure limit exceeded: {$newExposurePct}% > {$settings->max_portfolio_exposure_percent}%",
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    // =========================================================================
    // Private
    // =========================================================================

    /**
     * Build chronological equity curve from realized PnL events in history.
     */
    private function buildEquityCurve(float $initialCapital): array
    {
        $events = DB::table('paper_trade_history')
            ->whereIn('event_type', [
                'TP1', 'TP2', 'TP3',
                'PARTIAL_CLOSE',
                'STOP_LOSS',
                'SMART_EXIT',
                'CLOSED',
                'EXPIRED',
            ])
            ->orderBy('created_at')
            ->pluck('pnl_realized');

        $equity = $initialCapital;
        $curve  = [];

        foreach ($events as $pnl) {
            $equity  += (float) $pnl;
            $curve[] = $equity;
        }

        return $curve;
    }
}