<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use App\Services\CredentialService;
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
            return $this->fail(401, 'missing_headers');
        }

        $contentLength = (int) $request->header('Content-Length', 0);
        $rawBody = (string) $request->getContent();
        if ($contentLength > self::MAX_BODY_BYTES || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->fail(413, 'payload_too_large');
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return $this->fail(401, 'stale_timestamp');
        }

        if (! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) {
            return $this->fail(401, 'bad_nonce');
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

            return $this->fail(401, 'invalid_credentials');
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
            return $this->fail(401, 'invalid_credentials');
        }

        if ($requiredScope !== null && ! in_array($requiredScope, $credential->scopes ?? [], true)) {
            return $this->fail(403, 'insufficient_scope');
        }

        $inserted = DB::table('request_nonces')->insertOrIgnore([
            'credential_id' => $credential->id,
            'nonce' => $nonce,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        if ($inserted === 0) {
            return $this->fail(401, 'replayed_request');
        }

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('connector_credential', $credential);
        $request->attributes->set('connector_website', $website);

        return $next($request);
    }

    private function fail(int $status, string $code): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $code,
        ], $status);
    }
}
