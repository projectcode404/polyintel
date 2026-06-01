<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Market;
use App\Services\MarketAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MarketController extends Controller
{
    public function __construct(
        private readonly MarketAnalyticsService $analyticsService,
    ) {}

    /**
     * Markets index — AG Grid container page.
     */
    public function index(): View
    {
        return view('markets.index');
    }

    /**
     * Market detail page.
     */
    public function show(Market $market): View
    {
        $stats     = $this->analyticsService->getMarketStats($market);
        $chartData = $this->analyticsService->getProbabilityChartData($market, hours: 24);

        return view('markets.show', compact('market', 'stats', 'chartData'));
    }

    /**
     * AG Grid server-side data endpoint untuk markets index.
     */
    public function gridData(Request $request): JsonResponse
    {
        $data = $this->analyticsService->getMarketsForGrid($request);

        return response()->json($data);
    }

    /**
     * AG Grid server-side data endpoint untuk snapshots di market detail.
     */
    public function snapshotGridData(Market $market, Request $request): JsonResponse
    {
        $data = $this->analyticsService->getSnapshotsForGrid($market, $request);

        return response()->json($data);
    }

    /**
     * Chart data endpoint — AJAX untuk Chart.js di market detail.
     * Support ganti range: ?hours=24|48|168
     */
    public function chartData(Market $market, Request $request): JsonResponse
    {
        $hours = (int) $request->input('hours', 24);
        $hours = in_array($hours, [24, 48, 168], true) ? $hours : 24;

        $data = $this->analyticsService->getProbabilityChartData($market, $hours);

        return response()->json($data);
    }
}
