<?php

namespace Tests\Feature;

use App\Models\ApiCredential;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WebsiteCredentialTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_same_tenant_all_roles_can_view_and_other_tenant_gets_404(): void
    {
        $a = $this->makeTenantWithUsers('Alpha');
        $b = $this->makeTenantWithUsers('Beta');

        $website = new Website([
            'name' => 'Alpha Shop',
            'platform' => 'laravel',
            'domain' => 'alpha-shop.test',
            'currency' => 'BDT',
        ]);
        $website->forceFill([
            'tenant_id' => $a['tenant']->id,
            'status' => 'pending',
            'verification_token' => str_repeat('x', 40),
        ])->save();

        $this->actingAs($a['owner'])
            ->get(route('websites.show', $website))
            ->assertOk()
            ->assertSee('Alpha Shop')
            ->assertSee('Not verified');

        $this->actingAs($a['analyst'])
            ->get(route('websites.index'))
            ->assertOk()
            ->assertSee('Alpha Shop');

        $this->actingAs($a['analyst'])
            ->get(route('websites.show', $website))
            ->assertOk();

        $this->actingAs($b['owner'])
            ->get(route('websites.show', $website))
            ->assertNotFound();

        $this->actingAs($b['owner'])
            ->delete(route('websites.destroy', $website))
            ->assertNotFound();

        $this->actingAs($b['owner'])
            ->post(route('websites.credentials.store', $website), ['scopes' => ['heartbeat']])
            ->assertNotFound();
    }

    public function test_role_permissions_for_website_creation_and_deletion(): void
    {
        $g = $this->makeTenantWithUsers('Gamma');

        $this->actingAs($g['analyst'])->get(route('websites.create'))->assertForbidden();
        $this->actingAs($g['analyst'])->post(route('websites.store'), [
            'name' => 'Blocked Store',
            'platform' => 'file',
        ])->assertForbidden();

        // Admin can create
        $this->actingAs($g['admin'])->post(route('websites.store'), [
            'name' => 'File Store',
            'platform' => 'file',
            'currency' => 'bdt',
        ])->assertRedirect();

        $website = Website::withoutGlobalScopes()->where('tenant_id', $g['tenant']->id)->firstOrFail();
        $this->assertNull($website->domain);
        $this->assertSame('BDT', $website->currency);

        // Analyst cannot issue credentials
        $this->actingAs($g['analyst'])->post(route('websites.credentials.store', $website), [
            'scopes' => ['heartbeat'],
        ])->assertForbidden();

        // Admin cannot delete website
        $this->actingAs($g['admin'])->delete(route('websites.destroy', $website))->assertForbidden();

        // Owner can delete website
        $this->actingAs($g['owner'])->delete(route('websites.destroy', $website))->assertRedirect(route('websites.index'));
        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
    }

    public function test_domain_normalization_uniqueness_and_ignored_injected_fields(): void
    {
        $a = $this->makeTenantWithUsers('Delta');
        $b = $this->makeTenantWithUsers('Epsilon');

        // Register via ownerA with mixed casing, www, path, AND malicious tenant_id/status
        $this->actingAs($a['owner'])->post(route('websites.store'), [
            'name' => 'Delta Store',
            'platform' => 'laravel',
            'domain' => 'https://WWW.DeltaStore.COM:8080/shop?ref=1',
            'currency' => 'usd',
            'tenant_id' => $b['tenant']->id,
            'status' => 'active',
        ])->assertRedirect();

        $website = Website::withoutGlobalScopes()->where('domain', 'deltastore.com')->firstOrFail();
        $this->assertSame($a['tenant']->id, $website->tenant_id);
        $this->assertSame('pending', $website->status);
        $this->assertNull($website->verified_at);

        // Another tenant trying to register the same domain gets validation error
        $this->actingAs($b['owner'])->post(route('websites.store'), [
            'name' => 'Copycat',
            'platform' => 'laravel',
            'domain' => 'http://deltastore.com',
        ])->assertSessionHasErrors('domain');
    }

    public function test_secret_shown_once_with_no_store_not_in_db_session_or_logs_and_rotation_and_revoke(): void
    {
        $z = $this->makeTenantWithUsers('Zeta');

        $website = new Website([
            'name' => 'Zeta Shop',
            'platform' => 'laravel',
            'domain' => 'zeta-shop.test',
            'currency' => 'BDT',
        ]);
        $website->forceFill([
            'tenant_id' => $z['tenant']->id,
            'status' => 'pending',
            'verification_token' => str_repeat('z', 40),
        ])->save();

        $otherWebsite = new Website([
            'name' => 'Zeta Two',
            'platform' => 'file',
            'domain' => null,
            'currency' => 'BDT',
        ]);
        $otherWebsite->forceFill([
            'tenant_id' => $z['tenant']->id,
            'status' => 'pending',
            'verification_token' => str_repeat('y', 40),
        ])->save();

        $logPath = storage_path('logs/laravel.log');
        $logOffset = is_file($logPath) ? filesize($logPath) : 0;

        Log::listen(function ($event): void {
            $this->assertStringNotContainsString('lys_', $event->message);
            $this->assertStringNotContainsString('lys_', json_encode($event->context) ?: '');
        });

        $response = $this->actingAs($z['owner'])->post(route('websites.credentials.store', $website), [
            'scopes' => ['heartbeat', 'orders:write'],
        ]);

        $response->assertOk();
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);

        $content = $response->getContent();
        $this->assertMatchesRegularExpression('/lyp_[a-f0-9]{20}/', $content);
        $this->assertMatchesRegularExpression('/lys_[a-f0-9]{64}/', $content);
        preg_match('/lys_[a-f0-9]{64}/', $content, $matches);
        $secret = $matches[0];

        // Session does not contain secret
        $sessionSerialized = json_encode(session()->all()) ?: '';
        $this->assertStringNotContainsString($secret, $sessionSerialized);

        // Database stores encrypted secret only
        $rawRow = DB::table('api_credentials')->where('website_id', $website->id)->first();
        $this->assertNotNull($rawRow);
        $this->assertNotSame($secret, $rawRow->secret_encrypted);
        $this->assertStringNotContainsString($secret, $rawRow->secret_encrypted);
        $this->assertSame($secret, Crypt::decryptString($rawRow->secret_encrypted));

        // Log file contains no plaintext secret
        if (is_file($logPath)) {
            $appended = file_get_contents($logPath, false, null, $logOffset) ?: '';
            $this->assertStringNotContainsString($secret, $appended);
        }

        // Reloading /websites/{website} never contains the secret again
        $this->actingAs($z['owner'])
            ->get(route('websites.show', $website))
            ->assertOk()
            ->assertSee($rawRow->key_id)
            ->assertDontSee($secret);

        $firstCred = ApiCredential::findOrFail($rawRow->id);

        // Credential of website A cannot be rotated or revoked under website B's URL
        $this->actingAs($z['owner'])
            ->post(route('websites.credentials.rotate', [$otherWebsite, $firstCred]))
            ->assertNotFound();
        $this->actingAs($z['owner'])
            ->post(route('websites.credentials.revoke', [$otherWebsite, $firstCred]))
            ->assertNotFound();

        // Rotate creates 2nd credential and sets 1h grace expiry on old one
        $rotateResp = $this->actingAs($z['owner'])
            ->post(route('websites.credentials.rotate', [$website, $firstCred]));

        $rotateResp->assertOk();
        $this->assertStringContainsString('no-store', strtolower((string) $rotateResp->headers->get('Cache-Control')));

        $firstCred->refresh();
        $this->assertNotNull($firstCred->expires_at);
        $this->assertTrue($firstCred->expires_at->gt(now()->addMinutes(55)));
        $this->assertTrue($firstCred->expires_at->lte(now()->addMinutes(65)));
        $this->assertSame(2, ApiCredential::where('website_id', $website->id)->count());

        // Revoke blocks immediately
        $this->actingAs($z['owner'])
            ->post(route('websites.credentials.revoke', [$website, $firstCred]))
            ->assertRedirect(route('websites.show', $website));

        $firstCred->refresh();
        $this->assertNotNull($firstCred->revoked_at);
        $this->assertFalse($firstCred->isUsable());
        $this->assertSame('revoked', $firstCred->state());

        // Cannot rotate an already revoked credential
        $this->actingAs($z['owner'])
            ->post(route('websites.credentials.rotate', [$website, $firstCred]))
            ->assertRedirect(route('websites.show', $website))
            ->assertSessionHas('err');
        $this->assertSame(2, ApiCredential::where('website_id', $website->id)->count());
    }
}
