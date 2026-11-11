<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Courier\CourierImporter;
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

    public function handle(?OrderImporter $importer = null, ?CourierImporter $courierImporter = null): void
    {
        $batch = ImportBatch::withoutGlobalScopes()->find($this->batchId);
        if ($batch === null) {
            return;
        }

        if ($batch->kind === 'orders') {
            ($importer ?? app(OrderImporter::class))->processBatch($batch);
        } elseif ($batch->kind === 'courier') {
            ($courierImporter ?? app(CourierImporter::class))->processBatch($batch);
        }
    }
}
