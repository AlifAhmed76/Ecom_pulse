<?php

namespace Tests\Feature;

use App\Jobs\BuildTenantWeeklyReport;
use App\Jobs\BuildWeeklyReports;
use App\Jobs\DeleteTenantData;
use App\Jobs\ExportTenantData;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotLead;
use App\Models\Customer;
use App\Models\Experiment;
use App\Models\ExperimentResult;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResearchFinding;
use App\Models\TenantExport;
use App\Models\Website;
use App\Models\WeeklyReport;
use App\Services\Demo\DemoSeeder;
use App\Services\Rnd\RuleEngine;
use App\Services\Sync\SyncService;
use App\Services\WeeklyReportService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WeeklyReportDemoExportDeleteTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_csv_sanitization_neutralizes_spreadsheet_formula_injection(): void
    {
        $this->assertSame("'=CMD|'/C calc'!A0", Csv::sanitizeCell("=CMD|'/C calc'!A0"));
        $this->assertSame("'+SUM(1,2)", Csv::sanitizeCell('+SUM(1,2)'));
        $this->assertSame("'-2+3", Csv::sanitizeCell('-2+3'));
        $this->assertSame("'@SUM(A1:A2)", Csv::sanitizeCell('@SUM(A1:A2)'));
        $this->assertSame("'\t=1+1", Csv::sanitizeCell("\t=1+1"));
        $this->assertSame("'\r=1+1", Csv::sanitizeCell("\r=1+1"));
        $this->assertSame("' =HYPERLINK(\"http://evil\")", Csv::sanitizeCell(' =HYPERLINK("http://evil")'));
        $this->assertSame("' \t=HYPERLINK(\"http://evil\")", Csv::sanitizeCell(" \t=HYPERLINK(\"http://evil\")"));
        $this->assertSame('Safe Value', Csv::sanitizeCell('Safe Value'));
    }

    public function test_demo_seed_is_deterministic_idempotent_and_triggers_rnd_findings(): void
    {
        $t = $this->makeTenantWithUsers('Demo Brand');
        $tenantId = (int) $t['tenant']->id;
        $ownerId = (int) $t['owner']->id;
        $frozenNow = Carbon::parse('2026-11-11 10:00:00', 'Asia/Dhaka');

        $res1 = DemoSeeder::seed($tenantId, $ownerId, $frozenNow);
        $this->assertFalse($res1['already_seeded']);
        $this->assertSame(1, DB::table('websites')->where('tenant_id', $tenantId)->where('source', 'demo')->count());
        $this->assertSame(40, $res1['products']);
        $this->assertSame(2500, $res1['orders']);
        $this->assertSame(4, $res1['employees']);
        $this->assertGreaterThan(1000, $res1['shipments']);
        $this->assertSame(135, $res1['ad_rows']);
        $this->assertGreaterThan(2500, $res1['history_rows']);
        $this->assertSame(
            40,
            DB::table('products')->where('tenant_id', $tenantId)->where('source', 'demo')->whereNotNull('cost')->count()
        );
        $this->assertSame(
            3,
            DB::table('courier_shipments')->where('tenant_id', $tenantId)->where('source', 'demo')->distinct()->count('courier')
        );

        // Verify RedX has worse return rate than Pathao as designed in DEMO_PLAN
        $courierStats = DB::table('courier_shipments')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->selectRaw("courier, SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) * 1.0 / COUNT(*) AS ret_rate")
            ->groupBy('courier')
            ->pluck('ret_rate', 'courier')
            ->all();
        $this->assertGreaterThan((float) $courierStats['Pathao'], (float) $courierStats['RedX']);

        // Verify Rafiq Hasan has higher cancel rate than Nusrat Jahan
        $empStats = DB::table('orders as o')
            ->join('order_assignments as oa', 'oa.order_id', '=', 'o.id')
            ->join('employees as e', 'e.id', '=', 'oa.employee_id')
            ->where('o.tenant_id', $tenantId)
            ->where('o.source', 'demo')
            ->selectRaw("e.name, SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) * 1.0 / COUNT(*) AS cancel_rate")
            ->groupBy('e.name')
            ->pluck('cancel_rate', 'e.name')
            ->all();
        $this->assertGreaterThan((float) $empStats['Nusrat Jahan'], (float) $empStats['Rafiq Hasan']);

        // Snapshot first 15 demo orders to prove exact determinism across re-seeding
        $snap1 = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->orderBy('external_id')
            ->limit(15)
            ->get(['external_id', 'status', 'total', 'courier', 'city'])
            ->map(fn ($r) => (array) $r)
            ->all();

        // Calling seed again is idempotent (does not duplicate)
        $resAgain = DemoSeeder::seed($tenantId, $ownerId, $frozenNow);
        $this->assertTrue($resAgain['already_seeded']);
        $this->assertSame(2500, DB::table('orders')->where('tenant_id', $tenantId)->where('source', 'demo')->count());

        // RuleEngine scan on demo data produces findings cleanly
        $scan = app(RuleEngine::class)->scanTenant($tenantId, $frozenNow);
        $this->assertGreaterThan(0, $scan['findings_total']);
        $this->assertGreaterThan(0, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());

        // Remove and re-seed with same frozen time -> byte/value-identical order snapshot
        DemoSeeder::remove($tenantId, $ownerId);
        $res2 = DemoSeeder::seed($tenantId, $ownerId, $frozenNow);
        $this->assertSame($res1['orders'], $res2['orders']);
        $this->assertSame($res1['shipments'], $res2['shipments']);
        $this->assertSame($res1['history_rows'], $res2['history_rows']);

        $snap2 = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('source', 'demo')
            ->orderBy('external_id')
            ->limit(15)
            ->get(['external_id', 'status', 'total', 'courier', 'city'])
            ->map(fn ($r) => (array) $r)
            ->all();

        $this->assertSame($snap1, $snap2);
    }

    public function test_demo_load_and_removal_are_owner_only_and_preserve_non_demo_data(): void
    {
        $a = $this->makeTenantWithUsers('Alpha Retail');
        $b = $this->makeTenantWithUsers('Beta Retail');
        $tenantA = (int) $a['tenant']->id;
        $tenantB = (int) $b['tenant']->id;

        // Seed non-demo (real) records in Tenant A
        $realSite = new Website([
            'name' => 'Real Storefront',
            'platform' => 'laravel',
            'domain' => 'real-alpha.example.com',
            'currency' => 'BDT',
        ]);
        $realSite->forceFill([
            'tenant_id' => $tenantA,
            'source' => 'manual',
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();

        $realProduct = Product::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantA,
            'website_id' => $realSite->id,
            'external_id' => 'REAL-SKU-1',
            'sku' => 'R-100',
            'name' => 'Real Silk Panjabi',
            'price' => 2500,
            'cost' => 1400,
            'stock' => 25,
            'source' => 'connector',
        ]);

        $realOrder = new Order();
        $realOrder->forceFill([
            'tenant_id' => $tenantA,
            'website_id' => $realSite->id,
            'external_id' => 'REAL-ORD-1',
            'status' => 'delivered',
            'ordered_at' => now()->subDays(2),
            'subtotal' => 2500,
            'discount' => 0,
            'shipping' => 70,
            'total' => 2570,
            'currency' => 'BDT',
            'courier' => 'Pathao',
            'source' => 'connector',
        ])->save();

        DB::table('courier_shipments')->insert([
            'tenant_id' => $tenantA,
            'website_id' => $realSite->id,
            'courier' => 'Pathao',
            'consignment_id' => 'REAL-CN-1',
            'order_ref' => 'REAL-ORD-1',
            'order_id' => $realOrder->id,
            'status' => 'delivered',
            'cod_amount' => 2570,
            'collected_amount' => 2570,
            'matched' => true,
            'source' => 'import',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ad_performance_daily')->insert([
            'tenant_id' => $tenantA,
            'website_id' => $realSite->id,
            'platform' => 'meta',
            'campaign' => 'real_campaign',
            'date' => now()->subDay()->format('Y-m-d'),
            'spend' => 1200,
            'impressions' => 10000,
            'clicks' => 200,
            'platform_conversions' => 8,
            'platform_revenue' => 16000,
            'source' => 'import',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Also seed demo dataset in Tenant B to prove Tenant A removal never touches Tenant B
        DemoSeeder::seed($tenantB, (int) $b['owner']->id);

        // Admin and analyst are forbidden (403)
        $this->actingAs($a['admin'])->post('/workspace/demo/load')->assertForbidden();
        $this->actingAs($a['analyst'])->post('/workspace/demo/load')->assertForbidden();
        $this->actingAs($a['admin'])->post('/workspace/demo/remove')->assertForbidden();
        $this->actingAs($a['analyst'])->post('/workspace/demo/remove')->assertForbidden();

        // Owner loads demo dataset
        $this->actingAs($a['owner'])
            ->post('/workspace/demo/load')
            ->assertRedirect('/workspace');

        $this->assertSame(1, DB::table('websites')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertSame(2500, DB::table('orders')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertDatabaseHas('orders', ['id' => $realOrder->id, 'external_id' => 'REAL-ORD-1']);

        // Scan R&D so findings exist on demo website
        app(RuleEngine::class)->scanTenant($tenantA);
        $this->assertGreaterThan(0, ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tenantA)->where('website_id', '<>', $realSite->id)->count());

        // Owner removes demo dataset
        $this->actingAs($a['owner'])
            ->post('/workspace/demo/remove')
            ->assertRedirect('/workspace');

        // All demo rows gone from Tenant A
        $this->assertSame(0, DB::table('websites')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertSame(0, DB::table('products')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertSame(0, DB::table('customers')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertSame(0, DB::table('employees')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertSame(0, DB::table('orders')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertSame(0, DB::table('courier_shipments')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertSame(0, DB::table('ad_performance_daily')->where('tenant_id', $tenantA)->where('source', 'demo')->count());
        $this->assertSame(
            0,
            ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tenantA)->where('website_id', '<>', $realSite->id)->count()
        );

        // Real non-demo Tenant A data AND Tenant B's demo data remain 100% intact
        $this->assertDatabaseHas('websites', ['id' => $realSite->id, 'domain' => 'real-alpha.example.com']);
        $this->assertDatabaseHas('products', ['id' => $realProduct->id, 'sku' => 'R-100']);
        $this->assertDatabaseHas('orders', ['id' => $realOrder->id, 'external_id' => 'REAL-ORD-1']);
        $this->assertSame(1, DB::table('courier_shipments')->where('tenant_id', $tenantA)->where('consignment_id', 'REAL-CN-1')->count());
        $this->assertSame(1, DB::table('ad_performance_daily')->where('tenant_id', $tenantA)->where('campaign', 'real_campaign')->count());
        $this->assertSame(2500, DB::table('orders')->where('tenant_id', $tenantB)->where('source', 'demo')->count());

        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenantA, 'action' => 'demo.seed']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenantA, 'action' => 'demo.remove']);
    }

    public function test_tenant_export_is_queued_tenant_only_secret_free_and_csv_injection_safe(): void
    {
        Storage::fake('local');

        $a = $this->makeTenantWithUsers('Export Corp A');
        $b = $this->makeTenantWithUsers('Export Corp B');
        $tenantA = (int) $a['tenant']->id;
        $tenantB = (int) $b['tenant']->id;

        // Tenant A records including dangerous CSV formulas and secrets that must NOT leak
        $siteA = new Website([
            'name' => 'Alpha Export Store',
            'platform' => 'laravel',
            'domain' => 'alpha-exp.example.com',
            'currency' => 'BDT',
        ]);
        $siteA->forceFill([
            'tenant_id' => $tenantA,
            'status' => 'active',
            'verification_token' => 'topsecret-verification-token-alpha',
        ])->save();

        DB::table('api_credentials')->insert([
            'website_id' => $siteA->id,
            'key_id' => 'key_alpha_pub',
            'secret_encrypted' => Crypt::encryptString('super-secret-hmac-key-never-export'),
            'scopes' => json_encode(['orders:write']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $custA = Customer::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantA,
            'customer_key' => SyncService::customerKeyFor($tenantA, '01700001111'),
            'name' => '=CMD|"/C calc"!A0',
        ]);

        $orderA = new Order();
        $orderA->forceFill([
            'tenant_id' => $tenantA,
            'website_id' => $siteA->id,
            'external_id' => 'ORD-ALPHA-1',
            'customer_id' => $custA->id,
            'status' => 'cancelled',
            'ordered_at' => now(),
            'subtotal' => 900,
            'discount' => 0,
            'shipping' => 70,
            'total' => 970,
            'currency' => 'BDT',
            'city' => '@SUM(A1:A2)',
            'cancel_reason' => '+SUM(1,2)',
            'courier' => '-2+3',
        ])->save();

        $botA = new Chatbot();
        $botA->forceFill([
            'tenant_id' => $tenantA,
            'website_id' => $siteA->id,
            'name' => 'Alpha Bot',
            'enabled' => true,
            'widget_domain' => 'alpha-exp.example.com',
            'public_key' => 'cb_'.Str::random(16),
            'handoff_text' => 'Contact human team',
        ])->save();

        $leadA = new ChatbotLead();
        $leadA->forceFill([
            'tenant_id' => $tenantA,
            'chatbot_id' => $botA->id,
            'name' => " \t=HYPERLINK(\"http://evil\")",
            'contact_hash' => SyncService::customerKeyFor($tenantA, '01811112222'),
            'contact_enc' => Crypt::encryptString('01811112222-plaintext-lead-phone'),
            'consent_at' => now(),
            'created_at' => now(),
        ])->save();

        // Tenant B data that must NEVER appear inside Tenant A's export ZIP
        $siteB = new Website([
            'name' => 'Secret Beta Store',
            'platform' => 'laravel',
            'domain' => 'beta-exclusive.example.com',
            'currency' => 'BDT',
        ]);
        $siteB->forceFill([
            'tenant_id' => $tenantB,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();

        $orderB = new Order();
        $orderB->forceFill([
            'tenant_id' => $tenantB,
            'website_id' => $siteB->id,
            'external_id' => 'ORD-BETA-PRIVATE-999',
            'status' => 'delivered',
            'ordered_at' => now(),
            'subtotal' => 5000,
            'discount' => 0,
            'shipping' => 60,
            'total' => 5060,
            'currency' => 'BDT',
        ])->save();

        // Role check: admin & analyst forbidden (403)
        $this->actingAs($a['admin'])->post('/workspace/export')->assertForbidden();
        $this->actingAs($a['analyst'])->post('/workspace/export')->assertForbidden();

        // Queued job dispatch verification
        $realQueue = Queue::getFacadeRoot();
        Queue::fake();
        $this->actingAs($a['owner'])->post('/workspace/export')->assertRedirect('/workspace');
        Queue::assertPushed(ExportTenantData::class, fn (ExportTenantData $job): bool => $job->tenantId === $tenantA);

        // Execute synchronously via real request
        Queue::swap($realQueue);
        TenantExport::withoutGlobalScopes()->where('tenant_id', $tenantA)->delete();

        $this->actingAs($a['owner'])->post('/workspace/export')->assertRedirect('/workspace');

        /** @var TenantExport|null $export */
        $export = TenantExport::withoutGlobalScopes()->where('tenant_id', $tenantA)->first();
        $this->assertNotNull($export);
        $this->assertSame('ready', $export->status);
        $this->assertTrue(Storage::disk('local')->exists((string) $export->stored_path));

        // Cross-tenant owner gets 404; same-tenant admin/analyst get 403
        $this->actingAs($b['owner'])->get("/workspace/exports/{$export->id}/download")->assertNotFound();
        $this->actingAs($a['admin'])->get("/workspace/exports/{$export->id}/download")->assertForbidden();
        $this->actingAs($a['analyst'])->get("/workspace/exports/{$export->id}/download")->assertForbidden();

        // Same-tenant owner downloads valid ZIP
        $res = $this->actingAs($a['owner'])->get("/workspace/exports/{$export->id}/download");
        $res->assertOk();
        $zipBytes = $res->streamedContent();
        $this->assertNotSame('', $zipBytes);

        $tmpZip = tempnam(sys_get_temp_dir(), 't16zip_');
        $this->assertNotFalse($tmpZip);
        file_put_contents($tmpZip, $zipBytes);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmpZip) === true);

        $allCsvText = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $content = (string) $zip->getFromIndex($i);
            $allCsvText .= "\n--- {$name} ---\n".$content;
        }

        $customersCsv = (string) $zip->getFromName('customers.csv');
        $ordersCsv = (string) $zip->getFromName('orders.csv');
        $leadsCsv = (string) $zip->getFromName('chatbot_leads.csv');
        $credsCsv = (string) $zip->getFromName('api_credentials.csv');
        $websitesCsv = (string) $zip->getFromName('websites.csv');
        $usersCsv = (string) $zip->getFromName('users.csv');

        $zip->close();
        @unlink($tmpZip);

        // Tenant A present, Tenant B strictly absent across every CSV inside ZIP
        $this->assertStringContainsString('ORD-ALPHA-1', $ordersCsv);
        $this->assertStringContainsString('key_alpha_pub', $credsCsv);
        $this->assertStringNotContainsString('ORD-BETA-PRIVATE-999', $allCsvText);
        $this->assertStringNotContainsString('beta-exclusive.example.com', $allCsvText);
        $this->assertStringNotContainsString($b['owner']->email, $allCsvText);

        // Secrets & encrypted plaintext columns must NEVER appear
        $this->assertStringNotContainsString('topsecret-verification-token-alpha', $websitesCsv);
        $this->assertStringNotContainsString('super-secret-hmac-key-never-export', $credsCsv);
        $this->assertStringNotContainsString('secret_encrypted', $credsCsv);
        $this->assertStringNotContainsString('remember_token', $usersCsv);
        $this->assertStringNotContainsString('$2y$', $usersCsv);
        $this->assertStringNotContainsString('01811112222-plaintext-lead-phone', $leadsCsv);

        // CSV injection cells neutralized with leading apostrophe
        $this->assertStringContainsString("'=CMD|", $customersCsv);
        $this->assertStringContainsString("'+SUM(1,2)", $ordersCsv);
        $this->assertStringContainsString("'-2+3", $ordersCsv);
        $this->assertStringContainsString("'@SUM(A1:A2)", $ordersCsv);
        $this->assertStringContainsString("' \t=HYPERLINK(", $leadsCsv);
    }

    public function test_tenant_delete_requires_owner_exact_slug_confirmation_and_preserves_other_tenant_and_audit_chain(): void
    {
        Storage::fake('local');

        $a = $this->makeTenantWithUsers('Delete Target');
        $b = $this->makeTenantWithUsers('Keep Survivor');
        $tenantA = (int) $a['tenant']->id;
        $tenantB = (int) $b['tenant']->id;
        $slugA = (string) $a['tenant']->slug;

        // Private files for both tenants
        Storage::disk('local')->put("imports/t{$tenantA}/pending.csv", 'a,b');
        Storage::disk('local')->put("exports/t{$tenantA}/out.zip", 'PKtest');
        Storage::disk('local')->put("imports/t{$tenantB}/keep.csv", 'keep,data');

        $siteA = new Website(['name' => 'A', 'platform' => 'file', 'currency' => 'BDT']);
        $siteA->forceFill(['tenant_id' => $tenantA, 'status' => 'active', 'verification_token' => bin2hex(random_bytes(16))])->save();
        $siteB = new Website(['name' => 'B', 'platform' => 'file', 'currency' => 'BDT']);
        $siteB->forceFill(['tenant_id' => $tenantB, 'status' => 'active', 'verification_token' => bin2hex(random_bytes(16))])->save();

        Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantA,
            'website_id' => $siteA->id,
            'external_id' => 'DEL-ORD-1',
            'status' => 'delivered',
            'ordered_at' => now(),
            'subtotal' => 500,
            'discount' => 0,
            'shipping' => 60,
            'total' => 560,
            'currency' => 'BDT',
        ]);

        Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantB,
            'website_id' => $siteB->id,
            'external_id' => 'KEEP-ORD-1',
            'status' => 'delivered',
            'ordered_at' => now(),
            'subtotal' => 800,
            'discount' => 0,
            'shipping' => 60,
            'total' => 860,
            'currency' => 'BDT',
        ]);

        // Role enforcement: admin and analyst get 403
        $this->actingAs($a['admin'])->post('/workspace/delete', ['confirm_slug' => $slugA])->assertForbidden();
        $this->actingAs($a['analyst'])->post('/workspace/delete', ['confirm_slug' => $slugA])->assertForbidden();

        // Wrong slug rejected and deletes nothing
        $this->actingAs($a['owner'])
            ->post('/workspace/delete', ['confirm_slug' => 'wrong-slug'])
            ->assertRedirect('/workspace')
            ->assertSessionHasErrors('confirm_slug');
        $this->assertDatabaseHas('tenants', ['id' => $tenantA]);

        // Verify queued job dispatch with fake queue
        $realQueue = Queue::getFacadeRoot();
        Queue::fake();
        $this->actingAs($a['owner'])
            ->post('/workspace/delete', ['confirm_slug' => $slugA])
            ->assertRedirect('/login');
        $this->assertGuest();
        Queue::assertPushed(
            DeleteTenantData::class,
            fn (DeleteTenantData $job): bool => $job->tenantId === $tenantA && $job->tenantSlug === $slugA
        );

        // Now execute synchronously with real queue to test full deletion
        Queue::swap($realQueue);
        // Re-authenticate owner since logout occurred above
        $this->actingAs($a['owner'])
            ->post('/workspace/delete', ['confirm_slug' => $slugA])
            ->assertRedirect('/login');
        $this->assertGuest();

        $this->assertDatabaseMissing('tenants', ['id' => $tenantA]);
        $this->assertSame(0, DB::table('users')->where('tenant_id', $tenantA)->count());
        $this->assertSame(0, DB::table('websites')->where('tenant_id', $tenantA)->count());
        $this->assertSame(0, DB::table('orders')->where('tenant_id', $tenantA)->count());
        $this->assertFalse(Storage::disk('local')->exists("imports/t{$tenantA}/pending.csv"));
        $this->assertFalse(Storage::disk('local')->exists("exports/t{$tenantA}/out.zip"));

        // Tenant B and its private storage are completely intact
        $this->assertDatabaseHas('tenants', ['id' => $tenantB]);
        $this->assertDatabaseHas('orders', ['tenant_id' => $tenantB, 'external_id' => 'KEEP-ORD-1']);
        $this->assertTrue(Storage::disk('local')->exists("imports/t{$tenantB}/keep.csv"));

        // Deletion is audited and hash chain remains 100% valid
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenantA, 'action' => 'tenant.delete.requested']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenantA, 'action' => 'tenant.delete.completed']);
        $chain = AuditLog::verifyChain();
        $this->assertTrue($chain['valid'], 'Audit log hash chain must remain valid after tenant deletion');
    }

    public function test_weekly_report_scheduled_saturday_0800_builds_idempotently_and_renders_en_and_bn_without_placeholders(): void
    {
        // Verify console scheduler includes build-weekly-reports Saturday 08:00 Asia/Dhaka
        $events = collect(app(Schedule::class)->events());
        $weeklyEvent = $events->first(
            fn ($e): bool => str_contains((string) ($e->description ?? ''), 'build-weekly-reports')
        );
        $this->assertNotNull($weeklyEvent);
        $this->assertSame('0 8 * * 6', $weeklyEvent->expression);
        $this->assertSame('Asia/Dhaka', $weeklyEvent->timezone);

        $frozenNow = Carbon::parse('2026-11-11 10:00:00', 'Asia/Dhaka'); // Wednesday -> current week Sat 2026-11-07
        Carbon::setTestNow($frozenNow);

        $a = $this->makeTenantWithUsers('Dhaka Wear');
        $b = $this->makeTenantWithUsers('Other Wear');
        $tenantA = (int) $a['tenant']->id;

        $siteA = new Website([
            'name' => 'Dhaka Wear Main',
            'platform' => 'laravel',
            'domain' => 'dhakawear.example.com',
            'currency' => 'BDT',
        ]);
        $siteA->forceFill(['tenant_id' => $tenantA, 'status' => 'active', 'verification_token' => bin2hex(random_bytes(16))])->save();

        // Current Sat-Fri window orders (2026-11-07 .. 2026-11-13)
        Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantA,
            'website_id' => $siteA->id,
            'external_id' => 'WR-1',
            'status' => 'delivered',
            'ordered_at' => '2026-11-08 14:00:00',
            'subtotal' => 2000,
            'discount' => 0,
            'shipping' => 70,
            'total' => 2070,
            'currency' => 'BDT',
            'courier' => 'Pathao',
            'city' => 'Dhaka',
        ]);
        Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantA,
            'website_id' => $siteA->id,
            'external_id' => 'WR-2',
            'status' => 'returned',
            'ordered_at' => '2026-11-09 15:00:00',
            'subtotal' => 1500,
            'discount' => 0,
            'shipping' => 110,
            'total' => 1610,
            'currency' => 'BDT',
            'courier' => 'RedX',
            'city' => 'Chattogram',
            'cancel_reason' => 'Buyer unreachable',
        ]);

        $finding = ResearchFinding::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantA,
            'website_id' => $siteA->id,
            'rule_code' => 'R04',
            'subject_key' => 'courier:RedX',
            'week_key' => '2026-W46',
            'fingerprint' => hash('sha256', "t{$tenantA}|r04|redx"),
            'is_insufficient_data' => false,
            'template_key' => 'R04.trigger',
            'params' => [
                'courier' => 'RedX',
                'return_rate' => 38.5,
                'shipments' => 120,
                'peer_rate' => 12.0,
                'p_value' => 0.0002,
                'money' => 18500.00,
                'currency' => 'BDT',
                'site' => 'Dhaka Wear Main',
                'confidence_reason_key' => 'high_significant',
            ],
            'title' => 'RedX high return rate',
            'summary' => 'RedX return rate is elevated',
            'evidence' => ['shipments' => 120],
            'severity' => 'high',
            'money_at_stake' => 18500.00,
            'currency' => 'BDT',
            'confidence' => 'high',
            'confidence_reason' => 'p < 0.01 across 120 shipments',
            'hypotheses' => ['Area handoff issue'],
            'missing_data' => ['Courier pickup logs'],
            'recommended_action' => 'Audit RedX Chattogram parcels',
            'experiment_plan' => 'Route 50% Chattogram parcels to Pathao',
            'success_metric' => ['metric' => 'return_rate', 'baseline' => '38.5%', 'target' => '<= 18%', 'duration' => '14 days'],
            'status' => 'new',
            'first_seen_at' => $frozenNow,
            'last_seen_at' => $frozenNow,
        ]);

        $exp = Experiment::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantA,
            'website_id' => $siteA->id,
            'finding_id' => $finding->id,
            'title' => 'Route Chattogram COD to Pathao',
            'hypothesis' => 'Reduces return rate in Chattogram',
            'design' => '50/50 courier routing for 14 days',
            'baseline' => ['return_rate' => 38.5],
            'success_metric' => ['target_return_rate' => 20.0],
            'min_sample' => 40,
            'planned_days' => 14,
            'owner_user_id' => $a['owner']->id,
            'status' => 'completed',
            'approved_by' => $a['owner']->id,
            'approved_at' => $frozenNow->copy()->subDays(10),
            'started_at' => $frozenNow->copy()->subDays(9),
            'ended_at' => $frozenNow->copy()->subDay(),
        ]);

        ExperimentResult::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantA,
            'experiment_id' => $exp->id,
            'measured' => ['metric_value' => '15.2%'],
            'outcome' => 'validated',
            'lesson' => 'Pathao cut Chattogram returns significantly.',
            'recorded_by' => $a['owner']->id,
            'recorded_at' => $frozenNow->copy()->subDay(),
        ]);

        // Analyst cannot build weekly reports (403); admin and owner can
        $this->actingAs($a['analyst'])->post('/weekly-reports/build', ['period' => 'current'])->assertForbidden();

        // Build current week via Admin POST
        $buildRes = $this->actingAs($a['admin'])->post('/weekly-reports/build', ['period' => 'current']);
        /** @var WeeklyReport|null $report */
        $report = WeeklyReport::withoutGlobalScopes()->where('tenant_id', $tenantA)->first();
        $this->assertNotNull($report);
        $buildRes->assertRedirect("/weekly-reports/{$report->id}");

        // Re-running build for same (tenant_id, week_start) is strictly idempotent (1 row total)
        app(WeeklyReportService::class)->buildForTenant($tenantA, WeeklyReportService::defaultWeekStart($frozenNow, false), $frozenNow);
        $this->assertSame(1, WeeklyReport::withoutGlobalScopes()->where('tenant_id', $tenantA)->count());

        // Scheduled batch dispatcher queues per-tenant builds cleanly
        Queue::fake();
        (new BuildWeeklyReports())->handle();
        Queue::assertPushed(BuildTenantWeeklyReport::class, 2);

        // Tenant isolation: Tenant B owner receives 404 for Tenant A's report
        $this->actingAs($b['owner'])->get("/weekly-reports/{$report->id}")->assertNotFound();

        // English preview + strict print/CSP/no-email-SMS check
        $enHtml = $this->actingAs($a['analyst'])
            ->get("/weekly-reports/{$report->id}?lang=en&bn_digits=0")
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Weekly Executive Summary', $enHtml);
        $this->assertStringContainsString('Money Lost', $enHtml);
        $this->assertStringContainsString('Courier Highlights', $enHtml);
        $this->assertStringContainsString('Route Chattogram COD to Pathao', $enHtml);
        $this->assertStringContainsString('RedX', $enHtml);
        $this->assertStringContainsString('data-print-button', $enHtml);
        $this->assertStringNotContainsString('Send Email', $enHtml);
        $this->assertStringNotContainsString('Send SMS', $enHtml);
        $this->assertStringNotContainsString('onclick=', $enHtml);
        $this->assertSame(0, preg_match('/\{[a-zA-Z0-9_]+\}/', (string) $enHtml), 'English weekly report contains unresolved placeholder');

        // Index page renders cleanly in EN & BN without placeholders
        $indexEn = $this->actingAs($a['analyst'])->get('/weekly-reports?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString('Weekly Summary Reports', $indexEn);
        $this->assertSame(0, preg_match('/\{[a-zA-Z0-9_]+\}/', (string) $indexEn));

        $indexBn = $this->actingAs($a['owner'])->get('/weekly-reports?lang=bn&bn_digits=1')->assertOk()->getContent();
        $this->assertStringContainsString('সাপ্তাহিক সারসংক্ষেপ প্রতিবেদন', $indexBn);
        $this->assertStringContainsString('চলতি সপ্তাহ তৈরি করুন', $indexBn);
        $this->assertSame(0, preg_match('/\{[a-zA-Z0-9_]+\}/', (string) $indexBn));

        // Workspace management page shows demo, export, and delete controls only to Owner
        $wsOwner = $this->actingAs($a['owner'])->get('/workspace')->assertOk()->getContent();
        $this->assertStringContainsString('Load demo data', $wsOwner);
        $this->assertStringContainsString('Queue workspace export', $wsOwner);
        $this->assertStringContainsString('Delete workspace permanently', $wsOwner);
        $this->assertStringNotContainsString('onclick=', $wsOwner);

        $wsAdmin = $this->actingAs($a['admin'])->get('/workspace')->assertOk()->getContent();
        $this->assertStringNotContainsString('Load demo data', $wsAdmin);
        $this->assertStringNotContainsString('Queue workspace export', $wsAdmin);
        $this->assertStringNotContainsString('Delete workspace permanently', $wsAdmin);
        $this->assertSame(0, preg_match('/\{[a-zA-Z0-9_]+\}/', (string) $wsAdmin));

        // Bangla preview + Bangla numerals check + zero unresolved placeholders
        $bnHtml = $this->actingAs($a['owner'])
            ->get("/weekly-reports/{$report->id}?lang=bn&bn_digits=1")
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('সাপ্তাহিক সারসংক্ষেপ প্রতিবেদন', $bnHtml);
        $this->assertStringContainsString('কুরিয়ার পারফরম্যান্স হাইলাইটস', $bnHtml);
        $this->assertStringContainsString('এক্সপেরিমেন্ট আপডেট', $bnHtml);
        $this->assertMatchesRegularExpression('/[০-৯]/u', (string) $bnHtml);
        $this->assertSame(0, preg_match('/\{[a-zA-Z0-9_]+\}/', (string) $bnHtml), 'Bangla weekly report contains unresolved placeholder');
    }
}
