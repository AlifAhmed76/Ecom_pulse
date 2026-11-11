<?php

namespace Tests\Feature;

use App\Jobs\RunRndScan;
use App\Jobs\ScanAllTenantsRnd;
use App\Models\ResearchFinding;
use App\Models\Website;
use App\Services\Rnd\RuleEngine;
use App\Services\Sync\SyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RndEngineAndActionCenterTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createSite(int $tenantId, string $name = 'Test Store', string $currency = 'BDT'): Website
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

    private function insertCustomer(int $tenantId, string $seed, string $name = 'Buyer'): int
    {
        $key = str_contains($seed, '@')
            ? SyncService::customerKeyFor($tenantId, null, $seed)
            : SyncService::customerKeyFor($tenantId, $seed);

        return (int) DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'customer_key' => $key,
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

    private function insertProduct(int $tenantId, int $websiteId, string $sku, string $name, ?int $stock, float $price, ?float $cost = null): int
    {
        return (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'website_id' => $websiteId,
            'external_id' => $sku,
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
     * Insert an order (plus item/assignment if requested so DQ >= 85 tests cleanly).
     *
     * @param  array<string, mixed>  $extra
     */
    private function insertRichOrder(
        int $tenantId,
        int $websiteId,
        string $extId,
        string $status,
        string $orderedAt,
        float $total,
        ?int $customerId = null,
        ?int $employeeId = null,
        ?int $productId = null,
        float $unitCost = 500,
        array $extra = []
    ): int {
        $oid = (int) DB::table('orders')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'website_id' => $websiteId,
            'external_id' => $extId,
            'customer_id' => $customerId,
            'status' => $status,
            'source_status' => $status,
            'ordered_at' => $orderedAt,
            'subtotal' => $total,
            'discount' => 0,
            'shipping' => 0,
            'total' => $total,
            'currency' => 'BDT',
            'courier' => in_array($status, ['shipped', 'delivered', 'returned'], true) ? 'Pathao' : null,
            'source' => 'connector',
            'created_at' => $orderedAt,
            'updated_at' => $orderedAt,
        ], $extra));

        if ($productId !== null) {
            $prodRow = DB::table('products')->where('id', $productId)->first(['name', 'sku']);
            DB::table('order_items')->insert([
                'order_id' => $oid,
                'product_id' => $productId,
                'product_name' => $prodRow?->name ?? 'Standard SKU',
                'sku' => $prodRow?->sku ?? 'STD-1',
                'quantity' => 1,
                'unit_price' => $total,
                'unit_cost' => $unitCost,
                'line_total' => $total,
            ]);
        }

        if ($employeeId !== null) {
            DB::table('order_assignments')->insert([
                'order_id' => $oid,
                'employee_id' => $employeeId,
                'assigned_at' => $orderedAt,
            ]);
        }

        return $oid;
    }

    public function test_r01_sales_drop_triggers_respects_thresholds_and_emits_insufficient_below_20(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));
        $engine = app(RuleEngine::class);

        // Tenant A: < 20 orders in prev 7d -> R01.insufficient info finding (no alert)
        $tSmall = $this->makeTenantWithUsers('R01 Small');
        $siteSmall = $this->createSite($tSmall['tenant']->id);
        for ($i = 1; $i <= 10; $i++) {
            $this->insertRichOrder($tSmall['tenant']->id, $siteSmall->id, "SM-P-{$i}", 'delivered', '2026-10-31 10:00:00', 1000);
        }
        $this->insertRichOrder($tSmall['tenant']->id, $siteSmall->id, 'SM-C-1', 'delivered', '2026-11-10 10:00:00', 200);

        $engine->scanTenant($tSmall['tenant']->id);
        $r01Small = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tSmall['tenant']->id)->where('rule_code', 'R01')->get();
        $this->assertCount(1, $r01Small);
        $this->assertTrue($r01Small->first()->is_insufficient_data);
        $this->assertSame('low', $r01Small->first()->severity);

        // Tenant B: 25 prev orders @ 1000 (25,000), cur 21 orders @ 1000 (21,000 => -16% < 20%) -> NO R01 finding!
        $tBelow = $this->makeTenantWithUsers('R01 Below Threshold');
        $siteBelow = $this->createSite($tBelow['tenant']->id);
        for ($i = 1; $i <= 25; $i++) {
            $this->insertRichOrder($tBelow['tenant']->id, $siteBelow->id, "BT-P-{$i}", 'delivered', '2026-10-31 10:00:00', 1000);
        }
        for ($i = 1; $i <= 21; $i++) {
            $this->insertRichOrder($tBelow['tenant']->id, $siteBelow->id, "BT-C-{$i}", 'delivered', '2026-11-10 10:00:00', 1000);
        }
        $engine->scanTenant($tBelow['tenant']->id);
        $this->assertSame(0, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tBelow['tenant']->id)->where('rule_code', 'R01')->count());

        // Tenant C: 25 prev orders @ 1000 (25,000), cur 15 orders @ 1000 (15,000 => -40% >= 20%) -> triggers R01 critical (10k / 40k = 25% >= 10%)
        $tHit = $this->makeTenantWithUsers('R01 Trigger');
        $siteHit = $this->createSite($tHit['tenant']->id);
        for ($i = 1; $i <= 25; $i++) {
            $this->insertRichOrder($tHit['tenant']->id, $siteHit->id, "HT-P-{$i}", 'delivered', '2026-10-31 10:00:00', 1000);
        }
        for ($i = 1; $i <= 15; $i++) {
            $this->insertRichOrder($tHit['tenant']->id, $siteHit->id, "HT-C-{$i}", 'delivered', '2026-11-10 10:00:00', 1000);
        }
        $engine->scanTenant($tHit['tenant']->id);
        $f = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tHit['tenant']->id)->where('rule_code', 'R01')->firstOrFail();
        $this->assertFalse($f->is_insufficient_data);
        $this->assertSame('10000.00', (string) $f->money_at_stake);
        $this->assertSame('critical', $f->severity);
        $this->assertSame('low', $f->confidence); // < 8 trailing weeks -> descriptive threshold

        // Tenant D: 8 populated trailing weeks + >= 100 combined orders + DQ >= 85 + sharp z drop -> high confidence
        $tHigh2 = $this->makeTenantWithUsers('R01 Z High');
        $siteH2 = $this->createSite($tHigh2['tenant']->id);
        $custH2 = $this->insertCustomer($tHigh2['tenant']->id, 'r01-high2@example.test');
        $empH2 = $this->insertEmployee($tHigh2['tenant']->id, 'Rana');
        $prodH2 = $this->insertProduct($tHigh2['tenant']->id, $siteH2->id, 'R1H2', 'Polos', 500, 1000, 500);
        $histTotals = [2 => 54000, 3 => 56000, 4 => 54000, 5 => 56000, 6 => 54500, 7 => 55500, 8 => 55000];
        foreach ($histTotals as $w => $tot) {
            $day = Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka')->subDays($w * 7 + 1)->format('Y-m-d H:i:s');
            $this->insertRichOrder($tHigh2['tenant']->id, $siteH2->id, "H2W-{$w}", 'delivered', $day, (float) $tot, $custH2, $empH2, $prodH2, (float) ($tot / 2));
        }
        // Week 1 (prev 7d): 55 orders @ 1000 = 55,000
        for ($i = 1; $i <= 55; $i++) {
            $this->insertRichOrder($tHigh2['tenant']->id, $siteH2->id, "H2P-{$i}", 'delivered', '2026-10-31 10:00:00', 1000, $custH2, $empH2, $prodH2, 500);
        }
        // Current 7d: 45 orders @ 800 = 36,000 (-34.55%, n=55+45=100, z=-25.1, p<0.0001, DQ=100)
        for ($i = 1; $i <= 45; $i++) {
            $this->insertRichOrder($tHigh2['tenant']->id, $siteH2->id, "H2C-{$i}", 'delivered', '2026-11-10 10:00:00', 800, $custH2, $empH2, $prodH2, 400);
        }
        $engine->scanTenant($tHigh2['tenant']->id);
        $fHigh = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tHigh2['tenant']->id)->where('rule_code', 'R01')->firstOrFail();
        $this->assertSame('high', $fHigh->confidence);
        $this->assertNotNull($fHigh->evidence['z_score']);
        $this->assertLessThan(0.001, $fHigh->evidence['p_value']);
    }

    public function test_r02_and_r03_spikes_trigger_with_high_confidence_and_guard_small_or_insignificant(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));
        $engine = app(RuleEngine::class);

        // 1) High-confidence + rich DQ >= 85 spike for R02 & R03
        $t = $this->makeTenantWithUsers('R02 R03 Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid);
        $cust = $this->insertCustomer($tid, 'r02-buyer@example.test');
        $emp = $this->insertEmployee($tid, 'Nadia');
        $prod = $this->insertProduct($tid, $site->id, 'P1', 'Panjabi', 100, 1000, 600);

        // Trailing 28d baseline (2026-10-20): 100 orders (95 delivered, 2 returned, 3 cancelled) -> gross30 part = 97,000
        for ($i = 1; $i <= 100; $i++) {
            $st = $i <= 95 ? 'delivered' : ($i <= 97 ? 'returned' : 'cancelled');
            $this->insertRichOrder($tid, $site->id, "B28-{$i}", $st, '2026-10-20 10:00:00', 1000, $cust, $emp, $prod, 600);
        }

        // Current 7d (2026-11-10): 140 orders -> 40 cancelled (28.57% vs 3%), 15 returned, 85 delivered (ret 15/100=15% vs 2.06%; 15k/197k=7.61% -> high)
        for ($i = 1; $i <= 140; $i++) {
            $st = $i <= 40 ? 'cancelled' : ($i <= 55 ? 'returned' : 'delivered');
            $this->insertRichOrder($tid, $site->id, "C7-{$i}", $st, '2026-11-10 10:00:00', 1000, $cust, $emp, $prod, 600);
        }

        $engine->scanTenant($tid);

        $r02 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R02')->firstOrFail();
        $this->assertFalse($r02->is_insufficient_data);
        $this->assertSame('high', $r02->confidence); // n=140 >= 100, p < 0.01, DQ >= 85
        $this->assertSame('critical', $r02->severity); // 40k / 197k = 20.3% >= 10%

        $r03 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R03')->firstOrFail();
        $this->assertFalse($r03->is_insufficient_data);
        $this->assertSame('high', $r03->confidence); // n=100 >= 100, p < 0.01, DQ >= 85
        $this->assertSame('high', $r03->severity); // 15k / 197k = 7.61% (5-10%) -> high

        // 2) DQ < 60 lowers R02 confidence to low despite large n & p < 0.01
        $tLowDq = $this->makeTenantWithUsers('R02 Low DQ');
        $siteLd = $this->createSite($tLowDq['tenant']->id);
        for ($i = 1; $i <= 50; $i++) {
            $st = $i <= 2 ? 'cancelled' : 'delivered';
            $this->insertRichOrder($tLowDq['tenant']->id, $siteLd->id, "LDB-{$i}", $st, '2026-10-20 10:00:00', 100, null, null, null, 0, ['courier' => null]);
        }
        for ($i = 1; $i <= 110; $i++) {
            $st = $i <= 40 ? 'cancelled' : 'delivered';
            $this->insertRichOrder($tLowDq['tenant']->id, $siteLd->id, "LDC-{$i}", $st, '2026-11-10 10:00:00', 100, null, null, null, 0, ['courier' => null]);
        }
        $engine->scanTenant($tLowDq['tenant']->id);
        $r02LowDq = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tLowDq['tenant']->id)->where('rule_code', 'R02')->firstOrFail();
        $this->assertSame('low', $r02LowDq->confidence); // Missing items/customer/employee/courier -> DQ < 60

        // 3) Below sample size (n=25 < 30 for R02 & R03): must NOT trigger spikes; < 20 emits insufficient
        $t25 = $this->makeTenantWithUsers('R02 R03 N25');
        $site25 = $this->createSite($t25['tenant']->id);
        for ($i = 1; $i <= 30; $i++) {
            $this->insertRichOrder($t25['tenant']->id, $site25->id, "N25B-{$i}", $i === 1 ? 'cancelled' : ($i === 2 ? 'returned' : 'delivered'), '2026-10-20 10:00:00', 100);
        }
        for ($i = 1; $i <= 25; $i++) {
            $st = $i <= 5 ? 'cancelled' : ($i <= 10 ? 'returned' : 'delivered');
            $this->insertRichOrder($t25['tenant']->id, $site25->id, "N25C-{$i}", $st, '2026-11-10 10:00:00', 100);
        }
        $engine->scanTenant($t25['tenant']->id);
        $this->assertSame(0, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $t25['tenant']->id)->whereIn('rule_code', ['R02', 'R03'])->count());

        // 4) Below < 20 emits insufficient info notice; >= 30 with small (< 5pp) insignificant difference does NOT trigger
        $tSmall = $this->makeTenantWithUsers('R02 R03 Insufficient');
        $siteSm = $this->createSite($tSmall['tenant']->id);
        for ($i = 1; $i <= 12; $i++) {
            $this->insertRichOrder($tSmall['tenant']->id, $siteSm->id, "SM23-{$i}", $i <= 3 ? 'cancelled' : ($i <= 6 ? 'returned' : 'delivered'), '2026-11-10 10:00:00', 100);
        }
        $engine->scanTenant($tSmall['tenant']->id);
        $this->assertTrue(ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tSmall['tenant']->id)->where('rule_code', 'R02')->firstOrFail()->is_insufficient_data);
        $this->assertTrue(ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tSmall['tenant']->id)->where('rule_code', 'R03')->firstOrFail()->is_insufficient_data);

        $tNoSig = $this->makeTenantWithUsers('R02 R03 Below 5pp');
        $siteNs = $this->createSite($tNoSig['tenant']->id);
        for ($i = 1; $i <= 60; $i++) {
            $st = $i <= 3 ? 'cancelled' : ($i <= 6 ? 'returned' : 'delivered');
            $this->insertRichOrder($tNoSig['tenant']->id, $siteNs->id, "NSB-{$i}", $st, '2026-10-20 10:00:00', 100);
        }
        for ($i = 1; $i <= 40; $i++) {
            $st = $i <= 3 ? 'cancelled' : ($i <= 6 ? 'returned' : 'delivered');
            $this->insertRichOrder($tNoSig['tenant']->id, $siteNs->id, "NSC-{$i}", $st, '2026-11-10 10:00:00', 100);
        }
        $engine->scanTenant($tNoSig['tenant']->id);
        $this->assertSame(0, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tNoSig['tenant']->id)->whereIn('rule_code', ['R02', 'R03'])->count());
    }

    public function test_r04_and_r05_outliers_missing_courier_and_non_blaming_wording(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));
        $engine = app(RuleEngine::class);

        // Missing courier emits R04.missing_courier info finding
        $tNoCou = $this->makeTenantWithUsers('Missing Courier');
        $sNoCou = $this->createSite($tNoCou['tenant']->id);
        for ($i = 1; $i <= 25; $i++) {
            $this->insertRichOrder($tNoCou['tenant']->id, $sNoCou->id, "NC-{$i}", 'delivered', '2026-11-10 10:00:00', 500, null, null, null, 0, ['courier' => null]);
        }
        $engine->scanTenant($tNoCou['tenant']->id);
        $r04Miss = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tNoCou['tenant']->id)->where('rule_code', 'R04')->firstOrFail();
        $this->assertTrue($r04Miss->is_insufficient_data);
        $this->assertStringContainsString('Missing data: courier', $r04Miss->summary);

        // Outlier Courier (Steadfast: 15/30=50% vs Pathao: 2/50=4%) + Outlier Employee (Karim: 18/30=60% vs Rafi: 2/40=5%)
        $t = $this->makeTenantWithUsers('Outlier Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid);
        $empRafi = $this->insertEmployee($tid, 'Rafi');
        $empKarim = $this->insertEmployee($tid, 'Karim');
        $cust = $this->insertCustomer($tid, '01755000001');
        $prod = $this->insertProduct($tid, $site->id, 'SKU-O', 'Shoe', 200, 1000, 500);

        for ($i = 1; $i <= 40; $i++) {
            $st = $i <= 2 ? 'cancelled' : ($i <= 4 ? 'returned' : 'delivered');
            $this->insertRichOrder($tid, $site->id, "PR-{$i}", $st, '2026-11-05 10:00:00', 1000, $cust, $empRafi, $prod, 500, ['courier' => 'Pathao']);
        }
        for ($i = 1; $i <= 30; $i++) {
            $st = $i <= 18 ? 'cancelled' : 'delivered';
            $this->insertRichOrder($tid, $site->id, "KR-{$i}", $st, '2026-11-06 10:00:00', 1000, $cust, $empKarim, $prod, 500, ['courier' => 'Pathao']);
        }
        for ($i = 1; $i <= 30; $i++) {
            $st = $i <= 15 ? 'returned' : 'delivered';
            $this->insertRichOrder($tid, $site->id, "SF-{$i}", $st, '2026-11-07 10:00:00', 1000, $cust, $empRafi, $prod, 500, ['courier' => 'Steadfast']);
        }

        $engine->scanTenant($tid);

        $r04 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R04')->firstOrFail();
        $this->assertFalse($r04->is_insufficient_data);
        $this->assertStringContainsString('Steadfast', $r04->title);

        $r05 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R05')->firstOrFail();
        $this->assertFalse($r05->is_insufficient_data);
        $this->assertStringContainsString('Karim', $r05->title);
        $this->assertStringContainsString('workload and customer mix may differ', strtolower($r05->summary));

        // Balanced / insignificant courier & staff rates + < 20 samples
        $tBal = $this->makeTenantWithUsers('Balanced Couriers & Staff');
        $sBal = $this->createSite($tBal['tenant']->id);
        $e1 = $this->insertEmployee($tBal['tenant']->id, 'Emp1');
        $e2 = $this->insertEmployee($tBal['tenant']->id, 'Emp2');
        for ($i = 1; $i <= 40; $i++) {
            $st1 = $i <= 4 ? 'returned' : ($i <= 6 ? 'cancelled' : 'delivered');
            $this->insertRichOrder($tBal['tenant']->id, $sBal->id, "BAL1-{$i}", $st1, '2026-11-05 10:00:00', 1000, null, $e1, null, 0, ['courier' => 'Pathao']);
            $this->insertRichOrder($tBal['tenant']->id, $sBal->id, "BAL2-{$i}", $st1, '2026-11-05 10:00:00', 1000, null, $e2, null, 0, ['courier' => 'Steadfast']);
        }
        $engine->scanTenant($tBal['tenant']->id);
        $this->assertSame(0, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tBal['tenant']->id)->whereIn('rule_code', ['R04', 'R05'])->count());

        $tTiny = $this->makeTenantWithUsers('Tiny Couriers & Staff');
        $sTiny = $this->createSite($tTiny['tenant']->id);
        $eTiny = $this->insertEmployee($tTiny['tenant']->id, 'TinyEmp');
        for ($i = 1; $i <= 10; $i++) {
            $this->insertRichOrder($tTiny['tenant']->id, $sTiny->id, "TY-{$i}", 'delivered', '2026-11-05 10:00:00', 500, null, $eTiny, null, 0, ['courier' => 'Pathao']);
        }
        $engine->scanTenant($tTiny['tenant']->id);
        $this->assertTrue(ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tTiny['tenant']->id)->where('rule_code', 'R04')->firstOrFail()->is_insufficient_data);
        $this->assertTrue(ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tTiny['tenant']->id)->where('rule_code', 'R05')->firstOrFail()->is_insufficient_data);
    }

    public function test_r06_r07_r08_stock_dead_stock_and_concentration_rules(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));
        $engine = app(RuleEngine::class);

        $t = $this->makeTenantWithUsers('Catalog Rules Co');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid);

        // Top SKU (60% revenue, stock=1, 30 units/30d -> cover=1.0d <= 2d immediate stock-out -> R06 critical + R08 > 40%)
        $hero = $this->insertProduct($tid, $site->id, 'HERO', 'Hero Jacket', 1, 2000, 1200);
        // Dead SKU: stock 40, cost 300 => 12,000 dead value, no sales in 60d -> R07
        $this->insertProduct($tid, $site->id, 'DEAD', 'Dusty Bag', 40, 500, 300);
        // 4 healthy SKUs so Hero Jacket is strictly top 20% (1 of 5 selling products)
        $otherP = [];
        for ($k = 1; $k <= 4; $k++) {
            $otherP[] = $this->insertProduct($tid, $site->id, "OT-{$k}", "Side Item {$k}", 200, 500, 250);
        }

        // 30 orders for Hero (qty 1 each = 60,000 rev; total site orders >= 20)
        for ($i = 1; $i <= 30; $i++) {
            $this->insertRichOrder($tid, $site->id, "H-{$i}", 'delivered', '2026-11-05 10:00:00', 2000, null, null, $hero, 1200);
        }
        // 20 orders across 4 side items (5,000 each -> total 80,000, Hero = 75% > 40%!)
        foreach ($otherP as $idx => $pid) {
            for ($j = 1; $j <= 5; $j++) {
                $this->insertRichOrder($tid, $site->id, "S-{$idx}-{$j}", 'delivered', '2026-11-06 10:00:00', 1000, null, null, $pid, 500);
            }
        }

        $engine->scanTenant($tid);

        $r06 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R06')->firstOrFail();
        $this->assertFalse($r06->is_insufficient_data);
        $this->assertStringContainsString('Hero Jacket', $r06->title);
        $this->assertSame('critical', $r06->severity); // immediate stock-out (<2d cover) on top-20% SKU

        $r07 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R07')->firstOrFail();
        $this->assertFalse($r07->is_insufficient_data);
        $this->assertSame('12000.00', (string) $r07->money_at_stake); // 40 * 300
        $this->assertStringContainsString('Dusty Bag', $r07->summary);

        $r08 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R08')->firstOrFail();
        $this->assertFalse($r08->is_insufficient_data);
        $this->assertStringContainsString('Hero Jacket', $r08->summary);

        // Balanced catalog with plenty of stock & no dead stock -> R06, R07, R08 do NOT trigger!
        $tHealthy = $this->makeTenantWithUsers('Balanced Catalog Co');
        $sH = $this->createSite($tHealthy['tenant']->id);
        for ($k = 1; $k <= 5; $k++) {
            $pid = $this->insertProduct($tHealthy['tenant']->id, $sH->id, "BAL-{$k}", "Even SKU {$k}", 200, 1000, 500);
            for ($j = 1; $j <= 5; $j++) {
                // 25 total orders; each SKU = 20% (<= 40%), top 3 = 60% (<= 70%), stock=200 => cover=1200d >= 7d
                $this->insertRichOrder($tHealthy['tenant']->id, $sH->id, "E-{$k}-{$j}", 'delivered', '2026-11-05 10:00:00', 1000, null, null, $pid, 500);
            }
        }
        $engine->scanTenant($tHealthy['tenant']->id);
        $this->assertSame(0, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tHealthy['tenant']->id)->whereIn('rule_code', ['R06', 'R07', 'R08'])->count());

        // Insufficient (< 20 orders) + missing_value (R07) + missing_items (R08)
        $tSmallCat = $this->makeTenantWithUsers('Small Catalog Co');
        $sSc = $this->createSite($tSmallCat['tenant']->id);
        $lowPid = $this->insertProduct($tSmallCat['tenant']->id, $sSc->id, 'LOW1', 'Low Sample', 1, 1000, 500);
        $this->insertProduct($tSmallCat['tenant']->id, $sSc->id, 'DEAD1', 'Unsold Item', 10, 500, 300);
        for ($i = 1; $i <= 10; $i++) {
            $this->insertRichOrder($tSmallCat['tenant']->id, $sSc->id, "SC-{$i}", 'delivered', '2026-11-05 10:00:00', 1000, null, null, $lowPid, 500);
        }
        $engine->scanTenant($tSmallCat['tenant']->id);
        $this->assertTrue(ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tSmallCat['tenant']->id)->where('rule_code', 'R06')->firstOrFail()->is_insufficient_data);
        $this->assertTrue(ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tSmallCat['tenant']->id)->where('rule_code', 'R07')->firstOrFail()->is_insufficient_data);
        $this->assertTrue(ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tSmallCat['tenant']->id)->where('rule_code', 'R08')->firstOrFail()->is_insufficient_data);
    }

    public function test_r09_r10_r11_r12_margin_discount_repeat_and_dq_blocker_plus_coverage_guard(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));
        $engine = app(RuleEngine::class);

        // Part A: R09 blocked when cost coverage < 60%
        $tLowCov = $this->makeTenantWithUsers('R09 Low Cov');
        $siteLc = $this->createSite($tLowCov['tenant']->id);
        for ($i = 1; $i <= 25; $i++) {
            $oid = $this->insertRichOrder($tLowCov['tenant']->id, $siteLc->id, "LCP-{$i}", 'delivered', '2026-09-25 10:00:00', 1000);
            DB::table('order_items')->insert(['order_id' => $oid, 'product_name' => 'A', 'quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 400, 'line_total' => 1000]);
        }
        for ($i = 1; $i <= 25; $i++) {
            $oid = $this->insertRichOrder($tLowCov['tenant']->id, $siteLc->id, "LCC-{$i}", 'delivered', '2026-11-05 10:00:00', 1000);
            // 10/25 = 40% costed (< 60%)
            DB::table('order_items')->insert(['order_id' => $oid, 'product_name' => 'A', 'quantity' => 1, 'unit_price' => 1000, 'unit_cost' => $i <= 10 ? 800 : null, 'line_total' => 1000]);
        }
        $engine->scanTenant($tLowCov['tenant']->id);
        $r09Blocked = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tLowCov['tenant']->id)->where('rule_code', 'R09')->firstOrFail();
        $this->assertTrue($r09Blocked->is_insufficient_data);
        $this->assertSame('R09.low_coverage', $r09Blocked->template_key);

        // Part B: Full trigger for R09 (60% -> 40% margin = -20pp), R10 (discounted returns higher), R11 (repeat rate drop), R12 (bad indicator)
        $t = $this->makeTenantWithUsers('R09-R12 Full');
        $tid = $t['tenant']->id;
        $site = $this->createSite($tid);
        $prod = $this->insertProduct($tid, $site->id, 'M-1', 'Kurti', 100, 1000, 600);

        for ($b = 1; $b <= 50; $b++) {
            $cid = $this->insertCustomer($tid, "prev-buyer-{$b}@example.test");
            if ($b <= 30) {
                $this->insertRichOrder($tid, $site->id, "R11P-{$b}-1", 'delivered', '2026-09-01 10:00:00', 1000, $cid);
                $this->insertRichOrder($tid, $site->id, "R11P-{$b}-2", 'delivered', '2026-09-02 10:00:00', 1000, $cid);
            } else {
                $this->insertRichOrder($tid, $site->id, "R11P-{$b}-1", 'delivered', '2026-09-01 10:00:00', 1000, $cid);
            }
        }

        // Prior 30d for R09 (2026-10-01): 25 delivered orders with unit_cost=400 (margin 60%)
        for ($i = 1; $i <= 25; $i++) {
            $this->insertRichOrder($tid, $site->id, "R09P-{$i}", 'delivered', '2026-10-01 10:00:00', 1000, null, null, $prod, 400);
        }

        // Current 30d & 60d (2026-11-05):
        // - 50 brand new buyers (0% repeat vs 60% prior -> R11!)
        // - 25 discounted (15 returned / 25 = 60% return) vs 25 undiscounted (0/25 = 0%) -> R10!
        // - unit_cost = 480/600 on 800/1000 -> margin 40% vs 60% (-20pp) -> R09!
        // - No employee -> R12!
        for ($i = 1; $i <= 50; $i++) {
            $cid = $this->insertCustomer($tid, "cur-buyer-{$i}@example.test");
            if ($i <= 25) {
                $st = $i <= 15 ? 'returned' : 'delivered';
                $this->insertRichOrder($tid, $site->id, "CUR-D-{$i}", $st, '2026-11-05 10:00:00', 800, $cid, null, $prod, 480, ['discount' => 200]);
            } else {
                $this->insertRichOrder($tid, $site->id, "CUR-U-{$i}", 'delivered', '2026-11-05 10:00:00', 1000, $cid, null, $prod, 600, ['discount' => 0]);
            }
        }

        $engine->scanTenant($tid);

        $r09 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R09')->firstOrFail();
        $this->assertFalse($r09->is_insufficient_data);
        $this->assertSame('R09.trigger', $r09->template_key);

        $r10 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R10')->firstOrFail();
        $this->assertFalse($r10->is_insufficient_data);
        $this->assertSame('R10.trigger', $r10->template_key);
        $this->assertSame(25, $r10->params['undisc_n']);

        $r11 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R11')->firstOrFail();
        $this->assertFalse($r11->is_insufficient_data);
        $this->assertSame('R11.trigger', $r11->template_key);

        $r12 = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tid)->where('rule_code', 'R12')->firstOrFail();
        $this->assertSame('R12.trigger', $r12->template_key);
        $this->assertStringContainsString('R05', $r12->summary);

        // Part C: Clean below-threshold tenant -> none of R09, R10, R11, R12 trigger
        $tClean = $this->makeTenantWithUsers('R09-R12 Clean');
        $cTid = $tClean['tenant']->id;
        $cSite = $this->createSite($cTid);
        $cEmp = $this->insertEmployee($cTid, 'CleanEmp');
        $cProd = $this->insertProduct($cTid, $cSite->id, 'C-1', 'Clean Item', 500, 1000, 500);
        for ($i = 1; $i <= 55; $i++) {
            $cCust = $this->insertCustomer($cTid, "clean-prev-{$i}@example.test");
            $this->insertRichOrder($cTid, $cSite->id, "CP-1-{$i}", 'delivered', '2026-09-01 10:00:00', 1000, $cCust, $cEmp, $cProd, 500);
            if ($i <= 10) {
                $this->insertRichOrder($cTid, $cSite->id, "CP-2-{$i}", 'delivered', '2026-09-02 10:00:00', 1000, $cCust, $cEmp, $cProd, 500);
            }
        }
        for ($i = 1; $i <= 25; $i++) {
            $cCust = $this->insertCustomer($cTid, "clean-p30-{$i}@example.test");
            $this->insertRichOrder($cTid, $cSite->id, "CP30-1-{$i}", 'delivered', '2026-10-01 10:00:00', 1000, $cCust, $cEmp, $cProd, 500);
            if ($i <= 5) {
                $this->insertRichOrder($cTid, $cSite->id, "CP30-2-{$i}", 'delivered', '2026-10-02 10:00:00', 1000, $cCust, $cEmp, $cProd, 500);
            }
        }
        for ($i = 1; $i <= 55; $i++) {
            $cCust = $this->insertCustomer($cTid, "clean-cur-{$i}@example.test");
            // Same ~18% repeat, margin 49% (-1pp < 3pp), discounted (25) & undiscounted (30) both 0% return, DQ=100%
            $this->insertRichOrder($cTid, $cSite->id, "CC-1-{$i}", 'delivered', '2026-11-05 10:00:00', 1000, $cCust, $cEmp, $cProd, 510, ['discount' => $i <= 25 ? 50 : 0]);
            if ($i <= 10) {
                $this->insertRichOrder($cTid, $cSite->id, "CC-2-{$i}", 'delivered', '2026-11-06 10:00:00', 1000, $cCust, $cEmp, $cProd, 510);
            }
        }
        $engine->scanTenant($cTid);
        $this->assertSame(0, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $cTid)->whereIn('rule_code', ['R09', 'R10', 'R11', 'R12'])->count());
    }

    public function test_dedupe_updates_open_finding_without_duplication_and_action_center_rbac_locale_isolation(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $a = $this->makeTenantWithUsers('Action Alpha');
        $b = $this->makeTenantWithUsers('Action Beta');

        $siteA = $this->createSite($a['tenant']->id, 'Alpha Fashion');
        $siteB = $this->createSite($b['tenant']->id, 'Beta Secret');

        // Trigger R01 on Tenant A
        for ($i = 1; $i <= 25; $i++) {
            $this->insertRichOrder($a['tenant']->id, $siteA->id, "AP-{$i}", 'delivered', '2026-10-31 10:00:00', 1000);
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->insertRichOrder($a['tenant']->id, $siteA->id, "AC-{$i}", 'delivered', '2026-11-10 10:00:00', 1000);
        }

        // Trigger R01 on Tenant B
        for ($i = 1; $i <= 25; $i++) {
            $this->insertRichOrder($b['tenant']->id, $siteB->id, "BP-{$i}", 'delivered', '2026-10-31 10:00:00', 5000);
        }

        // 1) Scan via unauthenticated queued job
        Auth::logout();
        (new RunRndScan($a['tenant']->id))->handle();
        (new RunRndScan($b['tenant']->id))->handle();

        $r01A = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $a['tenant']->id)->where('rule_code', 'R01')->firstOrFail();
        $r01B = ResearchFinding::withoutGlobalScopes()->where('tenant_id', $b['tenant']->id)->where('rule_code', 'R01')->firstOrFail();
        $firstSeen = $r01A->first_seen_at;

        // Dedupe check: running scan again same week updates last_seen_at without creating duplicate
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 18, 30, 0, 'Asia/Dhaka'));
        (new RunRndScan($a['tenant']->id))->handle();
        $this->assertSame(1, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $a['tenant']->id)->where('rule_code', 'R01')->count());
        $r01A->refresh();
        $this->assertSame($firstSeen->format('Y-m-d H:i:s'), $r01A->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-11 18:30:00', $r01A->last_seen_at->format('Y-m-d H:i:s'));

        // 2) Action Center renders sorted & grouped, Analytics evidence link, and EN -> BN toggle
        $this->actingAs($a['analyst'])
            ->get('/action-center')
            ->assertOk()
            ->assertSee('Alpha Fashion')
            ->assertDontSee('Beta Secret')
            ->assertSee('UNVERIFIED HYPOTHESES', false);

        // Detail page shows ALL 10 mandatory sections
        $this->actingAs($a['analyst'])
            ->get("/action-center/findings/{$r01A->id}")
            ->assertOk()
            ->assertSee('1. Finding')
            ->assertSee('2. Evidence')
            ->assertSee('3. Risk / priority')
            ->assertSee('4. Confidence level')
            ->assertSee('5. Possible causes')
            ->assertSee('6. Missing data')
            ->assertSee('7. Recommended action')
            ->assertSee('8. Experiment plan')
            ->assertSee('9. Success metric')
            ->assertSee('10. Status & outcome')
            ->assertSee('Unverified hypothesis:')
            ->assertSee('/analytics?');

        // Toggle to Bangla (+ optional Bangla digits) and verify full Bangla rendering without raw EN hypotheses
        $this->actingAs($a['analyst'])->post('/locale', ['locale' => 'bn', 'bn_digits' => 1])->assertRedirect();
        $this->actingAs($a['analyst'])
            ->get("/action-center/findings/{$r01A->id}")
            ->assertOk()
            ->assertSee('যাচাইহীন অনুমান:')
            ->assertSee('১. পর্যবেক্ষণ')
            ->assertSee('১০. অবস্থা ও ফলাফল')
            ->assertSee('১৫,০০০.০০'); // Bangla formatted diff_amount / money_at_stake

        // Switch back to EN and test analyst/owner RBAC & empty state
        $this->actingAs($a['analyst'])->post('/locale', ['locale' => 'en'])->assertRedirect();

        // 3) Analyst CANNOT scan, review, or dismiss!
        $this->actingAs($a['analyst'])->post('/action-center/scan')->assertForbidden();
        $this->actingAs($a['analyst'])->post("/action-center/findings/{$r01A->id}/review")->assertForbidden();
        $this->actingAs($a['analyst'])->post("/action-center/findings/{$r01A->id}/dismiss", ['dismiss_reason' => 'No access'])->assertForbidden();

        // 4) Cross-tenant finding view / dismiss / website filter -> 404
        $this->actingAs($a['owner'])->get("/action-center/findings/{$r01B->id}")->assertNotFound();
        $this->actingAs($a['owner'])->get('/action-center?website_id='.$siteB->id)->assertNotFound();
        $this->actingAs($a['owner'])->post("/action-center/findings/{$r01B->id}/dismiss", ['dismiss_reason' => 'Cross tenant'])->assertNotFound();

        // 5) Owner reviews & dismisses R01A; closed finding stays dismissed across subsequent weekly re-scans
        $this->actingAs($a['owner'])
            ->post("/action-center/findings/{$r01A->id}/review", ['outcome_note' => 'Investigating stock'])
            ->assertRedirect();
        $this->assertDatabaseHas('research_findings', ['id' => $r01A->id, 'status' => 'reviewing']);

        $this->actingAs($a['owner'])
            ->post("/action-center/findings/{$r01A->id}/dismiss", ['dismiss_reason' => 'Planned Eid closure'])
            ->assertRedirect();
        $this->assertDatabaseHas('research_findings', [
            'id' => $r01A->id,
            'status' => 'dismissed',
            'dismiss_reason' => 'Planned Eid closure',
            'reviewed_by' => $a['owner']->id,
        ]);

        // Re-running scan same week does NOT re-open or duplicate dismissed finding
        (new RunRndScan($a['tenant']->id))->handle();
        $this->assertDatabaseHas('research_findings', ['id' => $r01A->id, 'status' => 'dismissed']);
        $this->assertSame(1, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $a['tenant']->id)->where('rule_code', 'R01')->count());

        // When only remaining findings are R12/dismissed, filtering rule=R01 in open queue shows helpful empty state
        $this->actingAs($a['owner'])
            ->get('/action-center?rule=R01')
            ->assertOk()
            ->assertSee('No open R&D findings match the current filter.')
            ->assertSee('To evaluate all 12 rules');

        // 6) ScanAllTenantsRnd schedules one RunRndScan per tenant
        Queue::fake();
        (new ScanAllTenantsRnd())->handle();
        Queue::assertPushedOn('default', RunRndScan::class, fn (RunRndScan $j) => $j->tenantId === $a['tenant']->id);
        Queue::assertPushedOn('default', RunRndScan::class, fn (RunRndScan $j) => $j->tenantId === $b['tenant']->id);
    }
}
