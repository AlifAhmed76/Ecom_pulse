@extends('layouts.app')

@section('title', 'Dashboard - Last Year Project')

@section('content')
<div id="dashboard-root" data-metrics-url="{{ route('metrics.dashboard.json') }}">
    <div class="page-header">
        <div>
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle">Workspace snapshot for {{ $tenant->name }}</p>
        </div>
        <div class="page-actions">
            <span id="dashboard-updated" class="badge badge--neutral">Updated {{ $metrics['updated_hhmm'] ?? '--:--' }}</span>
            @if($user->canManage())
                <form method="POST" action="{{ route('dashboard.refresh') }}">
                    @csrf
                    <button type="submit" class="btn btn--secondary">Refresh data</button>
                </form>
            @endif
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Websites</div>
            <div class="kpi-value" data-metric="websites">{{ number_format((int) ($metrics['websites'] ?? 0)) }}</div>
            <div class="kpi-meta">Registered stores</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Connected</div>
            <div class="kpi-value" data-metric="connected">{{ number_format((int) ($metrics['connected'] ?? 0)) }}</div>
            <div class="kpi-meta">Heartbeat &lt; 10 min</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Active Credentials</div>
            <div class="kpi-value" data-metric="active_credentials">{{ number_format((int) ($metrics['active_credentials'] ?? 0)) }}</div>
            <div class="kpi-meta">Usable API keys</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Events (24h)</div>
            <div class="kpi-value" data-metric="events_24h">{{ number_format((int) ($metrics['events_24h'] ?? 0)) }}</div>
            <div class="kpi-meta">Ingested connector events</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Orders (30d)</div>
            <div class="kpi-value" data-metric="orders_30d">{{ number_format((int) ($metrics['orders_30d'] ?? 0)) }}</div>
            <div class="kpi-meta">Synced or imported</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Open Findings</div>
            <div class="kpi-value" data-metric="open_findings">{{ number_format((int) ($metrics['open_findings'] ?? 0)) }}</div>
            <div class="kpi-meta">R&amp;D action queue</div>
        </div>
    </div>

    <div class="quick-links">
        <a href="{{ route('orders.index') }}" class="quick-link-card">
            <span class="quick-link-title">Orders &amp; Status History</span>
            <span class="quick-link-desc">Inspect normalised orders, items, and status transitions.</span>
        </a>
        <a href="{{ route('products.index') }}" class="quick-link-card">
            <span class="quick-link-title">Products &amp; Stock</span>
            <span class="quick-link-desc">Monitor SKU pricing, unit cost coverage, and low-stock items.</span>
        </a>
        <a href="{{ route('analytics.index') }}" class="quick-link-card">
            <span class="quick-link-title">Analytics</span>
            <span class="quick-link-desc">Inspect KPIs, courier &amp; staff scorecards, stock cover, and data quality.</span>
        </a>
        <a href="{{ route('courier-loss.index') }}" class="quick-link-card">
            <span class="quick-link-title">Courier &amp; Loss</span>
            <span class="quick-link-desc">Track estimated sales not realised, courier outliers, and advisory risk flags.</span>
        </a>
        <a href="{{ route('marketing.index') }}" class="quick-link-card">
            <span class="quick-link-title">Marketing &amp; Real ROAS</span>
            <span class="quick-link-desc">Compare platform-reported ad metrics against real UTM-matched delivered orders.</span>
        </a>
        <a href="{{ route('action-center.index') }}" class="quick-link-card">
            <span class="quick-link-title">Action Center (R&amp;D Lab)</span>
            <span class="quick-link-desc">Review deterministic findings, hypotheses, and weekly actions in English &amp; Bangla.</span>
        </a>
        <a href="{{ route('experiments.index') }}" class="quick-link-card">
            <span class="quick-link-title">Experiments (R&amp;D Tracker)</span>
            <span class="quick-link-desc">Approve hypotheses, lock baselines, record results, and capture validated lessons.</span>
        </a>
        <a href="{{ route('websites.index') }}" class="quick-link-card">
            <span class="quick-link-title">Websites &amp; Credentials</span>
            <span class="quick-link-desc">Register store domains and issue signed API keys for connectors.</span>
        </a>
        <a href="{{ route('chatbots.index') }}" class="quick-link-card">
            <span class="quick-link-title">Chatbots &amp; Knowledge Base</span>
            <span class="quick-link-desc">Configure grounded store chat widgets, KB FAQs, leads, and handoff.</span>
        </a>
        @if($user->canManage())
            <a href="{{ route('security.index') }}" class="quick-link-card">
                <span class="quick-link-title">Security Events &amp; Rules</span>
                <span class="quick-link-desc">Review login failures, rate limits, probes, and opt-in rules.</span>
            </a>
            <a href="{{ route('workspace.show') }}" class="quick-link-card">
                <span class="quick-link-title">Workspace &amp; Members</span>
                <span class="quick-link-desc">Inspect company workspace isolation and active user roles.</span>
            </a>
            <a href="{{ route('system.index') }}" class="quick-link-card">
                <span class="quick-link-title">System &amp; Cache</span>
                <span class="quick-link-desc">View cache TTL, worker status, and refresh metrics safely.</span>
            </a>
            <a href="{{ route('audit.index') }}" class="quick-link-card">
                <span class="quick-link-title">Audit Ledger</span>
                <span class="quick-link-desc">Verify hash-chain integrity across workspace operations.</span>
            </a>
        @endif
    </div>
</div>
@endsection
