<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaperTrade;
use App\Models\PaperTradeSetting;
use App\Services\PaperTrading\EquityCurveService;
use App\Services\PaperTrading\PerformanceAnalyticsService;
use App\Services\PaperTrading\PortfolioDashboardService;
use App\Services\PortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * PaperTradeDashboardController
 *
 * Thin controller — semua business logic ada di Services.
 * Controller hanya:
 *   1. Resolve TradingAccount dari Auth user
 *   2. Delegate ke Service
 *   3. Pass data ke View atau return JSON
 */
final class PaperTradeDashboardController extends Controller
{
    public function __construct(
        private readonly PortfolioService            $portfolioService,
        private readonly PortfolioDashboardService   $dashboardService,
        private readonly PerformanceAnalyticsService $analyticsService,
        private readonly EquityCurveService          $equityCurveService,
    ) {}

    // =========================================================================
    // index() — Main Dashboard
    // =========================================================================

    /**
     * GET /paper-trades
     *
     * Render dashboard utama dengan semua data untuk:
     * - Overview cards
     * - Equity curve chart
     * - Smart exit stats
     * - Performance metrics
     * - Recent activity timeline
     */
    public function index(): View
    {
        $account = $this->portfolioService->getAccountForUser(Auth::user());

        $overview       = $this->dashboardService->getOverviewCards($account);
        $equityCurve    = $this->equityCurveService->getDailyEquityCurve($account);
        $equitySummary  = $this->equityCurveService->getEquitySummary($account, $equityCurve);
        $smartExitStats = $this->dashboardService->getSmartExitStats($account);
        $performance    = $this->analyticsService->getFullMetrics($account);
        $recentActivity = $this->dashboardService->getRecentActivity($account, 20);
        $settings       = PaperTradeSetting::current();
        $monthlyPnl     = $this->analyticsService->getMonthlyPnlChart($account);

        return view('paper-trades.index', compact(
            'account',
            'overview',
            'equityCurve',
            'equitySummary',
            'smartExitStats',
            'performance',
            'recentActivity',
            'settings',
            'monthlyPnl',
        ));
    }

    // =========================================================================
    // show() — Trade Detail Page
    // =========================================================================

    /**
     * GET /paper-trades/{trade}
     *
     * Detail page untuk satu trade:
     * - Trade info
     * - Exit strategy (TP, SL, Breakeven)
     * - Metrics (unrealized PnL, ROI, holding time)
     * - History timeline
     */
    public function show(PaperTrade $paperTrade): View
    {
        $account = $this->portfolioService->getAccountForUser(Auth::user());

        // Pastikan trade milik user ini
        abort_if(
            $paperTrade->trading_account_id !== $account->id,
            403,
            'Unauthorized'
        );

        $paperTrade->load(['market', 'signal', 'history']);

        $holdingMinutes = $paperTrade->entered_at
            ? (int) $paperTrade->entered_at->diffInMinutes(now())
            : 0;

        $holdingDisplay = $holdingMinutes < 60
            ? "{$holdingMinutes}m"
            : round($holdingMinutes / 60, 1) . 'h';

        $unrealizedPnl = (float) ($paperTrade->unrealized_pnl_usd ?? 0);
        $unrealizedRoi = (float) $paperTrade->position_size_usd > 0
            ? ($unrealizedPnl / (float) $paperTrade->position_size_usd) * 100
            : 0.0;

        return view('paper-trades.show', compact(
            'paperTrade',
            'holdingDisplay',
            'unrealizedPnl',
            'unrealizedRoi',
        ));
    }

    // =========================================================================
    // activeTrades() — AG Grid: Active Trades
    // =========================================================================

    /**
     * GET /api/paper-trades/active
     *
     * Server-side data untuk AG Grid active trades.
     */
    public function activeTrades(Request $request): JsonResponse
    {
        $account = $this->portfolioService->getAccountForUser(Auth::user());

        $data = $this->dashboardService->getActiveTradesForGrid($account, $request);

        return response()->json($data);
    }

    // =========================================================================
    // closedTrades() — AG Grid: Closed Trades
    // =========================================================================

    /**
     * GET /api/paper-trades/closed
     *
     * Server-side data untuk AG Grid closed trades.
     */
    public function closedTrades(Request $request): JsonResponse
    {
        $account = $this->portfolioService->getAccountForUser(Auth::user());

        $data = $this->dashboardService->getClosedTradesForGrid($account, $request);

        return response()->json($data);
    }

    // =========================================================================
    // timeline() — Recent Activity (AJAX)
    // =========================================================================

    /**
     * GET /api/paper-trades/timeline
     *
     * Latest 50 events untuk timeline widget.
     * Dipanggil oleh AJAX polling setiap 60 detik.
     */
    public function timeline(Request $request): JsonResponse
    {
        $account = $this->portfolioService->getAccountForUser(Auth::user());

        $limit    = min((int) $request->input('limit', 50), 100);
        $activity = $this->dashboardService->getRecentActivity($account, $limit);

        return response()->json(['events' => $activity]);
    }

    // =========================================================================
    // refresh() — AJAX Polling Payload
    // =========================================================================

    /**
     * GET /api/paper-trades/refresh
     *
     * Lightweight payload untuk auto-refresh setiap 60 detik.
     * Update: overview cards + equity terbaru.
     */
    public function refresh(): JsonResponse
    {
        $account = $this->portfolioService->getAccountForUser(Auth::user());

        return response()->json(
            $this->dashboardService->getRefreshPayload($account)
        );
    }

    // =========================================================================
    // equityCurve() — Chart Data (AJAX)
    // =========================================================================

    /**
     * GET /api/paper-trades/equity-curve
     *
     * Data equity curve untuk Chart.js.
     * Dipanggil saat user pilih range berbeda.
     */
    public function equityCurve(Request $request): JsonResponse
    {
        $account = $this->portfolioService->getAccountForUser(Auth::user());

        $mode = $request->input('mode', 'daily'); // 'daily' | 'per_trade'

        $data = $mode === 'per_trade'
            ? $this->equityCurveService->getEquityCurve($account)
            : $this->equityCurveService->getDailyEquityCurve($account);

        return response()->json($data);
    }
}
