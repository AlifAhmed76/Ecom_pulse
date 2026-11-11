<?php

namespace App\Jobs;

use App\Services\WeeklyReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildTenantWeeklyReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?string $weekStart = null
    ) {
    }

    public function handle(WeeklyReportService $service): void
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $start = $this->weekStart !== null && $this->weekStart !== ''
            ? Carbon::parse($this->weekStart, $tz)->startOfDay()
            : null;

        $service->buildForTenant($this->tenantId, $start);
    }
}
