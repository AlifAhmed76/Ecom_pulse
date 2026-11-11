<?php

namespace Tests\Feature;

use App\Jobs\ExportTenantData;
use App\Models\Analysis;
use App\Models\Chatbot;
use App\Models\Experiment;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\ResearchFinding;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\User;
use App\Models\Website;
use App\Services\WeeklyReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RouteSmokeTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_database_seeder_is_idempotent_and_creates_readme_demo_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Tenant::count());
        $this->assertDatabaseHas('tenants', ['slug' => 'demo-store']);
        $this->assertDatabaseHas('tenants', ['slug' => 'other-company']);
        $this->assertDatabaseHas('users', ['email' => 'owner@demostore.test', 'role' => User::ROLE_OWNER]);
        $this->assertDatabaseHas('users', ['email' => 'analyst@demostore.test', 'role' => User::ROLE_ANALYST]);
        $this->assertDatabaseHas('users', ['email' => 'owner@othercompany.test', 'role' => User::ROLE_OWNER]);
    }

    public function test_smoke_hits_every_get_web_route_as_guest_owner_and_analyst_with_zero_500_errors(): void
    {
        Storage::fake('local');

        $t = $this->makeTenantWithUsers('Smoke Store');
        $tenantId = (int) $t['tenant']->id;
        $owner = $t['owner'];
        $analyst = $t['analyst'];

        $website = new Website([
            'name' => 'Smoke Shop',
            'platform' => 'laravel',
            'domain' => 'smokeshop.example.com',
            'currency' => 'BDT',
        ]);
        $website->forceFill([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(16)),
        ])->save();

        $order = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'website_id' => $website->id,
            'external_id' => 'SMK-ORD-1',
            'status' => 'delivered',
            'ordered_at' => now()->subDay(),
            'subtotal' => 1200,
            'discount' => 0,
            'shipping' => 70,
            'total' => 1270,
            'currency' => 'BDT',
            'courier' => 'Pathao',
            'city' => 'Dhaka',
        ]);

        $finding = ResearchFinding::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'website_id' => $website->id,
            'rule_code' => 'R01',
            'subject_key' => 'site:'.$website->id,
            'week_key' => now()->format('o-\WW'),
            'fingerprint' => hash('sha256', "smoke|{$tenantId}|r01"),
            'is_insufficient_data' => false,
            'template_key' => 'R01.trigger',
            'params' => [
                'site' => 'Smoke Shop',
                'drop_pct' => 28.0,
                'cur_orders' => 25,
                'prev_orders' => 35,
                'cur_sales' => 25000,
                'prev_sales' => 36000,
                'money' => 11000,
                'currency' => 'BDT',
                'confidence_reason_key' => 'moderate_drop',
            ],
            'title' => 'Sales dip observed',
            'summary' => 'Weekly orders dipped compared with baseline.',
            'evidence' => ['prev_orders' => 35, 'cur_orders' => 25],
            'severity' => 'high',
            'money_at_stake' => 11000.00,
            'currency' => 'BDT',
            'confidence' => 'medium',
            'confidence_reason' => 'n >= 30 baseline comparison',
            'hypotheses' => ['Campaign fatigue'],
            'missing_data' => ['Daily ad breakdown'],
            'recommended_action' => 'Review top product conversion rates.',
            'experiment_plan' => 'Test free-delivery threshold.',
            'success_metric' => ['metric' => 'orders', 'baseline' => '25', 'target' => '35', 'duration' => '7 days'],
            'status' => 'new',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $experiment = Experiment::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'website_id' => $website->id,
            'finding_id' => $finding->id,
            'title' => 'Smoke Experiment',
            'hypothesis' => 'Improves conversion rate',
            'design' => 'Test free delivery over 1500 BDT',
            'baseline' => ['orders' => 25],
            'success_metric' => ['target_orders' => 35],
            'min_sample' => 30,
            'planned_days' => 7,
            'owner_user_id' => $owner->id,
            'status' => 'draft',
        ]);

        $chatbot = new Chatbot();
        $chatbot->forceFill([
            'tenant_id' => $tenantId,
            'website_id' => $website->id,
            'name' => 'Smoke Bot',
            'enabled' => true,
            'widget_domain' => 'smokeshop.example.com',
            'public_key' => 'cb_'.Str::random(16),
            'handoff_text' => 'Human agent will follow up.',
        ])->save();

        $weeklyReport = app(WeeklyReportService::class)->buildForTenant($tenantId);

        // Prepare uploaded import batches across orders, courier, and ads for /map and /show routes
        Storage::disk('local')->put("imports/t{$tenantId}/orders.csv", "order_id,status,total\nO1,delivered,500\n");
        Storage::disk('local')->put("imports/t{$tenantId}/courier.csv", "consignment,status,cod\nCN1,delivered,500\n");
        Storage::disk('local')->put("imports/t{$tenantId}/ads.csv", "date,campaign,spend\n2026-11-10,camp1,500\n");

        $orderBatch = ImportBatch::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'website_id' => $website->id,
            'user_id' => $owner->id,
            'kind' => 'orders',
            'filename' => 'orders.csv',
            'stored_path' => "imports/t{$tenantId}/orders.csv",
            'format' => 'csv',
            'status' => 'uploaded',
            'header' => ['order_id', 'status', 'total'],
            'mapping' => ['order_id' => 'order_id', 'status' => 'status', 'line_total' => 'total'],
        ]);

        $courierBatch = ImportBatch::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'website_id' => $website->id,
            'user_id' => $owner->id,
            'kind' => 'courier',
            'filename' => 'courier.csv',
            'stored_path' => "imports/t{$tenantId}/courier.csv",
            'format' => 'csv',
            'status' => 'uploaded',
            'header' => ['consignment', 'status', 'cod'],
            'mapping' => ['_courier' => 'Pathao'],
        ]);

        $adsBatch = ImportBatch::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'website_id' => $website->id,
            'user_id' => $owner->id,
            'kind' => 'ads',
            'filename' => 'ads.csv',
            'stored_path' => "imports/t{$tenantId}/ads.csv",
            'format' => 'csv',
            'status' => 'uploaded',
            'header' => ['date', 'campaign', 'spend'],
            'mapping' => ['_platform' => 'meta'],
        ]);

        $analysis = Analysis::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'user_id' => $owner->id,
            'filename' => 'orders.csv',
            'rows_count' => 1,
            'result' => [
                'rows_read' => 1,
                'skipped_rows' => 0,
                'orders_count' => 1,
                'gross_sales' => 500.0,
                'delivered_revenue' => 500.0,
                'aov' => 500.0,
                'cancellation_rate' => 0.0,
                'status_counts' => ['delivered' => 1],
                'daily_trend' => ['2026-11-10' => ['orders' => 1, 'revenue' => 500.0]],
                'top_products' => ['Widget' => 500.0],
                'mapping' => ['order_id' => 'order_id'],
            ],
        ]);

        $export = new TenantExport();
        $export->forceFill([
            'tenant_id' => $tenantId,
            'requested_by' => $owner->id,
            'status' => 'queued',
            'filename' => 'smoke-export.zip',
        ])->save();
        (new ExportTenantData($tenantId, (int) $export->id))->handle();

        // Guest routes
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();

        // Enumerate every GET route under web middleware automatically so no route is skipped
        /** @var list<RoutingRoute> $webGetRoutes */
        $webGetRoutes = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            if (str_starts_with($uri, 'api/') || str_starts_with($uri, '_') || str_starts_with($uri, 'storage/')) {
                continue;
            }
            $webGetRoutes[] = $route;
        }

        $this->assertNotEmpty($webGetRoutes);

        $resolveUri = function (string $uri) use (
            $website,
            $order,
            $finding,
            $experiment,
            $chatbot,
            $weeklyReport,
            $orderBatch,
            $courierBatch,
            $adsBatch,
            $analysis,
            $export
        ): string {
            if (str_contains($uri, '{batch}')) {
                $batchId = match (true) {
                    str_starts_with($uri, 'courier-imports/') => $courierBatch->id,
                    str_starts_with($uri, 'marketing/imports/') => $adsBatch->id,
                    default => $orderBatch->id,
                };
                $uri = str_replace('{batch}', (string) $batchId, $uri);
            }

            return '/'.ltrim(strtr($uri, [
                '{website}' => (string) $website->id,
                '{order}' => (string) $order->id,
                '{finding}' => (string) $finding->id,
                '{experiment}' => (string) $experiment->id,
                '{chatbot}' => (string) $chatbot->id,
                '{weeklyReport}' => (string) $weeklyReport->id,
                '{analysis}' => (string) $analysis->id,
                '{tenantExport}' => (string) $export->id,
            ]), '/');
        };

        $hitsCount = 0;

        foreach (['owner' => $owner, 'analyst' => $analyst] as $roleLabel => $user) {
            foreach ($webGetRoutes as $route) {
                $uri = $resolveUri($route->uri());
                $res = $this->actingAs($user)->get($uri);
                $status = $res->getStatusCode();

                $this->assertLessThan(
                    500,
                    $status,
                    "Route GET {$uri} returned HTTP {$status} for {$roleLabel}."
                );

                // Guest-only routes redirect to /dashboard when authenticated; owner sees 200 on every app page
                if ($roleLabel === 'owner' && ! in_array($uri, ['/login', '/register'], true)) {
                    $this->assertSame(
                        200,
                        $status,
                        "Expected HTTP 200 on GET {$uri} for owner, got {$status}."
                    );
                }

                if ($roleLabel === 'analyst' && ! in_array($uri, ['/login', '/register'], true)) {
                    $this->assertTrue(
                        in_array($status, [200, 403], true),
                        "Expected HTTP 200 or 403 on GET {$uri} for analyst, got {$status}."
                    );
                }

                $hitsCount++;
            }
        }

        // Also check Bangla query toggle on key presentation pages
        $this->actingAs($owner)->get('/action-center?lang=bn&bn_digits=1')->assertOk();
        $this->actingAs($owner)->get("/weekly-reports/{$weeklyReport->id}?lang=bn&bn_digits=1")->assertOk();
        $this->actingAs($analyst)->get('/analytics')->assertOk();

        $this->assertGreaterThan(50, $hitsCount);
    }
}
