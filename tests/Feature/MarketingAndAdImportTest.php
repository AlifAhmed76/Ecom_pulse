<?php

namespace Tests\Feature;

use App\Models\AdPerformanceDaily;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\ResearchFinding;
use App\Models\Website;
use App\Services\Marketing\AdMapper;
use App\Services\Marketing\MarketingAnalyticsService;
use App\Services\Rnd\RuleEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MarketingAndAdImportTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createWebsite(int $tenantId, string $name = 'Fashion BD', string $currency = 'BDT'): Website
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

    private function seedOrder(
        int $tenantId,
        Website $site,
        string $extId,
        string $status,
        float $total,
        ?string $utmCampaign,
        Carbon $orderedAt,
        ?string $currency = null
    ): void {
        $order = new Order();
        $order->forceFill([
            'tenant_id' => $tenantId,
            'website_id' => $site->id,
            'external_id' => $extId,
            'status' => $status,
            'source_status' => $status,
            'currency' => $currency ?? $site->currency,
            'subtotal' => $total,
            'discount' => 0.00,
            'shipping' => 0.00,
            'total' => $total,
            'utm_campaign' => $utmCampaign,
            'source' => 'import',
            'ordered_at' => $orderedAt->format('Y-m-d H:i:s'),
            'status_changed_at' => $orderedAt->format('Y-m-d H:i:s'),
        ]);
        $order->save();
    }

    private function seedAdRow(
        int $tenantId,
        ?int $websiteId,
        string $platform,
        string $campaign,
        string $date,
        float $spend,
        int $impressions,
        int $clicks,
        float $conversions,
        ?float $revenue
    ): void {
        $row = new AdPerformanceDaily();
        $row->forceFill([
            'tenant_id' => $tenantId,
            'website_id' => $websiteId,
            'platform' => $platform,
            'campaign' => $campaign,
            'date' => $date,
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'platform_conversions' => $conversions,
            'platform_revenue' => $revenue,
        ]);
        $row->save();
    }

    public function test_golden_dataset_roas_cpa_coverage_and_no_division_by_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Golden Marketing Co');
        $site = $this->createWebsite($t['tenant']->id);

        // Spend = 1500 + 500 = 2000; Platform Conv = 15 + 5 = 20 -> Platform CPA = 100.00;
        // Platform Rev = 9000 + 3000 = 12000 -> Platform ROAS = 6.00x
        $this->seedAdRow($t['tenant']->id, $site->id, 'meta', 'eid_panjabi', '2026-11-08', 1500.00, 10000, 500, 15, 9000.00);
        $this->seedAdRow($t['tenant']->id, $site->id, 'meta', 'eid_panjabi', '2026-11-09', 500.00, 5000, 250, 5, 3000.00);
        // Zero-spend / zero-conv / zero-clicks campaign to prove NO DivisionByZero
        $this->seedAdRow($t['tenant']->id, $site->id, 'google', 'zero_campaign', '2026-11-09', 0.00, 0, 0, 0.0, null);

        // Orders in 30d window (4 orders total -> 3 with utm_campaign = 75.0% UTM coverage):
        // - 2 delivered orders matching 'eid_panjabi' (total 4000 Tk -> Real ROAS = 4000 / 2000 = 2.00x, Real CPA = 2000 / 2 = 1000.00)
        // - 1 cancelled order matching 'eid_panjabi' (MUST NOT count in delivered revenue / Real ROAS!)
        // - 1 order without utm_campaign (untagged direct order)
        $this->seedOrder($t['tenant']->id, $site, 'M-1', 'delivered', 2500.00, 'eid_panjabi', Carbon::parse('2026-11-08 15:00:00', 'Asia/Dhaka'));
        $this->seedOrder($t['tenant']->id, $site, 'M-2', 'delivered', 1500.00, 'EID_PANJABI', Carbon::parse('2026-11-09 16:00:00', 'Asia/Dhaka'));
        $this->seedOrder($t['tenant']->id, $site, 'M-3', 'cancelled', 3000.00, 'eid_panjabi', Carbon::parse('2026-11-09 18:00:00', 'Asia/Dhaka'));
        $this->seedOrder($t['tenant']->id, $site, 'M-4', 'delivered', 1200.00, null, Carbon::parse('2026-11-10 11:00:00', 'Asia/Dhaka'));

        $report = MarketingAnalyticsService::for($t['tenant']->id)->report(['preset' => '30']);

        // Platform-reported vs real-order strictly separated
        $this->assertSame(2000.0, $report['platform_reported']['spend']);
        $this->assertSame(15000, $report['platform_reported']['impressions']);
        $this->assertSame(750, $report['platform_reported']['clicks']);
        $this->assertSame(5.0, $report['platform_reported']['ctr_pct']);
        $this->assertSame(20.0, $report['platform_reported']['conversions']);
        $this->assertSame(100.0, $report['platform_reported']['cpa']);
        $this->assertSame(12000.0, $report['platform_reported']['revenue']);
        $this->assertSame(6.0, $report['platform_reported']['roas']);

        $this->assertSame(3, $report['real_orders']['matched_orders']);
        $this->assertSame(2, $report['real_orders']['delivered_orders']);
        $this->assertSame(4000.0, $report['real_orders']['delivered_revenue']);
        $this->assertSame(1000.0, $report['real_orders']['cpa']);
        $this->assertSame(2.0, $report['real_orders']['roas']);

        // Coverage math: 3 of 4 orders have UTM = 75.0%; 3 of 3 UTM orders matched = 100.0%
        $this->assertSame(4, $report['attribution']['orders_total']);
        $this->assertSame(3, $report['attribution']['orders_with_utm']);
        $this->assertSame(75.0, $report['attribution']['utm_coverage_pct']);
        $this->assertSame(100.0, $report['attribution']['campaign_match_of_utm_pct']);

        // Zero campaign row has safe nulls (no division by zero)
        $zeroRow = collect($report['campaigns'])->firstWhere('campaign', 'zero_campaign');
        $this->assertNotNull($zeroRow);
        $this->assertNull($zeroRow['ctr_pct']);
        $this->assertNull($zeroRow['platform_cpa']);
        $this->assertNull($zeroRow['platform_roas']);
        $this->assertNull($zeroRow['real_cpa']);
        $this->assertNull($zeroRow['real_roas']);

        // Web page displays separated panels + explicit non-profit caveat
        $res = $this->actingAs($t['analyst'])->get('/marketing');
        $res->assertOk()
            ->assertSee('Platform-Reported Results')
            ->assertSee('Real-Order Results')
            ->assertSee('not profit')
            ->assertSee('6.00x')
            ->assertSee('2.00x')
            ->assertSee('75.0%');
    }

    public function test_manual_ad_import_idempotent_reimport_and_cleanup_and_rbac(): void
    {
        Storage::fake('local');

        $tA = $this->makeTenantWithUsers('Import Ads A');
        $tB = $this->makeTenantWithUsers('Import Ads B');
        $siteA = $this->createWebsite($tA['tenant']->id);
        $siteB = $this->createWebsite($tB['tenant']->id);

        // Verify AdMapper NEVER auto-guesses column aliases
        $empty = AdMapper::emptyMapping();
        foreach ($empty as $val) {
            $this->assertNull($val);
        }

        // Analyst cannot upload ad reports -> 403
        $csvPath = base_path('tests/Fixtures/synthetic_ads.csv');
        $file1 = new UploadedFile($csvPath, 'synthetic_ads.csv', 'text/csv', null, true);
        $this->actingAs($tA['analyst'])
            ->post('/marketing/imports', [
                'platform' => 'meta',
                'website_id' => $siteA->id,
                'file' => $file1,
            ])
            ->assertForbidden();

        // Owner uploads synthetic_ads.csv -> lands on mapping page
        $uploadRes = $this->actingAs($tA['owner'])
            ->post('/marketing/imports', [
                'platform' => 'meta',
                'website_id' => $siteA->id,
                'file' => $file1,
            ]);
        $batch1 = ImportBatch::withoutGlobalScopes()->where('kind', 'ads')->latest('id')->firstOrFail();
        $uploadRes->assertRedirect("/marketing/imports/{$batch1->id}/map");

        // Reject missing required mapping fields
        $this->actingAs($tA['owner'])
            ->post("/marketing/imports/{$batch1->id}/confirm", [
                'platform' => 'meta',
                'map' => ['date' => 'Day', 'campaign' => 'CampName'], // spend omitted!
            ])
            ->assertSessionHasErrors('map');

        // Confirm valid manual column mapping -> synchronous processing creates 3 rows and deletes temp file
        $validMap = [
            'date' => 'Day',
            'campaign' => 'CampName',
            'spend' => 'SpendAmt',
            'impressions' => 'Impr',
            'clicks' => 'LinkClicks',
            'platform_conversions' => 'Purchases',
            'platform_revenue' => 'ConvVal',
        ];

        $this->actingAs($tA['owner'])
            ->post("/marketing/imports/{$batch1->id}/confirm", [
                'platform' => 'meta',
                'map' => $validMap,
            ])
            ->assertRedirect("/marketing/imports/{$batch1->id}");

        $batch1->refresh();
        $this->assertSame('done', $batch1->status);
        $this->assertSame(3, $batch1->stats['created']);
        $this->assertSame(0, $batch1->stats['updated']);
        $this->assertNull($batch1->stored_path);
        $this->assertSame([], Storage::disk('local')->allFiles("imports/t{$tA['tenant']->id}"));

        // Idempotent re-import of the EXACT same file updates rows without duplicating
        $file2 = new UploadedFile($csvPath, 'synthetic_ads.csv', 'text/csv', null, true);
        $this->actingAs($tA['owner'])->post('/marketing/imports', [
            'platform' => 'meta',
            'website_id' => $siteA->id,
            'file' => $file2,
        ]);
        $batch2 = ImportBatch::withoutGlobalScopes()->where('kind', 'ads')->latest('id')->firstOrFail();
        $this->actingAs($tA['owner'])
            ->post("/marketing/imports/{$batch2->id}/confirm", [
                'platform' => 'meta',
                'map' => $validMap,
            ])
            ->assertRedirect("/marketing/imports/{$batch2->id}");

        $batch2->refresh();
        $this->assertSame(0, $batch2->stats['created']);
        $this->assertSame(3, $batch2->stats['updated']);
        $this->assertSame(
            3,
            AdPerformanceDaily::withoutGlobalScopes()->where('tenant_id', $tA['tenant']->id)->count()
        );

        // Tenant B isolation: 404 on Tenant A batch/website filter, zero visibility into Tenant A campaigns
        $this->actingAs($tB['owner'])->get("/marketing/imports/{$batch1->id}")->assertNotFound();
        $this->actingAs($tB['owner'])->get("/marketing?website_id={$siteA->id}")->assertNotFound();
        $this->actingAs($tB['owner'])->get('/marketing')->assertOk()->assertDontSee('eid_panjabi');
    }

    public function test_r13_roas_gap_rule_respects_utm_coverage_threshold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Asia/Dhaka'));

        // Tenant A: Low UTM coverage (1 of 5 orders = 20% < 50% threshold) -> R13.trigger suppressed, R13.low_coverage emitted!
        $tLow = $this->makeTenantWithUsers('Low UTM Store');
        $siteLow = $this->createWebsite($tLow['tenant']->id);
        $this->seedAdRow($tLow['tenant']->id, $siteLow->id, 'meta', 'winter_sale', '2026-11-08', 5000.00, 20000, 1000, 40, 40000.00); // Plat ROAS 8.0x
        $this->seedOrder($tLow['tenant']->id, $siteLow, 'L-1', 'delivered', 1000.00, 'winter_sale', Carbon::parse('2026-11-08 14:00:00', 'Asia/Dhaka'));
        for ($i = 2; $i <= 5; $i++) {
            $this->seedOrder($tLow['tenant']->id, $siteLow, "L-{$i}", 'delivered', 1000.00, null, Carbon::parse('2026-11-09 14:00:00', 'Asia/Dhaka'));
        }

        app(RuleEngine::class)->scanTenant($tLow['tenant']->id, Carbon::now('Asia/Dhaka'));

        $this->assertNull(
            ResearchFinding::withoutGlobalScopes()
                ->where('tenant_id', $tLow['tenant']->id)
                ->where('rule_code', 'R13')
                ->where('is_insufficient_data', false)
                ->first()
        );
        $lowCovFinding = ResearchFinding::withoutGlobalScopes()
            ->where('tenant_id', $tLow['tenant']->id)
            ->where('rule_code', 'R13')
            ->where('is_insufficient_data', true)
            ->first();
        $this->assertNotNull($lowCovFinding);
        $this->assertSame('R13.low_coverage', $lowCovFinding->template_key);

        // Tenant B: High UTM coverage (4 of 5 orders = 80% >= 50%), big ROAS gap (8.0x platform vs 1.0x real) -> R13.trigger raised!
        $tHigh = $this->makeTenantWithUsers('High UTM Store');
        $siteHigh = $this->createWebsite($tHigh['tenant']->id);
        $this->seedAdRow($tHigh['tenant']->id, $siteHigh->id, 'meta', 'winter_sale', '2026-11-08', 5000.00, 20000, 1000, 40, 40000.00); // Plat ROAS 8.00x
        $this->seedOrder($tHigh['tenant']->id, $siteHigh, 'H-1', 'delivered', 5000.00, 'winter_sale', Carbon::parse('2026-11-08 14:00:00', 'Asia/Dhaka')); // Real ROAS 1.00x
        for ($i = 2; $i <= 4; $i++) {
            $this->seedOrder($tHigh['tenant']->id, $siteHigh, "H-{$i}", 'cancelled', 2000.00, 'winter_sale', Carbon::parse('2026-11-09 14:00:00', 'Asia/Dhaka'));
        }
        $this->seedOrder($tHigh['tenant']->id, $siteHigh, 'H-5', 'delivered', 1000.00, null, Carbon::parse('2026-11-10 14:00:00', 'Asia/Dhaka'));

        app(RuleEngine::class)->scanTenant($tHigh['tenant']->id, Carbon::now('Asia/Dhaka'));

        $r13Trigger = ResearchFinding::withoutGlobalScopes()
            ->where('tenant_id', $tHigh['tenant']->id)
            ->where('rule_code', 'R13')
            ->where('is_insufficient_data', false)
            ->first();
        $this->assertNotNull($r13Trigger);
        $this->assertSame('R13.trigger', $r13Trigger->template_key);
        $this->assertStringContainsString('80.0%', $r13Trigger->title);
        $this->assertSame(35000.0, (float) $r13Trigger->money_at_stake); // 40000 plat rev - 5000 real deliv rev
    }
}
