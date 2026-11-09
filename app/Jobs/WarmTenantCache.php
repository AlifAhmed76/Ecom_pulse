<?php

namespace App\Jobs;

use App\Services\MetricsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WarmTenantCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public int $uniqueFor = 120;

    public function __construct(public readonly int $tenantId)
    {
    }

    public function uniqueId(): string
    {
        return 'warm-tenant-'.$this->tenantId;
    }

    public function handle(): void
    {
        MetricsService::for($this->tenantId)->rebuildDashboard();
    }
}
