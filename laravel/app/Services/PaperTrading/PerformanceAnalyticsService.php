<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrade;
use App\Models\PaperTradeSetting;
use App\Models\TradingAccount;

/**
 * PerformanceAnalyticsService
 *
 * Menghitung semua performance metrics dari closed trades:
 * win rate, profit factor, expectancy, Sharpe ratio,
 * max consecutive wins/losses, average win/loss, dll.
 *
 * Semua kalkulasi berbasis data real — tidak ada fake metrics.
 */
final class PerformanceAnalyticsService
{
    // =========================================================================
    // Main Entry Point
    // =========================================================================

    /**
     * Semua metrics dalam satu call.
     * Controller hanya memanggil ini.
     */
    public function getFullMetrics(TradingAccount $account): array
    {
        $closedTrades = $this->getClosedTrades($account);

        if ($closedTrades->isEmpty()) {
            return $this->emptyMetrics();
        }

        $wins   = $closedTrades->where('pnl_usd', '>', 0);
        $losses = $closedTrades->where('pnl_usd', '<', 0);

        $grossProfit = $wins->sum('pnl_usd');
        $grossLoss   = abs($losses->sum('pnl_usd'));
        $netPnl      = (float) $closedTrades->sum('pnl_usd');

        $avgWin  = $wins->count() > 0 ? $grossProfit / $wins->count() : 0.0;
        $avgLoss = $losses->count() > 0 ? $grossLoss / $losses->count() : 0.0;

        $profitFactor = $grossLoss > 0
            ? $grossProfit / $grossLoss
            : ($grossProfit > 0 ? 999.0 : 0.0);

        // Expectancy = (Win Rate × Avg Win) - (Loss Rate × Avg Loss)
        $winRate    = $closedTrades->count() > 0
            ? $wins->count() / $closedTrades->count()
            : 0.0;
        $lossRate   = 1 - $winRate;
        $expectancy = ($winRate * $avgWin) - ($lossRate * $avgLoss);

        // Consecutive wins/losses
        [$maxConsecWins, $maxConsecLosses] = $this->calculateConsecutive($closedTrades);

        // Sharpe ratio (simplified, daily returns)
        $sharpeRatio = $this->calculateSharpeRatio($closedTrades);

        // Largest single win/loss
        $largestWin  = $wins->count() > 0 ? (float) $wins->max('pnl_usd') : 0.0;
        $largestLoss = $losses->count() > 0 ? abs((float) $losses->min('pnl_usd')) : 0.0;

        // Average holding time
        $avgHoldingHours = $closedTrades->avg('holding_period_hours') ?? 0.0;

        // ROI stats
        $avgRoi    = $closedTrades->avg('roi') ?? 0.0;
        $bestRoi   = $closedTrades->max('roi') ?? 0.0;
        $worstRoi  = $closedTrades->min('roi') ?? 0.0;

        return [
            // Core metrics
            'total_trades'         => $closedTrades->count(),
            'winning_trades'       => $wins->count(),
            'losing_trades'        => $losses->count(),
            'win_rate'             => round($winRate * 100, 2),
            'loss_rate'            => round($lossRate * 100, 2),

            // PnL
            'net_pnl'              => round($netPnl, 2),
            'gross_profit'         => round($grossProfit, 2),
            'gross_loss'           => round($grossLoss, 2),
            'avg_win'              => round($avgWin, 2),
            'avg_loss'             => round($avgLoss, 2),
            'largest_win'          => round($largestWin, 2),
            'largest_loss'         => round($largestLoss, 2),

            // Ratios
            'profit_factor'        => round($profitFactor, 2),
            'expectancy'           => round($expectancy, 2),
            'sharpe_ratio'         => round($sharpeRatio, 2),

            // Streaks
            'max_consecutive_wins'   => $maxConsecWins,
            'max_consecutive_losses' => $maxConsecLosses,

            // Time
            'avg_holding_hours'    => round($avgHoldingHours, 2),

            // ROI
            'avg_roi_percent'      => round($avgRoi * 100, 2),
            'best_roi_percent'     => round($bestRoi * 100, 2),
            'worst_roi_percent'    => round($worstRoi * 100, 2),
        ];
    }

    // =========================================================================
    // Exit Breakdown
    // =========================================================================

    /**
     * Breakdown performance per exit reason.
     * Berguna untuk mengevaluasi efektifitas setiap exit strategy.
     */
    public function getExitBreakdown(TradingAccount $account): array
    {
        $closedTrades = $this->getClosedTrades($account);

        $statuses = [
            PaperTrade::STATUS_TAKE_PROFIT,
            PaperTrade::STATUS_STOPPED,
            PaperTrade::STATUS_SMART_EXIT,
            PaperTrade::STATUS_EXPIRED,
            PaperTrade::STATUS_CLOSED,
        ];

        $breakdown = [];

        foreach ($statuses as $status) {
            $group = $closedTrades->where('status', $status);

            if ($group->isEmpty()) {
                continue;
            }

            $wins     = $group->where('pnl_usd', '>', 0)->count();
            $total    = $group->count();
            $winRate  = $total > 0 ? round(($wins / $total) * 100, 1) : 0.0;
            $avgPnl   = round($group->avg('pnl_usd'), 2);
            $totalPnl = round($group->sum('pnl_usd'), 2);

            $breakdown[$status] = [
                'status'    => strtoupper($status),
                'count'     => $total,
                'win_rate'  => $winRate,
                'avg_pnl'   => $avgPnl,
                'total_pnl' => $totalPnl,
            ];
        }

        return $breakdown;
    }

    // =========================================================================
    // Monthly Summary
    // =========================================================================

    /**
     * PnL per bulan untuk bar chart di analytics section.
     * Return format siap Chart.js.
     */
    public function getMonthlyPnlChart(TradingAccount $account): array
    {
        $rows = PaperTrade::where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->whereNotNull('exited_at')
            ->selectRaw("
                TO_CHAR(exited_at, 'YYYY-MM') AS month,
                SUM(pnl_usd)                  AS total_pnl,
                COUNT(*)                       AS trade_count,
                SUM(CASE WHEN pnl_usd > 0 THEN 1 ELSE 0 END) AS win_count
            ")
            ->groupByRaw("TO_CHAR(exited_at, 'YYYY-MM')")
            ->orderByRaw("TO_CHAR(exited_at, 'YYYY-MM') ASC")
            ->get();

        $labels     = [];
        $pnlData    = [];
        $colorData  = [];

        foreach ($rows as $row) {
            $labels[]    = $row->month;
            $pnlData[]   = round((float) $row->total_pnl, 2);
            $colorData[] = (float) $row->total_pnl >= 0
                ? 'rgba(47, 179, 68, 0.7)'
                : 'rgba(230, 57, 70, 0.7)';
        }

        return compact('labels', 'pnlData', 'colorData');
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function getClosedTrades(TradingAccount $account): \Illuminate\Database\Eloquent\Collection
    {
        return PaperTrade::where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->orderBy('exited_at')
            ->get([
                'pnl_usd', 'roi', 'status', 'outcome',
                'holding_period_hours', 'position_size_usd',
                'exited_at',
            ]);
    }

    /**
     * Hitung max consecutive wins dan losses dari urutan trades.
     * Return [maxWins, maxLosses].
     */
    private function calculateConsecutive(\Illuminate\Database\Eloquent\Collection $trades): array
    {
        $maxWins   = 0;
        $maxLosses = 0;
        $curWins   = 0;
        $curLosses = 0;

        foreach ($trades as $trade) {
            $pnl = (float) $trade->pnl_usd;

            if ($pnl > 0) {
                $curWins++;
                $curLosses = 0;
                $maxWins   = max($maxWins, $curWins);
            } elseif ($pnl < 0) {
                $curLosses++;
                $curWins   = 0;
                $maxLosses = max($maxLosses, $curLosses);
            } else {
                // Breakeven — reset streak
                $curWins   = 0;
                $curLosses = 0;
            }
        }

        return [$maxWins, $maxLosses];
    }

    /**
     * Simplified Sharpe Ratio menggunakan daily returns dari closed trades.
     * Sharpe = (Mean Return - Risk Free Rate) / Std Dev Returns
     * Risk free rate diasumsikan 0 untuk paper trading.
     */
    private function calculateSharpeRatio(\Illuminate\Database\Eloquent\Collection $trades): float
    {
        if ($trades->count() < 2) {
            return 0.0;
        }

        $returns = $trades->pluck('roi')->map(fn ($r) => (float) $r)->toArray();

        $mean = array_sum($returns) / count($returns);

        $variance = array_sum(
            array_map(fn ($r) => ($r - $mean) ** 2, $returns)
        ) / (count($returns) - 1);

        $stdDev = $variance > 0 ? sqrt($variance) : 0.0;

        if ($stdDev === 0.0) {
            return $mean > 0 ? 999.0 : 0.0;
        }

        // Annualize: assuming ~252 trading periods
        return ($mean / $stdDev) * sqrt(252);
    }

    /**
     * Empty metrics saat belum ada closed trades.
     */
    private function emptyMetrics(): array
    {
        return [
            'total_trades'           => 0,
            'winning_trades'         => 0,
            'losing_trades'          => 0,
            'win_rate'               => 0.0,
            'loss_rate'              => 0.0,
            'net_pnl'                => 0.0,
            'gross_profit'           => 0.0,
            'gross_loss'             => 0.0,
            'avg_win'                => 0.0,
            'avg_loss'               => 0.0,
            'largest_win'            => 0.0,
            'largest_loss'           => 0.0,
            'profit_factor'          => 0.0,
            'expectancy'             => 0.0,
            'sharpe_ratio'           => 0.0,
            'max_consecutive_wins'   => 0,
            'max_consecutive_losses' => 0,
            'avg_holding_hours'      => 0.0,
            'avg_roi_percent'        => 0.0,
            'best_roi_percent'       => 0.0,
            'worst_roi_percent'      => 0.0,
        ];
    }
}
