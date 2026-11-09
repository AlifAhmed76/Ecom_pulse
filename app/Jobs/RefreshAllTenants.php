<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshAllTenants implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        foreach (DB::table('tenants')->orderBy('id')->cursor() as $tenant) {
            WarmTenantCache::dispatch((int) $tenant->id)->onQueue('default');
        }
    }
}
