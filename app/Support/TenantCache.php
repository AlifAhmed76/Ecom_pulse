<?php

namespace App\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class TenantCache
{
    public static function ttl(): int
    {
        return (int) config('lyp.cache_ttl', 1800);
    }

    public static function versionKey(int $tenantId): string
    {
        return "t{$tenantId}:ver";
    }

    public static function version(int $tenantId): int
    {
        return (int) Cache::get(self::versionKey($tenantId), 1);
    }

    public static function key(int $tenantId, string $name): string
    {
        return 't'.$tenantId.':v'.self::version($tenantId).':'.$name;
    }

    public static function lockKey(int $tenantId, string $name): string
    {
        return 'lock:'.self::key($tenantId, $name);
    }

    public static function get(int $tenantId, string $name, mixed $default = null): mixed
    {
        return Cache::get(self::key($tenantId, $name), $default);
    }

    public static function put(int $tenantId, string $name, mixed $value, ?int $ttlSeconds = null): mixed
    {
        Cache::put(self::key($tenantId, $name), $value, $ttlSeconds ?? self::ttl());

        return $value;
    }

    public static function clear(int $tenantId): int
    {
        $verKey = self::versionKey($tenantId);
        $next = self::version($tenantId) + 1;
        Cache::forever($verKey, $next);

        return $next;
    }

    public static function remember(int $tenantId, string $name, callable $callback, ?int $ttlSeconds = null): mixed
    {
        $key = self::key($tenantId, $name);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $ttl = $ttlSeconds ?? self::ttl();
        $waitSeconds = (int) config('lyp.cache_lock_wait', 5);

        try {
            return Cache::lock("lock:{$key}", 10)->block($waitSeconds, function () use ($key, $callback, $ttl) {
                $fresh = Cache::get($key);
                if ($fresh !== null) {
                    return $fresh;
                }

                $computed = $callback();
                Cache::put($key, $computed, $ttl);

                return $computed;
            });
        } catch (LockTimeoutException) {
            $fallback = Cache::get($key);
            if ($fallback !== null) {
                return $fallback;
            }

            return $callback();
        }
    }
}
