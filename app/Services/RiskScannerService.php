<?php

namespace App\Services;

use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RiskScannerService
{
    public const S1_WINDOW_DAYS = 30;

    public const S1_MIN_FAILURES = 3;

    public const S2_WINDOW_SECONDS = 86400; // 24h

    public const S2_MIN_DUPLICATES = 2;

    public const S3_WINDOW_SECONDS = 3600; // 1h

    public const S3_MIN_BURST = 5;

    /**
     * Run advisory suspicious-order rules S1-S3 strictly within $tenantId.
     * NEVER modifies, cancels, blocks, or mutates any order row.
     *
     * @return array{s1: int, s2: int, s3: int, total_inserted: int}
     */
    public function scanTenant(int $tenantId, ?Carbon $referenceNow = null): array
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $now = ($referenceNow ?? now($tz))->copy()->setTimezone($tz);
        $nowStr = $now->format('Y-m-d H:i:s');

        $counts = ['s1' => 0, 's2' => 0, 's3' => 0, 'total_inserted' => 0];

        // Rule S1: same customer_key with >= 3 cancelled/returned orders in 30 days
        $cutoff30d = $now->copy()->subDays(self::S1_WINDOW_DAYS)->format('Y-m-d H:i:s');
        $s1Customers = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('customer_id')
            ->whereIn('status', ['cancelled', 'returned'])
            ->where('ordered_at', '>=', $cutoff30d)
            ->where('ordered_at', '<=', $nowStr)
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= ?', [self::S1_MIN_FAILURES])
            ->pluck('customer_id')
            ->all();

        if ($s1Customers !== []) {
            $s1OrdersByCustomer = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->whereIn('customer_id', $s1Customers)
                ->whereIn('status', ['cancelled', 'returned'])
                ->where('ordered_at', '>=', $cutoff30d)
                ->where('ordered_at', '<=', $nowStr)
                ->orderBy('ordered_at')
                ->orderBy('id')
                ->get(['id', 'customer_id', 'external_id', 'status', 'ordered_at'])
                ->groupBy('customer_id');

            foreach ($s1OrdersByCustomer as $custId => $rows) {
                if ($rows->count() < self::S1_MIN_FAILURES) {
                    continue;
                }
                $latest = $rows->last();
                $fingerprint = hash('sha256', "S1|t{$tenantId}|c{$custId}|o{$latest->id}");
                $evidence = [
                    'failed_count_30d' => $rows->count(),
                    'window_days' => self::S1_WINDOW_DAYS,
                    'order_ids' => $rows->pluck('id')->map(fn ($v) => (int) $v)->values()->all(),
                    'external_ids' => $rows->pluck('external_id')->values()->all(),
                    'timestamps' => $rows->pluck('ordered_at')->values()->all(),
                ];

                $inserted = $this->insertFlagIfNew(
                    $tenantId,
                    (int) $latest->id,
                    (int) $custId,
                    'S1',
                    $fingerprint,
                    $evidence,
                    $nowStr
                );
                if ($inserted) {
                    $counts['s1']++;
                    $counts['total_inserted']++;
                }
            }
        }

        // Rule S2: same customer_key, same item set and total within 24h (>= 2 orders)
        $s2Customers = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= ?', [self::S2_MIN_DUPLICATES])
            ->pluck('customer_id')
            ->all();

        if ($s2Customers !== []) {
            $candidateOrders = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->whereIn('customer_id', $s2Customers)
                ->orderBy('ordered_at')
                ->orderBy('id')
                ->get(['id', 'customer_id', 'external_id', 'total', 'ordered_at']);

            $orderIds = $candidateOrders->pluck('id')->all();
            $itemsByOrder = DB::table('order_items')
                ->whereIn('order_id', $orderIds)
                ->get(['order_id', 'sku', 'product_name', 'quantity'])
                ->groupBy('order_id');

            $buckets = [];
            foreach ($candidateOrders as $ord) {
                $ordItems = $itemsByOrder->get($ord->id);
                if ($ordItems === null || $ordItems->isEmpty()) {
                    continue; // Cannot assert "same item set" without item rows
                }

                $parts = [];
                foreach ($ordItems as $it) {
                    $parts[] = mb_strtolower(trim((string) ($it->sku ?? '')), 'UTF-8')
                        .'|'.mb_strtolower(trim((string) $it->product_name), 'UTF-8')
                        .'|'.(int) $it->quantity;
                }
                sort($parts);
                $sig = implode(';', $parts);
                $totalKey = number_format((float) $ord->total, 2, '.', '');
                $bucketKey = $ord->customer_id.'|'.$totalKey.'|'.$sig;

                $buckets[$bucketKey][] = [
                    'id' => (int) $ord->id,
                    'customer_id' => (int) $ord->customer_id,
                    'external_id' => (string) $ord->external_id,
                    'ordered_at' => (string) $ord->ordered_at,
                    'ts' => Carbon::parse((string) $ord->ordered_at, $tz)->getTimestamp(),
                ];
            }

            foreach ($buckets as $bucketOrders) {
                $n = count($bucketOrders);
                if ($n < self::S2_MIN_DUPLICATES) {
                    continue;
                }

                $i = 0;
                while ($i < $n) {
                    $j = $i;
                    while ($j + 1 < $n && ($bucketOrders[$j + 1]['ts'] - $bucketOrders[$i]['ts']) <= self::S2_WINDOW_SECONDS) {
                        $j++;
                    }

                    $clusterSize = $j - $i + 1;
                    if ($clusterSize >= self::S2_MIN_DUPLICATES) {
                        $slice = array_slice($bucketOrders, $i, $clusterSize);
                        $ids = array_column($slice, 'id');
                        $last = end($slice);
                        $fingerprint = hash('sha256', "S2|t{$tenantId}|".implode(',', $ids));
                        $evidence = [
                            'duplicate_count' => $clusterSize,
                            'window_hours' => 24,
                            'order_ids' => $ids,
                            'external_ids' => array_column($slice, 'external_id'),
                            'timestamps' => array_column($slice, 'ordered_at'),
                        ];

                        $inserted = $this->insertFlagIfNew(
                            $tenantId,
                            $last['id'],
                            $last['customer_id'],
                            'S2',
                            $fingerprint,
                            $evidence,
                            $nowStr
                        );
                        if ($inserted) {
                            $counts['s2']++;
                            $counts['total_inserted']++;
                        }
                        $i = $j + 1;
                    } else {
                        $i++;
                    }
                }
            }
        }

        // Rule S3: >= 5 orders from same customer_key within 1 hour
        $s3Customers = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= ?', [self::S3_MIN_BURST])
            ->pluck('customer_id')
            ->all();

        if ($s3Customers !== []) {
            $burstOrdersByCustomer = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->whereIn('customer_id', $s3Customers)
                ->orderBy('ordered_at')
                ->orderBy('id')
                ->get(['id', 'customer_id', 'external_id', 'ordered_at'])
                ->groupBy('customer_id');

            foreach ($burstOrdersByCustomer as $custId => $rows) {
                $list = $rows->map(fn (object $r): array => [
                    'id' => (int) $r->id,
                    'external_id' => (string) $r->external_id,
                    'ordered_at' => (string) $r->ordered_at,
                    'ts' => Carbon::parse((string) $r->ordered_at, $tz)->getTimestamp(),
                ])->values()->all();

                $n = count($list);
                $i = 0;
                while ($i < $n) {
                    $j = $i;
                    while ($j + 1 < $n && ($list[$j + 1]['ts'] - $list[$i]['ts']) <= self::S3_WINDOW_SECONDS) {
                        $j++;
                    }

                    $clusterSize = $j - $i + 1;
                    if ($clusterSize >= self::S3_MIN_BURST) {
                        $slice = array_slice($list, $i, $clusterSize);
                        $ids = array_column($slice, 'id');
                        $last = end($slice);
                        $fingerprint = hash('sha256', "S3|t{$tenantId}|c{$custId}|".implode(',', $ids));
                        $evidence = [
                            'order_count_1h' => $clusterSize,
                            'window_minutes' => 60,
                            'order_ids' => $ids,
                            'external_ids' => array_column($slice, 'external_id'),
                            'timestamps' => array_column($slice, 'ordered_at'),
                        ];

                        $inserted = $this->insertFlagIfNew(
                            $tenantId,
                            $last['id'],
                            (int) $custId,
                            'S3',
                            $fingerprint,
                            $evidence,
                            $nowStr
                        );
                        if ($inserted) {
                            $counts['s3']++;
                            $counts['total_inserted']++;
                        }
                        $i = $j + 1;
                    } else {
                        $i++;
                    }
                }
            }
        }

        if ($counts['total_inserted'] > 0) {
            TenantCache::clear($tenantId);
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function insertFlagIfNew(
        int $tenantId,
        int $orderId,
        int $customerId,
        string $rule,
        string $fingerprint,
        array $evidence,
        string $nowStr
    ): bool {
        $affected = DB::table('risk_flags')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'rule' => $rule,
            'fingerprint' => $fingerprint,
            'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'open',
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        return $affected > 0;
    }
}
