<?php

namespace App\Jobs;

use App\Services\RiskScannerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunRiskScan implements ShouldBeUnique, ShouldQueue
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
        return 'risk-scan-'.$this->tenantId;
    }

    public function handle(RiskScannerService $scanner): void
    {
        $scanner->scanTenant($this->tenantId);
    }
}
