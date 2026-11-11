<?php

namespace App\Jobs;

use App\Services\MetricsService;
use App\Services\Rnd\RuleEngine;
use App\Support\TenantCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunRndScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $tenantId)
    {
    }

    public function handle(?RuleEngine $engine = null): void
    {
        TenantCache::clear($this->tenantId);
        ($engine ?? app(RuleEngine::class))->scanTenant($this->tenantId);
        MetricsService::for($this->tenantId)->rebuildDashboard();
    }
}
