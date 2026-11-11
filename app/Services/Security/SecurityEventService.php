<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityRule;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SecurityEventService
{
    private const BLOCKED_META_KEYS = [
        'password',
        'secret',
        'token',
        'signature',
        'authorization',
        'cookie',
        'phone',
        'mobile',
        'email',
        'raw_body',
        'payload',
        'key',
    ];

    /**
     * Guarantee every rule starts as 'log_only' by default for a tenant.
     *
     * @return Collection<int, SecurityRule>
     */
    public function ensureDefaultsForTenant(int $tenantId): Collection
    {
        $nowStr = now()->format('Y-m-d H:i:s');
        $defaults = [
            'login_failed' => 5,
            'rate_limit_hit' => 10,
            'signature_failed' => 5,
            'suspicious_request' => 10,
            'widget_auth_failed' => 10,
        ];

        foreach ($defaults as $type => $threshold) {
            DB::table('security_rules')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'type' => $type,
                'threshold' => $threshold,
                'mode' => 'log_only',
                'enabled' => true,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
        }

        return SecurityRule::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('type')
            ->get();
    }

    /**
     * Record a sanitized app-level security event (never stores raw IP, passwords, secrets, or tokens).
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(
        string $type,
        string $severity,
        ?int $tenantId,
        ?int $websiteId,
        Request $request,
        array $meta = []
    ): SecurityEvent {
        $ipHash = hash_hmac('sha256', (string) ($request->ip() ?? '0.0.0.0'), (string) config('app.key'));
        $rawPath = rawurldecode((string) $request->getRequestUri());
        $cleanPath = mb_substr(str_replace(["\r", "\n", "\0"], '', $rawPath), 0, 255, 'UTF-8');

        $cleanMeta = $this->scrubMeta($meta);

        $event = new SecurityEvent();
        $event->forceFill([
            'tenant_id' => $tenantId,
            'website_id' => $websiteId,
            'type' => mb_substr($type, 0, 60, 'UTF-8'),
            'severity' => in_array($severity, ['info', 'low', 'medium', 'high'], true) ? $severity : 'info',
            'ip_hash' => $ipHash,
            'path' => $cleanPath,
            'meta' => $cleanMeta,
            'review_status' => 'open',
            'created_at' => now(),
        ]);
        $event->save();

        return $event;
    }

    /**
     * Check whether a rule has explicitly been opted into 'throttle' mode by owner/admin
     * and whether this ip_hash exceeded its threshold in the window. Never throttles in default log_only mode.
     */
    public function shouldThrottle(int $tenantId, string $type, Request $request, int $windowMinutes = 5): bool
    {
        $rule = SecurityRule::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->first();

        if ($rule === null || ! $rule->enabled || $rule->mode !== 'throttle') {
            return false;
        }

        $ipHash = hash_hmac('sha256', (string) ($request->ip() ?? '0.0.0.0'), (string) config('app.key'));
        $cutoff = now()->subMinutes(max(1, $windowMinutes))->format('Y-m-d H:i:s');

        $count = DB::table('security_events')
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('ip_hash', $ipHash)
            ->where('created_at', '>=', $cutoff)
            ->count();

        return $count >= max(1, (int) $rule->threshold);
    }

    public function isSuspiciousProbePath(string $requestUri): bool
    {
        $decoded = str_replace(["\r", "\n", "\0"], '', rawurldecode($requestUri));
        $lower = strtolower($decoded);
        $patterns = [
            '/wp-admin',
            '/wp-login',
            '.php',
            '/.env',
            '/.git',
            '../',
            '<script',
            'eval-stdin',
        ];

        foreach ($patterns as $pat) {
            if (str_contains($lower, $pat)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $meta
     * @return array<array-key, mixed>
     */
    private function scrubMeta(array $meta): array
    {
        $out = [];
        foreach ($meta as $k => $v) {
            $keyLower = strtolower((string) $k);
            $blocked = false;
            foreach (self::BLOCKED_META_KEYS as $frag) {
                if ($keyLower === $frag || str_contains($keyLower, $frag)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                $out[$k] = '[redacted]';

                continue;
            }

            if (is_array($v)) {
                $out[$k] = $this->scrubMeta($v);
            } elseif (is_string($v)) {
                $clean = (string) preg_replace('/(lys_[A-Za-z0-9]{12,}|lyw1\.[A-Za-z0-9._-]+|sk-[A-Za-z0-9_-]{8,}|Bearer\s+\S+)/i', '[redacted]', $v);
                $out[$k] = mb_substr($clean, 0, 255, 'UTF-8');
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
