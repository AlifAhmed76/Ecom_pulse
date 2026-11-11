<?php

namespace Tests\Feature;

use App\Jobs\RunRiskScan;
use App\Jobs\ScanAllTenantsRisk;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\RiskFlag;
use App\Models\Website;
use App\Services\CourierAnalyticsService;
use App\Services\RiskScannerService;
use App\Services\Sync\SyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CourierAndLossTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createSite(int $tenantId, string $name = 'Shop', string $currency = 'BDT'): Website
    {
        $site = new Website([
            'name' => $name,
            'platform' => 'file',
            'currency' => $currency,
        ]);
        $site->forceFill([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();

        return $site;
    }

    private function insertCustomer(int $tenantId, string $phone): int
    {
        return (int) DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'customer_key' => SyncService::customerKeyFor($tenantId, $phone),
            'name' => 'Buyer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEmployee(int $tenantId, string $name): int
    {
        return (int) DB::table('employees')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function insertOrder(int $tenantId, int $websiteId, string $extId, string $status, string $orderedAt, float $total, array $extra = []): int
    {
        return (int) DB::table('orders')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'website_id' => $websiteId,
            'external_id' => $extId,
            'customer_id' => null,
            'status' => $status,
            'source_status' => $status,
            'ordered_at' => $orderedAt,
            'subtotal' => $total,
            'discount' => 0,
            'shipping' => 0,
            'total' => $total,
            'currency' => 'BDT',
            'courier' => null,
            'consignment_id' => null,
            'city' => null,
            'cancel_reason' => null,
            'source' => 'connector',
            'created_at' => $orderedAt,
            'updated_at' => $orderedAt,
        ], $extra));
    }

    public function test_golden_dataset_loss_totals_splits_and_return_handling_cost(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Loss Golden Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid, 'Golden Shop', 'BDT');

        $empA = $this->insertEmployee($tid, 'Rafi');
        $empB = $this->insertEmployee($tid, 'Nadia');

        // 1) Delivered: 2000 (not lost)
        $this->insertOrder($tid, $site->id, 'L-1', 'delivered', '2026-11-10 10:00:00', 2000, [
            'courier' => 'Pathao',
            'city' => 'Dhaka',
        ]);

        // 2) Cancelled: 1200 (Rafi, Pathao, Dhaka, reason="Changed mind")
        $c1 = $this->insertOrder($tid, $site->id, 'L-2', 'cancelled', '2026-11-10 11:00:00', 1200, [
            'courier' => 'Pathao',
            'city' => 'Dhaka',
            'cancel_reason' => 'Changed mind',
        ]);
        DB::table('order_items')->insert([
            'order_id' => $c1,
            'product_name' => 'Hoodie',
            'sku' => 'HD-1',
            'quantity' => 2,
            'unit_price' => 600,
            'line_total' => 1200,
        ]);
        DB::table('order_assignments')->insert(['order_id' => $c1, 'employee_id' => $empA, 'assigned_at' => '2026-11-10 11:05:00']);

        // 3) Returned: 800 (Nadia, Steadfast, Chattogram)
        $r1 = $this->insertOrder($tid, $site->id, 'L-3', 'returned', '2026-11-10 12:00:00', 800, [
            'courier' => 'Steadfast',
            'city' => 'Chattogram',
        ]);
        DB::table('order_items')->insert([
            'order_id' => $r1,
            'product_name' => 'Sneaker',
            'sku' => 'SN-1',
            'quantity' => 1,
            'unit_price' => 800,
            'line_total' => 800,
        ]);
        DB::table('order_assignments')->insert(['order_id' => $r1, 'employee_id' => $empB, 'assigned_at' => '2026-11-10 12:05:00']);

        // Before courier fees exist -> shows "Needs courier report"
        $beforeReport = CourierAnalyticsService::for($tid)->report(['preset' => '7']);
        $this->assertSame(4000.0, $beforeReport['loss_headline']['all_orders_value']);
        $this->assertSame(1200.0, $beforeReport['loss_headline']['cancelled_value']);
        $this->assertSame(800.0, $beforeReport['loss_headline']['returned_value']);
        $this->assertSame(2000.0, $beforeReport['loss_headline']['total_lost_value']);
        $this->assertSame(50.0, $beforeReport['loss_headline']['lost_share_pct']);
        $this->assertFalse($beforeReport['loss_headline']['has_return_fee_data']);
        $this->assertNull($beforeReport['loss_headline']['estimated_return_handling_cost']);

        $this->actingAs($t['analyst'])
            ->get('/courier-loss?preset=7')
            ->assertOk()
            ->assertSee('Estimated sales not realised')
            ->assertSee('Needs courier report')
            ->assertSee('2,000.00')
            ->assertSee('50.00% of gross order value')
            ->assertDontSee('profit lost');

        // Insert returned courier_shipment with fees (60 delivery + 60 return = 120)
        DB::table('courier_shipments')->insert([
            'tenant_id' => $tid,
            'website_id' => $site->id,
            'courier' => 'Steadfast',
            'order_id' => $r1,
            'status' => 'returned',
            'delivery_fee' => 60.00,
            'return_fee' => 60.00,
            'matched' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \App\Support\TenantCache::clear($tid);
        $afterReport = CourierAnalyticsService::for($tid)->report(['preset' => '7']);
        $this->assertTrue($afterReport['loss_headline']['has_return_fee_data']);
        $this->assertSame(120.0, $afterReport['loss_headline']['estimated_return_handling_cost']);

        // Verify all 5 splits
        $byCourier = collect($afterReport['loss_splits']['by_courier'])->keyBy('label');
        $this->assertSame(1200.0, $byCourier['Pathao']['total_lost_value']);
        $this->assertSame(800.0, $byCourier['Steadfast']['total_lost_value']);

        $byArea = collect($afterReport['loss_splits']['by_area'])->keyBy('label');
        $this->assertSame(1200.0, $byArea['Dhaka']['total_lost_value']);
        $this->assertSame(800.0, $byArea['Chattogram']['total_lost_value']);

        $byEmp = collect($afterReport['loss_splits']['by_employee'])->keyBy('label');
        $this->assertSame(1200.0, $byEmp['Rafi']['total_lost_value']);
        $this->assertSame(800.0, $byEmp['Nadia']['total_lost_value']);

        $byProd = collect($afterReport['loss_splits']['by_product'])->keyBy('name');
        $this->assertSame(2, $byProd['Hoodie']['cancelled_units']);
        $this->assertSame(1200.0, $byProd['Hoodie']['total_lost_value']);
        $this->assertSame(1, $byProd['Sneaker']['returned_units']);
        $this->assertSame(800.0, $byProd['Sneaker']['total_lost_value']);

        $byReason = collect($afterReport['loss_splits']['by_cancel_reason'])->keyBy('reason');
        $this->assertSame(1200.0, $byReason['Changed mind']['cancelled_value']);
    }

    public function test_courier_import_matching_priority_coverage_history_and_no_auto_guessing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));
        Storage::fake('local');

        $t = $this->makeTenantWithUsers('Courier Match Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid, 'Match Shop', 'BDT');

        // Matched Order 1 by consignment_id
        $o1 = $this->insertOrder($tid, $site->id, 'ORD-101', 'shipped', '2026-11-09 09:00:00', 1000, [
            'consignment_id' => 'CN-9001',
        ]);
        // Matched Order 2 by order_ref (external_id = ORD-REF-2)
        $o2 = $this->insertOrder($tid, $site->id, 'ORD-REF-2', 'delivered', '2026-11-09 10:00:00', 800);
        // Matched Order 3 by customer_key + cod_amount within 1 day window
        $cust3 = $this->insertCustomer($tid, '01711223344');
        $o3 = $this->insertOrder($tid, $site->id, 'ORD-303', 'shipped', '2026-11-10 10:00:00', 550, [
            'customer_id' => $cust3,
        ]);

        // Ambiguous non-unique match: two orders with same customer & COD 999 inside 24h -> must NOT match either!
        $custAmbig = $this->insertCustomer($tid, '01999887766');
        $this->insertOrder($tid, $site->id, 'AMB-1', 'shipped', '2026-11-10 12:00:00', 999, ['customer_id' => $custAmbig]);
        $this->insertOrder($tid, $site->id, 'AMB-2', 'shipped', '2026-11-10 14:00:00', 999, ['customer_id' => $custAmbig]);

        $csvContent = file_get_contents(base_path('tests/Fixtures/synthetic_courier.csv'));
        $upload = UploadedFile::fake()->createWithContent('courier_nov.csv', (string) $csvContent);

        // Analyst cannot access courier import
        $this->actingAs($t['analyst'])->get('/courier-imports')->assertForbidden();

        // Owner uploads courier file
        $resp = $this->actingAs($t['owner'])->post('/courier-imports', [
            'courier_name' => 'Pathao',
            'website_id' => $site->id,
            'file' => $upload,
        ]);

        $batch = ImportBatch::query()->where('kind', 'courier')->latest('id')->firstOrFail();
        $resp->assertRedirect(route('courier-imports.map', $batch));

        // Verify NO column names were auto-guessed!
        foreach (array_keys(\App\Services\Courier\CourierMapper::FIELD_LABELS) as $field) {
            $this->assertNull($batch->mapping[$field]);
        }

        // Map screen displays explicit UNVERIFIED warning and manual dropdowns
        $this->actingAs($t['owner'])
            ->get(route('courier-imports.map', $batch))
            ->assertOk()
            ->assertSee('Explicit mapping required');

        // Confirm with explicit mapping from synthetic_courier.csv
        $this->actingAs($t['owner'])->post(route('courier-imports.confirm', $batch), [
            'courier_name' => 'Pathao',
            'map' => [
                'consignment_id' => 'Tracking_Col',
                'order_ref' => 'Order_Col',
                'status' => 'Deliv_Status',
                'cod_amount' => 'COD_Val',
                'collected_amount' => 'Collected_Val',
                'delivery_fee' => 'Del_Fee',
                'return_fee' => 'Ret_Fee',
                'area' => 'Dest_Area',
                'shipped_at' => 'Ship_Dt',
                'delivered_at' => 'Del_Dt',
                'returned_at' => 'Ret_Dt',
                'customer_contact' => 'Phone_Col',
            ],
        ])->assertRedirect(route('courier-imports.show', $batch));

        $batch->refresh();
        $this->assertSame('done', $batch->status);
        $this->assertNull($batch->stored_path); // temporary file cleaned up
        $this->assertSame(4, $batch->stats['rows_read']);
        $this->assertSame(3, $batch->stats['matched']);
        $this->assertSame(1, $batch->stats['unmatched']); // ambiguous 999 kept unmatched
        $this->assertSame(75.0, (float) $batch->stats['coverage_pct']);
        $this->assertSame(1, $batch->stats['match_methods']['consignment_id']);
        $this->assertSame(1, $batch->stats['match_methods']['order_ref']);
        $this->assertSame(1, $batch->stats['match_methods']['customer_amount']);

        // Order statuses updated and order_status_history appended with source='courier'
        $this->assertDatabaseHas('orders', ['id' => $o1, 'status' => 'delivered', 'courier' => 'Pathao']);
        $this->assertDatabaseHas('orders', ['id' => $o2, 'status' => 'returned']);
        $this->assertDatabaseHas('orders', ['id' => $o3, 'status' => 'delivered']);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $o1, 'to_status' => 'delivered', 'source' => 'courier']);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $o2, 'to_status' => 'returned', 'source' => 'courier']);

        // Customer phone was hashed ONLY, raw phone not saved in courier_shipments
        $this->assertDatabaseHas('courier_shipments', [
            'tenant_id' => $tid,
            'courier' => 'Pathao',
            'order_id' => $o3,
            'customer_key' => SyncService::customerKeyFor($tid, '01711223344'),
            'matched' => 1,
        ]);
        $this->assertDatabaseHas('courier_shipments', [
            'tenant_id' => $tid,
            'courier' => 'Pathao',
            'cod_amount' => 999,
            'order_id' => null,
            'matched' => 0,
        ]);

        // Re-importing the exact same file does NOT duplicate shipments
        $upload2 = UploadedFile::fake()->createWithContent('courier_nov_2.csv', (string) $csvContent);
        $this->actingAs($t['owner'])->post('/courier-imports', [
            'courier_name' => 'Pathao',
            'website_id' => $site->id,
            'file' => $upload2,
        ]);
        $batch2 = ImportBatch::query()->where('kind', 'courier')->latest('id')->firstOrFail();
        $this->actingAs($t['owner'])->post(route('courier-imports.confirm', $batch2), [
            'courier_name' => 'Pathao',
            'map' => [
                'consignment_id' => 'Tracking_Col',
                'order_ref' => 'Order_Col',
                'status' => 'Deliv_Status',
                'cod_amount' => 'COD_Val',
                'collected_amount' => 'Collected_Val',
                'delivery_fee' => 'Del_Fee',
                'return_fee' => 'Ret_Fee',
                'area' => 'Dest_Area',
                'shipped_at' => 'Ship_Dt',
                'delivered_at' => 'Del_Dt',
                'returned_at' => 'Ret_Dt',
                'customer_contact' => 'Phone_Col',
            ],
        ]);
        // Only CN-9001, ORD-303, ORD-REF-2 updated idempotently (no duplicate CN-9001 / o2 / o3 rows)
        $this->assertSame(1, DB::table('courier_shipments')->where('tenant_id', $tid)->where('consignment_id', 'CN-9001')->count());
        $this->assertSame(1, DB::table('courier_shipments')->where('tenant_id', $tid)->where('order_id', $o2)->count());
        $this->assertSame(1, DB::table('courier_shipments')->where('tenant_id', $tid)->where('order_id', $o3)->count());
    }

    public function test_scorecard_sample_size_guard_and_z_test_outlier_boundaries(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Scorecard Stats Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid, 'Stats Shop', 'BDT');

        // Helper to insert N delivered + M returned shipments cleanly
        $insertShipments = function (string $courier, string $area, int $delivered, int $returned) use ($tid, $site): void {
            $rows = [];
            for ($i = 0; $i < $delivered; $i++) {
                $rows[] = [
                    'tenant_id' => $tid,
                    'website_id' => $site->id,
                    'courier' => $courier,
                    'area' => $area,
                    'status' => 'delivered',
                    'cod_amount' => 1000,
                    'collected_amount' => 1000,
                    'shipped_at' => '2026-11-09 10:00:00',
                    'delivered_at' => '2026-11-10 10:00:00', // 24.0 h
                    'matched' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            for ($i = 0; $i < $returned; $i++) {
                $rows[] = [
                    'tenant_id' => $tid,
                    'website_id' => $site->id,
                    'courier' => $courier,
                    'area' => $area,
                    'status' => 'returned',
                    'cod_amount' => 1000,
                    'collected_amount' => 0,
                    'shipped_at' => '2026-11-09 10:00:00',
                    'delivered_at' => null,
                    'matched' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('courier_shipments')->insert($rows);
        };

        // Baseline major courier: 95 delivered, 5 returned (95%, n=100)
        $insertShipments('SteadyMain', 'Dhaka', 95, 5);
        // Courier with n=19 (< 20): insufficient data & no rank
        $insertShipments('SmallOne', 'Rajshahi', 10, 9);
        // Courier with n=29 (< 30 z-test threshold!): low rate 10/29 = 34.48% vs 95%, passes 20-sample guard, MUST NOT trigger z-test outlier because n < 30!
        $insertShipments('Border29', 'Khulna', 10, 19);
        // Courier with n=30 (>= 30): low rate 15/30 = 50% vs other couriers -> MUST trigger Statistically unusual!
        $insertShipments('Outlier30', 'Sylhet', 15, 15);

        $report = CourierAnalyticsService::for($tid)->report(['preset' => '30']);
        $byCourier = collect($report['courier_scorecard'])->keyBy('name');

        $this->assertTrue($byCourier['SmallOne']['insufficient_data']);
        $this->assertNull($byCourier['SmallOne']['success_rate']);
        $this->assertNull($byCourier['SmallOne']['rank']);
        $this->assertFalse($byCourier['SmallOne']['statistically_unusual']);

        $this->assertFalse($byCourier['Border29']['insufficient_data']);
        $this->assertSame(34.48, $byCourier['Border29']['success_rate']);
        $this->assertNotNull($byCourier['Border29']['rank']);
        $this->assertFalse($byCourier['Border29']['statistically_unusual']); // n=29 < 30 boundary!

        $this->assertFalse($byCourier['Outlier30']['insufficient_data']);
        $this->assertSame(50.0, $byCourier['Outlier30']['success_rate']);
        $this->assertTrue($byCourier['Outlier30']['statistically_unusual']); // n=30, p < 0.05!
        $this->assertSame('Statistically unusual', $byCourier['Outlier30']['outlier_label']);
        $this->assertLessThan(0.05, $byCourier['Outlier30']['p_value']);
        $this->assertSame(24.0, $byCourier['SteadyMain']['avg_delivery_hours']);

        // Verify Area scorecard applies identical guards & z-test
        $byArea = collect($report['area_scorecard'])->keyBy('name');
        $this->assertFalse($byArea['Khulna']['statistically_unusual']);
        $this->assertTrue($byArea['Sylhet']['statistically_unusual']);

        $this->actingAs($t['owner'])
            ->get('/courier-loss')
            ->assertOk()
            ->assertSee('Statistically unusual')
            ->assertSee('Insufficient data (&lt;20)', false);
    }

    public function test_suspicious_order_rules_s1_s2_s3_boundaries_and_advisory_review_does_not_alter_orders(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Risk Rules Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid, 'Risk Shop', 'BDT');

        // S1 boundary: 2 failures in 30d (+ 1 failure 31 days ago) -> MUST NOT trigger
        $cS1Low = $this->insertCustomer($tid, '01710000001');
        $this->insertOrder($tid, $site->id, 'S1L-OLD', 'cancelled', '2026-10-10 10:00:00', 500, ['customer_id' => $cS1Low]); // 32d ago
        $this->insertOrder($tid, $site->id, 'S1L-1', 'cancelled', '2026-11-01 10:00:00', 500, ['customer_id' => $cS1Low]);
        $this->insertOrder($tid, $site->id, 'S1L-2', 'returned', '2026-11-05 10:00:00', 500, ['customer_id' => $cS1Low]);

        // S1 trigger: 3 failures inside 30d
        $cS1Hit = $this->insertCustomer($tid, '01710000002');
        $this->insertOrder($tid, $site->id, 'S1H-1', 'cancelled', '2026-10-20 10:00:00', 500, ['customer_id' => $cS1Hit]);
        $this->insertOrder($tid, $site->id, 'S1H-2', 'returned', '2026-11-01 10:00:00', 500, ['customer_id' => $cS1Hit]);
        $s1LatestId = $this->insertOrder($tid, $site->id, 'S1H-3', 'cancelled', '2026-11-10 10:00:00', 500, ['customer_id' => $cS1Hit]);

        // S2 boundary: same customer, same item set & total, 25h apart -> MUST NOT trigger
        $cS2Far = $this->insertCustomer($tid, '01710000003');
        $f1 = $this->insertOrder($tid, $site->id, 'S2F-1', 'pending', '2026-11-08 10:00:00', 900, ['customer_id' => $cS2Far]);
        $f2 = $this->insertOrder($tid, $site->id, 'S2F-2', 'pending', '2026-11-09 11:00:00', 900, ['customer_id' => $cS2Far]); // +25h
        foreach ([$f1, $f2] as $oid) {
            DB::table('order_items')->insert([
                'order_id' => $oid,
                'product_name' => 'Polo',
                'sku' => 'POLO-1',
                'quantity' => 1,
                'unit_price' => 900,
                'line_total' => 900,
            ]);
        }

        // S2 non-match: within 2h, same total, DIFFERENT item set -> MUST NOT trigger
        $cS2Diff = $this->insertCustomer($tid, '01710000004');
        $d1 = $this->insertOrder($tid, $site->id, 'S2D-1', 'pending', '2026-11-10 10:00:00', 900, ['customer_id' => $cS2Diff]);
        $d2 = $this->insertOrder($tid, $site->id, 'S2D-2', 'pending', '2026-11-10 12:00:00', 900, ['customer_id' => $cS2Diff]);
        DB::table('order_items')->insert([
            ['order_id' => $d1, 'product_name' => 'Item A', 'sku' => 'A-1', 'quantity' => 1, 'unit_price' => 900, 'line_total' => 900],
            ['order_id' => $d2, 'product_name' => 'Item B', 'sku' => 'B-1', 'quantity' => 1, 'unit_price' => 900, 'line_total' => 900],
        ]);

        // S2 trigger: within 23h, same item set & total (items inserted in swapped order to verify canonical sorting)
        $cS2Hit = $this->insertCustomer($tid, '01710000005');
        $h1 = $this->insertOrder($tid, $site->id, 'S2H-1', 'pending', '2026-11-09 10:00:00', 700, ['customer_id' => $cS2Hit]);
        $h2 = $this->insertOrder($tid, $site->id, 'S2H-2', 'pending', '2026-11-10 09:00:00', 700, ['customer_id' => $cS2Hit]); // +23h
        DB::table('order_items')->insert([
            ['order_id' => $h1, 'product_name' => 'Cap', 'sku' => 'C-1', 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200],
            ['order_id' => $h1, 'product_name' => 'Tee', 'sku' => 'T-1', 'quantity' => 1, 'unit_price' => 500, 'line_total' => 500],
            ['order_id' => $h2, 'product_name' => 'Tee', 'sku' => 'T-1', 'quantity' => 1, 'unit_price' => 500, 'line_total' => 500],
            ['order_id' => $h2, 'product_name' => 'Cap', 'sku' => 'C-1', 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200],
        ]);

        // S3 boundary: 4 orders in 45m + 5th order at +65m -> MUST NOT trigger
        $cS3Miss = $this->insertCustomer($tid, '01710000006');
        foreach (['10:00:00', '10:15:00', '10:30:00', '10:45:00', '11:05:00'] as $idx => $time) {
            $this->insertOrder($tid, $site->id, 'S3M-'.$idx, 'pending', '2026-11-11 '.$time, 300, ['customer_id' => $cS3Miss]);
        }

        // S3 trigger: 5 orders within 55m
        $cS3Hit = $this->insertCustomer($tid, '01710000007');
        foreach (['08:00:00', '08:10:00', '08:25:00', '08:40:00', '08:55:00'] as $idx => $time) {
            $this->insertOrder($tid, $site->id, 'S3H-'.$idx, 'confirmed', '2026-11-11 '.$time, 400 + $idx, ['customer_id' => $cS3Hit]);
        }

        // Run scan via unauthenticated job context (scheduler / queue)
        Auth::logout();
        (new RunRiskScan($tid))->handle(app(RiskScannerService::class));

        $flags = RiskFlag::withoutGlobalScopes()->where('tenant_id', $tid)->get();
        $this->assertCount(3, $flags);

        $s1 = $flags->firstWhere('rule', 'S1');
        $this->assertNotNull($s1);
        $this->assertSame($cS1Hit, $s1->customer_id);
        $this->assertSame($s1LatestId, $s1->order_id);

        $s2 = $flags->firstWhere('rule', 'S2');
        $this->assertNotNull($s2);
        $this->assertSame($cS2Hit, $s2->customer_id);
        $this->assertSame([$h1, $h2], $s2->evidence['order_ids']);

        $s3 = $flags->firstWhere('rule', 'S3');
        $this->assertNotNull($s3);
        $this->assertSame($cS3Hit, $s3->customer_id);
        $this->assertSame(5, $s3->evidence['order_count_1h']);

        // Re-running scan does NOT create duplicate flags
        (new RunRiskScan($tid))->handle(app(RiskScannerService::class));
        $this->assertSame(3, RiskFlag::withoutGlobalScopes()->where('tenant_id', $tid)->count());

        // UI shows false-positives reminder and review queue
        $this->actingAs($t['analyst'])
            ->get('/courier-loss')
            ->assertOk()
            ->assertSee('Possible false positives: repeat legitimate buyers, family orders');

        // Analyst cannot review flags or scan
        $this->actingAs($t['analyst'])->post("/courier-loss/flags/{$s1->id}/review", ['status' => 'reviewed_ok'])->assertForbidden();
        $this->actingAs($t['analyst'])->post('/courier-loss/scan')->assertForbidden();

        // Owner confirms S1 & marks S2 reviewed_ok -> linked orders NEVER altered!
        $s1OrderBefore = Order::withoutGlobalScopes()->findOrFail($s1->order_id);
        $s2OrderBefore = Order::withoutGlobalScopes()->findOrFail($s2->order_id);

        $this->actingAs($t['owner'])->post("/courier-loss/flags/{$s1->id}/review", [
            'status' => 'confirmed',
            'review_note' => 'Verified fake COD address',
        ])->assertRedirect();

        $this->actingAs($t['owner'])->post("/courier-loss/flags/{$s2->id}/review", [
            'status' => 'reviewed_ok',
        ])->assertRedirect();

        $this->assertDatabaseHas('risk_flags', ['id' => $s1->id, 'status' => 'confirmed', 'reviewed_by' => $t['owner']->id]);
        $this->assertDatabaseHas('risk_flags', ['id' => $s2->id, 'status' => 'reviewed_ok', 'reviewed_by' => $t['owner']->id]);

        $s1OrderAfter = Order::withoutGlobalScopes()->findOrFail($s1->order_id);
        $s2OrderAfter = Order::withoutGlobalScopes()->findOrFail($s2->order_id);
        $this->assertSame($s1OrderBefore->status, $s1OrderAfter->status);
        $this->assertSame((string) $s1OrderBefore->total, (string) $s1OrderAfter->total);
        $this->assertSame($s2OrderBefore->status, $s2OrderAfter->status);
        $this->assertSame((string) $s2OrderBefore->total, (string) $s2OrderAfter->total);

        // Re-scan preserves existing reviewed statuses
        (new RunRiskScan($tid))->handle(app(RiskScannerService::class));
        $this->assertDatabaseHas('risk_flags', ['id' => $s1->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('risk_flags', ['id' => $s2->id, 'status' => 'reviewed_ok']);
    }

    public function test_tenant_isolation_and_scheduled_risk_scan_dispatch(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $a = $this->makeTenantWithUsers('Courier Iso Alpha');
        $b = $this->makeTenantWithUsers('Courier Iso Beta');

        $siteA = $this->createSite($a['tenant']->id, 'Alpha Site', 'BDT');
        $siteB = $this->createSite($b['tenant']->id, 'Beta Secret Site', 'BDT');

        $this->insertOrder($a['tenant']->id, $siteA->id, 'A-1', 'cancelled', '2026-11-10 10:00:00', 500, ['courier' => 'AlphaCou']);
        $this->insertOrder($b['tenant']->id, $siteB->id, 'B-1', 'cancelled', '2026-11-10 10:00:00', 88888, ['courier' => 'SecretBetaCou']);

        $bCust = $this->insertCustomer($b['tenant']->id, '01812345678');
        $bOrd = $this->insertOrder($b['tenant']->id, $siteB->id, 'B-FLAG', 'cancelled', '2026-11-10 10:00:00', 100, ['customer_id' => $bCust]);
        $bFlag = new RiskFlag();
        $bFlag->forceFill([
            'tenant_id' => $b['tenant']->id,
            'order_id' => $bOrd,
            'customer_id' => $bCust,
            'rule' => 'S1',
            'fingerprint' => hash('sha256', 'secret-beta-flag'),
            'evidence' => ['external_ids' => ['B-FLAG'], 'timestamps' => ['2026-11-10 10:00:00']],
            'status' => 'open',
        ])->save();

        // Tenant A cannot see Tenant B loss totals, couriers, or flags
        $this->actingAs($a['owner'])
            ->get('/courier-loss')
            ->assertOk()
            ->assertSee('500.00')
            ->assertDontSee('88,888.00')
            ->assertDontSee('SecretBetaCou')
            ->assertDontSee('B-FLAG');

        // Cross-tenant website filter and cross-tenant flag review return 404
        $this->actingAs($a['owner'])->get('/courier-loss?website_id='.$siteB->id)->assertNotFound();
        $this->actingAs($a['owner'])->post("/courier-loss/flags/{$bFlag->id}/review", ['status' => 'confirmed'])->assertNotFound();
        $this->assertDatabaseHas('risk_flags', ['id' => $bFlag->id, 'status' => 'open']);

        // ScanAllTenantsRisk queues one RunRiskScan per tenant
        Queue::fake();
        (new ScanAllTenantsRisk())->handle();
        Queue::assertPushedOn('default', RunRiskScan::class, fn (RunRiskScan $j) => $j->tenantId === $a['tenant']->id);
        Queue::assertPushedOn('default', RunRiskScan::class, fn (RunRiskScan $j) => $j->tenantId === $b['tenant']->id);

        $this->expectException(InvalidArgumentException::class);
        CourierAnalyticsService::for($a['tenant']->id)->report(['website_id' => $siteB->id]);
    }
}
