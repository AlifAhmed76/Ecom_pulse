<?php

namespace App\Services\Courier;

use App\Jobs\WarmTenantCache;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Services\Import\RowReader;
use App\Support\StatusMapper;
use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CourierImporter
{
    public function __construct(
        private readonly RowReader $reader = new RowReader(),
        private readonly CourierMapper $mapper = new CourierMapper(),
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
                throw new RuntimeException('Uploaded courier file could not be located in private storage.');
            }

            $mapping = $batch->mapping ?? [];
            $courierName = trim((string) ($mapping['_courier'] ?? ''));
            if ($courierName === '') {
                throw new RuntimeException('Courier name is required for courier report import.');
            }
            $courierName = mb_substr($courierName, 0, 80);

            $rowsRead = 0;
            $created = 0;
            $updated = 0;
            $matched = 0;
            $unmatched = 0;
            $skipped = 0;
            $skippedReasons = [];
            $statusBreakdown = [];
            $matchMethods = [
                'consignment_id' => 0,
                'order_ref' => 0,
                'customer_amount' => 0,
            ];

            $tz = config('app.timezone', 'Asia/Dhaka');

            foreach ($this->reader->iterate($fullPath, $batch->format) as $row) {
                $rowsRead++;
                $mapped = $this->mapper->mapRow($row, $mapping, (int) $batch->tenant_id);
                if (! $mapped['ok']) {
                    $skipped++;
                    $reason = $mapped['reason'] ?? 'invalid_row';
                    $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
                    continue;
                }

                $rawStatus = (string) $mapped['raw_status'];
                $d = $mapped['data'];
                $canonical = (string) $d['status'];

                if (! isset($statusBreakdown[$rawStatus])) {
                    $statusBreakdown[$rawStatus] = ['canonical' => $canonical, 'count' => 0];
                }
                $statusBreakdown[$rawStatus]['count']++;

                [$matchedOrderId, $method] = $this->matchOrder(
                    (int) $batch->tenant_id,
                    $batch->website_id !== null ? (int) $batch->website_id : null,
                    $d
                );

                $isMatched = $matchedOrderId !== null;
                if ($isMatched) {
                    $matched++;
                    $matchMethods[$method] = ($matchMethods[$method] ?? 0) + 1;
                } else {
                    $unmatched++;
                }

                $outcome = DB::transaction(function () use (
                    $batch,
                    $courierName,
                    $d,
                    $matchedOrderId,
                    $isMatched,
                    $method,
                    $tz
                ): string {
                    $nowStr = now($tz)->format('Y-m-d H:i:s');

                    $shippedStr = $d['shipped_at'] instanceof Carbon ? $d['shipped_at']->format('Y-m-d H:i:s') : null;
                    $deliveredStr = $d['delivered_at'] instanceof Carbon ? $d['delivered_at']->format('Y-m-d H:i:s') : null;
                    $returnedStr = $d['returned_at'] instanceof Carbon ? $d['returned_at']->format('Y-m-d H:i:s') : null;

                    $existingQuery = DB::table('courier_shipments')
                        ->where('tenant_id', $batch->tenant_id)
                        ->where('courier', $courierName);

                    if ($d['consignment_id'] !== null) {
                        $existingQuery->where('consignment_id', $d['consignment_id']);
                    } elseif ($matchedOrderId !== null) {
                        $existingQuery->whereNull('consignment_id')->where('order_id', $matchedOrderId);
                    } elseif ($d['order_ref'] !== null) {
                        $existingQuery->whereNull('consignment_id')->whereNull('order_id')->where('order_ref', $d['order_ref']);
                    } else {
                        $existingQuery->whereRaw('1 = 0');
                    }

                    $existing = $existingQuery->lockForUpdate()->first();

                    $cols = [
                        'tenant_id' => $batch->tenant_id,
                        'website_id' => $batch->website_id,
                        'import_batch_id' => $batch->id,
                        'courier' => $courierName,
                        'consignment_id' => $d['consignment_id'],
                        'order_ref' => $d['order_ref'],
                        'order_id' => $matchedOrderId,
                        'customer_key' => $d['customer_key'],
                        'status' => $d['status'],
                        'source_status' => $d['source_status'],
                        'cod_amount' => $d['cod_amount'],
                        'collected_amount' => $d['collected_amount'],
                        'delivery_fee' => $d['delivery_fee'],
                        'return_fee' => $d['return_fee'],
                        'area' => $d['area'],
                        'shipped_at' => $shippedStr,
                        'delivered_at' => $deliveredStr,
                        'returned_at' => $returnedStr,
                        'matched' => $isMatched,
                        'match_method' => $method,
                        'updated_at' => $nowStr,
                    ];

                    if ($existing === null) {
                        $cols['created_at'] = $nowStr;
                        DB::table('courier_shipments')->insert($cols);
                        $action = 'created';
                    } else {
                        DB::table('courier_shipments')->where('id', $existing->id)->update($cols);
                        $action = 'updated';
                    }

                    if ($matchedOrderId !== null) {
                        $this->applyOrderCourierUpdate(
                            $matchedOrderId,
                            $courierName,
                            $d,
                            $returnedStr ?? $deliveredStr ?? $shippedStr ?? $nowStr,
                            $nowStr
                        );
                    }

                    return $action;
                });

                if ($outcome === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $processed = $matched + $unmatched;
            $coveragePct = $processed > 0 ? round(($matched / $processed) * 100, 2) : 0.0;

            $unmapped = [];
            foreach ($statusBreakdown as $raw => $info) {
                if ($info['canonical'] === 'other') {
                    $unmapped[$raw] = $info['count'];
                }
            }

            $batch->forceFill([
                'status' => 'done',
                'stats' => [
                    'courier' => $courierName,
                    'rows_read' => $rowsRead,
                    'created' => $created,
                    'updated' => $updated,
                    'matched' => $matched,
                    'unmatched' => $unmatched,
                    'coverage_pct' => $coveragePct,
                    'match_methods' => $matchMethods,
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
                'import.courier',
                "Imported courier batch #{$batch->id} ({$courierName}): read {$rowsRead}, matched {$matched}/{$processed} ({$coveragePct}%)",
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

    /**
     * Matching order per IMPORT_SPEC.md:
     * 1) consignment_id == orders.consignment_id
     * 2) order_ref == orders.external_id
     * 3) customer_key + cod_amount == orders.total within 1 day window, only if unique.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: ?int, 1: ?string}
     */
    private function matchOrder(int $tenantId, ?int $websiteId, array $row): array
    {
        if ($row['consignment_id'] !== null) {
            $q = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->where('consignment_id', $row['consignment_id']);
            if ($websiteId !== null) {
                $q->where('website_id', $websiteId);
            }
            $ids = $q->limit(2)->pluck('id');
            if ($ids->count() === 1) {
                return [(int) $ids->first(), 'consignment_id'];
            }
        }

        if ($row['order_ref'] !== null) {
            $q = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->where('external_id', $row['order_ref']);
            if ($websiteId !== null) {
                $q->where('website_id', $websiteId);
            }
            $ids = $q->limit(2)->pluck('id');
            if ($ids->count() === 1) {
                return [(int) $ids->first(), 'order_ref'];
            }
        }

        if ($row['customer_key'] !== null && $row['cod_amount'] !== null) {
            $q = DB::table('orders as o')
                ->join('customers as c', 'c.id', '=', 'o.customer_id')
                ->where('o.tenant_id', $tenantId)
                ->where('c.tenant_id', $tenantId)
                ->where('c.customer_key', $row['customer_key'])
                ->whereRaw('ABS(o.total - ?) < 0.01', [(float) $row['cod_amount']]);

            if ($websiteId !== null) {
                $q->where('o.website_id', $websiteId);
            }

            $anchor = $row['shipped_at'] ?? $row['delivered_at'] ?? $row['returned_at'] ?? null;
            if ($anchor instanceof Carbon) {
                $q->where('o.ordered_at', '>=', $anchor->copy()->subDay()->format('Y-m-d H:i:s'))
                    ->where('o.ordered_at', '<=', $anchor->copy()->addDay()->format('Y-m-d H:i:s'));
            }

            $ids = $q->limit(2)->pluck('o.id');
            if ($ids->count() === 1) {
                return [(int) $ids->first(), 'customer_amount'];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function applyOrderCourierUpdate(
        int $orderId,
        string $courierName,
        array $d,
        string $changedAtStr,
        string $nowStr
    ): void {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        if ($order === null) {
            return;
        }

        $updates = [];
        if ($order->courier === null || trim((string) $order->courier) === '') {
            $updates['courier'] = $courierName;
        }
        if (($order->consignment_id === null || trim((string) $order->consignment_id) === '') && $d['consignment_id'] !== null) {
            $updates['consignment_id'] = $d['consignment_id'];
        }
        if (($order->city === null || trim((string) $order->city) === '') && $d['area'] !== null) {
            $updates['city'] = $d['area'];
        }

        $oldStatus = (string) $order->status;
        $newStatus = (string) $d['status'];
        $shouldUpdateStatus = false;

        if ($newStatus !== 'other' && $newStatus !== $oldStatus) {
            if ($newStatus === 'returned' || $newStatus === 'delivered') {
                $shouldUpdateStatus = true;
            } elseif ($newStatus === 'shipped' && ! in_array($oldStatus, ['delivered', 'returned', 'cancelled'], true)) {
                $shouldUpdateStatus = true;
            }
        }

        if ($shouldUpdateStatus) {
            $updates['status'] = $newStatus;
            $updates['source_status'] = $d['source_status'];
            $updates['status_changed_at'] = $changedAtStr;
        }

        if ($updates !== []) {
            $updates['updated_at'] = $nowStr;
            DB::table('orders')->where('id', $orderId)->update($updates);
        }

        if ($shouldUpdateStatus) {
            DB::table('order_status_history')->insert([
                'order_id' => $orderId,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'changed_at' => $changedAtStr,
                'employee_id' => null,
                'source' => 'courier',
            ]);
        }
    }
}
