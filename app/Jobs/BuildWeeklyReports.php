<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\WeeklyReportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildWeeklyReports implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(): void
    {
        $weekStartStr = WeeklyReportService::defaultWeekStart(null, true)->format('Y-m-d');

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            BuildTenantWeeklyReport::dispatch((int) $tenantId, $weekStartStr)->onQueue('default');
        }
    }
}
