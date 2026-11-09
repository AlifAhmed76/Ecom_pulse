<?php

use App\Jobs\RefreshAllTenants;
use App\Jobs\WorkerBeat;
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

Schedule::command('lyp:prune')
    ->everyFiveMinutes()
    ->withoutOverlapping();
