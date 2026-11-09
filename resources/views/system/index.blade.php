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
        <div class="kpi-meta">{{ $llmDriver === 'none' ? 'Disabled (optional T13)' : 'Configured' }}</div>
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
            <div class="kv-item-label">AI Assistance</div>
            <div class="kv-item-value muted">Optional explanations remain disabled until configured (driver: {{ $llmDriver }}).</div>
        </div>
    </div>
</div>
@endsection
