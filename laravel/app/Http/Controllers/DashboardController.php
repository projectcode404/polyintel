<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function index(): View
    {
        return view('dashboard.index', [
            'stats'                    => $this->dashboardService->getStats(),
            'topMarkets'               => $this->dashboardService->getTopMarkets(),
            'snapshotActivityChart'    => $this->dashboardService->getSnapshotActivityChart(),
            'probabilityDistribution'  => $this->dashboardService->getProbabilityDistributionChart(),
            'subCategoryBreakdown'     => $this->dashboardService->getSubCategoryBreakdown(),
        ]);
    }
}
