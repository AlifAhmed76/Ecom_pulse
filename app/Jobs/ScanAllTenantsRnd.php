<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanAllTenantsRnd implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(): void
    {
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            RunRndScan::dispatch((int) $tenantId)->onQueue('default');
        }
    }
}
