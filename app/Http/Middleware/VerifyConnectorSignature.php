<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use App\Models\Website;
use App\Services\CredentialService;
use App\Services\Security\SecurityEventService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VerifyConnectorSignature
{
    private const MAX_BODY_BYTES = 1_048_576;

    private const MAX_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $keyId = (string) $request->header('X-LYP-Key', '');
        $timestamp = (string) $request->header('X-LYP-Timestamp', '');
        $nonce = (string) $request->header('X-LYP-Nonce', '');
        $signature = (string) $request->header('X-LYP-Signature', '');

        if ($keyId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->fail($request, 401, 'missing_headers');
        }

        $contentLength = (int) $request->header('Content-Length', 0);
        $rawBody = (string) $request->getContent();
        if ($contentLength > self::MAX_BODY_BYTES || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->fail($request, 413, 'payload_too_large');
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return $this->fail($request, 401, 'stale_timestamp', keyId: $keyId);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) {
            return $this->fail($request, 401, 'bad_nonce', keyId: $keyId);
        }

        $credential = ApiCredential::query()
            ->with(['website' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('key_id', $keyId)
            ->first();

        $website = $credential?->website;

        if (
            $credential === null
            || ! $credential->isUsable()
            || $website === null
            || $website->status === 'disabled'
        ) {
            // Match work profile of HMAC verification before rejecting identically.
            hash_hmac('sha256', $rawBody, 'dummy-secret');

            return $this->fail($request, 401, 'invalid_credentials', $website);
        }

        $secret = CredentialService::secretOf($credential);
        $canonical = implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $timestamp,
            $nonce,
            hash('sha256', $rawBody),
        ]);
        $expected = hash_hmac('sha256', $canonical, $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->fail($request, 401, 'invalid_credentials', $website);
        }

        if ($requiredScope !== null && ! in_array($requiredScope, $credential->scopes ?? [], true)) {
            return $this->fail($request, 403, 'insufficient_scope', $website);
        }

        $inserted = DB::table('request_nonces')->insertOrIgnore([
            'credential_id' => $credential->id,
            'nonce' => $nonce,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        if ($inserted === 0) {
            return $this->fail($request, 401, 'replayed_request', $website);
        }

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('connector_credential', $credential);
        $request->attributes->set('connector_website', $website);

        return $next($request);
    }

    private function fail(
        Request $request,
        int $status,
        string $code,
        ?Website $website = null,
        ?string $keyId = null
    ): JsonResponse {
        if ($website === null && $keyId !== null && $keyId !== '') {
            $website = ApiCredential::query()
                ->with(['website' => fn ($q) => $q->withoutGlobalScopes()])
                ->where('key_id', $keyId)
                ->first()?->website;
        }

        app(SecurityEventService::class)->record(
            'signature_failed',
            in_array($code, ['replayed_request', 'invalid_credentials'], true) ? 'high' : 'medium',
            $website?->tenant_id !== null ? (int) $website->tenant_id : null,
            $website?->id !== null ? (int) $website->id : null,
            $request,
            ['reason' => $code]
        );

        return response()->json([
            'ok' => false,
            'error' => $code,
        ], $status);
    }
}
