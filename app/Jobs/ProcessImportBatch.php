<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\OrderImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $batchId)
    {
    }

    public function handle(OrderImporter $importer): void
    {
        $batch = ImportBatch::withoutGlobalScopes()->find($this->batchId);
        if ($batch === null) {
            return;
        }

        if ($batch->kind === 'orders') {
            $importer->processBatch($batch);
        }
    }
}
