<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MarketController;
use Illuminate\Support\Facades\Route;

// ---- Auth ----
require __DIR__ . '/auth.php';

// ---- Redirect root ke dashboard ----
Route::get('/', fn () => redirect()->route('dashboard'));

// =========================================================================
// Protected routes — auth + role: admin atau analyst
// =========================================================================
Route::middleware(['auth'])->group(function () {

    // ---- Dashboard ----
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    // ---- Markets ----
    Route::prefix('markets')->name('markets.')->group(function () {
        Route::get('/', [MarketController::class, 'index'])
            ->name('index');
        Route::get('/{market}', [MarketController::class, 'show'])
            ->name('show');
    });

    // ---- Placeholder pages ----
    Route::get('/signals', fn () => view('placeholder.coming-soon', [
        'title'   => 'Signals',
        'message' => 'Signal generation akan tersedia di Sprint 4.',
        'icon'    => 'activity',
    ]))->name('signals.index');

    Route::get('/paper-trades', fn () => view('placeholder.coming-soon', [
        'title'   => 'Paper Trades',
        'message' => 'Paper trading akan tersedia setelah signals aktif.',
        'icon'    => 'trending-up',
    ]))->name('paper-trades.index');

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
// API routes untuk AG Grid server-side (masih butuh auth)
// =========================================================================
Route::middleware(['auth'])->prefix('api')->name('api.')->group(function () {
    Route::get('/markets/grid', [MarketController::class, 'gridData'])
        ->name('markets.grid');
    Route::get('/markets/{market}/snapshots/grid', [MarketController::class, 'snapshotGridData'])
        ->name('markets.snapshots.grid');
    Route::get('/markets/{market}/chart', [MarketController::class, 'chartData'])
        ->name('markets.chart');
});
