<?php

namespace App\Services\Marketing;

use App\Jobs\WarmTenantCache;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Services\Import\RowReader;
use App\Support\TenantCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AdImporter
{
    public function __construct(
        private readonly RowReader $reader = new RowReader(),
        private readonly AdMapper $mapper = new AdMapper(),
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
                throw new RuntimeException('Uploaded ad report file could not be located in private storage.');
            }

            $mapping = $batch->mapping ?? [];
            $platform = strtolower(trim((string) ($mapping['_platform'] ?? '')));
            if (! in_array($platform, AdMapper::SUPPORTED_PLATFORMS, true)) {
                throw new RuntimeException('Ad platform selection (meta, google, tiktok, other) is required.');
            }

            $rowsRead = 0;
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $skippedReasons = [];
            $minDate = null;
            $maxDate = null;
            $nowStr = now()->format('Y-m-d H:i:s');

            foreach ($this->reader->iterate($fullPath, $batch->format) as $row) {
                $rowsRead++;
                $mapped = $this->mapper->mapRow($row, $mapping);
                if (! $mapped['ok']) {
                    $skipped++;
                    $reason = $mapped['reason'] ?? 'invalid_row';
                    $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;

                    continue;
                }

                $d = $mapped['data'];
                if ($minDate === null || $d['date'] < $minDate) {
                    $minDate = $d['date'];
                }
                if ($maxDate === null || $d['date'] > $maxDate) {
                    $maxDate = $d['date'];
                }

                $outcome = DB::transaction(function () use ($batch, $platform, $d, $nowStr): string {
                    $existing = DB::table('ad_performance_daily')
                        ->where('tenant_id', (int) $batch->tenant_id)
                        ->where('platform', $platform)
                        ->where('campaign', $d['campaign'])
                        ->where('date', $d['date'])
                        ->lockForUpdate()
                        ->first(['id']);

                    $cols = [
                        'website_id' => $batch->website_id,
                        'import_batch_id' => $batch->id,
                        'spend' => $d['spend'],
                        'impressions' => $d['impressions'],
                        'clicks' => $d['clicks'],
                        'platform_conversions' => $d['platform_conversions'],
                        'platform_revenue' => $d['platform_revenue'],
                        'updated_at' => $nowStr,
                    ];

                    if ($existing === null) {
                        DB::table('ad_performance_daily')->insert(array_merge($cols, [
                            'tenant_id' => (int) $batch->tenant_id,
                            'platform' => $platform,
                            'campaign' => $d['campaign'],
                            'date' => $d['date'],
                            'created_at' => $nowStr,
                        ]));

                        return 'created';
                    }

                    DB::table('ad_performance_daily')
                        ->where('id', $existing->id)
                        ->update($cols);

                    return 'updated';
                });

                if ($outcome === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $stats = [
                'platform' => $platform,
                'rows_read' => $rowsRead,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'skipped_reasons' => $skippedReasons,
                'date_min' => $minDate,
                'date_max' => $maxDate,
            ];

            $batch->forceFill([
                'status' => 'done',
                'stats' => $stats,
                'stored_path' => null,
            ])->save();

            AuditLog::record(
                'import.ads_completed',
                "Imported ads batch #{$batch->id} ({$platform}, created={$created}, updated={$updated}, skipped={$skipped})",
                (int) $batch->tenant_id,
                (int) $batch->user_id
            );

            TenantCache::clear((int) $batch->tenant_id);
            WarmTenantCache::dispatch((int) $batch->tenant_id);
        } catch (Throwable $e) {
            $batch->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
                'stored_path' => null,
            ])->save();

            AuditLog::record(
                'import.ads_failed',
                "Ad report import batch #{$batch->id} failed: ".mb_substr($e->getMessage(), 0, 120, 'UTF-8'),
                (int) $batch->tenant_id,
                (int) $batch->user_id
            );
        } finally {
            if ($storedPath !== '' && $disk->exists($storedPath)) {
                $disk->delete($storedPath);
            }
        }
    }
}
