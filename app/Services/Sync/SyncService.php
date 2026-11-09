<?php

namespace App\Services\Sync;

use App\Models\Website;
use App\Support\Parse;
use App\Support\StatusMapper;
use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncService
{
    public function __construct(private readonly int $tenantId)
    {
    }

    public static function for(int $tenantId): self
    {
        return new self($tenantId);
    }

    public static function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', Parse::toAsciiDigits($raw));
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            return '0'.substr($digits, 3);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            return '0'.$digits;
        }

        return $digits;
    }

    public static function customerKeyFor(
        int $tenantId,
        ?string $phone = null,
        ?string $email = null,
        ?string $externalCustomerId = null
    ): ?string {
        $normPhone = self::normalizePhone($phone);
        if ($normPhone !== null) {
            $identity = 'phone:'.$normPhone;
        } elseif ($email !== null && trim($email) !== '') {
            $identity = 'email:'.mb_strtolower(trim($email), 'UTF-8');
        } elseif ($externalCustomerId !== null && trim($externalCustomerId) !== '') {
            $identity = 'ext:'.trim($externalCustomerId);
        } else {
            return null;
        }

        return hash_hmac('sha256', "t{$tenantId}|{$identity}", (string) config('app.key'));
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array{created: int, updated: int}
     */
    public function upsertProducts(Website $website, array $products): array
    {
        if ((int) $website->tenant_id !== $this->tenantId) {
            throw new InvalidArgumentException('Website does not belong to this tenant.');
        }

        $created = 0;
        $updated = 0;
        $nowStr = now()->format('Y-m-d H:i:s');

        DB::transaction(function () use ($website, $products, $nowStr, &$created, &$updated): void {
            foreach ($products as $item) {
                $extId = trim((string) $item['id']);
                $existing = DB::table('products')
                    ->where('website_id', $website->id)
                    ->where('external_id', $extId)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    DB::table('products')->insert([
                        'tenant_id' => $this->tenantId,
                        'website_id' => $website->id,
                        'external_id' => $extId,
                        'sku' => isset($item['sku']) ? mb_substr(trim((string) $item['sku']), 0, 100) : null,
                        'name' => mb_substr(trim((string) $item['name']), 0, 255),
                        'category' => isset($item['category']) ? mb_substr(trim((string) $item['category']), 0, 120) : null,
                        'price' => array_key_exists('price', $item) ? Parse::num($item['price']) : null,
                        'cost' => array_key_exists('cost', $item) ? Parse::num($item['cost']) : null,
                        'stock' => array_key_exists('stock', $item) ? Parse::intNum($item['stock']) : null,
                        'active' => array_key_exists('active', $item) ? (bool) $item['active'] : true,
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ]);
                    $created++;
                } else {
                    $update = [
                        'name' => mb_substr(trim((string) $item['name']), 0, 255),
                        'updated_at' => $nowStr,
                    ];

                    if (array_key_exists('sku', $item)) {
                        $update['sku'] = $item['sku'] !== null ? mb_substr(trim((string) $item['sku']), 0, 100) : null;
                    }
                    if (array_key_exists('category', $item)) {
                        $update['category'] = $item['category'] !== null ? mb_substr(trim((string) $item['category']), 0, 120) : null;
                    }
                    if (array_key_exists('price', $item)) {
                        $update['price'] = Parse::num($item['price']);
                    }
                    if (array_key_exists('cost', $item)) {
                        $update['cost'] = Parse::num($item['cost']);
                    }
                    if (array_key_exists('stock', $item)) {
                        $update['stock'] = Parse::intNum($item['stock']);
                    }
                    if (array_key_exists('active', $item) && $item['active'] !== null) {
                        $update['active'] = (bool) $item['active'];
                    }

                    DB::table('products')->where('id', $existing->id)->update($update);
                    $updated++;
                }
            }
        });

        if ($created + $updated > 0) {
            TenantCache::clear($this->tenantId);
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array{created: int, updated: int, stale: int}
     */
    public function upsertOrders(
        Website $website,
        array $orders,
        string $source = 'connector',
        ?int $importBatchId = null,
        bool $mergeWithinBatch = false
    ): array {
        $created = 0;
        $updated = 0;
        $stale = 0;

        foreach ($orders as $orderPayload) {
            $outcome = $this->upsertOrder($website, $orderPayload, $source, $importBatchId, $mergeWithinBatch);
            if ($outcome === 'created') {
                $created++;
            } elseif ($outcome === 'updated') {
                $updated++;
            } else {
                $stale++;
            }
        }

        if ($created + $updated > 0) {
            TenantCache::clear($this->tenantId);
        }

        return ['created' => $created, 'updated' => $updated, 'stale' => $stale];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return 'created'|'updated'|'stale'
     */
    public function upsertOrder(
        Website $website,
        array $payload,
        string $source = 'connector',
        ?int $importBatchId = null,
        bool $mergeWithinBatch = false
    ): string {
        if ((int) $website->tenant_id !== $this->tenantId) {
            throw new InvalidArgumentException('Website does not belong to this tenant.');
        }

        return DB::transaction(function () use ($website, $payload, $source, $importBatchId, $mergeWithinBatch): string {
            $tz = config('app.timezone', 'Asia/Dhaka');
            $now = now($tz);
            $nowStr = $now->format('Y-m-d H:i:s');
            $extId = mb_substr(trim((string) $payload['id']), 0, 120);

            $existing = DB::table('orders')
                ->where('website_id', $website->id)
                ->where('external_id', $extId)
                ->lockForUpdate()
                ->first();

            $sourceUpdatedAt = Parse::datetime($payload['updated_at'] ?? $payload['source_updated_at'] ?? null, $tz);
            if ($existing !== null && $existing->source_updated_at !== null && $sourceUpdatedAt !== null) {
                $storedUpdated = Carbon::parse($existing->source_updated_at, $tz);
                if ($sourceUpdatedAt->lt($storedUpdated)) {
                    return 'stale';
                }
            }

            // Customer upsert via irreversible tenant-salted SHA-256 key
            $custPayload = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
            $customerKey = self::customerKeyFor(
                $this->tenantId,
                $custPayload['phone'] ?? ($payload['customer_phone'] ?? null),
                $custPayload['email'] ?? ($payload['customer_email'] ?? null),
                $custPayload['id'] ?? null
            );

            $customerId = $existing?->customer_id;
            if ($customerKey !== null) {
                $custName = isset($custPayload['name']) ? mb_substr(trim((string) $custPayload['name']), 0, 150) : null;
                if ($custName === '') {
                    $custName = null;
                }

                DB::table('customers')->insertOrIgnore([
                    'tenant_id' => $this->tenantId,
                    'customer_key' => $customerKey,
                    'name' => $custName,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]);

                $custRow = DB::table('customers')
                    ->where('tenant_id', $this->tenantId)
                    ->where('customer_key', $customerKey)
                    ->first(['id', 'name']);

                if ($custRow !== null) {
                    $customerId = (int) $custRow->id;
                    if ($custName !== null && $custRow->name !== $custName) {
                        DB::table('customers')->where('id', $customerId)->update([
                            'name' => $custName,
                            'updated_at' => $nowStr,
                        ]);
                    }
                }
            } elseif (! $mergeWithinBatch && array_key_exists('customer', $payload) && $payload['customer'] === null) {
                $customerId = null;
            }

            // Employee resolution
            $employeeName = trim((string) ($payload['employee']['name'] ?? $payload['employee_name'] ?? ''));
            $employeeId = null;
            if ($employeeName !== '') {
                $cleanEmp = mb_substr($employeeName, 0, 150);
                DB::table('employees')->insertOrIgnore([
                    'tenant_id' => $this->tenantId,
                    'name' => $cleanEmp,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]);
                $empVal = DB::table('employees')
                    ->where('tenant_id', $this->tenantId)
                    ->where('name', $cleanEmp)
                    ->value('id');
                $employeeId = $empVal !== null ? (int) $empVal : null;
            }

            // Resolve items + snapshot costs from matching product
            $incomingItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $resolvedItems = [];
            $incomingItemsSubtotal = 0.0;

            foreach ($incomingItems as $item) {
                $itemExtId = isset($item['product_id']) ? trim((string) $item['product_id']) : '';
                $itemSku = isset($item['sku']) && trim((string) $item['sku']) !== ''
                    ? mb_substr(trim((string) $item['sku']), 0, 100)
                    : null;

                $matchedProduct = null;
                if ($itemExtId !== '') {
                    $matchedProduct = DB::table('products')
                        ->where('website_id', $website->id)
                        ->where('external_id', $itemExtId)
                        ->first(['id', 'cost']);
                }
                if ($matchedProduct === null && $itemSku !== null) {
                    $matchedProduct = DB::table('products')
                        ->where('tenant_id', $this->tenantId)
                        ->where('sku', $itemSku)
                        ->orderByRaw('CASE WHEN website_id = ? THEN 0 ELSE 1 END', [$website->id])
                        ->first(['id', 'cost']);
                }

                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = Parse::num($item['unit_price'] ?? 0) ?? 0.0;
                $unitCost = array_key_exists('unit_cost', $item) && $item['unit_cost'] !== null
                    ? Parse::num($item['unit_cost'])
                    : ($matchedProduct !== null && $matchedProduct->cost !== null ? (float) $matchedProduct->cost : null);
                $lineTotal = round($qty * $unitPrice, 2);
                $incomingItemsSubtotal += $lineTotal;

                $resolvedItems[] = [
                    'product_id' => $matchedProduct !== null ? (int) $matchedProduct->id : null,
                    'product_name' => mb_substr(trim((string) ($item['name'] ?? $item['product_name'] ?? 'Item')), 0, 255),
                    'sku' => $itemSku,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ];
            }

            $sameBatchMerge = $mergeWithinBatch
                && $existing !== null
                && $importBatchId !== null
                && (int) $existing->import_batch_id === $importBatchId;

            $discount = array_key_exists('discount', $payload) && $payload['discount'] !== null
                ? (Parse::num($payload['discount']) ?? 0.0)
                : ($existing !== null && $mergeWithinBatch ? (float) $existing->discount : 0.0);

            $shipping = array_key_exists('shipping', $payload) && $payload['shipping'] !== null
                ? (Parse::num($payload['shipping']) ?? 0.0)
                : ($existing !== null && $mergeWithinBatch ? (float) $existing->shipping : 0.0);

            if ($sameBatchMerge && $resolvedItems !== []) {
                $existingSubtotal = (float) DB::table('order_items')->where('order_id', $existing->id)->sum('line_total');
                $subtotal = round($existingSubtotal + $incomingItemsSubtotal, 2);
            } elseif ($resolvedItems !== []) {
                $subtotal = round($incomingItemsSubtotal, 2);
            } elseif ($existing !== null && $mergeWithinBatch) {
                $subtotal = (float) $existing->subtotal;
            } else {
                $subtotal = round(Parse::num($payload['subtotal'] ?? 0) ?? 0.0, 2);
            }

            $hasExplicitTotal = array_key_exists('total', $payload) && $payload['total'] !== null && Parse::num($payload['total']) !== null;
            if ($hasExplicitTotal) {
                $total = round((float) Parse::num($payload['total']), 2);
            } elseif ($existing !== null && $mergeWithinBatch && $resolvedItems === []) {
                $total = (float) $existing->total;
            } else {
                $total = round(max(0, $subtotal - $discount + $shipping), 2);
            }

            $orderedAt = Parse::datetime($payload['ordered_at'] ?? null, $tz) ?? $now;
            $orderedAtStr = $orderedAt->format('Y-m-d H:i:s');

            $rawStatus = array_key_exists('status', $payload) && trim((string) $payload['status']) !== ''
                ? mb_substr(trim((string) $payload['status']), 0, 80)
                : ($existing?->source_status ?? 'pending');
            $canonicalStatus = StatusMapper::map($rawStatus);

            $statusChangedAt = Parse::datetime($payload['status_changed_at'] ?? null, $tz)
                ?? $sourceUpdatedAt
                ?? $orderedAt;

            $pickStr = static function (string $key, int $max, ?string $fallback) use ($payload, $mergeWithinBatch): ?string {
                if (array_key_exists($key, $payload)) {
                    $val = trim((string) ($payload[$key] ?? ''));
                    if ($val !== '') {
                        return mb_substr($val, 0, $max);
                    }

                    return $mergeWithinBatch ? $fallback : null;
                }

                return $mergeWithinBatch ? $fallback : null;
            };

            $currency = isset($payload['currency']) && trim((string) $payload['currency']) !== ''
                ? strtoupper(substr(trim((string) $payload['currency']), 0, 3))
                : ($existing?->currency ?? $website->currency ?? 'BDT');

            $orderColumns = [
                'tenant_id' => $this->tenantId,
                'website_id' => $website->id,
                'external_id' => $extId,
                'customer_id' => $customerId,
                'status' => $canonicalStatus,
                'source_status' => $rawStatus,
                'ordered_at' => $existing !== null && $mergeWithinBatch && ! isset($payload['ordered_at'])
                    ? $existing->ordered_at
                    : $orderedAtStr,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping' => $shipping,
                'total' => $total,
                'currency' => $currency,
                'courier' => $pickStr('courier', 80, $existing?->courier),
                'consignment_id' => $pickStr('consignment_id', 120, $existing?->consignment_id),
                'city' => $pickStr('city', 100, $existing?->city),
                'cancel_reason' => $pickStr('cancel_reason', 255, $existing?->cancel_reason),
                'utm_campaign' => $pickStr('utm_campaign', 120, $existing?->utm_campaign),
                'source_updated_at' => $sourceUpdatedAt?->format('Y-m-d H:i:s') ?? $existing?->source_updated_at,
                'source' => $source,
                'import_batch_id' => $importBatchId ?? $existing?->import_batch_id,
                'updated_at' => $nowStr,
            ];

            if ($existing === null) {
                $orderColumns['status_changed_at'] = $statusChangedAt->format('Y-m-d H:i:s');
                $orderColumns['created_at'] = $nowStr;
                $orderId = DB::table('orders')->insertGetId($orderColumns);

                foreach ($resolvedItems as $itemRow) {
                    $itemRow['order_id'] = $orderId;
                    DB::table('order_items')->insert($itemRow);
                }

                if ($employeeId !== null) {
                    DB::table('order_assignments')->insert([
                        'order_id' => $orderId,
                        'employee_id' => $employeeId,
                        'assigned_at' => $nowStr,
                    ]);
                }

                DB::table('order_status_history')->insert([
                    'order_id' => $orderId,
                    'from_status' => null,
                    'to_status' => $canonicalStatus,
                    'changed_at' => $statusChangedAt->format('Y-m-d H:i:s'),
                    'employee_id' => $employeeId,
                    'source' => $source,
                ]);

                return 'created';
            }

            $oldStatus = (string) $existing->status;
            $statusChanged = $oldStatus !== $canonicalStatus;
            $orderColumns['status_changed_at'] = $statusChanged
                ? $statusChangedAt->format('Y-m-d H:i:s')
                : $existing->status_changed_at;

            DB::table('orders')->where('id', $existing->id)->update($orderColumns);

            if ($sameBatchMerge) {
                foreach ($resolvedItems as $itemRow) {
                    $itemRow['order_id'] = $existing->id;
                    DB::table('order_items')->insert($itemRow);
                }
            } elseif (! $mergeWithinBatch || array_key_exists('items', $payload)) {
                if (! $mergeWithinBatch || $resolvedItems !== []) {
                    DB::table('order_items')->where('order_id', $existing->id)->delete();
                    foreach ($resolvedItems as $itemRow) {
                        $itemRow['order_id'] = $existing->id;
                        DB::table('order_items')->insert($itemRow);
                    }
                }
            }

            if ($employeeId !== null) {
                $hasAssignment = DB::table('order_assignments')->where('order_id', $existing->id)->exists();
                if ($hasAssignment) {
                    DB::table('order_assignments')->where('order_id', $existing->id)->update([
                        'employee_id' => $employeeId,
                        'assigned_at' => $nowStr,
                    ]);
                } else {
                    DB::table('order_assignments')->insert([
                        'order_id' => $existing->id,
                        'employee_id' => $employeeId,
                        'assigned_at' => $nowStr,
                    ]);
                }
            }

            if ($statusChanged) {
                DB::table('order_status_history')->insert([
                    'order_id' => $existing->id,
                    'from_status' => $oldStatus,
                    'to_status' => $canonicalStatus,
                    'changed_at' => $statusChangedAt->format('Y-m-d H:i:s'),
                    'employee_id' => $employeeId,
                    'source' => $source,
                ]);
            }

            return 'updated';
        });
    }
}
