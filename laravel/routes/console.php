<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessSignalCycleJob;
use App\Jobs\SmartExitMonitorJob;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

// Process pending signals → open paper trades
// Runs every 5 minutes, aligned with Python signal generation
Schedule::command('trade:process-signals')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Auto-close resolved trades + update unrealized PnL
// Runs every 5 minutes
Schedule::command('trade:auto-close')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Process signal cycles (e.g. check for cycle completion, update statuses, etc.)
Schedule::job(new ProcessSignalCycleJob())->everyFiveMinutes();
Schedule::job(new SmartExitMonitorJob())->everyMinute()->withoutOverlapping();
