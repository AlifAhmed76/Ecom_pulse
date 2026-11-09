<?php

namespace App\Jobs;

use App\Services\MetricsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WarmTenantCache implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $tenantId)
    {
    }

    public function handle(): void
    {
        MetricsService::for($this->tenantId)->rebuildDashboard();
    }
}
