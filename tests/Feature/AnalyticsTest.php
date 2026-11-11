<?php

namespace Tests\Feature;

use App\Jobs\WarmTenantCache;
use App\Models\Website;
use App\Services\AnalyticsService;
use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createSite(int $tenantId, string $name, string $currency = 'BDT', bool $connected = false): Website
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
            'last_heartbeat_at' => $connected ? now()->subMinutes(2) : null,
        ])->save();

        return $site;
    }

    private function insertCustomer(int $tenantId, string $keySeed, string $name): int
    {
        return (int) DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'customer_key' => hash('sha256', $keySeed),
            'name' => $name,
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

    private function insertProduct(int $tenantId, int $websiteId, string $extId, string $sku, string $name, ?int $stock, float $price, ?float $cost = null): int
    {
        return (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'website_id' => $websiteId,
            'external_id' => $extId,
            'sku' => $sku,
            'name' => $name,
            'price' => $price,
            'cost' => $cost,
            'stock' => $stock,
            'active' => true,
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
            'source' => 'connector',
            'created_at' => $orderedAt,
            'updated_at' => $orderedAt,
        ], $extra));
    }

    public function test_golden_dataset_exact_values_and_http_render(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Golden Analytics Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid, 'Golden Shop', 'BDT');

        $c1 = $this->insertCustomer($tid, '01711000001', 'Amina');
        $c2 = $this->insertCustomer($tid, '01711000002', 'Babu');
        $c3 = $this->insertCustomer($tid, '01711000003', 'Choton');

        $e1 = $this->insertEmployee($tid, 'Rafi');
        $e2 = $this->insertEmployee($tid, 'Tania');

        $p1 = $this->insertProduct($tid, $site->id, 'P1', 'SKU-1', 'Tunic', 20, 500, 300);
        $p2 = $this->insertProduct($tid, $site->id, 'P2', 'SKU-2', 'Scarf', 8, 200, 100);
        $p3 = $this->insertProduct($tid, $site->id, 'P3', 'SKU-3', 'Cap', 100, 50, 30);

        // Prior lifetime order outside 7-day window (2026-09-27) -> makes C1 a repeat buyer
        $oldId = $this->insertOrder($tid, $site->id, 'OLD-1', 'delivered', '2026-09-27 10:00:00', 500, ['customer_id' => $c1]);
        DB::table('order_items')->insert([
            'order_id' => $oldId,
            'product_id' => $p1,
            'product_name' => 'Tunic',
            'sku' => 'SKU-1',
            'quantity' => 5,
            'unit_price' => 100,
            'unit_cost' => 60,
            'line_total' => 500,
        ]);

        // ORD-1: 2026-11-10 delivered, 1000, C1, Pathao, E1, 30m response
        $o1 = $this->insertOrder($tid, $site->id, 'ORD-1', 'delivered', '2026-11-10 10:00:00', 1000, [
            'customer_id' => $c1,
            'courier' => 'Pathao',
        ]);
        DB::table('order_items')->insert([
            'order_id' => $o1,
            'product_id' => $p1,
            'product_name' => 'Tunic',
            'sku' => 'SKU-1',
            'quantity' => 2,
            'unit_price' => 500,
            'unit_cost' => 300,
            'line_total' => 1000,
        ]);
        DB::table('order_assignments')->insert(['order_id' => $o1, 'employee_id' => $e1, 'assigned_at' => '2026-11-10 10:05:00']);
        DB::table('order_status_history')->insert([
            'order_id' => $o1,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'changed_at' => '2026-11-10 10:30:00',
            'employee_id' => $e1,
            'source' => 'connector',
        ]);

        // ORD-2: 2026-11-10 delivered, 600, C2, Pathao, E1, 15m response
        $o2 = $this->insertOrder($tid, $site->id, 'ORD-2', 'delivered', '2026-11-10 14:00:00', 600, [
            'customer_id' => $c2,
            'courier' => 'Pathao',
        ]);
        DB::table('order_items')->insert([
            [
                'order_id' => $o2,
                'product_id' => $p1,
                'product_name' => 'Tunic',
                'sku' => 'SKU-1',
                'quantity' => 1,
                'unit_price' => 400,
                'unit_cost' => 250,
                'line_total' => 400,
            ],
            [
                'order_id' => $o2,
                'product_id' => $p2,
                'product_name' => 'Scarf',
                'sku' => 'SKU-2',
                'quantity' => 1,
                'unit_price' => 200,
                'unit_cost' => null,
                'line_total' => 200,
            ],
        ]);
        DB::table('order_assignments')->insert(['order_id' => $o2, 'employee_id' => $e1, 'assigned_at' => '2026-11-10 14:02:00']);
        DB::table('order_status_history')->insert([
            'order_id' => $o2,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'changed_at' => '2026-11-10 14:15:00',
            'employee_id' => $e1,
            'source' => 'connector',
        ]);

        // ORD-3: 2026-11-10 returned, 400, C2, Pathao, E1, import history MUST be excluded from response time
        $o3 = $this->insertOrder($tid, $site->id, 'ORD-3', 'returned', '2026-11-10 16:00:00', 400, [
            'customer_id' => $c2,
            'courier' => 'Pathao',
        ]);
        DB::table('order_items')->insert([
            'order_id' => $o3,
            'product_id' => $p2,
            'product_name' => 'Scarf',
            'sku' => 'SKU-2',
            'quantity' => 2,
            'unit_price' => 200,
            'unit_cost' => 100,
            'line_total' => 400,
        ]);
        DB::table('order_assignments')->insert(['order_id' => $o3, 'employee_id' => $e1, 'assigned_at' => '2026-11-10 16:05:00']);
        DB::table('order_status_history')->insert([
            'order_id' => $o3,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'changed_at' => '2026-11-10 16:45:00',
            'employee_id' => $e1,
            'source' => 'import',
        ]);

        // ORD-4: 2026-11-11 cancelled, 300, C3, E2, ONLY import history -> Tania response = null ("n/a")
        $o4 = $this->insertOrder($tid, $site->id, 'ORD-4', 'cancelled', '2026-11-11 09:00:00', 300, [
            'customer_id' => $c3,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $o4,
            'product_id' => $p2,
            'product_name' => 'Scarf',
            'sku' => 'SKU-2',
            'quantity' => 1,
            'unit_price' => 300,
            'unit_cost' => 150,
            'line_total' => 300,
        ]);
        DB::table('order_assignments')->insert(['order_id' => $o4, 'employee_id' => $e2, 'assigned_at' => '2026-11-11 09:01:00']);
        DB::table('order_status_history')->insert([
            'order_id' => $o4,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'changed_at' => '2026-11-11 09:10:00',
            'employee_id' => $e2,
            'source' => 'import',
        ]);

        // ORD-5: 2026-11-11 shipped, 500, C3, Steadfast, E2
        $o5 = $this->insertOrder($tid, $site->id, 'ORD-5', 'shipped', '2026-11-11 11:00:00', 500, [
            'customer_id' => $c3,
            'courier' => 'Steadfast',
        ]);
        DB::table('order_items')->insert([
            'order_id' => $o5,
            'product_id' => $p3,
            'product_name' => 'Cap',
            'sku' => 'SKU-3',
            'quantity' => 10,
            'unit_price' => 50,
            'unit_cost' => 30,
            'line_total' => 500,
        ]);
        DB::table('order_assignments')->insert(['order_id' => $o5, 'employee_id' => $e2, 'assigned_at' => '2026-11-11 11:01:00']);

        $data = AnalyticsService::for($tid)->analytics(['preset' => '7']);

        $this->assertSame('2026-11-05', $data['filters']['from']);
        $this->assertSame('2026-11-11', $data['filters']['to']);
        $this->assertSame('BDT', $data['currency']);
        $this->assertFalse($data['mixed_currency']);
        $this->assertNotEmpty($data['computed_at']);
        $this->assertGreaterThanOrEqual(15, count($data['definitions']));

        // Exact KPI numbers
        $k = $data['kpis'];
        $this->assertSame(5, $k['orders']);
        $this->assertSame(4, $k['non_cancelled_orders']);
        $this->assertSame(2, $k['delivered_orders']);
        $this->assertSame(1, $k['cancelled_orders']);
        $this->assertSame(1, $k['returned_orders']);
        $this->assertSame(2500.0, $k['gross_sales']);
        $this->assertSame(1600.0, $k['delivered_revenue']);
        $this->assertSame(625.0, $k['aov']);
        $this->assertSame(20.0, $k['cancellation_rate']);
        $this->assertSame(33.33, $k['return_rate']);
        $this->assertSame(40.0, $k['delivery_rate']);
        $this->assertSame(66.67, $k['repeat_purchase_rate']);
        $this->assertSame(3, $k['buyers_in_range']);
        $this->assertSame(2, $k['repeat_buyers']);
        $this->assertSame(300.0, $k['lost_to_cancel']);
        $this->assertSame(400.0, $k['lost_to_return']);

        // Daily trend
        $trendByDay = collect($data['daily_trend'])->keyBy('day');
        $this->assertCount(7, $data['daily_trend']);
        $this->assertSame(3, $trendByDay['2026-11-10']['orders']);
        $this->assertSame(2000.0, $trendByDay['2026-11-10']['gross_sales']);
        $this->assertSame(1600.0, $trendByDay['2026-11-10']['delivered_revenue']);
        $this->assertSame(2, $trendByDay['2026-11-11']['orders']);
        $this->assertSame(500.0, $trendByDay['2026-11-11']['gross_sales']);
        $this->assertSame(0.0, $trendByDay['2026-11-11']['delivered_revenue']);

        // Top products
        $prods = collect($data['top_products'])->keyBy('name');
        $this->assertSame(1400.0, $prods['Tunic']['revenue']);
        $this->assertSame(3, $prods['Tunic']['units']);
        $this->assertSame(0, $prods['Tunic']['return_units']);
        $this->assertSame(0, $prods['Tunic']['cancel_units']);

        $this->assertSame(600.0, $prods['Scarf']['revenue']);
        $this->assertSame(3, $prods['Scarf']['units']);
        $this->assertSame(2, $prods['Scarf']['return_units']);
        $this->assertSame(1, $prods['Scarf']['cancel_units']);

        $this->assertSame(500.0, $prods['Cap']['revenue']);
        $this->assertSame(10, $prods['Cap']['units']);

        // Estimated contribution
        $c = $data['contribution'];
        $this->assertSame(550.0, $c['estimated_contribution']);
        $this->assertSame(87.5, $c['cost_coverage_pct']);
        $this->assertTrue($c['sufficient_coverage']);
        $this->assertSame(39.29, $c['margin_pct']);

        // Employee response time ignores import history
        $emps = collect($data['employees'])->keyBy('name');
        $this->assertSame(3, $emps['Rafi']['assigned']);
        $this->assertSame(100.0, $emps['Rafi']['confirmation_rate']);
        $this->assertSame(22.5, $emps['Rafi']['avg_response_minutes']);

        $this->assertSame(2, $emps['Tania']['assigned']);
        $this->assertSame(50.0, $emps['Tania']['confirmation_rate']);
        $this->assertSame(50.0, $emps['Tania']['cancel_rate']);
        $this->assertNull($emps['Tania']['avg_response_minutes']);

        // Stock alerts: Scarf (stock=8 <= 10, 30d non-cancelled units = 3 -> cover 80.0 days)
        $alerts = collect($data['stock_alerts'])->keyBy('name');
        $this->assertTrue($alerts->has('Scarf'));
        $this->assertSame(3, $alerts['Scarf']['units_30d']);
        $this->assertSame(80.0, $alerts['Scarf']['cover_days']);
        $this->assertFalse($alerts->has('Tunic'));
        $this->assertFalse($alerts->has('Cap'));

        // Analyst HTTP page render includes range, currency, computed_at, definitions, charts
        $this->actingAs($t['analyst'])
            ->get('/analytics?preset=7')
            ->assertOk()
            ->assertSee('2026-11-05 to 2026-11-11 (Asia/Dhaka)')
            ->assertSee('BDT')
            ->assertSee($data['computed_at'])
            ->assertSee('Metric Definitions')
            ->assertSee('Data Quality Breakdown')
            ->assertSee('Estimated Contribution')
            ->assertSee('87.5%')
            ->assertSee('chart-analytics-daily-json')
            ->assertSee('chart-analytics-funnel-json')
            ->assertSee('chart-analytics-top-products-json')
            ->assertSee('22.5 min')
            ->assertSee('n/a')
            ->assertSee('Insufficient data');
    }

    public function test_stock_cover_days_alert_and_null_cover_when_no_30d_sales(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Stock Cover Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid, 'Stock Shop', 'BDT');

        // Fast-moving item: stock 15 (> 10 threshold), 30 units sold in last 30d -> cover = 15.0 days (alert!)
        $fast = $this->insertProduct($tid, $site->id, 'FAST-1', 'FAST', 'Fast Item', 15, 100, 50);
        // Zero-sales low-stock item: stock 4 (<= 10 threshold), 0 sales -> cover_days = null
        $this->insertProduct($tid, $site->id, 'SLOW-1', 'SLOW', 'Zero Sales Low', 4, 100, 50);

        $ordId = $this->insertOrder($tid, $site->id, 'ORD-FAST', 'confirmed', '2026-11-01 10:00:00', 3000);
        DB::table('order_items')->insert([
            'order_id' => $ordId,
            'product_id' => $fast,
            'product_name' => 'Fast Item',
            'sku' => 'FAST',
            'quantity' => 30,
            'unit_price' => 100,
            'unit_cost' => 50,
            'line_total' => 3000,
        ]);

        $alerts = collect(AnalyticsService::for($tid)->analytics(['preset' => '30'])['stock_alerts'])->keyBy('name');

        $this->assertTrue($alerts->has('Fast Item'));
        $this->assertSame(15.0, $alerts['Fast Item']['cover_days']);
        $this->assertTrue($alerts->has('Zero Sales Low'));
        $this->assertNull($alerts['Zero Sales Low']['cover_days']);

        $this->actingAs($t['owner'])
            ->get('/analytics')
            ->assertOk()
            ->assertSee('15.0 days')
            ->assertSee('No 30d sales');
    }

    public function test_minimum_sample_guards_for_courier_20_and_cost_coverage_60(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Sample Guards Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid, 'Guard Shop', 'BDT');

        // Courier A has 19 orders (< 20): 15 delivered, 4 returned -> insufficient_data = true, success_rate = null
        for ($i = 1; $i <= 19; $i++) {
            $status = $i <= 15 ? 'delivered' : 'returned';
            $this->insertOrder($tid, $site->id, "CA-{$i}", $status, '2026-11-10 10:00:00', 500, ['courier' => 'RedX']);
        }

        // Courier B has 20 orders (>= 20): 16 delivered, 4 returned -> 80.0%
        for ($i = 1; $i <= 20; $i++) {
            $status = $i <= 16 ? 'delivered' : 'returned';
            $ordId = $this->insertOrder($tid, $site->id, "CB-{$i}", $status, '2026-11-10 11:00:00', 100, ['courier' => 'Pathao']);
            // Delivered order cost coverage: 16 delivered orders * 100 = 1600 total rev; only 8 costed -> 50% (< 60%)
            if ($status === 'delivered') {
                DB::table('order_items')->insert([
                    'order_id' => $ordId,
                    'product_name' => 'Widget',
                    'sku' => 'W-1',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'unit_cost' => $i <= 8 ? 60 : null,
                    'line_total' => 100,
                ]);
            }
        }

        $data = AnalyticsService::for($tid)->analytics(['preset' => '30']);
        $couriers = collect($data['couriers'])->keyBy('courier');

        $this->assertSame(19, $couriers['RedX']['total']);
        $this->assertTrue($couriers['RedX']['insufficient_data']);
        $this->assertNull($couriers['RedX']['success_rate']);

        $this->assertSame(20, $couriers['Pathao']['total']);
        $this->assertFalse($couriers['Pathao']['insufficient_data']);
        $this->assertSame(80.0, $couriers['Pathao']['success_rate']);

        $this->assertSame(50.0, $data['contribution']['cost_coverage_pct']);
        $this->assertFalse($data['contribution']['sufficient_coverage']);
        $this->assertNull($data['contribution']['margin_pct']);
        $this->assertSame('cost data insufficient', $data['contribution']['status_label']);

        $this->actingAs($t['owner'])
            ->get('/analytics')
            ->assertOk()
            ->assertSee('Insufficient data (&lt;20)', false)
            ->assertSee('80.00%')
            ->assertSee('Cost data insufficient (50.0% coverage)');
    }

    public function test_mixed_currency_warning_hides_non_comparable_money_totals(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Mixed Currency Co');
        $tid = $t['tenant']->id;
        $siteBdt = $this->createSite($tid, 'BD Shop', 'BDT');
        $siteUsd = $this->createSite($tid, 'US Shop', 'USD');

        $this->insertOrder($tid, $siteBdt->id, 'BD-1', 'delivered', '2026-11-10 10:00:00', 1000, ['currency' => 'BDT']);
        $this->insertOrder($tid, $siteUsd->id, 'US-1', 'delivered', '2026-11-10 11:00:00', 25, ['currency' => 'USD']);

        $data = AnalyticsService::for($tid)->analytics(['preset' => '7']);

        $this->assertTrue($data['mixed_currency']);
        $this->assertSame('MIXED', $data['currency']);
        $this->assertNotEmpty($data['currency_warning']);
        $this->assertSame(2, $data['kpis']['orders']);
        $this->assertSame(100.0, $data['kpis']['delivery_rate']);
        $this->assertNull($data['kpis']['gross_sales']);
        $this->assertNull($data['kpis']['delivered_revenue']);
        $this->assertNull($data['kpis']['aov']);
        $this->assertNull($data['kpis']['lost_to_cancel']);
        $this->assertNull($data['kpis']['lost_to_return']);
        $this->assertNull($data['contribution']['estimated_contribution']);
        $this->assertNull($data['contribution']['margin_pct']);

        $this->actingAs($t['owner'])
            ->get('/analytics?preset=7')
            ->assertOk()
            ->assertSee('Mixed currencies detected')
            ->assertSee('BDT, USD')
            ->assertSee('Hidden');

        // Filtering to only BD Shop restores single-currency calculations
        $bdtOnly = AnalyticsService::for($tid)->analytics(['preset' => '7', 'website_id' => $siteBdt->id]);
        $this->assertFalse($bdtOnly['mixed_currency']);
        $this->assertSame('BDT', $bdtOnly['currency']);
        $this->assertSame(1000.0, $bdtOnly['kpis']['gross_sales']);
    }

    public function test_tenant_isolation_and_cross_tenant_website_filter_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $a = $this->makeTenantWithUsers('Tenant Alpha');
        $b = $this->makeTenantWithUsers('Tenant Beta');

        $siteA = $this->createSite($a['tenant']->id, 'Alpha Shop', 'BDT');
        $siteB = $this->createSite($b['tenant']->id, 'Beta Secret Shop', 'BDT');

        $this->insertOrder($a['tenant']->id, $siteA->id, 'A-1', 'delivered', '2026-11-10 10:00:00', 750, ['courier' => 'AlphaCourier']);
        $this->insertOrder($b['tenant']->id, $siteB->id, 'B-1', 'delivered', '2026-11-10 10:00:00', 99999, ['courier' => 'BetaSecretCourier']);

        $this->actingAs($a['owner'])
            ->get('/analytics')
            ->assertOk()
            ->assertSee('750.00')
            ->assertDontSee('99,999.00')
            ->assertDontSee('Beta Secret Shop')
            ->assertDontSee('BetaSecretCourier');

        // Cross-tenant website_id must return 404 via HTTP and throw in service
        $this->actingAs($a['owner'])
            ->get('/analytics?website_id='.$siteB->id)
            ->assertNotFound();

        $this->actingAs($a['owner'])
            ->get('/analytics?website_id=not-an-id')
            ->assertNotFound();

        $this->expectException(InvalidArgumentException::class);
        AnalyticsService::for($a['tenant']->id)->analytics(['website_id' => $siteB->id]);
    }

    public function test_second_load_executes_zero_queries_and_warm_job_works_without_auth(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Cached Analytics Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid, 'Cached Shop', 'BDT');
        $this->insertOrder($tid, $site->id, 'C-1', 'delivered', '2026-11-10 10:00:00', 1200);

        // Unauthenticated scheduled warm job populates both dashboard and default analytics cache cleanly
        Auth::logout();
        (new WarmTenantCache($tid))->handle();

        // Insert a second order directly into DB WITHOUT clearing TenantCache
        $this->insertOrder($tid, $site->id, 'C-2', 'delivered', '2026-11-10 12:00:00', 800);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $cachedService = AnalyticsService::for($tid)->analytics(['preset' => '30']);
        $serviceQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $serviceQueries);
        $this->assertSame(1, $cachedService['kpis']['orders']);

        // Pre-load tenant relation on user as session/auth would hold; second HTTP GET /analytics does 0 SQL queries
        $t['owner']->load('tenant');
        $this->actingAs($t['owner'])->get('/analytics')->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $resp = $this->actingAs($t['owner'])->get('/analytics');
        $httpQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $resp->assertOk()->assertSee('1,200.00')->assertDontSee('2,000.00');
        $this->assertCount(0, $httpQueries);

        // Same for website-filtered second load -> 0 SQL queries
        $this->actingAs($t['owner'])->get('/analytics?preset=7&website_id='.$site->id)->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($t['owner'])->get('/analytics?preset=7&website_id='.$site->id)->assertOk();
        $filteredQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $filteredQueries);

        // Clearing tenant cache causes recompute including C-2
        TenantCache::clear($tid);
        $fresh = AnalyticsService::for($tid)->analytics(['preset' => '30']);
        $this->assertSame(2, $fresh['kpis']['orders']);
        $this->assertSame(2000.0, $fresh['kpis']['gross_sales']);
    }

    public function test_data_quality_score_weighted_arithmetic_and_levels(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('DQ Arithmetic Co');
        $tid = $t['tenant']->id;

        // 1 connected site (heartbeat 2m ago), whose latest order update is 50h old -> stale (1/1 = 100%, penalty 10)
        $connectedStaleSite = $this->createSite($tid, 'Stale Connected Shop', 'BDT', true);
        // 1 disconnected site (heartbeat null) -> excluded from connected sites denominator
        $this->createSite($tid, 'Offline Shop', 'BDT', false);

        $cust = $this->insertCustomer($tid, '01811000001', 'Known Cust');
        $emp = $this->insertEmployee($tid, 'Staff One');

        // Create 10 orders updated 50 hours ago (2026-11-09 10:00:00 -> within 7d range, older than 48h):
        // - 5 with NO items -> 50% (weight 15 => penalty 7.5, bad)
        // - 5 WITH 2 items each = 10 items total, 5 items missing unit_cost -> 50% (weight 15 => penalty 7.5, bad)
        // - 2 with status='other' -> 20% (weight 20 => penalty 4.0, warn because <= 20%)
        // - 4 shipped/delivered/returned (status in 1..4): 1 without courier -> 25% (weight 20 => penalty 5.0, bad)
        // - 10 orders, ALL 10 have customer_key -> 0% (weight 10 => penalty 0.0, ok)
        // - 10 orders, 10 have no assigned employee -> 100% (weight 10 => penalty 10.0, bad)
        // Total penalty = 7.5 + 4.0 + 0.0 + 5.0 + 10.0 + 7.5 + 10.0 = 44.0 => score = 56
        $staleTs = '2026-11-09 10:00:00';

        for ($i = 1; $i <= 10; $i++) {
            $status = match (true) {
                $i <= 2 => 'other',
                $i === 3 => 'shipped',    // missing courier!
                $i <= 6 => 'delivered',  // 3 with courier
                default => 'pending',
            };
            $courier = match (true) {
                $i === 3 => null,
                $i >= 4 && $i <= 6 => 'Pathao',
                default => null,
            };

            $ordId = $this->insertOrder($tid, $connectedStaleSite->id, "DQ-{$i}", $status, $staleTs, 200, [
                'customer_id' => $cust,
                'courier' => $courier,
                'source_updated_at' => $staleTs,
                'updated_at' => $staleTs,
            ]);

            if ($i <= 5) {
                DB::table('order_items')->insert([
                    [
                        'order_id' => $ordId,
                        'product_name' => 'Item A',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'unit_cost' => 60, // cost present
                        'line_total' => 100,
                    ],
                    [
                        'order_id' => $ordId,
                        'product_name' => 'Item B',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'unit_cost' => null, // cost missing (5 of 10 items)
                        'line_total' => 100,
                    ],
                ]);
            }
        }

        // Verify employee table stays unused (100% missing employee); touch $emp only so variable isn't unused
        $this->assertGreaterThan(0, $emp);

        $data = AnalyticsService::for($tid)->analytics(['preset' => '7']);
        $dq = $data['data_quality'];
        $inds = collect($dq['indicators'])->keyBy('key');

        $this->assertSame(50.0, $inds['no_items']['pct']);
        $this->assertSame(7.5, $inds['no_items']['penalty']);
        $this->assertSame('bad', $inds['no_items']['level']);

        $this->assertSame(20.0, $inds['status_other']['pct']);
        $this->assertSame(4.0, $inds['status_other']['penalty']);
        $this->assertSame('warn', $inds['status_other']['level']);

        $this->assertSame(0.0, $inds['no_customer_key']['pct']);
        $this->assertSame(0.0, $inds['no_customer_key']['penalty']);
        $this->assertSame('ok', $inds['no_customer_key']['level']);

        $this->assertSame(25.0, $inds['missing_courier']['pct']);
        $this->assertSame(5.0, $inds['missing_courier']['penalty']);
        $this->assertSame('bad', $inds['missing_courier']['level']);

        $this->assertSame(100.0, $inds['no_employee']['pct']);
        $this->assertSame(10.0, $inds['no_employee']['penalty']);
        $this->assertSame('bad', $inds['no_employee']['level']);

        $this->assertSame(50.0, $inds['missing_item_cost']['pct']);
        $this->assertSame(7.5, $inds['missing_item_cost']['penalty']);
        $this->assertSame('bad', $inds['missing_item_cost']['level']);

        $this->assertSame(100.0, $inds['stale_connected_site']['pct']);
        $this->assertSame(10.0, $inds['stale_connected_site']['penalty']);
        $this->assertSame('bad', $inds['stale_connected_site']['level']);

        $this->assertSame(56, $dq['score']);

        // Also test custom inclusive Asia/Dhaka date filter works cleanly
        $custom = AnalyticsService::for($tid)->analytics([
            'preset' => 'custom',
            'from' => '2026-11-09',
            'to' => '2026-11-09',
        ]);
        $this->assertSame('custom', $custom['filters']['preset']);
        $this->assertSame(10, $custom['kpis']['orders']);
    }
}
