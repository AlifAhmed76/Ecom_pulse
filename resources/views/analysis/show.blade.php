@extends('layouts.app')

@section('title', 'Analysis: ' . $analysis->filename)

@section('content')
@php
    $r = $analysis->result ?? [];
    $daily = $r['daily_trend'] ?? [];
    $statusCounts = $r['status_counts'] ?? [];
    $topProducts = $r['top_products'] ?? [];

    $dailyChartConfig = [
        'type' => 'bar',
        'data' => [
            'labels' => array_keys($daily),
            'datasets' => [[
                'label' => 'Gross Revenue (non-cancelled)',
                'data' => array_values(array_map(fn ($d) => (float) ($d['revenue'] ?? 0), $daily)),
                'backgroundColor' => '#0F766E',
                'borderRadius' => 4,
            ]],
        ],
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
        ],
    ];

    $statusChartConfig = [
        'type' => 'doughnut',
        'data' => [
            'labels' => array_map('ucfirst', array_keys(array_filter($statusCounts))),
            'datasets' => [[
                'data' => array_values(array_filter($statusCounts)),
                'backgroundColor' => ['#D97706', '#0F766E', '#1E40AF', '#059669', '#DC2626', '#9333EA', '#6B7280'],
            ]],
        ],
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
        ],
    ];
@endphp

<div class="page-header">
    <div>
        <h1 class="page-title">{{ $analysis->filename }}</h1>
        <p class="page-subtitle">Read-only snapshot analyzed {{ $analysis->created_at?->format('Y-m-d H:i') }} (no orders saved to database).</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('analysis.index') }}" class="btn btn--secondary">New file analysis</a>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Orders Found</div>
        <div class="kpi-value">{{ number_format((int) ($r['orders_count'] ?? 0)) }}</div>
        <div class="kpi-meta">{{ number_format((int) ($r['rows_read'] ?? 0)) }} rows read</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Gross Sales</div>
        <div class="kpi-value">{{ number_format((float) ($r['gross_sales'] ?? 0), 2) }}</div>
        <div class="kpi-meta">Non-cancelled orders</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Delivered Revenue</div>
        <div class="kpi-value">{{ number_format((float) ($r['delivered_revenue'] ?? 0), 2) }}</div>
        <div class="kpi-meta">Delivered status</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">AOV / Cancel Rate</div>
        <div class="kpi-value">{{ number_format((float) ($r['aov'] ?? 0), 2) }}</div>
        <div class="kpi-meta">Cancel rate: {{ number_format((float) ($r['cancellation_rate'] ?? 0), 1) }}%</div>
    </div>
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Daily Gross Revenue Trend</h2>
        </div>
        <div class="chart-box">
            <canvas data-chart-source="chart-daily-json"></canvas>
        </div>
        <script type="application/json" id="chart-daily-json">@json($dailyChartConfig)</script>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Status Breakdown</h2>
        </div>
        <div class="chart-box">
            <canvas data-chart-source="chart-status-json"></canvas>
        </div>
        <script type="application/json" id="chart-status-json">@json($statusChartConfig)</script>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Top Products by Revenue</h2>
    </div>
    @if(empty($topProducts))
        <div class="empty-state">No item-level product revenue detected (whole-order file format).</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Estimated Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($topProducts as $prodName => $rev)
                        <tr>
                            <td>{{ $prodName }}</td>
                            <td class="num">{{ number_format((float) $rev, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/chart.umd.js') }}?v={{ filemtime(public_path('js/chart.umd.js')) }}" defer></script>
@endpush
