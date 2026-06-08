<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessSignalCycleJob;
use App\Jobs\SmartExitMonitorJob;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

// trade:process-signals DISABLED — digantikan oleh ProcessSignalCycleJob
// PaperTradingService lama tidak memiliki score filter dan max concurrent guard
// Schedule::command('trade:process-signals')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// Auto-close resolved trades + update unrealized PnL
Schedule::command('trade:auto-close')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Pipeline baru — score filter + max concurrent + position sizing
Schedule::job(new ProcessSignalCycleJob())->everyFiveMinutes();
Schedule::job(new SmartExitMonitorJob())->everyMinute()->withoutOverlapping();
