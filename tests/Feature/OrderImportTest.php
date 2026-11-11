<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportBatch;
use App\Jobs\WarmTenantCache;
use App\Models\Analysis;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\Website;
use App\Services\Import\OrderImporter;
use App\Support\TenantCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OrderImportTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private function createWebsite(int $tenantId, string $name = 'File Shop', string $platform = 'file'): Website
    {
        $site = new Website([
            'name' => $name,
            'platform' => $platform,
            'domain' => null,
            'currency' => 'BDT',
        ]);
        $site->forceFill([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();

        return $site;
    }

    public function test_csv_with_bom_semicolons_grouping_non_adjacent_repeat_and_idempotent_reimport(): void
    {
        Queue::fake();
        Storage::fake('local');

        $t = $this->makeTenantWithUsers('Import Alpha');
        $site = $this->createWebsite($t['tenant']->id);

        $rawCsv = file_get_contents(base_path('tests/Fixtures/synthetic_orders.csv'));
        $bomCsv = "\xEF\xBB\xBF".$rawCsv;
        $upload = UploadedFile::fake()->createWithContent('orders_semicolon.csv', $bomCsv);

        $initialVer = TenantCache::version($t['tenant']->id);

        // Step 1: Upload
        $resp = $this->actingAs($t['owner'])->post('/imports', [
            'website_id' => $site->id,
            'file' => $upload,
        ]);

        $batch = ImportBatch::withoutGlobalScopes()->firstOrFail();
        $resp->assertRedirect(route('imports.map', $batch));
        $this->assertSame('uploaded', $batch->status);
        $this->assertNotNull($batch->stored_path);
        Storage::disk('local')->assertExists($batch->stored_path);

        // Step 2: Mapping screen shows warnings, auto-detected columns, and sample rows
        $this->actingAs($t['owner'])
            ->get(route('imports.map', $batch))
            ->assertOk()
            ->assertSee('Columns are matched by common names. Check the mapping before importing.')
            ->assertSee('MM/DD/YYYY')
            ->assertSee('ORD-201')
            ->assertSee('Cotton Polo');

        // Step 3: Confirm mapping (no worker beat -> synchronous)
        $storedPath = $batch->stored_path;
        $this->actingAs($t['owner'])
            ->post(route('imports.confirm', $batch), ['map' => $batch->mapping])
            ->assertRedirect(route('imports.show', $batch));

        $batch->refresh();
        $this->assertSame('done', $batch->status);
        $this->assertNull($batch->stored_path);
        Storage::disk('local')->assertMissing($storedPath);

        // Tenant cache cleared + WarmTenantCache queued
        $this->assertGreaterThan($initialVer, TenantCache::version($t['tenant']->id));
        Queue::assertPushed(WarmTenantCache::class, fn (WarmTenantCache $job): bool => $job->tenantId === $t['tenant']->id);

        // Exact stats & status breakdown
        $stats = $batch->stats;
        $this->assertSame(6, $stats['rows_read']);
        $this->assertSame(3, $stats['created']);
        $this->assertSame(1, $stats['updated']); // non-adjacent repeat of ORD-201
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(['missing_order_id' => 1], $stats['skipped_reasons']);
        $this->assertSame([
            'Delivered' => ['canonical' => 'delivered', 'count' => 3],
            'কনফার্মড' => ['canonical' => 'confirmed', 'count' => 1],
            'mystery_stage' => ['canonical' => 'other', 'count' => 1],
        ], $stats['status_breakdown']);
        $this->assertSame(['mystery_stage' => 1], $stats['unmapped_statuses']);

        // Exact orders, items, totals, and ambiguous date (11/02/2026 -> 2026-11-02)
        $this->assertSame(3, Order::withoutGlobalScopes()->where('tenant_id', $t['tenant']->id)->count());

        $ord201 = Order::withoutGlobalScopes()->where('website_id', $site->id)->where('external_id', 'ORD-201')->firstOrFail();
        $this->assertSame('delivered', $ord201->status);
        $this->assertSame('1500.00', $ord201->subtotal);
        $this->assertSame('50.00', $ord201->discount);
        $this->assertSame('60.00', $ord201->shipping);
        $this->assertSame('1510.00', $ord201->total);
        $this->assertSame(3, $ord201->items()->count());

        $ord202 = Order::withoutGlobalScopes()->where('website_id', $site->id)->where('external_id', 'ORD-202')->firstOrFail();
        $this->assertSame('confirmed', $ord202->status);
        $this->assertSame('2026-11-02', $ord202->ordered_at->format('Y-m-d'));
        $this->assertSame('1260.00', $ord202->total);

        $ord203 = Order::withoutGlobalScopes()->where('website_id', $site->id)->where('external_id', 'ORD-203')->firstOrFail();
        $this->assertSame('other', $ord203->status);
        $this->assertSame('mystery_stage', $ord203->source_status);
        $this->assertSame('2026-11-03', $ord203->ordered_at->format('Y-m-d'));
        $this->assertSame('800.00', $ord203->total);

        // No raw customer phones stored anywhere
        foreach (['customers', 'orders', 'import_batches', 'audit_logs'] as $table) {
            $dump = json_encode(DB::table($table)->get()->toArray()) ?: '';
            $this->assertStringNotContainsString('01711000111', $dump);
            $this->assertStringNotContainsString('01811000222', $dump);
        }

        // Result page renders report & unmapped warning
        $this->actingAs($t['owner'])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('Status Mapping Report')
            ->assertSee('mystery_stage')
            ->assertSee('missing_order_id');

        // Re-importing the exact same file creates NO duplicates and preserves exact items/totals
        $reupload = UploadedFile::fake()->createWithContent('orders_semicolon.csv', $bomCsv);
        $this->actingAs($t['owner'])->post('/imports', [
            'website_id' => $site->id,
            'file' => $reupload,
        ])->assertRedirect();

        $batch2 = ImportBatch::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
        $this->actingAs($t['owner'])->post(route('imports.confirm', $batch2), ['map' => $batch2->mapping])->assertRedirect();
        $batch2->refresh();

        $this->assertSame(0, $batch2->stats['created']);
        $this->assertSame(4, $batch2->stats['updated']);
        $this->assertSame(3, Order::withoutGlobalScopes()->where('tenant_id', $t['tenant']->id)->count());
        $this->assertSame(3, $ord201->items()->count());
        $this->assertSame('1510.00', $ord201->fresh()->total);
    }

    public function test_xlsx_with_date_cells_and_nested_xml_and_whole_order_csv_import(): void
    {
        Queue::fake();
        Storage::fake('local');

        $t = $this->makeTenantWithUsers('Formats Co');
        $site = $this->createWebsite($t['tenant']->id);

        // 1. XLSX with date cells (and ignored second sheet)
        $xlsxFile = UploadedFile::fake()->createWithContent(
            'synthetic_orders.xlsx',
            (string) file_get_contents(base_path('tests/Fixtures/synthetic_orders.xlsx'))
        );

        $this->actingAs($t['owner'])->post('/imports', [
            'website_id' => $site->id,
            'file' => $xlsxFile,
        ])->assertRedirect();

        $xlsxBatch = ImportBatch::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
        $this->actingAs($t['owner'])->post(route('imports.confirm', $xlsxBatch), ['map' => $xlsxBatch->mapping])->assertRedirect();
        $xlsxBatch->refresh();

        $this->assertSame('done', $xlsxBatch->status);
        $this->assertSame(2, $xlsxBatch->stats['created']);
        $this->assertNull($xlsxBatch->stored_path);

        $x101 = Order::withoutGlobalScopes()->where('website_id', $site->id)->where('external_id', 'XLSX-101')->firstOrFail();
        $this->assertSame('2026-11-05 14:30:00', $x101->ordered_at->format('Y-m-d H:i:s'));
        $this->assertSame('1810.00', $x101->total); // 2*750 + 250 + 60 shipping
        $this->assertSame(2, $x101->items()->count());

        $x102 = Order::withoutGlobalScopes()->where('website_id', $site->id)->where('external_id', 'XLSX-102')->firstOrFail();
        $this->assertSame('2026-11-06 09:15:00', $x102->ordered_at->format('Y-m-d H:i:s'));
        $this->assertSame('600.00', $x102->total); // 600 - 50 + 50
        $this->assertDatabaseMissing('orders', ['external_id' => 'SECOND-SHEET-IGNORED']);

        // 2. Nested XML
        $xmlFile = UploadedFile::fake()->createWithContent(
            'synthetic_orders.xml',
            (string) file_get_contents(base_path('tests/Fixtures/synthetic_orders.xml'))
        );

        $this->actingAs($t['owner'])->post('/imports', [
            'website_id' => $site->id,
            'file' => $xmlFile,
        ])->assertRedirect();

        $xmlBatch = ImportBatch::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
        $this->actingAs($t['owner'])->post(route('imports.confirm', $xmlBatch), ['map' => $xmlBatch->mapping])->assertRedirect();
        $xmlBatch->refresh();

        $this->assertSame('done', $xmlBatch->status);
        $xmlOrd = Order::withoutGlobalScopes()->where('website_id', $site->id)->where('external_id', 'XML-301')->firstOrFail();
        $this->assertSame('shipped', $xmlOrd->status);
        $this->assertSame('1110.00', $xmlOrd->total); // (2*450 + 150) + 60 shipping
        $this->assertSame(2, $xmlOrd->items()->count());

        // 3. Whole-order CSV without product columns
        $wholeCsv = UploadedFile::fake()->createWithContent(
            'whole_orders.csv',
            "Order ID,Order Date,Status,Total\nWHL-1,2026-11-07,Delivered,\"1,450.00\"\n"
        );
        $this->actingAs($t['owner'])->post('/imports', [
            'website_id' => $site->id,
            'file' => $wholeCsv,
        ])->assertRedirect();

        $wholeBatch = ImportBatch::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
        $this->actingAs($t['owner'])->post(route('imports.confirm', $wholeBatch), ['map' => $wholeBatch->mapping])->assertRedirect();
        $wholeOrd = Order::withoutGlobalScopes()->where('website_id', $site->id)->where('external_id', 'WHL-1')->firstOrFail();
        $this->assertSame('1450.00', $wholeOrd->total);
        $this->assertSame(0, $wholeOrd->items()->count());
    }

    public function test_queues_job_when_worker_beat_exists_and_processes_without_auth_user(): void
    {
        Queue::fake();
        Storage::fake('local');
        Cache::put('sys:worker_beat', now()->toIso8601String(), 180);

        $t = $this->makeTenantWithUsers('Queue Shop');
        $site = $this->createWebsite($t['tenant']->id);

        $csv = UploadedFile::fake()->createWithContent(
            'queued.csv',
            "Order ID,Status,Total\nQ-100,Confirmed,770\n"
        );

        $this->actingAs($t['owner'])->post('/imports', [
            'website_id' => $site->id,
            'file' => $csv,
        ])->assertRedirect();

        $batch = ImportBatch::withoutGlobalScopes()->firstOrFail();
        $storedPath = $batch->stored_path;

        $this->actingAs($t['owner'])->post(route('imports.confirm', $batch), ['map' => $batch->mapping])->assertRedirect();

        $batch->refresh();
        $this->assertSame('queued', $batch->status);
        Queue::assertPushedOn('default', ProcessImportBatch::class);

        // Execute job in non-authenticated worker context
        auth()->logout();
        (new ProcessImportBatch($batch->id))->handle(new OrderImporter());

        $batch->refresh();
        $this->assertSame('done', $batch->status);
        $this->assertNull($batch->stored_path);
        Storage::disk('local')->assertMissing($storedPath);

        $this->assertDatabaseHas('orders', [
            'tenant_id' => $t['tenant']->id,
            'website_id' => $site->id,
            'external_id' => 'Q-100',
            'total' => 770.00,
        ]);
    }

    public function test_security_rbac_tenant_isolation_extension_allowlist_xxe_rejection_and_discard(): void
    {
        Storage::fake('local');

        $a = $this->makeTenantWithUsers('Sec Alpha');
        $b = $this->makeTenantWithUsers('Sec Beta');
        $siteA = $this->createWebsite($a['tenant']->id, 'Alpha Site');
        $siteB = $this->createWebsite($b['tenant']->id, 'Beta Site');

        // Upload valid batch for Tenant A
        $csv = UploadedFile::fake()->createWithContent('ok.csv', "Order ID,Total\nA-1,500\n");
        $this->actingAs($a['owner'])->post('/imports', [
            'website_id' => $siteA->id,
            'file' => $csv,
        ])->assertRedirect();

        $batchA = ImportBatch::withoutGlobalScopes()->firstOrFail();

        // Analyst is forbidden (403) across all import & file-analysis routes
        $this->actingAs($a['analyst'])->get('/imports')->assertForbidden();
        $this->actingAs($a['analyst'])->post('/imports', ['website_id' => $siteA->id, 'file' => $csv])->assertForbidden();
        $this->actingAs($a['analyst'])->get(route('imports.map', $batchA))->assertForbidden();
        $this->actingAs($a['analyst'])->post(route('imports.confirm', $batchA), ['map' => $batchA->mapping])->assertForbidden();
        $this->actingAs($a['analyst'])->get(route('imports.show', $batchA))->assertForbidden();
        $this->actingAs($a['analyst'])->delete(route('imports.discard', $batchA))->assertForbidden();
        $this->actingAs($a['analyst'])->get('/file-analysis')->assertForbidden();
        $this->actingAs($a['analyst'])->post('/file-analysis', ['file' => $csv])->assertForbidden();

        // Cross-tenant batch endpoints return 404
        $this->actingAs($b['owner'])->get(route('imports.map', $batchA))->assertNotFound();
        $this->actingAs($b['owner'])->post(route('imports.confirm', $batchA), ['map' => $batchA->mapping])->assertNotFound();
        $this->actingAs($b['owner'])->get(route('imports.show', $batchA))->assertNotFound();
        $this->actingAs($b['owner'])->delete(route('imports.discard', $batchA))->assertNotFound();

        // Tenant B cannot upload into Tenant A's website_id
        $this->actingAs($b['owner'])->post('/imports', [
            'website_id' => $siteA->id,
            'file' => UploadedFile::fake()->createWithContent('cross.csv', "Order ID,Total\nX-1,100\n"),
        ])->assertSessionHasErrors('website_id');

        // Non-allowed extensions (.xls, .php) and oversized (> 20 MB) rejected and nothing kept
        $this->actingAs($b['owner'])->post('/imports', [
            'website_id' => $siteB->id,
            'file' => UploadedFile::fake()->create('legacy.xls', 10),
        ])->assertSessionHasErrors('file');

        $this->actingAs($b['owner'])->post('/imports', [
            'website_id' => $siteB->id,
            'file' => UploadedFile::fake()->create('too_big.csv', 20481),
        ])->assertSessionHasErrors('file');

        $this->actingAs($b['owner'])->post('/imports', [
            'website_id' => $siteB->id,
            'file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
        ])->assertSessionHasErrors('file');

        // Confirming without required fields (order_id / amount) fails validation cleanly
        $this->actingAs($a['owner'])->post(route('imports.confirm', $batchA), [
            'map' => ['order_id' => '', 'line_total' => 'Total'],
        ])->assertSessionHasErrors('map');

        $this->actingAs($a['owner'])->post(route('imports.confirm', $batchA), [
            'map' => ['order_id' => 'Order ID', 'line_total' => '', 'unit_price' => ''],
        ])->assertSessionHasErrors('map');

        // XML with DOCTYPE rejected on both import and quick file analysis, and file cleaned up
        $xxeContent = (string) file_get_contents(base_path('tests/Fixtures/synthetic_xxe.xml'));
        $this->actingAs($b['owner'])->post('/imports', [
            'website_id' => $siteB->id,
            'file' => UploadedFile::fake()->createWithContent('evil.xml', $xxeContent),
        ])->assertSessionHasErrors('file');

        $this->actingAs($b['owner'])->post('/file-analysis', [
            'file' => UploadedFile::fake()->createWithContent('evil.xml', $xxeContent),
        ])->assertSessionHasErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles("imports/t{$b['tenant']->id}"));
        $this->assertSame([], Storage::disk('local')->allFiles("analysis/t{$b['tenant']->id}"));

        // Discard deletes batch record and temporary private file
        $storedA = $batchA->stored_path;
        Storage::disk('local')->assertExists($storedA);
        $this->actingAs($a['owner'])->delete(route('imports.discard', $batchA))->assertRedirect(route('imports.index'));
        $this->assertDatabaseMissing('import_batches', ['id' => $batchA->id]);
        Storage::disk('local')->assertMissing($storedA);
    }

    public function test_quick_file_analysis_produces_kpis_and_charts_without_saving_orders(): void
    {
        Storage::fake('local');

        $a = $this->makeTenantWithUsers('Analysis Alpha');
        $b = $this->makeTenantWithUsers('Analysis Beta');

        $csvFile = UploadedFile::fake()->createWithContent(
            'synthetic_orders.csv',
            (string) file_get_contents(base_path('tests/Fixtures/synthetic_orders.csv'))
        );

        $resp = $this->actingAs($a['owner'])->post('/file-analysis', [
            'file' => $csvFile,
        ]);

        $analysis = Analysis::withoutGlobalScopes()->firstOrFail();
        $resp->assertRedirect(route('analysis.show', $analysis));

        // Zero orders or customers persisted
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('customers')->count());

        // Temporary upload cleaned up
        $this->assertSame([], Storage::disk('local')->allFiles("analysis/t{$a['tenant']->id}"));

        $r = $analysis->result;
        $this->assertSame(6, $r['rows_read']);
        $this->assertSame(1, $r['skipped_rows']);
        $this->assertSame(3, $r['orders_count']);
        $this->assertEquals(3570.0, $r['gross_sales']);
        $this->assertEquals(1510.0, $r['delivered_revenue']);
        $this->assertSame(1, $r['status_counts']['delivered']);
        $this->assertSame(1, $r['status_counts']['confirmed']);
        $this->assertSame(1, $r['status_counts']['other']);

        // Show page renders Chart.js + CSP-safe JSON blocks; cross-tenant gets 404
        $this->actingAs($a['owner'])
            ->get(route('analysis.show', $analysis))
            ->assertOk()
            ->assertSee('chart.umd.js')
            ->assertSee('chart-daily-json')
            ->assertSee('Cotton Polo');

        $this->actingAs($b['owner'])
            ->get(route('analysis.show', $analysis))
            ->assertNotFound();
    }
}
