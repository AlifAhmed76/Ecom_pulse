<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\CredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ConnectorApiTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private function createWebsite(int $tenantId, string $status = 'pending', ?string $domain = null): Website
    {
        $website = new Website([
            'name' => 'Connector Store '.uniqid(),
            'platform' => 'laravel',
            'domain' => $domain ?? 'store-'.uniqid().'.test',
            'currency' => 'BDT',
        ]);
        $website->forceFill([
            'tenant_id' => $tenantId,
            'status' => $status,
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();

        return $website;
    }

    /**
     * @param  array<string, string>  $overrideHeaders
     */
    private function sendSigned(
        string $uri,
        array $payload,
        string $keyId,
        string $secret,
        ?int $timestamp = null,
        ?string $nonce = null,
        ?string $rawOverride = null,
        array $overrideHeaders = []
    ): TestResponse {
        $rawBody = $rawOverride ?? (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $ts = (string) ($timestamp ?? time());
        $n = $nonce ?? bin2hex(random_bytes(16));
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;

        $canonical = "POST\n{$path}\n{$ts}\n{$n}\n".hash('sha256', $rawBody);
        $sig = hash_hmac('sha256', $canonical, $secret);

        $headers = array_merge([
            'HTTP_X_LYP_KEY' => $keyId,
            'HTTP_X_LYP_TIMESTAMP' => $ts,
            'HTTP_X_LYP_NONCE' => $n,
            'HTTP_X_LYP_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $overrideHeaders);

        return $this->call('POST', $uri, [], [], [], $headers, $rawBody);
    }

    public function test_valid_heartbeat_succeeds_without_cookies_and_updates_website(): void
    {
        $t = $this->makeTenantWithUsers('Heartbeat Co');
        $site = $this->createWebsite($t['tenant']->id, 'pending');
        $issued = CredentialService::issue($site, ['heartbeat']);

        $res = $this->sendSigned(
            '/api/v1/connector/heartbeat?ignored=query',
            ['connector_version' => '1.2.3'],
            $issued['credential']->key_id,
            $issued['secret']
        );

        $res->assertOk()
            ->assertJson([
                'ok' => true,
                'api_version' => 'v1',
            ])
            ->assertJsonStructure(['ok', 'api_version', 'server_time']);

        $this->assertCount(0, $res->headers->getCookies());

        $site->refresh();
        $this->assertSame('active', $site->status);
        $this->assertSame('1.2.3', $site->connector_version);
        $this->assertNotNull($site->last_heartbeat_at);
        $this->assertNotNull($issued['credential']->fresh()->last_used_at);
    }

    public function test_missing_headers_stale_future_timestamps_and_bad_nonce(): void
    {
        $t = $this->makeTenantWithUsers('Header Co');
        $site = $this->createWebsite($t['tenant']->id, 'active');
        $issued = CredentialService::issue($site, ['heartbeat']);

        $this->postJson('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'])
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'missing_headers']);

        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $issued['credential']->key_id, $issued['secret'], time() - 301)
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'stale_timestamp']);

        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $issued['credential']->key_id, $issued['secret'], time() + 301)
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'stale_timestamp']);

        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $issued['credential']->key_id, $issued['secret'], time(), 'too-short')
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'bad_nonce']);

        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $issued['credential']->key_id, $issued['secret'], time(), 'bad!nonce#chars$here')
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'bad_nonce']);
    }

    public function test_signature_failures_unknown_key_revoked_expired_and_disabled_website_all_return_identical_401(): void
    {
        $t = $this->makeTenantWithUsers('Auth Fail Co');
        $site = $this->createWebsite($t['tenant']->id, 'active');
        $issued = CredentialService::issue($site, ['heartbeat']);
        $key = $issued['credential']->key_id;
        $secret = $issued['secret'];

        // Wrong signature
        $wrongSig = $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $key, $secret, null, null, null, [
            'HTTP_X_LYP_SIGNATURE' => str_repeat('a', 64),
        ]);
        $wrongSig->assertStatus(401)->assertExactJson(['ok' => false, 'error' => 'invalid_credentials']);

        // Wrong secret
        $wrongSecret = $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $key, 'lys_'.str_repeat('0', 64));
        $wrongSecret->assertStatus(401)->assertExactJson(['ok' => false, 'error' => 'invalid_credentials']);

        // Unknown key must return identical error body & status
        $unknownKey = $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], 'lyp_'.str_repeat('f', 20), $secret);
        $unknownKey->assertStatus(401)->assertExactJson(['ok' => false, 'error' => 'invalid_credentials']);
        $this->assertSame($wrongSig->getContent(), $unknownKey->getContent());

        // Tampered body after signing
        $ts = time();
        $nonce = bin2hex(random_bytes(16));
        $origBody = '{"connector_version":"1.0.0"}';
        $tamperedBody = '{"connector_version":"9.9.9"}';
        $canonical = "POST\n/api/v1/connector/heartbeat\n{$ts}\n{$nonce}\n".hash('sha256', $origBody);
        $sig = hash_hmac('sha256', $canonical, $secret);
        $this->call('POST', '/api/v1/connector/heartbeat', [], [], [], [
            'HTTP_X_LYP_KEY' => $key,
            'HTTP_X_LYP_TIMESTAMP' => (string) $ts,
            'HTTP_X_LYP_NONCE' => $nonce,
            'HTTP_X_LYP_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $tamperedBody)->assertStatus(401)->assertExactJson(['ok' => false, 'error' => 'invalid_credentials']);

        // Expired credential
        $expired = CredentialService::issue($site, ['heartbeat'], now()->subMinute());
        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $expired['credential']->key_id, $expired['secret'])
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'invalid_credentials']);

        // Revoked credential
        $revokable = CredentialService::issue($site, ['heartbeat']);
        CredentialService::revoke($revokable['credential']);
        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $revokable['credential']->key_id, $revokable['secret'])
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'invalid_credentials']);

        // Disabled website
        $site->forceFill(['status' => 'disabled'])->save();
        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $key, $secret)
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'invalid_credentials']);
    }

    public function test_replay_protection_and_insufficient_scope_and_payload_too_large(): void
    {
        $t = $this->makeTenantWithUsers('Replay Scope Co');
        $site = $this->createWebsite($t['tenant']->id, 'active');
        $issued = CredentialService::issue($site, ['heartbeat']); // does NOT have events:write

        $nonce = 'nonce_fixed_1234567890';
        $ts = time();

        // Missing scope returns 403
        $this->sendSigned('/api/v1/connector/events', [
            'events' => [['id' => 'e1', 'type' => 'order.viewed']],
        ], $issued['credential']->key_id, $issued['secret'], $ts, $nonce)
            ->assertStatus(403)
            ->assertExactJson(['ok' => false, 'error' => 'insufficient_scope']);

        // Valid heartbeat uses nonce once
        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $issued['credential']->key_id, $issued['secret'], $ts, $nonce)
            ->assertOk();

        // Replaying same nonce with same key returns 401 replayed_request
        $this->sendSigned('/api/v1/connector/heartbeat', ['connector_version' => '1.0.0'], $issued['credential']->key_id, $issued['secret'], $ts, $nonce)
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'replayed_request']);

        // Body > 1 MB returns 413 payload_too_large
        $oversized = str_repeat('a', 1_048_600);
        $this->sendSigned('/api/v1/connector/heartbeat', [], $issued['credential']->key_id, $issued['secret'], null, null, $oversized)
            ->assertStatus(413)
            ->assertExactJson(['ok' => false, 'error' => 'payload_too_large']);
    }

    public function test_events_idempotency_isolation_and_validation(): void
    {
        $tA = $this->makeTenantWithUsers('Events Alpha');
        $tB = $this->makeTenantWithUsers('Events Beta');
        $siteA = $this->createWebsite($tA['tenant']->id, 'active');
        $siteB = $this->createWebsite($tB['tenant']->id, 'active');

        $credA = CredentialService::issue($siteA, ['events:write']);
        $credB = CredentialService::issue($siteB, ['events:write']);

        // Send batch with an injected website_id in the body (must be ignored)
        $batch = [
            'website_id' => $siteB->id,
            'events' => [
                [
                    'id' => 'evt-100',
                    'type' => 'checkout.started',
                    'occurred_at' => '2026-11-10T00:10:00+06:00',
                    'payload' => ['cart' => 1500],
                ],
                [
                    'id' => 'evt-101',
                    'type' => 'order.placed',
                    'payload' => ['total' => 2500],
                ],
            ],
        ];

        $this->sendSigned('/api/v1/connector/events', $batch, $credA['credential']->key_id, $credA['secret'])
            ->assertStatus(202)
            ->assertExactJson(['ok' => true, 'accepted' => 2, 'duplicates' => 0]);

        // Re-sending identical events increments duplicates and creates zero new rows
        $this->sendSigned('/api/v1/connector/events', $batch, $credA['credential']->key_id, $credA['secret'])
            ->assertStatus(202)
            ->assertExactJson(['ok' => true, 'accepted' => 0, 'duplicates' => 2]);

        $this->assertSame(2, DB::table('integration_events')->where('website_id', $siteA->id)->count());
        $this->assertSame(0, DB::table('integration_events')->where('website_id', $siteB->id)->count());

        // Same source_event_id on website B is accepted separately
        $this->sendSigned('/api/v1/connector/events', [
            'events' => [
                ['id' => 'evt-100', 'type' => 'checkout.started', 'payload' => ['cart' => 900]],
            ],
        ], $credB['credential']->key_id, $credB['secret'])
            ->assertStatus(202)
            ->assertExactJson(['ok' => true, 'accepted' => 1, 'duplicates' => 0]);

        // Validation errors return 422 JSON
        $this->sendSigned('/api/v1/connector/events', ['events' => []], $credA['credential']->key_id, $credA['secret'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);

        // Batch > 100 events returns 422
        $tooMany = [];
        for ($i = 0; $i < 101; $i++) {
            $tooMany[] = ['id' => 'e-'.$i, 'type' => 'ping'];
        }
        $this->sendSigned('/api/v1/connector/events', ['events' => $tooMany], $credA['credential']->key_id, $credA['secret'])
            ->assertStatus(422);

        // Single event payload > 64 KB returns 422
        $this->sendSigned('/api/v1/connector/events', [
            'events' => [
                ['id' => 'big-evt', 'type' => 'blob', 'payload' => ['data' => str_repeat('x', 66_000)]],
            ],
        ], $credA['credential']->key_id, $credA['secret'])
            ->assertStatus(422);
    }

    public function test_independent_hmac_vector_matches_server_and_prune_removes_old_nonces(): void
    {
        $t = $this->makeTenantWithUsers('Vector Co');
        $site = $this->createWebsite($t['tenant']->id, 'active');
        $issued = CredentialService::issue($site, ['heartbeat']);

        // Manually construct canonical string matching docs/API_CONTRACT.md
        $ts = time();
        $nonce = 'IndepVectorNonce12345';
        $rawBody = '{"connector_version":"2.0.0"}';
        $canonical = "POST\n/api/v1/connector/heartbeat\n{$ts}\n{$nonce}\n".hash('sha256', $rawBody);
        $sig = strtolower(hash_hmac('sha256', $canonical, $issued['secret']));

        $this->call('POST', '/api/v1/connector/heartbeat', [], [], [], [
            'HTTP_X_LYP_KEY' => $issued['credential']->key_id,
            'HTTP_X_LYP_TIMESTAMP' => (string) $ts,
            'HTTP_X_LYP_NONCE' => $nonce,
            'HTTP_X_LYP_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $rawBody)->assertOk();

        // Insert an old nonce (16 min ago) and run lyp:prune
        DB::table('request_nonces')->insert([
            'credential_id' => $issued['credential']->id,
            'nonce' => 'old_nonce_exceeded_15m',
            'created_at' => now()->subMinutes(16)->format('Y-m-d H:i:s'),
        ]);

        $this->artisan('lyp:prune')->assertSuccessful();

        $this->assertDatabaseMissing('request_nonces', ['nonce' => 'old_nonce_exceeded_15m']);
        $this->assertDatabaseHas('request_nonces', ['nonce' => $nonce]);
    }

    public function test_connector_rate_limit_returns_429_json(): void
    {
        RateLimiter::clear('connector');

        $t = $this->makeTenantWithUsers('Rate Co');
        $site = $this->createWebsite($t['tenant']->id, 'active');
        $issued = CredentialService::issue($site, ['heartbeat']);

        // 120 requests allowed per key per minute; 121st triggers 429
        for ($i = 1; $i <= 120; $i++) {
            $resp = $this->sendSigned(
                '/api/v1/connector/heartbeat',
                ['connector_version' => '1.0.0'],
                $issued['credential']->key_id,
                $issued['secret']
            );
            $resp->assertOk();
        }

        $limited = $this->sendSigned(
            '/api/v1/connector/heartbeat',
            ['connector_version' => '1.0.0'],
            $issued['credential']->key_id,
            $issued['secret']
        );
        $limited->assertStatus(429)
            ->assertExactJson(['ok' => false, 'error' => 'rate_limited']);
    }
}
