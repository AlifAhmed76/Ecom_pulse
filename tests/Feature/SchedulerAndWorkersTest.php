<?php

namespace Tests\Feature;

use App\Jobs\RefreshAllTenants;
use App\Jobs\WarmTenantCache;
use App\Jobs\WorkerBeat;
use App\Models\Website;
use App\Services\CredentialService;
use App\Support\TenantCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SchedulerAndWorkersTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_refresh_all_tenants_dispatches_one_job_per_tenant(): void
    {
        Queue::fake();

        $t1 = $this->makeTenantWithUsers('Tenant One');
        $t2 = $this->makeTenantWithUsers('Tenant Two');

        (new RefreshAllTenants())->handle();

        Queue::assertPushedOn('default', WarmTenantCache::class, fn (WarmTenantCache $job) => $job->tenantId === $t1['tenant']->id);
        Queue::assertPushedOn('default', WarmTenantCache::class, fn (WarmTenantCache $job) => $job->tenantId === $t2['tenant']->id);
        Queue::assertPushed(WarmTenantCache::class, 2);
    }

    public function test_unique_warm_job_does_not_pile_up(): void
    {
        Queue::fake();

        $t = $this->makeTenantWithUsers('Unique Queue Tenant');

        WarmTenantCache::dispatch($t['tenant']->id)->onQueue('high');
        WarmTenantCache::dispatch($t['tenant']->id)->onQueue('high');
        WarmTenantCache::dispatch($t['tenant']->id)->onQueue('default');

        Queue::assertPushed(WarmTenantCache::class, 1);
    }

    public function test_warm_job_populates_cache_and_worker_beat_updates_system_page(): void
    {
        $t = $this->makeTenantWithUsers('Warm Beat Tenant');
        $site = new Website([
            'name' => 'Warm Store',
            'platform' => 'file',
            'currency' => 'BDT',
        ]);
        $site->forceFill([
            'tenant_id' => $t['tenant']->id,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();

        $this->assertNull(TenantCache::get($t['tenant']->id, 'dashboard'));

        (new WarmTenantCache($t['tenant']->id))->handle();

        $cached = TenantCache::get($t['tenant']->id, 'dashboard');
        $this->assertIsArray($cached);
        $this->assertSame(1, $cached['websites']);

        // Initially not running
        $this->actingAs($t['owner'])
            ->get('/system')
            ->assertOk()
            ->assertSee('Not running');

        // WorkerBeat and scheduler beat turn system status to Running without leaking cross-tenant counts
        (new WorkerBeat())->handle();
        Cache::put('sys:scheduler_beat', now()->toIso8601String(), 180);

        $this->actingAs($t['owner'])
            ->get('/system')
            ->assertOk()
            ->assertSee('Running');
    }

    public function test_prune_removes_only_nonces_older_than_15_minutes(): void
    {
        $t = $this->makeTenantWithUsers('Prune Co');
        $site = new Website([
            'name' => 'Prune Store',
            'platform' => 'laravel',
            'domain' => 'prune.test',
            'currency' => 'BDT',
        ]);
        $site->forceFill([
            'tenant_id' => $t['tenant']->id,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();
        $issued = CredentialService::issue($site, ['heartbeat']);

        DB::table('request_nonces')->insert([
            [
                'credential_id' => $issued['credential']->id,
                'nonce' => 'nonce_expired_16_minutes',
                'created_at' => now()->subMinutes(16)->format('Y-m-d H:i:s'),
            ],
            [
                'credential_id' => $issued['credential']->id,
                'nonce' => 'nonce_fresh_5_minutes',
                'created_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
            ],
        ]);

        $this->artisan('lyp:prune')->assertSuccessful();

        $this->assertDatabaseMissing('request_nonces', ['nonce' => 'nonce_expired_16_minutes']);
        $this->assertDatabaseHas('request_nonces', ['nonce' => 'nonce_fresh_5_minutes']);
    }
}
