<?php

use App\Jobs\BuildWeeklyReports;
use App\Jobs\RefreshAllTenants;
use App\Jobs\ScanAllTenantsRisk;
use App\Jobs\ScanAllTenantsRnd;
use App\Jobs\WorkerBeat;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Cache::put('sys:scheduler_beat', now()->toIso8601String(), 180);
})->name('scheduler-beat')->everyMinute();

Schedule::job(new WorkerBeat(), 'high')
    ->name('worker-beat')
    ->everyMinute();

Schedule::job(new RefreshAllTenants(), 'default')
    ->name('refresh-all-tenants')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::job(new ScanAllTenantsRisk(), 'default')
    ->name('risk-scan-all-tenants')
    ->hourly()
    ->withoutOverlapping();

Schedule::job(new ScanAllTenantsRnd(), 'default')
    ->name('rnd-scan-all-tenants')
    ->dailyAt('02:30')
    ->timezone(config('app.timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();

Schedule::job(new BuildWeeklyReports(), 'default')
    ->name('build-weekly-reports')
    ->weeklyOn(ConsoleSchedule::SATURDAY, '08:00')
    ->timezone(config('app.timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();

Schedule::command('lyp:prune')
    ->everyFiveMinutes()
    ->withoutOverlapping();
