@extends('layouts.app')

@section('title', 'System - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">System Status</h1>
        <p class="page-subtitle">Cache health, background workers, and workspace runtime configuration.</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('system.cache-clear') }}">
            @csrf
            <button type="submit" class="btn btn--primary">Clear workspace cache</button>
        </form>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Cache Store</div>
        <div class="kpi-value">{{ $cacheStore }}</div>
        <div class="kpi-meta">TTL {{ number_format($cacheTtl) }} s ({{ intdiv($cacheTtl, 60) }} min)</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Scheduler Status</div>
        <div class="kpi-value">
            @if($schedulerAlive)
                <span class="badge badge--ok">Running</span>
            @else
                <span class="badge badge--warn">Not running</span>
            @endif
        </div>
        <div class="kpi-meta">Heartbeat checked per minute</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Queue Worker</div>
        <div class="kpi-value">
            @if($workerAlive)
                <span class="badge badge--ok">Running</span>
            @else
                <span class="badge badge--warn">Not running</span>
            @endif
        </div>
        <div class="kpi-meta">Database queue worker status</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">LLM Driver</div>
        <div class="kpi-value">{{ $llmDriver }}</div>
        <div class="kpi-meta">{{ $llmDriver === 'none' ? 'Disabled (safe offline default)' : ($llmModel !== '' ? $llmModel : 'Configured') }}</div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Workspace Cache Metadata</h2>
        <span class="badge badge--neutral">Tenant Isolated</span>
    </div>
    <div class="kv-grid">
        <div>
            <div class="kv-item-label">Last Dashboard Computation</div>
            <div class="kv-item-value mono">{{ $computedAt ?? 'Not computed yet' }}</div>
        </div>
        <div>
            <div class="kv-item-label">Configured Cache TTL</div>
            <div class="kv-item-value">{{ number_format($cacheTtl) }} seconds</div>
        </div>
        <div>
            <div class="kv-item-label">Required Workers</div>
            <div class="kv-item-value mono">php artisan queue:work --queue=high,default,ai --timeout=180</div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">Optional AI / LLM Configuration</h2>
            <p class="help-text">Read-only explanation layer (`ai` queue). Monthly usage shown for this tenant only.</p>
        </div>
        <form method="POST" action="{{ route('system.llm-ping') }}">
            @csrf
            <button type="submit" class="btn btn--secondary">Test connection</button>
        </form>
    </div>

    <div class="kv-grid mb-sm">
        <div>
            <div class="kv-item-label">Configured Driver</div>
            <div class="kv-item-value mono">{{ $llmDriver }}</div>
        </div>
        <div>
            <div class="kv-item-label">Configured Model</div>
            <div class="kv-item-value mono">{{ $llmModel !== '' ? $llmModel : '— (disabled)' }}</div>
        </div>
        <div>
            <div class="kv-item-label">Monthly Tokens Used / Budget</div>
            <div class="kv-item-value mono">
                {{ number_format((int) ($llmUsage['total_tokens'] ?? 0)) }} / {{ number_format((int) ($llmUsage['budget'] ?? 0)) }}
            </div>
        </div>
        <div>
            <div class="kv-item-label">Calls This Month</div>
            <div class="kv-item-value mono">{{ number_format((int) ($llmUsage['calls_count'] ?? 0)) }} (remaining: {{ number_format((int) ($llmUsage['remaining'] ?? 0)) }} tokens)</div>
        </div>
    </div>

    @if(session('llm_ping'))
        @php $ping = session('llm_ping'); @endphp
        <div class="baseline-box">
            <div class="panel-head">
                <span class="badge {{ ! empty($ping['ok']) ? 'badge--ok' : 'badge--warn' }}">
                    {{ ! empty($ping['ok']) ? 'Connection OK' : 'Not Connected' }}
                </span>
                <span class="mono help-text">Latency: {{ (int) ($ping['latency_ms'] ?? 0) }} ms</span>
            </div>
            <p>{{ $ping['message'] ?? '' }}</p>
        </div>
    @endif

    @if($llmDriver === 'none')
        <div class="alert alert--info mb-sm" role="note">
            AI is currently off (`LLM_DRIVER=none`). All Action Center rules and Bangla/English templates work normally without AI. See <span class="mono">docs/LLM_SETUP.md</span> for offline Ollama instructions.
        </div>
    @endif
</div>
@endsection
