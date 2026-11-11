<?php

namespace App\Services\Chat;

use App\Models\Chatbot;
use App\Support\Domain;
use Illuminate\Http\Request;

class WidgetTokenService
{
    public const DEFAULT_TTL_SECONDS = 900; // 15 minutes short-lived widget token

    public const MAX_TTL_SECONDS = 3600;

    /**
     * Issue a short-lived, domain-restricted HMAC-signed token for a single chatbot.
     *
     * @return array{token: string, expires_at: int, ttl_seconds: int, domain: string}
     */
    public function issue(Chatbot $chatbot, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $ttl = max(60, min(self::MAX_TTL_SECONDS, $ttlSeconds));
        $exp = now()->getTimestamp() + $ttl;
        $payload = [
            'cb' => (int) $chatbot->id,
            't' => (int) $chatbot->tenant_id,
            'dom' => (string) $chatbot->widget_domain,
            'exp' => $exp,
            'jti' => bin2hex(random_bytes(8)),
        ];

        $b64 = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = hash_hmac('sha256', $b64, (string) config('app.key'));

        return [
            'token' => 'lyw1.'.$b64.'.'.$sig,
            'expires_at' => $exp,
            'ttl_seconds' => $ttl,
            'domain' => (string) $chatbot->widget_domain,
        ];
    }

    /**
     * Verify signed token expiry, chatbot status, and Origin/Referer domain restriction.
     *
     * @return array{ok: bool, error: ?string, status: int, chatbot: ?Chatbot, tenant_id: ?int, website_id: ?int}
     */
    public function verifyRequest(Request $request): array
    {
        $rawToken = $this->extractToken($request);
        if ($rawToken === '') {
            return [
                'ok' => false,
                'error' => 'missing_widget_token',
                'status' => 401,
                'chatbot' => null,
                'tenant_id' => null,
                'website_id' => null,
            ];
        }

        if (! str_starts_with($rawToken, 'lyw1.')) {
            return [
                'ok' => false,
                'error' => 'invalid_widget_token',
                'status' => 401,
                'chatbot' => null,
                'tenant_id' => null,
                'website_id' => null,
            ];
        }

        $parts = explode('.', $rawToken);
        if (count($parts) !== 3) {
            return [
                'ok' => false,
                'error' => 'invalid_widget_token',
                'status' => 401,
                'chatbot' => null,
                'tenant_id' => null,
                'website_id' => null,
            ];
        }

        [, $b64, $sig] = $parts;
        $expectedSig = hash_hmac('sha256', $b64, (string) config('app.key'));
        if (! hash_equals($expectedSig, $sig)) {
            return [
                'ok' => false,
                'error' => 'invalid_widget_token',
                'status' => 401,
                'chatbot' => null,
                'tenant_id' => null,
                'website_id' => null,
            ];
        }

        $decoded = $this->base64UrlDecode($b64);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($payload) || ! isset($payload['cb'], $payload['t'], $payload['dom'], $payload['exp'])) {
            return [
                'ok' => false,
                'error' => 'invalid_widget_token',
                'status' => 401,
                'chatbot' => null,
                'tenant_id' => null,
                'website_id' => null,
            ];
        }

        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('tenant_id', (int) $payload['t'])
            ->find((int) $payload['cb']);

        if ($chatbot === null) {
            return [
                'ok' => false,
                'error' => 'chatbot_not_found',
                'status' => 401,
                'chatbot' => null,
                'tenant_id' => (int) $payload['t'],
                'website_id' => null,
            ];
        }

        if ((int) $payload['exp'] < now()->getTimestamp()) {
            return [
                'ok' => false,
                'error' => 'widget_token_expired',
                'status' => 401,
                'chatbot' => $chatbot,
                'tenant_id' => (int) $chatbot->tenant_id,
                'website_id' => (int) $chatbot->website_id,
            ];
        }

        if (! $chatbot->enabled) {
            return [
                'ok' => false,
                'error' => 'chatbot_disabled',
                'status' => 403,
                'chatbot' => $chatbot,
                'tenant_id' => (int) $chatbot->tenant_id,
                'website_id' => (int) $chatbot->website_id,
            ];
        }

        // Strict Origin / Referer domain check against both token claim and configured widget_domain
        $requestDomain = $this->extractRequestDomain($request);
        if (
            $requestDomain === null
            || ! hash_equals((string) $chatbot->widget_domain, $requestDomain)
            || ! hash_equals((string) $payload['dom'], $requestDomain)
        ) {
            return [
                'ok' => false,
                'error' => 'domain_mismatch',
                'status' => 403,
                'chatbot' => $chatbot,
                'tenant_id' => (int) $chatbot->tenant_id,
                'website_id' => (int) $chatbot->website_id,
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'status' => 200,
            'chatbot' => $chatbot,
            'tenant_id' => (int) $chatbot->tenant_id,
            'website_id' => (int) $chatbot->website_id,
        ];
    }

    /**
     * Extract tenant/website IDs strictly from HMAC-verified token signature (for rate-limit telemetry even if expired).
     *
     * @return ?array{tenant_id: int, website_id: ?int, chatbot_id: int}
     */
    public function verifiedSignatureContext(Request $request): ?array
    {
        $rawToken = $this->extractToken($request);
        if ($rawToken === '' || ! str_starts_with($rawToken, 'lyw1.')) {
            return null;
        }

        $parts = explode('.', $rawToken);
        if (count($parts) !== 3) {
            return null;
        }

        [, $b64, $sig] = $parts;
        $expectedSig = hash_hmac('sha256', $b64, (string) config('app.key'));
        if (! hash_equals($expectedSig, $sig)) {
            return null;
        }

        $decoded = $this->base64UrlDecode($b64);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($payload) || ! isset($payload['cb'], $payload['t'])) {
            return null;
        }

        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('tenant_id', (int) $payload['t'])
            ->find((int) $payload['cb']);

        if ($chatbot === null) {
            return null;
        }

        return [
            'tenant_id' => (int) $chatbot->tenant_id,
            'website_id' => (int) $chatbot->website_id,
            'chatbot_id' => (int) $chatbot->id,
        ];
    }

    public function extractRequestDomain(Request $request): ?string
    {
        $origin = trim((string) $request->header('Origin', ''));
        if ($origin !== '' && strtolower($origin) !== 'null') {
            $dom = Domain::normalize($origin);
            if ($dom !== null) {
                return $dom;
            }
        }

        $referer = trim((string) $request->header('Referer', ''));
        if ($referer !== '') {
            return Domain::normalize($referer);
        }

        return null;
    }

    private function extractToken(Request $request): string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && trim($bearer) !== '') {
            return trim($bearer);
        }

        return trim((string) $request->header('X-Widget-Token', ''));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $padded = strtr($data, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
