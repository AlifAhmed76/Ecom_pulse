<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanAllTenantsRisk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Tenant::query()
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($tenantId): void {
                RunRiskScan::dispatch((int) $tenantId)->onQueue('default');
            });
    }
}
