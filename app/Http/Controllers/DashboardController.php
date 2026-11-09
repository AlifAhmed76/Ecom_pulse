<?php

namespace App\Http\Controllers;

use App\Jobs\WarmTenantCache;
use App\Models\AuditLog;
use App\Services\MetricsService;
use App\Support\TenantCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $metrics = MetricsService::for($user->tenant_id)->dashboard();

        return view('dashboard', [
            'user' => $user,
            'tenant' => $tenant,
            'metrics' => $metrics,
        ]);
    }

    public function json(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;
        $payload = MetricsService::for($tenantId)->dashboard();
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $etag = '"'.sha1($tenantId.'|'.$json).'"';

        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'private, max-age=60, stale-while-revalidate=120');
        }

        return response($json, 200)
            ->header('Content-Type', 'application/json')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, max-age=60, stale-while-revalidate=120');
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();
        TenantCache::clear($user->tenant_id);
        WarmTenantCache::dispatch($user->tenant_id)->onQueue('high');

        AuditLog::record('cache.clear', 'Cleared tenant cache from dashboard', $user->tenant_id, $user->id);

        return redirect()
            ->route('dashboard')
            ->with('ok', 'Dashboard cache cleared and background refresh queued.');
    }
}
