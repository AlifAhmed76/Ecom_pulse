<?php

namespace App\Services\Demo;

use App\Models\AuditLog;
use App\Services\MetricsService;
use App\Services\Sync\SyncService;
use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class DemoSeeder
{
    public const DEMO_WEBSITE_NAME = 'Dhaka Fashion Demo Store';

    public const TOTAL_DEMO_ORDERS = 2500;

    public const TOTAL_DEMO_PRODUCTS = 40;

    /**
     * Summarize current demo vs non-demo counts strictly for one tenant.
     *
     * @return array{
     *   has_demo: bool,
     *   demo_websites: int,
     *   demo_products: int,
     *   demo_orders: int,
     *   demo_shipments: int,
     *   demo_ads: int,
     *   non_demo_websites: int,
     *   non_demo_orders: int
     * }
     */
    public static function summary(int $tenantId): array
    {
        $demoWebsites = (int) DB::table('websites')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->count();

        $demoOrders = (int) DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->count();

        $demoProducts = (int) DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->count();

        $demoShipments = (int) DB::table('courier_shipments')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->count();

        $demoAds = (int) DB::table('ad_performance_daily')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->count();

        $nonDemoWebsites = (int) DB::table('websites')
            ->where('tenant_id', $tenantId)
            ->where('source', '<>', 'demo')
            ->count();

        $nonDemoOrders = (int) DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('source', '<>', 'demo')
            ->count();

        return [
            'has_demo' => ($demoWebsites + $demoOrders + $demoProducts + $demoShipments + $demoAds) > 0,
            'demo_websites' => $demoWebsites,
            'demo_products' => $demoProducts,
            'demo_orders' => $demoOrders,
            'demo_shipments' => $demoShipments,
            'demo_ads' => $demoAds,
            'non_demo_websites' => $nonDemoWebsites,
            'non_demo_orders' => $nonDemoOrders,
        ];
    }

    /**
     * Deterministically seed a full Bangladesh COD demo dataset for $tenantId (marked source=demo).
     *
     * @return array{
     *   already_seeded: bool,
     *   website_id: int,
     *   products: int,
     *   customers: int,
     *   employees: int,
     *   orders: int,
     *   shipments: int,
     *   ad_rows: int,
     *   history_rows: int
     * }
     */
    public static function seed(int $tenantId, ?int $actorUserId = null, ?Carbon $referenceNow = null): array
    {
        $existingSite = DB::table('websites')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->first(['id']);

        if ($existingSite !== null) {
            return [
                'already_seeded' => true,
                'website_id' => (int) $existingSite->id,
                'products' => (int) DB::table('products')->where('tenant_id', $tenantId)->where('source', 'demo')->count(),
                'customers' => (int) DB::table('customers')->where('tenant_id', $tenantId)->where('source', 'demo')->count(),
                'employees' => (int) DB::table('employees')->where('tenant_id', $tenantId)->where('source', 'demo')->count(),
                'orders' => (int) DB::table('orders')->where('tenant_id', $tenantId)->where('source', 'demo')->count(),
                'shipments' => (int) DB::table('courier_shipments')->where('tenant_id', $tenantId)->where('source', 'demo')->count(),
                'ad_rows' => (int) DB::table('ad_performance_daily')->where('tenant_id', $tenantId)->where('source', 'demo')->count(),
                'history_rows' => (int) DB::table('order_status_history as h')
                    ->join('orders as o', 'o.id', '=', 'h.order_id')
                    ->where('o.tenant_id', $tenantId)
                    ->where('o.source', 'demo')
                    ->count(),
            ];
        }

        $tz = config('app.timezone', 'Asia/Dhaka');
        $now = ($referenceNow ?? now($tz))->copy()->setTimezone($tz);
        $nowStr = $now->format('Y-m-d H:i:s');
        $rng = new DeterministicRng(20261110);

        $counts = DB::transaction(function () use ($tenantId, $now, $nowStr, $rng): array {
            // 1. Demo Website (domain = null so multiple tenants can run demo without unique collision)
            $siteId = (int) DB::table('websites')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => self::DEMO_WEBSITE_NAME,
                'platform' => 'file',
                'source' => 'demo',
                'domain' => null,
                'status' => 'active',
                'currency' => 'BDT',
                'verification_token' => hash('sha256', "demo-site|t{$tenantId}|{$nowStr}"),
                'verified_at' => $nowStr,
                'last_heartbeat_at' => $nowStr,
                'connector_version' => 'demo-1.0',
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);

            // 2. 40 Demo Products with realistic costs & stock alerts / dead stock SKUs
            $catalog = self::productCatalog();
            $productRows = [];
            foreach ($catalog as $p) {
                $productRows[] = [
                    'tenant_id' => $tenantId,
                    'website_id' => $siteId,
                    'external_id' => $p['ext_id'],
                    'sku' => $p['sku'],
                    'name' => $p['name'],
                    'category' => $p['category'],
                    'price' => $p['price'],
                    'cost' => $p['cost'],
                    'stock' => $p['stock'],
                    'active' => true,
                    'source' => 'demo',
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
            }
            foreach (array_chunk($productRows, 40) as $chunk) {
                DB::table('products')->insert($chunk);
            }

            $productByExt = DB::table('products')
                ->where('website_id', $siteId)
                ->orderBy('id')
                ->get(['id', 'external_id', 'sku', 'name', 'price', 'cost'])
                ->keyBy('external_id')
                ->all();

            // 3. 4 Demo Employees (Rafiq Hasan carries high cancel rate for R05)
            $employeeNames = [
                'Nusrat Jahan',
                'Karim Uddin',
                'Sadia Afrin',
                'Rafiq Hasan',
            ];
            $empRows = [];
            foreach ($employeeNames as $empName) {
                $empRows[] = [
                    'tenant_id' => $tenantId,
                    'name' => $empName,
                    'source' => 'demo',
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
            }
            DB::table('employees')->insert($empRows);

            $empIds = [];
            foreach (DB::table('employees')->where('tenant_id', $tenantId)->where('source', 'demo')->orderBy('id')->get(['id', 'name']) as $er) {
                $empIds[(string) $er->name] = (int) $er->id;
            }

            // 4. 450 Deterministic Demo Customers (hashed keys only, zero raw PII stored)
            $customerNames = [
                'Tariq Mahmud', 'Farhana Akter', 'Mahmudul Hasan', 'Rasheda Begum',
                'Imran Hossain', 'Sumaiya Islam', 'Mehedi Hasan', 'Tasnim Tabassum',
                'Kamrul Islam', 'Fariha Sultana', 'Rakibul Alam', 'Nabila Chowdhury',
            ];
            $custCount = 450;
            $custBatch = [];
            for ($c = 1; $c <= $custCount; $c++) {
                $phone = '0171'.str_pad((string) $c, 7, '0', STR_PAD_LEFT);
                $custBatch[] = [
                    'tenant_id' => $tenantId,
                    'customer_key' => SyncService::customerKeyFor($tenantId, $phone),
                    'name' => $customerNames[($c - 1) % count($customerNames)].' #'.$c,
                    'source' => 'demo',
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
            }
            foreach (array_chunk($custBatch, 100) as $chunk) {
                DB::table('customers')->insertOrIgnore($chunk);
            }
            $custRows = DB::table('customers')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->orderBy('id')
                ->get(['id', 'customer_key'])
                ->all();

            // 5. Generate 2,500 Orders over 120 days (with distinct baseline vs last-7d trends for R01-R13)
            $activeSkus = array_slice($catalog, 0, 37); // Last 3 items remain 0-sale dead stock
            $skuWeights = [];
            foreach ($activeSkus as $idx => $p) {
                if ($idx === 0) {
                    $skuWeights[$p['ext_id']] = 32; // Hero product -> drives R06 & R08 concentration
                } elseif ($idx === 1) {
                    $skuWeights[$p['ext_id']] = 14;
                } elseif ($idx <= 5) {
                    $skuWeights[$p['ext_id']] = 5;
                } else {
                    $skuWeights[$p['ext_id']] = 1.5;
                }
            }

            $cities = ['Dhaka', 'Chattogram', 'Gazipur', 'Narayanganj', 'Sylhet', 'Rajshahi', 'Khulna'];
            $cityWeights = ['Dhaka' => 42, 'Chattogram' => 18, 'Gazipur' => 12, 'Narayanganj' => 9, 'Sylhet' => 7, 'Rajshahi' => 6, 'Khulna' => 6];
            $cancelReasons = ['Buyer unreachable', 'Changed mind', 'Duplicate order', 'High shipping fee', 'Ordered elsewhere'];
            $utmPool = ['dhaka_eid_fest', 'retargeting_cod', 'winter_bestseller'];

            // Build exact day-offset schedule totaling 2,500 orders:
            // - Days 119..14 (106 days) => 2,150 orders
            // - Days 13..7  (prev 7 days) => 230 orders
            // - Days 6..0   (last 7 days) => 120 orders (sharp drop -> triggers R01)
            $dayOffsets = [];
            for ($i = 0; $i < 2150; $i++) {
                $dayOffsets[] = 14 + ($i % 106);
            }
            for ($i = 0; $i < 230; $i++) {
                $dayOffsets[] = 7 + ($i % 7);
            }
            for ($i = 0; $i < 120; $i++) {
                $dayOffsets[] = $i % 7;
            }

            $ordersBatch = [];
            $metaByExtId = [];

            foreach ($dayOffsets as $idx => $daysAgo) {
                $ordNum = $idx + 1;
                $extId = 'DEMO-ORD-'.str_pad((string) $ordNum, 4, '0', STR_PAD_LEFT);

                $orderedDt = $now->copy()
                    ->subDays($daysAgo)
                    ->setTime($rng->intRange(9, 22), $rng->intRange(0, 59), $rng->intRange(0, 59));
                if ($orderedDt->gt($now)) {
                    $orderedDt = $now->copy()->subMinutes($rng->intRange(5, 90));
                }

                // Customer distribution: first 150 customers drive repeat orders cleanly
                $custIdx = $rng->chance(0.55)
                    ? $rng->intRange(0, min(149, count($custRows) - 1))
                    : $rng->intRange(0, count($custRows) - 1);
                $customerRow = $custRows[$custIdx];

                // Employee distribution (Rafiq Hasan carries deliberate higher cancel rate)
                $empName = $employeeNames[$rng->intRange(0, 3)];
                $employeeId = $empIds[$empName];

                // Courier allocation (RedX carries deliberate higher return rate)
                $courier = $rng->weightedKey(['Pathao' => 42, 'Steadfast' => 35, 'RedX' => 23]);

                // Pick 1 main SKU (hero item on ~32%, etc.)
                $pickedExt = $rng->weightedKey($skuWeights);
                $prod = $productByExt[$pickedExt];
                $qty = ($pickedExt === 'DEMO-001' || $rng->chance(0.18)) ? 2 : 1;
                $unitPrice = (float) $prod->price;
                $unitCost = (float) $prod->cost;
                $subtotal = round($qty * $unitPrice, 2);

                $city = $rng->weightedKey($cityWeights);
                $shipping = $city === 'Dhaka' ? 70.00 : 120.00;
                $hasDiscount = $rng->chance(0.16);
                $discount = $hasDiscount ? round($subtotal * 0.12, 2) : 0.00;
                $total = round(max(0.0, $subtotal - $discount + $shipping), 2);

                // Status resolution preserving R02/R03 (last 7d spike), R04 (RedX return outlier), R05 (Rafiq cancel outlier)
                $cancelProb = match (true) {
                    $daysAgo <= 6 && $empName === 'Rafiq Hasan' => 0.46,
                    $daysAgo <= 6 => 0.24,
                    $empName === 'Rafiq Hasan' => 0.36,
                    $hasDiscount => 0.18,
                    default => 0.10,
                };

                if ($rng->chance($cancelProb)) {
                    $status = 'cancelled';
                } elseif ($daysAgo <= 1 && $rng->chance(0.45)) {
                    $status = $rng->chance(0.5) ? 'pending' : 'confirmed';
                } elseif ($daysAgo <= 4 && $rng->chance(0.32)) {
                    $status = 'shipped';
                } else {
                    $returnProb = match (true) {
                        $daysAgo <= 6 && $courier === 'RedX' => 0.46,
                        $daysAgo <= 6 => 0.25,
                        $courier === 'RedX' => 0.38,
                        $courier === 'Steadfast' => 0.12,
                        default => 0.09,
                    };
                    $status = $rng->chance($returnProb) ? 'returned' : 'delivered';
                }

                // UTM attribution (~68% in last 45d; dhaka_eid_fest has lower delivery conversion for R13)
                $utm = null;
                if ($daysAgo <= 45 && $rng->chance(0.68)) {
                    $utm = $rng->weightedKey([
                        'dhaka_eid_fest' => 45,
                        'retargeting_cod' => 30,
                        'winter_bestseller' => 25,
                    ]);
                }

                $dispatched = in_array($status, ['shipped', 'delivered', 'returned'], true);
                $consignmentId = $dispatched ? strtoupper(substr($courier, 0, 3)).'-'.(900000 + $ordNum) : null;
                $cancelReason = $status === 'cancelled' ? $rng->pick($cancelReasons) : null;

                $confirmedDt = $orderedDt->copy()->addMinutes($rng->intRange(12, 85));
                if ($confirmedDt->gt($now)) {
                    $confirmedDt = $now->copy();
                }

                $shippedDt = $confirmedDt->copy()->addHours($rng->intRange(4, 18));
                if ($shippedDt->gt($now)) {
                    $shippedDt = $now->copy();
                }

                $settledDt = $shippedDt->copy()->addHours($rng->intRange(20, 65));
                if ($settledDt->gt($now)) {
                    $settledDt = $now->copy();
                }

                $statusChangedDt = match ($status) {
                    'pending' => $orderedDt,
                    'confirmed' => $confirmedDt,
                    'cancelled' => $confirmedDt,
                    'shipped' => $shippedDt,
                    default => $settledDt,
                };

                $ordersBatch[] = [
                    'tenant_id' => $tenantId,
                    'website_id' => $siteId,
                    'external_id' => $extId,
                    'customer_id' => (int) $customerRow->id,
                    'status' => $status,
                    'source_status' => $status,
                    'ordered_at' => $orderedDt->format('Y-m-d H:i:s'),
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'shipping' => $shipping,
                    'total' => $total,
                    'currency' => 'BDT',
                    'courier' => $dispatched ? $courier : null,
                    'consignment_id' => $consignmentId,
                    'city' => $city,
                    'cancel_reason' => $cancelReason,
                    'utm_campaign' => $utm,
                    'status_changed_at' => $statusChangedDt->format('Y-m-d H:i:s'),
                    'source_updated_at' => $statusChangedDt->format('Y-m-d H:i:s'),
                    'source' => 'demo',
                    'import_batch_id' => null,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];

                $metaByExtId[$extId] = [
                    'product_id' => (int) $prod->id,
                    'product_name' => (string) $prod->name,
                    'sku' => (string) $prod->sku,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'line_total' => $subtotal,
                    'employee_id' => $employeeId,
                    'customer_key' => (string) $customerRow->customer_key,
                    'ordered_at' => $orderedDt->format('Y-m-d H:i:s'),
                    'confirmed_at' => $confirmedDt->format('Y-m-d H:i:s'),
                    'shipped_at' => $shippedDt->format('Y-m-d H:i:s'),
                    'settled_at' => $settledDt->format('Y-m-d H:i:s'),
                    'status' => $status,
                    'courier' => $courier,
                    'consignment_id' => $consignmentId,
                    'city' => $city,
                    'total' => $total,
                    'shipping' => $shipping,
                ];
            }

            foreach (array_chunk($ordersBatch, 35) as $chunk) {
                DB::table('orders')->insert($chunk);
            }

            $insertedOrders = DB::table('orders')
                ->where('website_id', $siteId)
                ->where('source', 'demo')
                ->orderBy('id')
                ->get(['id', 'external_id']);

            $itemsBatch = [];
            $assignmentsBatch = [];
            $historyBatch = [];
            $shipmentsBatch = [];

            foreach ($insertedOrders as $ord) {
                $m = $metaByExtId[(string) $ord->external_id];
                $orderId = (int) $ord->id;
                $status = $m['status'];

                $itemsBatch[] = [
                    'order_id' => $orderId,
                    'product_id' => $m['product_id'],
                    'product_name' => $m['product_name'],
                    'sku' => $m['sku'],
                    'quantity' => $m['qty'],
                    'unit_price' => $m['unit_price'],
                    'unit_cost' => $m['unit_cost'],
                    'line_total' => $m['line_total'],
                ];

                $assignmentsBatch[] = [
                    'order_id' => $orderId,
                    'employee_id' => $m['employee_id'],
                    'assigned_at' => $m['ordered_at'],
                ];

                $historyBatch[] = [
                    'order_id' => $orderId,
                    'from_status' => null,
                    'to_status' => 'pending',
                    'changed_at' => $m['ordered_at'],
                    'employee_id' => $m['employee_id'],
                    'source' => 'demo',
                ];

                if ($status === 'cancelled') {
                    $historyBatch[] = [
                        'order_id' => $orderId,
                        'from_status' => 'pending',
                        'to_status' => 'cancelled',
                        'changed_at' => $m['confirmed_at'],
                        'employee_id' => $m['employee_id'],
                        'source' => 'demo',
                    ];
                } elseif ($status !== 'pending') {
                    $historyBatch[] = [
                        'order_id' => $orderId,
                        'from_status' => 'pending',
                        'to_status' => 'confirmed',
                        'changed_at' => $m['confirmed_at'],
                        'employee_id' => $m['employee_id'],
                        'source' => 'demo',
                    ];

                    if (in_array($status, ['shipped', 'delivered', 'returned'], true)) {
                        $historyBatch[] = [
                            'order_id' => $orderId,
                            'from_status' => 'confirmed',
                            'to_status' => 'shipped',
                            'changed_at' => $m['shipped_at'],
                            'employee_id' => $m['employee_id'],
                            'source' => 'demo',
                        ];

                        if ($status === 'delivered' || $status === 'returned') {
                            $historyBatch[] = [
                                'order_id' => $orderId,
                                'from_status' => 'shipped',
                                'to_status' => $status,
                                'changed_at' => $m['settled_at'],
                                'employee_id' => $m['employee_id'],
                                'source' => 'demo',
                            ];
                        }

                        $deliveryFee = $m['city'] === 'Dhaka' ? 60.00 : 110.00;
                        $returnFee = $status === 'returned' ? 50.00 : 0.00;
                        $shipmentsBatch[] = [
                            'tenant_id' => $tenantId,
                            'website_id' => $siteId,
                            'import_batch_id' => null,
                            'courier' => $m['courier'],
                            'consignment_id' => $m['consignment_id'],
                            'order_ref' => (string) $ord->external_id,
                            'order_id' => $orderId,
                            'customer_key' => $m['customer_key'],
                            'status' => $status,
                            'source_status' => $status,
                            'cod_amount' => $m['total'],
                            'collected_amount' => $status === 'delivered' ? $m['total'] : 0.00,
                            'delivery_fee' => $deliveryFee,
                            'return_fee' => $returnFee,
                            'area' => $m['city'],
                            'shipped_at' => $m['shipped_at'],
                            'delivered_at' => $status === 'delivered' ? $m['settled_at'] : null,
                            'returned_at' => $status === 'returned' ? $m['settled_at'] : null,
                            'matched' => true,
                            'match_method' => 'consignment_id',
                            'source' => 'demo',
                            'created_at' => $nowStr,
                            'updated_at' => $nowStr,
                        ];
                    }
                }
            }

            foreach (array_chunk($itemsBatch, 80) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }
            foreach (array_chunk($assignmentsBatch, 200) as $chunk) {
                DB::table('order_assignments')->insert($chunk);
            }
            foreach (array_chunk($historyBatch, 120) as $chunk) {
                DB::table('order_status_history')->insert($chunk);
            }
            foreach (array_chunk($shipmentsBatch, 40) as $chunk) {
                DB::table('courier_shipments')->insert($chunk);
            }

            // 6. Seed 45 days of daily ad performance across 3 campaigns (135 rows)
            $adRows = [];
            $campaigns = [
                ['platform' => 'meta', 'campaign' => 'dhaka_eid_fest', 'spend' => 1450.00, 'imp' => 28000, 'clk' => 840, 'conv' => 24.0, 'rev' => 36000.00],
                ['platform' => 'meta', 'campaign' => 'retargeting_cod', 'spend' => 650.00, 'imp' => 12500, 'clk' => 410, 'conv' => 11.0, 'rev' => 16500.00],
                ['platform' => 'google', 'campaign' => 'winter_bestseller', 'spend' => 550.00, 'imp' => 9800, 'clk' => 320, 'conv' => 9.0, 'rev' => 13200.00],
            ];
            for ($d = 44; $d >= 0; $d--) {
                $dayStr = $now->copy()->subDays($d)->format('Y-m-d');
                foreach ($campaigns as $camp) {
                    $adRows[] = [
                        'tenant_id' => $tenantId,
                        'website_id' => $siteId,
                        'import_batch_id' => null,
                        'platform' => $camp['platform'],
                        'campaign' => $camp['campaign'],
                        'date' => $dayStr,
                        'spend' => $camp['spend'],
                        'impressions' => $camp['imp'],
                        'clicks' => $camp['clk'],
                        'platform_conversions' => $camp['conv'],
                        'platform_revenue' => $camp['rev'],
                        'source' => 'demo',
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ];
                }
            }
            foreach (array_chunk($adRows, 60) as $chunk) {
                DB::table('ad_performance_daily')->insert($chunk);
            }

            return [
                'already_seeded' => false,
                'website_id' => $siteId,
                'products' => count($productRows),
                'customers' => count($custRows),
                'employees' => count($empRows),
                'orders' => count($ordersBatch),
                'shipments' => count($shipmentsBatch),
                'ad_rows' => count($adRows),
                'history_rows' => count($historyBatch),
            ];
        });

        TenantCache::clear($tenantId);
        MetricsService::for($tenantId)->rebuildDashboard();

        if (! $counts['already_seeded']) {
            AuditLog::record(
                'demo.seed',
                "Loaded deterministic demo data ({$counts['orders']} orders, {$counts['products']} products)",
                $tenantId,
                $actorUserId
            );
        }

        return $counts;
    }

    /**
     * Remove ONLY source=demo data (and demo-website R&D findings/experiments) for $tenantId,
     * leaving all real/non-demo tenant data untouched.
     *
     * @return array{orders_removed: int, products_removed: int, shipments_removed: int, ads_removed: int, websites_removed: int}
     */
    public static function remove(int $tenantId, ?int $actorUserId = null): array
    {
        $removed = DB::transaction(function () use ($tenantId): array {
            $demoSiteIds = DB::table('websites')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->pluck('id')
                ->all();

            // Remove findings/experiments scoped to demo websites (never touching real website data)
            if ($demoSiteIds !== []) {
                DB::table('experiment_results')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('experiment_id', function ($q) use ($tenantId, $demoSiteIds): void {
                        $q->select('id')->from('experiments')
                            ->where('tenant_id', $tenantId)
                            ->whereIn('website_id', $demoSiteIds);
                    })
                    ->delete();

                DB::table('experiments')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('website_id', $demoSiteIds)
                    ->delete();

                DB::table('research_findings')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('website_id', $demoSiteIds)
                    ->delete();
            }

            // Risk flags tied to demo orders or demo customers
            DB::table('risk_flags')
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($tenantId): void {
                    $q->whereIn('order_id', function ($sub) use ($tenantId): void {
                        $sub->select('id')->from('orders')
                            ->where('tenant_id', $tenantId)
                            ->where('source', 'demo');
                    })->orWhereIn('customer_id', function ($sub) use ($tenantId): void {
                        $sub->select('id')->from('customers')
                            ->where('tenant_id', $tenantId)
                            ->where('source', 'demo');
                    });
                })
                ->delete();

            $adsRemoved = DB::table('ad_performance_daily')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->delete();

            $shipmentsRemoved = DB::table('courier_shipments')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->delete();

            // Order child rows referencing demo orders
            DB::table('order_status_history')
                ->whereIn('order_id', function ($sub) use ($tenantId): void {
                    $sub->select('id')->from('orders')
                        ->where('tenant_id', $tenantId)
                        ->where('source', 'demo');
                })
                ->delete();

            DB::table('order_assignments')
                ->whereIn('order_id', function ($sub) use ($tenantId): void {
                    $sub->select('id')->from('orders')
                        ->where('tenant_id', $tenantId)
                        ->where('source', 'demo');
                })
                ->delete();

            DB::table('order_items')
                ->whereIn('order_id', function ($sub) use ($tenantId): void {
                    $sub->select('id')->from('orders')
                        ->where('tenant_id', $tenantId)
                        ->where('source', 'demo');
                })
                ->delete();

            $ordersRemoved = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->delete();

            $productsRemoved = DB::table('products')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->delete();

            DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->delete();

            DB::table('customers')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->delete();

            $websitesRemoved = DB::table('websites')
                ->where('tenant_id', $tenantId)
                ->where('source', 'demo')
                ->delete();

            return [
                'orders_removed' => (int) $ordersRemoved,
                'products_removed' => (int) $productsRemoved,
                'shipments_removed' => (int) $shipmentsRemoved,
                'ads_removed' => (int) $adsRemoved,
                'websites_removed' => (int) $websitesRemoved,
            ];
        });

        TenantCache::clear($tenantId);
        MetricsService::for($tenantId)->rebuildDashboard();

        AuditLog::record(
            'demo.remove',
            "Removed demo dataset ({$removed['orders_removed']} orders, {$removed['websites_removed']} websites)",
            $tenantId,
            $actorUserId
        );

        return $removed;
    }

    /**
     * 40 fixed Bangladesh COD commerce SKUs (37 active, 3 dead-stock SKUs with stock > 0 & 0 orders).
     *
     * @return list<array{ext_id: string, sku: string, name: string, category: string, price: float, cost: float, stock: int}>
     */
    private static function productCatalog(): array
    {
        $specs = [
            ['Premium Cotton Panjabi White', 'Menswear', 2200.00, 1080.00, 7],
            ['Printed Lawn Three-Piece', 'Womenswear', 1650.00, 860.00, 9],
            ['Embroidery Kurti Indigo', 'Womenswear', 1150.00, 620.00, 42],
            ['Classic Polo Shirt Navy', 'Menswear', 780.00, 410.00, 65],
            ['Georgette Hijab Pack of 3', 'Accessories', 690.00, 340.00, 90],
            ['Casual Denim Slim Fit', 'Menswear', 1350.00, 740.00, 48],
            ['Jamdani Half-Silk Saree', 'Womenswear', 2450.00, 1380.00, 22],
            ['Canvas Low-Top Sneakers', 'Footwear', 1550.00, 850.00, 34],
            ['Leather Crossbody Bag', 'Accessories', 1290.00, 690.00, 29],
            ['Floral Rayon Palazzo Set', 'Womenswear', 980.00, 520.00, 55],
            ['Linen Mandarin Shirt Olive', 'Menswear', 1190.00, 630.00, 44],
            ['Sport Dry-Fit Tee Black', 'Menswear', 550.00, 280.00, 110],
            ['Katan Festive Dupatta', 'Womenswear', 890.00, 460.00, 38],
            ['Cotton Bed Sheet Queen 4pc', 'Home', 1490.00, 820.00, 26],
            ['Microfiber Towel Duo', 'Home', 640.00, 330.00, 70],
            ['Minimalist Analog Watch', 'Accessories', 1390.00, 710.00, 31],
            ['Kids Eid Panjabi Sky', 'Kids', 920.00, 490.00, 46],
            ['Kids Floral Frock Rose', 'Kids', 850.00, 440.00, 50],
            ['Travel Backpack 28L', 'Accessories', 1690.00, 920.00, 24],
            ['Cotton Lungis Gift Box', 'Menswear', 1050.00, 560.00, 60],
            ['Oversized Hoodie Ash', 'Winterwear', 1250.00, 670.00, 40],
            ['Knit Cardigan Maroon', 'Winterwear', 1380.00, 740.00, 32],
            ['Pashmina Winter Shawl', 'Winterwear', 1120.00, 590.00, 37],
            ['Sports Running Shorts', 'Menswear', 490.00, 240.00, 85],
            ['Everyday Leather Wallet', 'Accessories', 680.00, 340.00, 64],
            ['UV Polarized Sunglasses', 'Accessories', 750.00, 360.00, 58],
            ['Cotton Formal Shirt Blue', 'Menswear', 1090.00, 580.00, 41],
            ['Chiffon Pleated Scarf', 'Womenswear', 590.00, 290.00, 80],
            ['Slip-On Comfort Sandals', 'Footwear', 890.00, 470.00, 49],
            ['Stainless Vacuum Flask 1L', 'Home', 790.00, 410.00, 62],
            ['Aromatic Prayer Mat Velour', 'Home', 950.00, 490.00, 52],
            ['Printed Tote Shopping Bag', 'Accessories', 450.00, 210.00, 95],
            ['Wireless Earbuds Lite', 'Electronics', 1450.00, 780.00, 33],
            ['Fast Braided Type-C Cable', 'Electronics', 350.00, 150.00, 140],
            ['Desk LED Study Lamp', 'Electronics', 880.00, 450.00, 47],
            ['Mens Ankle Socks Pack 5', 'Accessories', 420.00, 190.00, 120],
            ['Womens Knit Slipper Cozy', 'Footwear', 620.00, 310.00, 56],
            ['Velvet Shawl Limited Edition', 'Winterwear', 1890.00, 960.00, 22],
            ['Suede Loafers Heritage Tan', 'Footwear', 1750.00, 890.00, 25],
            ['Handcrafted Brass Desk Bell', 'Home', 1290.00, 650.00, 30],
        ];

        $out = [];
        foreach ($specs as $i => $row) {
            $num = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $out[] = [
                'ext_id' => 'DEMO-'.$num,
                'sku' => 'DM-SKU-'.$num,
                'name' => $row[0],
                'category' => $row[1],
                'price' => $row[2],
                'cost' => $row[3],
                'stock' => $row[4],
            ];
        }

        return $out;
    }
}
