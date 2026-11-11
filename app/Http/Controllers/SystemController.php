<?php

namespace App\Http\Controllers;

use App\Jobs\WarmTenantCache;
use App\Models\AuditLog;
use App\Services\Llm\LlmClient;
use App\Services\MetricsService;
use App\Support\TenantCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SystemController extends Controller
{
    public function index(Request $request, LlmClient $llm): View
    {
        $user = $request->user();
        $metrics = MetricsService::for($user->tenant_id)->dashboard();

        return view('system.index', [
            'cacheStore' => config('cache.default'),
            'cacheTtl' => TenantCache::ttl(),
            'computedAt' => $metrics['computed_at'] ?? null,
            'schedulerAlive' => Cache::get('sys:scheduler_beat') !== null,
            'workerAlive' => Cache::get('sys:worker_beat') !== null,
            'llmDriver' => $llm->driver(),
            'llmModel' => $llm->model(),
            'llmUsage' => $llm->tenantUsageThisMonth((int) $user->tenant_id),
        ]);
    }

    public function pingLlm(Request $request, LlmClient $llm): RedirectResponse
    {
        $user = $request->user();
        $res = $llm->ping();

        AuditLog::record(
            'llm.ping',
            "Tested LLM driver={$res['driver']} ok=".($res['ok'] ? 'true' : 'false'),
            (int) $user->tenant_id,
            $user->id
        );

        return redirect()
            ->route('system.index')
            ->with('llm_ping', $res)
            ->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }

    public function clearCache(Request $request): RedirectResponse
    {
        $user = $request->user();
        TenantCache::clear($user->tenant_id);
        WarmTenantCache::dispatch($user->tenant_id)->onQueue('high');

        AuditLog::record('cache.clear', 'Cleared tenant cache from system page', $user->tenant_id, $user->id);

        return redirect()
            ->route('system.index')
            ->with('ok', 'Workspace cache cleared and refresh queued.');
    }
}
