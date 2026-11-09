<?php

namespace App\Services;

use App\Support\TenantCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MetricsService
{
    public function __construct(private readonly int $tenantId)
    {
    }

    public static function for(int $tenantId): self
    {
        return new self($tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return TenantCache::remember(
            $this->tenantId,
            'dashboard',
            fn (): array => $this->computeDashboard()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuildDashboard(): array
    {
        $data = $this->computeDashboard();
        TenantCache::put($this->tenantId, 'dashboard', $data);

        return $data;
    }

    /**
     * Always filter explicitly by tenant_id so background jobs/CLI work safely without a signed-in user.
     *
     * @return array<string, mixed>
     */
    private function computeDashboard(): array
    {
        $now = now();
        $nowStr = $now->format('Y-m-d H:i:s');
        $cutoff10m = $now->copy()->subMinutes(10)->format('Y-m-d H:i:s');
        $cutoff24h = $now->copy()->subHours(24)->format('Y-m-d H:i:s');
        $cutoff30d = $now->copy()->subDays(30)->format('Y-m-d H:i:s');

        $websites = DB::table('websites')
            ->where('tenant_id', $this->tenantId)
            ->count();

        $connected = DB::table('websites')
            ->where('tenant_id', $this->tenantId)
            ->where('status', '!=', 'disabled')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', $cutoff10m)
            ->count();

        $activeCredentials = DB::table('api_credentials')
            ->join('websites', 'websites.id', '=', 'api_credentials.website_id')
            ->where('websites.tenant_id', $this->tenantId)
            ->whereNull('api_credentials.revoked_at')
            ->where(function ($q) use ($nowStr): void {
                $q->whereNull('api_credentials.expires_at')
                    ->orWhere('api_credentials.expires_at', '>', $nowStr);
            })
            ->count();

        $events24h = DB::table('integration_events')
            ->join('websites', 'websites.id', '=', 'integration_events.website_id')
            ->where('websites.tenant_id', $this->tenantId)
            ->where('integration_events.received_at', '>=', $cutoff24h)
            ->count();

        $orders30d = Schema::hasTable('orders')
            ? DB::table('orders')
                ->where('tenant_id', $this->tenantId)
                ->where('ordered_at', '>=', $cutoff30d)
                ->count()
            : 0;

        $products = Schema::hasTable('products')
            ? DB::table('products')
                ->where('tenant_id', $this->tenantId)
                ->count()
            : 0;

        $openFindings = Schema::hasTable('research_findings')
            ? DB::table('research_findings')
                ->where('tenant_id', $this->tenantId)
                ->whereIn('status', ['new', 'reviewing', 'approved', 'testing'])
                ->count()
            : 0;

        return [
            'websites' => $websites,
            'connected' => $connected,
            'active_credentials' => $activeCredentials,
            'events_24h' => $events24h,
            'orders_30d' => $orders30d,
            'products' => $products,
            'open_findings' => $openFindings,
            'computed_at' => $now->toIso8601String(),
            'updated_hhmm' => $now->format('H:i'),
            'ttl' => TenantCache::ttl(),
        ];
    }
}
