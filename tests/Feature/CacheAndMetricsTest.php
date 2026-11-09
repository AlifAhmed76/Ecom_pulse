<?php

namespace Tests\Feature;

use App\Jobs\WarmTenantCache;
use App\Models\Website;
use App\Services\CredentialService;
use App\Services\MetricsService;
use App\Support\TenantCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CacheAndMetricsTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private function seedTenantActivity(int $tenantId, bool $connected): Website
    {
        $site = new Website([
            'name' => 'Site '.uniqid(),
            'platform' => 'laravel',
            'domain' => 'site-'.uniqid().'.test',
            'currency' => 'BDT',
        ]);
        $site->forceFill([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
            'last_heartbeat_at' => $connected ? now()->subMinutes(2) : now()->subMinutes(25),
        ])->save();

        CredentialService::issue($site, ['heartbeat', 'events:write']);

        DB::table('integration_events')->insert([
            'website_id' => $site->id,
            'source_event_id' => 'evt-'.uniqid(),
            'type' => 'order.placed',
            'occurred_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'payload' => '{}',
            'status' => 'received',
            'received_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        return $site;
    }

    public function test_ttl_is_1800_and_second_read_executes_zero_queries_until_cleared(): void
    {
        $this->assertSame(1800, TenantCache::ttl());

        $a = $this->makeTenantWithUsers('Cache Alpha');
        $b = $this->makeTenantWithUsers('Cache Beta');

        $this->seedTenantActivity($a['tenant']->id, true);
        $this->seedTenantActivity($b['tenant']->id, true);
        $this->seedTenantActivity($b['tenant']->id, false);

        // First read computes and caches
        $first = MetricsService::for($a['tenant']->id)->dashboard();
        $this->assertSame(1, $first['websites']);
        $this->assertSame(1, $first['connected']);
        $this->assertSame(1, $first['active_credentials']);
        $this->assertSame(1, $first['events_24h']);
        $this->assertSame(1800, $first['ttl']);

        // Warm Tenant B as well
        $bFirst = MetricsService::for($b['tenant']->id)->dashboard();
        $this->assertSame(2, $bFirst['websites']);
        $this->assertSame(1, $bFirst['connected']);

        // Add another website directly to A's DB without clearing cache
        $this->seedTenantActivity($a['tenant']->id, true);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $second = MetricsService::for($a['tenant']->id)->dashboard();
        $queriesOnHit = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queriesOnHit);
        $this->assertSame(1, $second['websites']); // still cached until cleared

        // Clear Tenant A only -> A recomputes 2 websites, while Tenant B cache stays untouched (still cached)
        TenantCache::clear($a['tenant']->id);

        $this->seedTenantActivity($b['tenant']->id, true); // untouched DB change in B
        DB::enableQueryLog();
        DB::flushQueryLog();
        $bCachedAfterClearA = MetricsService::for($b['tenant']->id)->dashboard();
        $bQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $bQueries);
        $this->assertSame(2, $bCachedAfterClearA['websites']);

        $aRecomputed = MetricsService::for($a['tenant']->id)->dashboard();
        $this->assertSame(2, $aRecomputed['websites']);
    }

    public function test_dashboard_json_etag_304_and_auth_guards_and_role_permissions(): void
    {
        $a = $this->makeTenantWithUsers('JSON Alpha');
        $this->seedTenantActivity($a['tenant']->id, true);

        // Guests redirected to login
        $this->get('/metrics/dashboard.json')->assertRedirect('/login');
        $this->get('/system')->assertRedirect('/login');
        $this->post('/dashboard/refresh')->assertRedirect('/login');
        $this->post('/system/cache-clear')->assertRedirect('/login');

        // Analyst cannot access System or trigger cache clear
        $this->actingAs($a['analyst'])->get('/system')->assertForbidden();
        $this->actingAs($a['analyst'])->post('/dashboard/refresh')->assertForbidden();
        $this->actingAs($a['analyst'])->post('/system/cache-clear')->assertForbidden();

        // Analyst CAN read cached dashboard & dashboard.json
        $this->actingAs($a['analyst'])->get('/dashboard')->assertOk()->assertSee('Updated ');

        $jsonResp = $this->actingAs($a['analyst'])->get('/metrics/dashboard.json');
        $jsonResp->assertOk()
            ->assertJson(['websites' => 1, 'connected' => 1]);

        $etag = (string) $jsonResp->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $cacheControl = strtolower((string) $jsonResp->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=120', $cacheControl);

        // Sending matching If-None-Match returns 304 Not Modified cleanly
        $this->actingAs($a['analyst'])
            ->withHeaders(['If-None-Match' => $etag])
            ->get('/metrics/dashboard.json')
            ->assertStatus(304);
    }

    public function test_refresh_queues_warm_job_on_high_and_audits_clear(): void
    {
        Queue::fake();

        $a = $this->makeTenantWithUsers('Refresh Alpha');
        $this->seedTenantActivity($a['tenant']->id, true);

        MetricsService::for($a['tenant']->id)->dashboard();
        $versionBefore = TenantCache::version($a['tenant']->id);

        $this->actingAs($a['owner'])
            ->post('/dashboard/refresh')
            ->assertRedirect(route('dashboard'));

        $this->assertSame($versionBefore + 1, TenantCache::version($a['tenant']->id));
        Queue::assertPushedOn('high', WarmTenantCache::class, function (WarmTenantCache $job) use ($a): bool {
            return $job->tenantId === $a['tenant']->id;
        });

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $a['tenant']->id,
            'user_id' => $a['owner']->id,
            'action' => 'cache.clear',
        ]);

        // System page loads for owner/admin and clear-cache works
        $this->actingAs($a['admin'])
            ->get('/system')
            ->assertOk()
            ->assertSee('1,800');

        $this->actingAs($a['admin'])
            ->post('/system/cache-clear')
            ->assertRedirect(route('system.index'));
    }

    public function test_job_context_without_signed_in_user_filters_strictly_by_tenant(): void
    {
        $a = $this->makeTenantWithUsers('Job Alpha');
        $b = $this->makeTenantWithUsers('Job Beta');

        $this->seedTenantActivity($a['tenant']->id, true);
        $this->seedTenantActivity($b['tenant']->id, true);
        $this->seedTenantActivity($b['tenant']->id, true);

        Auth::logout();
        $this->assertFalse(Auth::check());

        (new WarmTenantCache($a['tenant']->id))->handle();

        $cachedA = TenantCache::get($a['tenant']->id, 'dashboard');
        $this->assertIsArray($cachedA);
        $this->assertSame(1, $cachedA['websites']);
        $this->assertNull(TenantCache::get($b['tenant']->id, 'dashboard'));
    }

    public function test_stampede_lock_timeout_falls_back_gracefully(): void
    {
        config(['lyp.cache_lock_wait' => 1]);

        $a = $this->makeTenantWithUsers('Lock Alpha');
        $this->seedTenantActivity($a['tenant']->id, true);

        $lockKey = TenantCache::lockKey($a['tenant']->id, 'dashboard');
        $heldLock = Cache::lock($lockKey, 10);
        $this->assertTrue($heldLock->get());

        try {
            $metrics = MetricsService::for($a['tenant']->id)->dashboard();
            $this->assertSame(1, $metrics['websites']);
        } finally {
            $heldLock->release();
        }
    }
}
