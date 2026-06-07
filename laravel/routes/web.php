<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PaperTradeController;
use App\Http\Controllers\PaperTradeDashboardController;
use App\Http\Controllers\PaperTradeSettingsController;
use App\Http\Controllers\SignalController;
use Illuminate\Support\Facades\Route;

// ---- Auth ----
require __DIR__ . '/auth.php';

// ---- Redirect root ke dashboard ----
Route::get('/', fn () => redirect()->route('dashboard'));

// =========================================================================
// Protected routes — auth
// =========================================================================
Route::middleware(['auth'])->group(function () {

    // ---- Dashboard ----
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    // ---- Markets ----
    Route::prefix('markets')->name('markets.')->group(function () {
        Route::get('/', [MarketController::class, 'index'])->name('index');
        Route::get('/{market}', [MarketController::class, 'show'])->name('show');
    });

    // ---- Signals ----
    Route::prefix('signals')->name('signals.')->group(function () {
        Route::get('/', [SignalController::class, 'index'])->name('index');
        Route::post('/{signal}/execute', [SignalController::class, 'execute'])->name('execute');
        Route::post('/{signal}/ignore', [SignalController::class, 'ignore'])->name('ignore');
    });

    // =========================================================================
    // Paper Trades — Phase 4 Dashboard
    // =========================================================================
    Route::prefix('paper-trades')->name('paper-trades.')->group(function () {

        // --- Phase 4: Dashboard utama ---
        Route::get('/', [PaperTradeDashboardController::class, 'index'])
            ->name('index');

        // --- Phase 4: Trade setting page ---
        Route::get('/settings', [PaperTradeSettingsController::class, 'index'])->name('settings');
        Route::put('/settings', [PaperTradeSettingsController::class, 'update'])->name('settings.update');

        // --- Phase 4: Trade detail page ---
        Route::get('/{paperTrade}', [PaperTradeDashboardController::class, 'show'])
            ->name('show');

        // --- Phase 1-3: Actions yang sudah ada (tetap pakai PaperTradeController) ---
        Route::post('/{trade}/close', [PaperTradeController::class, 'close'])
            ->name('close');

        Route::post('/settings', [PaperTradeController::class, 'updateSettings'])
            ->name('settings');
    });

    // ---- Performance ----
    Route::get('/performance', fn () => view('placeholder.coming-soon', [
        'title'   => 'Performance',
        'message' => 'Performance analytics akan tersedia setelah paper trading aktif.',
        'icon'    => 'bar-chart',
    ]))->name('performance.index');

    // =========================================================================
    // Admin only routes
    // =========================================================================
    Route::middleware(['role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->only([
            'index', 'store', 'destroy',
        ]);
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])
            ->name('users.update-role');
    });
});

// =========================================================================
// API routes — AG Grid server-side + AJAX (semua butuh auth)
// =========================================================================
Route::middleware(['auth'])->prefix('api')->name('api.')->group(function () {

    // ---- Markets ----
    Route::get('/markets/grid', [MarketController::class, 'gridData'])
        ->name('markets.grid');
    Route::get('/markets/{market}/snapshots/grid', [MarketController::class, 'snapshotGridData'])
        ->name('markets.snapshots.grid');
    Route::get('/markets/{market}/chart', [MarketController::class, 'chartData'])
        ->name('markets.chart');

    // ---- Signals ----
    Route::get('/signals/grid', [SignalController::class, 'gridData'])
        ->name('signals.grid');

    // ---- Paper Trades (lama — tetap ada untuk backward compat) ----
    Route::get('/paper-trades/grid', [PaperTradeController::class, 'gridData'])
        ->name('paper-trades.grid');

    // ---- Paper Trades (Phase 4 — endpoint baru) ----
    Route::get('/paper-trades/active', [PaperTradeDashboardController::class, 'activeTrades'])
        ->name('paper-trades.active');

    Route::get('/paper-trades/closed', [PaperTradeDashboardController::class, 'closedTrades'])
        ->name('paper-trades.closed');

    Route::get('/paper-trades/timeline', [PaperTradeDashboardController::class, 'timeline'])
        ->name('paper-trades.timeline');

    Route::get('/paper-trades/refresh', [PaperTradeDashboardController::class, 'refresh'])
        ->name('paper-trades.refresh');

    Route::get('/paper-trades/equity-curve', [PaperTradeDashboardController::class, 'equityCurve'])
        ->name('paper-trades.equity-curve');
});
