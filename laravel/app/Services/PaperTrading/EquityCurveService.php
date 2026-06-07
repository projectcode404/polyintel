<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrade;
use App\Models\PaperTradeSetting;
use App\Models\TradingAccount;

/**
 * EquityCurveService
 *
 * Membangun equity curve dari closed trades secara kronologis.
 * Output siap dipakai Chart.js di dashboard.
 *
 * Equity curve dihitung dari:
 *   - Initial capital (dari PaperTradeSetting)
 *   - Setiap closed trade menambah/mengurangi equity
 *
 * Juga menghitung drawdown per titik waktu.
 */
final class EquityCurveService
{
    // =========================================================================
    // Main Entry Point
    // =========================================================================

    /**
     * Data equity curve siap Chart.js.
     *
     * Return format:
     * [
     *   'labels'   => ['2026-06-01', ...],
     *   'equity'   => [1000.00, 1020.50, ...],
     *   'drawdown' => [0.00, -2.50, ...],     // dalam %
     *   'baseline' => 1000.00,                // initial capital
     * ]
     */
    public function getEquityCurve(TradingAccount $account): array
    {
        $settings       = PaperTradeSetting::current();
        $initialCapital = (float) $settings->initial_capital;

        $closedTrades = PaperTrade::where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->whereNotNull('exited_at')
            ->orderBy('exited_at')
            ->get(['pnl_usd', 'exited_at', 'status']);

        // Titik awal
        $labels   = [now()->subDays($closedTrades->count() + 1)->format('Y-m-d H:i')];
        $equity   = [$initialCapital];
        $drawdown = [0.0];

        $currentEquity = $initialCapital;
        $peakEquity    = $initialCapital;

        foreach ($closedTrades as $trade) {
            $currentEquity += (float) $trade->pnl_usd;

            if ($currentEquity > $peakEquity) {
                $peakEquity = $currentEquity;
            }

            $drawdownPct = $peakEquity > 0
                ? (($currentEquity - $peakEquity) / $peakEquity) * 100
                : 0.0;

            $labels[]   = $trade->exited_at->format('Y-m-d H:i');
            $equity[]   = round($currentEquity, 2);
            $drawdown[] = round($drawdownPct, 2);
        }

        return [
            'labels'   => $labels,
            'equity'   => $equity,
            'drawdown' => $drawdown,
            'baseline' => $initialCapital,
        ];
    }

    // =========================================================================
    // Daily Equity (untuk chart dengan range lebih panjang)
    // =========================================================================

    /**
     * Equity curve dikelompokkan per hari.
     * Cocok untuk chart dengan rentang lebih dari 30 hari.
     *
     * Mengambil equity snapshot akhir hari (closing equity per hari).
     */
    public function getDailyEquityCurve(TradingAccount $account): array
    {
        $settings       = PaperTradeSetting::current();
        $initialCapital = (float) $settings->initial_capital;

        // Ambil PnL per hari, diurutkan kronologis
        $dailyRows = PaperTrade::where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->whereNotNull('exited_at')
            ->selectRaw("
                DATE(exited_at) AS trade_date,
                SUM(pnl_usd)    AS daily_pnl,
                COUNT(*)        AS trade_count
            ")
            ->groupByRaw('DATE(exited_at)')
            ->orderByRaw('DATE(exited_at) ASC')
            ->get();

        if ($dailyRows->isEmpty()) {
            return [
                'labels'   => [now()->format('Y-m-d')],
                'equity'   => [$initialCapital],
                'drawdown' => [0.0],
                'baseline' => $initialCapital,
            ];
        }

        $labels   = [];
        $equity   = [];
        $drawdown = [];

        $currentEquity = $initialCapital;
        $peakEquity    = $initialCapital;

        foreach ($dailyRows as $row) {
            $currentEquity += (float) $row->daily_pnl;

            if ($currentEquity > $peakEquity) {
                $peakEquity = $currentEquity;
            }

            $drawdownPct = $peakEquity > 0
                ? (($currentEquity - $peakEquity) / $peakEquity) * 100
                : 0.0;

            $labels[]   = $row->trade_date;
            $equity[]   = round($currentEquity, 2);
            $drawdown[] = round($drawdownPct, 2);
        }

        return [
            'labels'   => $labels,
            'equity'   => $equity,
            'drawdown' => $drawdown,
            'baseline' => $initialCapital,
        ];
    }

    // =========================================================================
    // Summary Stats dari Equity Curve
    // =========================================================================

    /**
     * Statistik ringkas dari equity curve.
     * Digunakan untuk stat cards di atas chart.
     */
    public function getEquitySummary(TradingAccount $account): array
    {
        $curve = $this->getEquityCurve($account);

        $equityPoints = $curve['equity'];
        $baseline     = $curve['baseline'];

        if (count($equityPoints) < 2) {
            return [
                'current_equity'  => $baseline,
                'peak_equity'     => $baseline,
                'max_drawdown'    => 0.0,
                'total_return'    => 0.0,
                'total_return_pct' => 0.0,
            ];
        }

        $currentEquity = end($equityPoints);
        $peakEquity    = max($equityPoints);
        $maxDrawdown   = min($curve['drawdown']);
        $totalReturn   = $currentEquity - $baseline;
        $totalReturnPct = $baseline > 0
            ? ($totalReturn / $baseline) * 100
            : 0.0;

        return [
            'current_equity'   => round($currentEquity, 2),
            'peak_equity'      => round($peakEquity, 2),
            'max_drawdown'     => round($maxDrawdown, 2),      // negatif
            'total_return'     => round($totalReturn, 2),
            'total_return_pct' => round($totalReturnPct, 2),
        ];
    }
}
