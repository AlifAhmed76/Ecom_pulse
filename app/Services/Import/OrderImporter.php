<?php

namespace App\Services\Import;

use App\Jobs\WarmTenantCache;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\Website;
use App\Services\Sync\SyncService;
use App\Support\StatusMapper;
use App\Support\TenantCache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OrderImporter
{
    public function __construct(
        private readonly RowReader $reader = new RowReader(),
        private readonly OrderMapper $mapper = new OrderMapper(),
    ) {
    }

    public function processBatch(ImportBatch $batch): void
    {
        $batch->forceFill(['status' => 'processing', 'error' => null])->save();

        $disk = Storage::disk('local');
        $storedPath = (string) $batch->stored_path;
        $fullPath = $storedPath !== '' ? $disk->path($storedPath) : '';

        try {
            if ($fullPath === '' || ! is_file($fullPath)) {
                throw new \RuntimeException('Uploaded import file could not be located in private storage.');
            }

            $website = Website::withoutGlobalScopes()
                ->where('tenant_id', $batch->tenant_id)
                ->findOrFail($batch->website_id);

            $mapping = $batch->mapping ?? [];
            $sync = SyncService::for($batch->tenant_id);

            $rowsRead = 0;
            $created = 0;
            $updated = 0;
            $stale = 0;
            $skipped = 0;
            $skippedReasons = [];
            $statusBreakdown = [];

            $pendingOrder = null;

            $flushOrder = function (?array $orderData) use ($sync, $website, $batch, &$created, &$updated, &$stale): void {
                if ($orderData === null) {
                    return;
                }
                $outcome = $sync->upsertOrder($website, $orderData, 'import', $batch->id, true);
                if ($outcome === 'created') {
                    $created++;
                } elseif ($outcome === 'updated') {
                    $updated++;
                } else {
                    $stale++;
                }
            };

            foreach ($this->reader->iterate($fullPath, $batch->format) as $row) {
                $rowsRead++;
                $mapped = $this->mapper->mapRow($row, $mapping, $website->currency);
                if (! $mapped['ok']) {
                    $skipped++;
                    $reason = $mapped['reason'] ?? 'invalid_row';
                    $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
                    continue;
                }

                $rawStatus = $mapped['raw_status'];
                $canonical = StatusMapper::map($rawStatus);
                if (! isset($statusBreakdown[$rawStatus])) {
                    $statusBreakdown[$rawStatus] = ['canonical' => $canonical, 'count' => 0];
                }
                $statusBreakdown[$rawStatus]['count']++;

                $orderData = $mapped['data'];
                if ($pendingOrder !== null && $pendingOrder['id'] === $orderData['id']) {
                    // Merge adjacent rows sharing the same order_id into one upsert call
                    if (! empty($orderData['items'])) {
                        $pendingOrder['items'] = array_merge($pendingOrder['items'], $orderData['items']);
                    }
                    if ($pendingOrder['total'] === null && $orderData['total'] !== null) {
                        $pendingOrder['total'] = $orderData['total'];
                    }
                    if ($pendingOrder['customer'] === null && $orderData['customer'] !== null) {
                        $pendingOrder['customer'] = $orderData['customer'];
                    }
                    if ($pendingOrder['employee'] === null && $orderData['employee'] !== null) {
                        $pendingOrder['employee'] = $orderData['employee'];
                    }
                    if ((float) $pendingOrder['discount'] === 0.0 && (float) $orderData['discount'] > 0.0) {
                        $pendingOrder['discount'] = $orderData['discount'];
                    }
                    if ((float) $pendingOrder['shipping'] === 0.0 && (float) $orderData['shipping'] > 0.0) {
                        $pendingOrder['shipping'] = $orderData['shipping'];
                    }
                    foreach (['courier', 'consignment_id', 'city', 'cancel_reason', 'utm_campaign'] as $attr) {
                        if ($pendingOrder[$attr] === null && $orderData[$attr] !== null) {
                            $pendingOrder[$attr] = $orderData[$attr];
                        }
                    }
                } else {
                    $flushOrder($pendingOrder);
                    $pendingOrder = $orderData;
                }
            }

            $flushOrder($pendingOrder);

            $unmapped = [];
            foreach ($statusBreakdown as $raw => $info) {
                if ($info['canonical'] === 'other') {
                    $unmapped[$raw] = $info['count'];
                }
            }

            $batch->forceFill([
                'status' => 'done',
                'stats' => [
                    'rows_read' => $rowsRead,
                    'created' => $created,
                    'updated' => $updated,
                    'stale' => $stale,
                    'skipped' => $skipped,
                    'skipped_reasons' => $skippedReasons,
                    'status_breakdown' => $statusBreakdown,
                    'unmapped_statuses' => $unmapped,
                ],
                'stored_path' => null,
            ])->save();

            TenantCache::clear($batch->tenant_id);
            WarmTenantCache::dispatch($batch->tenant_id)->onQueue('default');

            AuditLog::record(
                'import.orders',
                "Imported batch #{$batch->id} ({$batch->filename}): read {$rowsRead}, created {$created}, updated {$updated}, skipped {$skipped}",
                $batch->tenant_id,
                $batch->user_id
            );
        } catch (Throwable $e) {
            $batch->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'stored_path' => null,
            ])->save();
        } finally {
            if ($storedPath !== '' && $disk->exists($storedPath)) {
                $disk->delete($storedPath);
            }
        }
    }
}
