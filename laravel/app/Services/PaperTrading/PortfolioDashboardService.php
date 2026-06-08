<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrade;
use App\Models\PaperTradeHistory;
use App\Models\PaperTradeSetting;
use App\Models\TradingAccount;
use Illuminate\Support\Facades\DB;

/**
 * PortfolioDashboardService
 *
 * Menyediakan semua data agregasi untuk overview cards
 * dan stats di paper trading dashboard.
 *
 * Controller hanya memanggil service ini.
 * Zero business logic di controller.
 */
final class PortfolioDashboardService
{
    // =========================================================================
    // Overview Cards
    // =========================================================================

    /**
     * Data lengkap untuk semua overview cards di dashboard.
     * Single query entry point untuk controller.
     */
    public function getOverviewCards(TradingAccount $account): array
    {
        $settings       = $account->settings ?? PaperTradeSetting::current();
        $initialCapital = (float) $settings->initial_capital;

        $openTrades   = $this->getOpenTrades($account);
        $closedTrades = $this->getClosedTrades($account);

        $totalPositionSize = $openTrades->sum('position_size_usd');
        $unrealizedPnl     = $openTrades->sum('unrealized_pnl_usd');
        $realizedPnl       = $closedTrades->sum('pnl_usd');

        $currentBalance = $initialCapital + $realizedPnl - $totalPositionSize;
        $currentEquity  = $initialCapital + $realizedPnl + $unrealizedPnl;
        $totalPnl       = $realizedPnl + $unrealizedPnl;

        // Portfolio value = initial capital + total PnL (realized + unrealized)
        $portfolioValue = $currentEquity;

        // Exposure = allocated capital / current equity
        $exposurePercent = $currentEquity > 0
            ? ($totalPositionSize / $currentEquity) * 100
            : 0.0;

        // ROI = total PnL / initial capital
        $roi = $initialCapital > 0
            ? ($totalPnl / $initialCapital) * 100
            : 0.0;

        // Win rate dari closed trades
        $winCount    = $closedTrades->where('outcome', 'win')->count();
        $lossCount   = $closedTrades->where('outcome', 'loss')->count();
        $totalClosed = $closedTrades->count();
        // Hanya hitung trade yang outcome definitif (win/loss).
        // Exclude NULL, breakeven, cancelled agar win rate tidak terdilusi.
        $ratedCount  = $winCount + $lossCount;
        $winRate     = $ratedCount > 0 ? ($winCount / $ratedCount) * 100 : 0.0;

        // Profit factor
        $grossProfit = $closedTrades->where('pnl_usd', '>', 0)->sum('pnl_usd');
        $grossLoss   = abs($closedTrades->where('pnl_usd', '<', 0)->sum('pnl_usd'));
        $profitFactor = $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? 999.0 : 0.0);

        // Max drawdown dari equity curve
        $maxDrawdown = $this->calculateMaxDrawdown($account);

        return [
            'portfolio_value'    => round($portfolioValue, 2),
            'current_equity'     => round($currentEquity, 2),
            'cash_available'     => round($currentBalance, 2),
            'allocated_capital'  => round($totalPositionSize, 2),
            'exposure_percent'   => round($exposurePercent, 2),
            'open_trades'        => $openTrades->count(),
            'closed_trades'      => $totalClosed,
            'win_rate'           => round($winRate, 2),
            'total_pnl'          => round($totalPnl, 2),
            'realized_pnl'       => round($realizedPnl, 2),
            'unrealized_pnl'     => round($unrealizedPnl, 2),
            'roi_percent'        => round($roi, 2),
            'profit_factor'      => round($profitFactor, 2),
            'max_drawdown'       => round($maxDrawdown, 2),
            'initial_capital'    => round($initialCapital, 2),
            'preset'             => $settings->preset ?? 'balanced',
        ];
    }

    // =========================================================================
    // Active Trades (untuk AG Grid)
    // =========================================================================

    /**
     * Data active trades untuk AG Grid server-side.
     * Format kolom sesuai Phase 4 spec.
     */
    public function getActiveTradesForGrid(TradingAccount $account, \Illuminate\Http\Request $request): array
    {
        $startRow  = (int) $request->input('startRow', 0);
        $endRow    = (int) $request->input('endRow', 100);
        $pageSize  = $endRow - $startRow;
        $sortModel = json_decode($request->input('sortModel', '[]'), true) ?? [];

        $query = PaperTrade::with(['market', 'signal'])
            ->where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::OPEN_STATUSES);

        $totalRows = $query->count();

        // Sorting
        if (! empty($sortModel)) {
            foreach ($sortModel as $sort) {
                $col = $this->sanitizeColumn($sort['colId'] ?? '');
                $dir = ($sort['sort'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                if ($col) {
                    $query->orderBy($col, $dir);
                }
            }
        } else {
            $query->orderByDesc('entered_at');
        }

        $rows = $query->skip($startRow)->take($pageSize)->get();

        return [
            'rows'      => $rows->map(fn ($t) => $this->formatActiveTrade($t)),
            'totalRows' => $totalRows,
        ];
    }

    // =========================================================================
    // Closed Trades (untuk AG Grid)
    // =========================================================================

    /**
     * Data closed trades untuk AG Grid server-side.
     */
    public function getClosedTradesForGrid(TradingAccount $account, \Illuminate\Http\Request $request): array
    {
        $startRow  = (int) $request->input('startRow', 0);
        $endRow    = (int) $request->input('endRow', 100);
        $pageSize  = $endRow - $startRow;
        $sortModel = json_decode($request->input('sortModel', '[]'), true) ?? [];

        $query = PaperTrade::with(['market'])
            ->where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::CLOSED_STATUSES);

        $totalRows = $query->count();

        // Sorting
        if (! empty($sortModel)) {
            foreach ($sortModel as $sort) {
                $col = $this->sanitizeColumn($sort['colId'] ?? '');
                $dir = ($sort['sort'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                if ($col) {
                    $query->orderBy($col, $dir);
                }
            }
        } else {
            $query->orderByDesc('exited_at');
        }

        $rows = $query->skip($startRow)->take($pageSize)->get();

        return [
            'rows'      => $rows->map(fn ($t) => $this->formatClosedTrade($t)),
            'totalRows' => $totalRows,
        ];
    }

    // =========================================================================
    // Recent Activity Timeline
    // =========================================================================

    /**
     * 50 event terbaru dari paper_trade_history untuk timeline.
     */
    public function getRecentActivity(TradingAccount $account, int $limit = 50): \Illuminate\Support\Collection
    {
        return PaperTradeHistory::with(['paperTrade.market'])
            ->whereHas('paperTrade', fn ($q) => $q->where('trading_account_id', $account->id))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($h) => [
                'id'           => $h->id,
                'event_type'   => $h->event_type,
                'market'       => $h->paperTrade?->market?->question ?? 'Unknown Market',
                'market_short' => \Illuminate\Support\Str::limit($h->paperTrade?->market?->question ?? 'Unknown', 50),
                'price'        => $h->price_at_event,
                'shares'       => $h->shares_affected,
                'pnl'          => $h->pnl_realized,
                'reason'       => $h->reason,
                'created_at'   => $h->created_at,
                'created_ago'  => $h->created_at->diffForHumans(),
                'trade_id'     => $h->paper_trade_id,
                'icon'         => $this->getEventIcon($h->event_type),
                'color'        => $this->getEventColor($h->event_type),
            ]);
    }

    // =========================================================================
    // Smart Exit Statistics
    // =========================================================================

    /**
     * Statistik exit reason untuk cards dan pie chart.
     */
    public function getSmartExitStats(TradingAccount $account): array
    {
        $closed = PaperTrade::where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $smartExitCount  = (int) ($closed[PaperTrade::STATUS_SMART_EXIT] ?? 0);
        $stopLossCount   = (int) ($closed[PaperTrade::STATUS_STOPPED] ?? 0);
        $takeProfitCount = (int) ($closed[PaperTrade::STATUS_TAKE_PROFIT] ?? 0);
        $expiredCount    = (int) ($closed[PaperTrade::STATUS_EXPIRED] ?? 0);
        $closedCount     = (int) ($closed[PaperTrade::STATUS_CLOSED] ?? 0);

        return [
            'smart_exit'   => $smartExitCount,
            'stop_loss'    => $stopLossCount,
            'take_profit'  => $takeProfitCount,
            'expired'      => $expiredCount,
            'manual_close' => $closedCount,
            // For pie chart
            'chart' => [
                'labels' => ['Take Profit', 'Stop Loss', 'Smart Exit', 'Expired', 'Manual'],
                'data'   => [$takeProfitCount, $stopLossCount, $smartExitCount, $expiredCount, $closedCount],
                'colors' => ['#2fb344', '#e63946', '#ae3ec9', '#f76707', '#748ffc'],
            ],
        ];
    }

    // =========================================================================
    // AJAX Refresh Payload
    // =========================================================================

    /**
     * Lightweight payload untuk AJAX polling (overview + equity terbaru).
     * Digunakan oleh auto-refresh setiap 60 detik.
     */
    public function getRefreshPayload(TradingAccount $account): array
    {
        return [
            'overview'      => $this->getOverviewCards($account),
            'last_updated'  => now()->format('H:i:s') . ' UTC',
        ];
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function getOpenTrades(TradingAccount $account): \Illuminate\Database\Eloquent\Collection
    {
        return PaperTrade::where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::OPEN_STATUSES)
            ->get(['position_size_usd', 'unrealized_pnl_usd', 'outcome']);
    }

    private function getClosedTrades(TradingAccount $account): \Illuminate\Database\Eloquent\Collection
    {
        return PaperTrade::where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->get(['pnl_usd', 'outcome', 'status']);
    }

    /**
     * Hitung max drawdown dari equity curve historis.
     * Max drawdown = penurunan terbesar dari peak ke trough.
     */
    private function calculateMaxDrawdown(TradingAccount $account): float
    {
        $trades = PaperTrade::where('trading_account_id', $account->id)
            ->whereIn('status', PaperTrade::CLOSED_STATUSES)
            ->orderBy('exited_at')
            ->get(['pnl_usd', 'exited_at']);

        if ($trades->isEmpty()) {
            return 0.0;
        }

        $settings       = PaperTradeSetting::current();
        $initialCapital = (float) $settings->initial_capital;

        $peak       = $initialCapital;
        $equity     = $initialCapital;
        $maxDrawdown = 0.0;

        foreach ($trades as $trade) {
            $equity += (float) $trade->pnl_usd;

            if ($equity > $peak) {
                $peak = $equity;
            }

            $drawdown = $peak > 0 ? (($peak - $equity) / $peak) * 100 : 0.0;

            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        return $maxDrawdown;
    }

    private function formatActiveTrade(PaperTrade $trade): array
    {
        $holdingMinutes = $trade->entered_at
            ? now()->diffInMinutes($trade->entered_at)
            : 0;

        $holdingDisplay = $holdingMinutes < 60
            ? "{$holdingMinutes}m"
            : round($holdingMinutes / 60, 1) . 'h';

        $pnlUsd     = (float) ($trade->unrealized_pnl_usd ?? 0);
        $pnlPercent = (float) $trade->position_size_usd > 0
            ? ($pnlUsd / (float) $trade->position_size_usd) * 100
            : 0.0;

        return [
            'id'              => $trade->id,
            'market'          => \Illuminate\Support\Str::limit($trade->market?->question ?? 'N/A', 55),
            'market_full'     => $trade->market?->question ?? 'N/A',
            'direction'       => strtoupper((string) $trade->direction),
            'entry_price'     => round((float) $trade->entry_price * 100, 2),      // as %
            'current_price'   => round((float) ($trade->current_price ?? $trade->entry_price) * 100, 2),
            'position_size'   => round((float) $trade->position_size_usd, 2),
            'pnl_usd'         => round($pnlUsd, 2),
            'pnl_percent'     => round($pnlPercent, 2),
            'signal_score'    => $trade->signal_score !== null
                ? round((float) $trade->signal_score * 100, 1)
                : null,
            'take_profit'     => $trade->take_profit_price
                ? round((float) $trade->take_profit_price * 100, 2)
                : null,
            'stop_loss'       => $trade->stop_loss_price
                ? round((float) $trade->stop_loss_price * 100, 2)
                : null,
            'holding_time'    => $holdingDisplay,
            'status'          => strtoupper((string) $trade->status),
            'entered_at'      => $trade->entered_at?->format('Y-m-d H:i'),
            'is_profit'       => $pnlUsd >= 0,
        ];
    }

    private function formatClosedTrade(PaperTrade $trade): array
    {
        $durationMinutes = ($trade->entered_at && $trade->exited_at)
            ? $trade->entered_at->diffInMinutes($trade->exited_at)
            : null;

        $durationDisplay = null;
        if ($durationMinutes !== null) {
            $durationDisplay = $durationMinutes < 60
                ? "{$durationMinutes}m"
                : round($durationMinutes / 60, 1) . 'h';
        }

        $pnlUsd = (float) ($trade->pnl_usd ?? 0);
        $roi    = (float) ($trade->roi ?? 0);

        return [
            'id'           => $trade->id,
            'market'       => \Illuminate\Support\Str::limit($trade->market?->question ?? 'N/A', 55),
            'market_full'  => $trade->market?->question ?? 'N/A',
            'direction'    => strtoupper((string) $trade->direction),
            'entry_price'  => round((float) $trade->entry_price * 100, 2),
            'exit_price'   => $trade->exit_price ? round((float) $trade->exit_price * 100, 2) : null,
            'pnl_usd'      => round($pnlUsd, 2),
            'roi_percent'  => round($roi * 100, 2),
            'exit_reason'  => strtoupper((string) $trade->status),
            'duration'     => $durationDisplay,
            'exited_at'    => $trade->exited_at?->format('Y-m-d H:i'),
            'is_profit'    => $pnlUsd >= 0,
        ];
    }

    private function getEventIcon(string $eventType): string
    {
        return match ($eventType) {
            'OPENED'          => 'arrow-up-circle',
            'PARTIAL_CLOSE'   => 'scissors',
            'TP1', 'TP2', 'TP3' => 'target',
            'STOP_LOSS'       => 'shield-off',
            'BREAKEVEN_MOVED' => 'lock',
            'SMART_EXIT'      => 'cpu',
            'CLOSED'          => 'check-circle',
            'EXPIRED'         => 'clock',
            default           => 'activity',
        };
    }

    private function getEventColor(string $eventType): string
    {
        return match ($eventType) {
            'OPENED'            => 'blue',
            'PARTIAL_CLOSE'     => 'yellow',
            'TP1', 'TP2', 'TP3' => 'green',
            'STOP_LOSS'         => 'red',
            'BREAKEVEN_MOVED'   => 'cyan',
            'SMART_EXIT'        => 'purple',
            'CLOSED'            => 'teal',
            'EXPIRED'           => 'orange',
            default             => 'secondary',
        };
    }

    /**
     * Whitelist kolom yang boleh di-sort untuk mencegah SQL injection.
     */
    private function sanitizeColumn(string $col): ?string
    {
        $allowed = [
            'entered_at', 'exited_at', 'pnl_usd', 'roi',
            'position_size_usd', 'entry_price', 'exit_price',
            'status', 'direction', 'unrealized_pnl_usd',
        ];

        return in_array($col, $allowed, true) ? $col : null;
    }
}
