<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_chain_valid_after_multiple_records_and_detects_edit_or_middle_deletion(): void
    {
        $a = $this->makeTenantWithUsers('Chain Alpha');
        $b = $this->makeTenantWithUsers('Chain Beta');

        AuditLog::record('auth.login', 'Owner A signed in', $a['tenant']->id, $a['owner']->id);
        AuditLog::record('website.create', 'Created website Alpha', $a['tenant']->id, $a['owner']->id);
        AuditLog::record('auth.login', 'Owner B signed in', $b['tenant']->id, $b['owner']->id);
        AuditLog::record('credential.issue', 'Issued key lyp_1234', $a['tenant']->id, $a['owner']->id);
        AuditLog::record('auth.logout', 'Signed out', $a['tenant']->id, $a['owner']->id);

        $check = AuditLog::verifyChain($a['tenant']->id);
        $this->assertTrue($check['valid']);
        $this->assertSame(4, $check['tenant_rows']);

        // Snapshot rows so we can test both tampering and middle deletion
        $rows = DB::table('audit_logs')->orderBy('id')->get();
        $middleId = $rows[2]->id;

        // 1. Tamper with details in a row
        DB::table('audit_logs')->where('id', $middleId)->update(['details' => 'Tampered details']);
        $this->assertFalse(AuditLog::verifyChain($a['tenant']->id)['valid']);

        // Restore original details -> valid again
        DB::table('audit_logs')->where('id', $middleId)->update(['details' => $rows[2]->details]);
        $this->assertTrue(AuditLog::verifyChain($a['tenant']->id)['valid']);

        // 2. Delete middle row -> chain linkage breaks
        DB::table('audit_logs')->where('id', $middleId)->delete();
        $this->assertFalse(AuditLog::verifyChain($a['tenant']->id)['valid']);
    }

    public function test_tenant_isolation_role_access_and_no_secrets_or_cross_tenant_counts_leaked(): void
    {
        $a = $this->makeTenantWithUsers('Audit Alpha');
        $b = $this->makeTenantWithUsers('Audit Beta');

        // Analyst gets 403
        $this->actingAs($a['analyst'])->get(route('audit.index'))->assertForbidden();
        $this->actingAs($a['analyst'])->post(route('audit.verify'))->assertForbidden();

        // Owner/admin allowed
        $this->actingAs($a['admin'])->get(route('audit.index'))->assertOk();

        // Record activity in Tenant B
        AuditLog::record('secret.op', 'Beta confidential entry', $b['tenant']->id, $b['owner']->id);
        AuditLog::record('secret.op2', 'Beta confidential entry two', $b['tenant']->id, $b['owner']->id);

        // Tenant A registers a website and issues/rotates/revokes credential -> records audit without secret
        $website = new Website([
            'name' => 'Alpha Audit Store',
            'platform' => 'laravel',
            'domain' => 'alpha-audit.test',
            'currency' => 'BDT',
        ]);
        $website->forceFill([
            'tenant_id' => $a['tenant']->id,
            'status' => 'pending',
            'verification_token' => str_repeat('a', 40),
        ])->save();

        $resp = $this->actingAs($a['owner'])->post(route('websites.credentials.store', $website), [
            'scopes' => ['heartbeat'],
        ]);
        preg_match('/lys_[a-f0-9]{64}/', $resp->getContent(), $matches);
        $secret = $matches[0];

        $this->assertSame(0, DB::table('audit_logs')->where('details', 'like', '%lys_%')->count());

        $auditPage = $this->actingAs($a['owner'])->get(route('audit.index'));
        $auditPage->assertOk()
            ->assertSee('credential.issue')
            ->assertDontSee($secret)
            ->assertDontSee('Beta confidential');

        // Verify button shows ONLY Tenant A count (1 workspace record), NEVER 3 global records
        $verifyFollow = $this->actingAs($a['owner'])->followingRedirects()->post(route('audit.verify'));
        $verifyFollow->assertOk()
            ->assertSee('Chain valid.')
            ->assertSee('1 workspace record(s)');

        // Tamper DB and verify reports failure cleanly without leaking Tenant B info
        DB::table('audit_logs')->where('tenant_id', $b['tenant']->id)->limit(1)->update(['details' => 'hacked']);
        $tamperedPage = $this->actingAs($a['owner'])->followingRedirects()->post(route('audit.verify'));
        $tamperedPage->assertOk()
            ->assertSee('Verification failed.')
            ->assertDontSee('Beta');
    }

    public function test_auth_events_record_audit_entries(): void
    {
        $a = $this->makeTenantWithUsers('Auth Audit');

        // Failed login
        $this->post('/login', ['email' => $a['owner']->email, 'password' => 'WrongPassword1']);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $a['tenant']->id,
            'action' => 'auth.login_failed',
            'user_id' => null,
        ]);

        // Valid login & logout
        $this->post('/login', ['email' => $a['owner']->email, 'password' => 'Password123']);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $a['tenant']->id,
            'user_id' => $a['owner']->id,
            'action' => 'auth.login',
        ]);

        $this->post('/logout');
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $a['tenant']->id,
            'user_id' => $a['owner']->id,
            'action' => 'auth.logout',
        ]);
    }
}
