@extends('layouts.app')

@section('title', 'Analytics - Last Year Project')

@section('content')
@php
    $filters = $analytics['filters'] ?? [];
    $kpis = $analytics['kpis'] ?? [];
    $contrib = $analytics['contribution'] ?? [];
    $daily = $analytics['daily_trend'] ?? [];
    $funnel = $analytics['status_funnel'] ?? [];
    $dq = $analytics['data_quality'] ?? ['score' => 100, 'indicators' => []];
    $websites = $analytics['websites'] ?? [];
    $currency = (string) ($analytics['currency'] ?? 'BDT');
    $mixedCurrency = (bool) ($analytics['mixed_currency'] ?? false);
    $rangeText = ($filters['from'] ?? '') . ' to ' . ($filters['to'] ?? '') . ' (Asia/Dhaka)';
    $websiteText = $filters['website_name'] ?? 'All websites';

    if ($mixedCurrency) {
        $dailyChartConfig = [
            'type' => 'bar',
            'data' => [
                'labels' => array_map(fn ($r) => $r['day'], $daily),
                'datasets' => [
                    [
                        'label' => 'Orders',
                        'data' => array_map(fn ($r) => (int) ($r['orders'] ?? 0), $daily),
                        'backgroundColor' => '#0F766E',
                        'borderRadius' => 4,
                    ],
                ],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
            ],
        ];
    } else {
        $dailyChartConfig = [
            'type' => 'bar',
            'data' => [
                'labels' => array_map(fn ($r) => $r['day'], $daily),
                'datasets' => [
                    [
                        'label' => 'Gross Sales (' . $currency . ')',
                        'data' => array_map(fn ($r) => (float) ($r['gross_sales'] ?? 0), $daily),
                        'backgroundColor' => '#0F766E',
                        'borderRadius' => 4,
                    ],
                    [
                        'label' => 'Delivered Revenue (' . $currency . ')',
                        'data' => array_map(fn ($r) => (float) ($r['delivered_revenue'] ?? 0), $daily),
                        'backgroundColor' => '#1E40AF',
                        'borderRadius' => 4,
                    ],
                ],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
            ],
        ];
    }

    $funnelChartConfig = [
        'type' => 'bar',
        'data' => [
            'labels' => array_map(fn ($r) => $r['label'], $funnel),
            'datasets' => [
                [
                    'label' => 'Orders',
                    'data' => array_map(fn ($r) => (int) ($r['count'] ?? 0), $funnel),
                    'backgroundColor' => ['#D97706', '#0F766E', '#1E40AF', '#059669', '#DC2626', '#9333EA', '#6B7280'],
                    'borderRadius' => 4,
                ],
            ],
        ],
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
        ],
    ];

    $topChartSlice = array_slice($analytics['top_products'] ?? [], 0, 10);
    $topProductsChartConfig = [
        'type' => 'bar',
        'data' => [
            'labels' => array_map(fn ($r) => $r['name'], $topChartSlice),
            'datasets' => [
                $mixedCurrency
                    ? [
                        'label' => 'Units (non-cancelled)',
                        'data' => array_map(fn ($r) => (int) ($r['units'] ?? 0), $topChartSlice),
                        'backgroundColor' => '#0F766E',
                        'borderRadius' => 4,
                    ]
                    : [
                        'label' => 'Revenue (' . $currency . ')',
                        'data' => array_map(fn ($r) => (float) ($r['revenue'] ?? 0), $topChartSlice),
                        'backgroundColor' => '#0F766E',
                        'borderRadius' => 4,
                    ],
            ],
        ],
        'options' => [
            'indexAxis' => 'y',
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
        ],
    ];
@endphp

<div class="page-header">
    <div>
        <h1 class="page-title">Analytics</h1>
        <p class="page-subtitle">
            Range: <strong>{{ $rangeText }}</strong> &middot;
            Store: <strong>{{ $websiteText }}</strong> &middot;
            Currency: <strong>{{ $currency }}</strong> &middot;
            Computed: <span class="mono">{{ $analytics['computed_at'] ?? '' }}</span>
        </p>
    </div>
    <div class="page-actions">
        <span class="badge badge--neutral">Updated {{ $analytics['updated_hhmm'] ?? '--:--' }}</span>
        <span class="badge {{ ($dq['score'] ?? 0) >= 85 ? 'badge--ok' : (($dq['score'] ?? 0) >= 60 ? 'badge--warn' : 'badge--danger') }}">
            Data Quality: {{ (int) ($dq['score'] ?? 0) }}/100
        </span>
    </div>
</div>

@if($mixedCurrency && ! empty($analytics['currency_warning']))
    <div class="alert alert--warn" role="alert">
        <strong>Mixed currencies detected:</strong> {{ $analytics['currency_warning'] }}
    </div>
@endif

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Filters</h2>
        <div class="inline-actions">
            <a href="{{ route('analytics.index', array_filter(['preset' => '7', 'website_id' => $filters['website_id'] ?? null])) }}"
               class="btn btn--sm {{ ($filters['preset'] ?? '') === '7' ? 'btn--primary' : 'btn--secondary' }}">Last 7 days</a>
            <a href="{{ route('analytics.index', array_filter(['preset' => '30', 'website_id' => $filters['website_id'] ?? null])) }}"
               class="btn btn--sm {{ ($filters['preset'] ?? '') === '30' ? 'btn--primary' : 'btn--secondary' }}">Last 30 days</a>
            <a href="{{ route('analytics.index', array_filter(['preset' => '90', 'website_id' => $filters['website_id'] ?? null])) }}"
               class="btn btn--sm {{ ($filters['preset'] ?? '') === '90' ? 'btn--primary' : 'btn--secondary' }}">Last 90 days</a>
        </div>
    </div>

    <form method="GET" action="{{ route('analytics.index') }}" class="filter-bar">
        <div class="field">
            <label for="preset">Date Range</label>
            <select id="preset" name="preset" class="select">
                <option value="7" @selected(($filters['preset'] ?? '') === '7')>Last 7 days</option>
                <option value="30" @selected(($filters['preset'] ?? '30') === '30')>Last 30 days</option>
                <option value="90" @selected(($filters['preset'] ?? '') === '90')>Last 90 days</option>
                <option value="custom" @selected(($filters['preset'] ?? '') === 'custom')>Custom dates</option>
            </select>
        </div>

        <div class="field">
            <label for="from">From (YYYY-MM-DD)</label>
            <input id="from" name="from" type="date" class="input" value="{{ $filters['from'] ?? '' }}">
        </div>

        <div class="field">
            <label for="to">To (YYYY-MM-DD)</label>
            <input id="to" name="to" type="date" class="input" value="{{ $filters['to'] ?? '' }}">
        </div>

        <div class="field">
            <label for="website_id">Website</label>
            <select id="website_id" name="website_id" class="select">
                <option value="">All websites</option>
                @foreach($websites as $site)
                    <option value="{{ $site['id'] }}" @selected((string) ($filters['website_id'] ?? '') === (string) $site['id'])>
                        {{ $site['name'] }} ({{ $site['currency'] }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field field--fit">
            <button type="submit" class="btn btn--primary">Apply</button>
            <a href="{{ route('analytics.index') }}" class="btn btn--ghost">Reset</a>
        </div>
    </form>
    <p class="help-text">To use custom start/end dates, select "Custom dates" in Date Range and click Apply. All dates use Asia/Dhaka inclusive bounds on order timestamp.</p>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Orders</div>
        <div class="kpi-value">{{ number_format((int) ($kpis['orders'] ?? 0)) }}</div>
        <div class="kpi-meta">{{ number_format((int) ($kpis['non_cancelled_orders'] ?? 0)) }} non-cancelled</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Gross Sales</div>
        <div class="kpi-value">
            @if($mixedCurrency || $kpis['gross_sales'] === null)
                Hidden
            @else
                {{ $currency }} {{ number_format((float) $kpis['gross_sales'], 2) }}
            @endif
        </div>
        <div class="kpi-meta">{{ $mixedCurrency ? 'Mixed currencies not comparable' : 'Status <> cancelled' }}</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Delivered Revenue</div>
        <div class="kpi-value">
            @if($mixedCurrency || $kpis['delivered_revenue'] === null)
                Hidden
            @else
                {{ $currency }} {{ number_format((float) $kpis['delivered_revenue'], 2) }}
            @endif
        </div>
        <div class="kpi-meta">{{ number_format((int) ($kpis['delivered_orders'] ?? 0)) }} delivered orders</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">AOV</div>
        <div class="kpi-value">
            @if($mixedCurrency || $kpis['aov'] === null)
                Hidden
            @else
                {{ $currency }} {{ number_format((float) $kpis['aov'], 2) }}
            @endif
        </div>
        <div class="kpi-meta">Gross sales / non-cancelled</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Delivery Rate</div>
        <div class="kpi-value">{{ number_format((float) ($kpis['delivery_rate'] ?? 0), 2) }}%</div>
        <div class="kpi-meta">{{ number_format((int) ($kpis['delivered_orders'] ?? 0)) }} / {{ number_format((int) ($kpis['orders'] ?? 0)) }} orders</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Cancellation Rate</div>
        <div class="kpi-value">{{ number_format((float) ($kpis['cancellation_rate'] ?? 0), 2) }}%</div>
        <div class="kpi-meta">
            {{ number_format((int) ($kpis['cancelled_orders'] ?? 0)) }} cancelled
            @if(! $mixedCurrency && $kpis['lost_to_cancel'] !== null)
                ({{ $currency }} {{ number_format((float) $kpis['lost_to_cancel'], 2) }})
            @endif
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Return Rate</div>
        <div class="kpi-value">{{ number_format((float) ($kpis['return_rate'] ?? 0), 2) }}%</div>
        <div class="kpi-meta">
            {{ number_format((int) ($kpis['returned_orders'] ?? 0)) }} returned / (delivered + returned)
            @if(! $mixedCurrency && $kpis['lost_to_return'] !== null)
                ({{ $currency }} {{ number_format((float) $kpis['lost_to_return'], 2) }})
            @endif
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Repeat Purchase Rate</div>
        <div class="kpi-value">{{ number_format((float) ($kpis['repeat_purchase_rate'] ?? 0), 2) }}%</div>
        <div class="kpi-meta">{{ number_format((int) ($kpis['repeat_buyers'] ?? 0)) }} / {{ number_format((int) ($kpis['buyers_in_range'] ?? 0)) }} buyers (&ge;2 lifetime non-cancelled)</div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">Estimated Contribution</h2>
            <p class="help-text">Before ads, shipping, returns, and overhead &mdash; not profit. Calculated on delivered order items with known unit cost.</p>
        </div>
        <div>
            @if($contrib['sufficient_coverage'] ?? false)
                <span class="badge badge--ok">Cost coverage: {{ number_format((float) ($contrib['cost_coverage_pct'] ?? 0), 1) }}%</span>
            @else
                <span class="badge badge--warn">Cost data insufficient ({{ number_format((float) ($contrib['cost_coverage_pct'] ?? 0), 1) }}% coverage)</span>
            @endif
        </div>
    </div>

    <div class="kv-grid">
        <div>
            <div class="kv-item-label">Estimated Contribution</div>
            <div class="kv-item-value tabular">
                @if($mixedCurrency || ($contrib['estimated_contribution'] ?? null) === null)
                    Hidden (mixed currencies)
                @else
                    {{ $currency }} {{ number_format((float) $contrib['estimated_contribution'], 2) }}
                @endif
            </div>
        </div>
        <div>
            <div class="kv-item-label">Cost Coverage %</div>
            <div class="kv-item-value tabular">{{ number_format((float) ($contrib['cost_coverage_pct'] ?? 0), 2) }}%</div>
        </div>
        <div>
            <div class="kv-item-label">Contribution Margin %</div>
            <div class="kv-item-value tabular">
                @if($mixedCurrency)
                    Hidden (mixed currencies)
                @elseif(! ($contrib['sufficient_coverage'] ?? false) || ($contrib['margin_pct'] ?? null) === null)
                    Hidden (&lt; 60% cost coverage &mdash; cost data insufficient)
                @else
                    {{ number_format((float) $contrib['margin_pct'], 2) }}%
                @endif
            </div>
        </div>
        <div>
            <div class="kv-item-label">Costed Delivered Item Revenue</div>
            <div class="kv-item-value tabular">
                @if($mixedCurrency || ($contrib['costed_revenue'] ?? null) === null)
                    Hidden
                @else
                    {{ $currency }} {{ number_format((float) $contrib['costed_revenue'], 2) }}
                @endif
            </div>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">{{ $mixedCurrency ? 'Daily Order Count Trend' : 'Daily Gross Sales & Delivered Revenue' }}</h2>
            <span class="badge badge--neutral">{{ $rangeText }}</span>
        </div>
        <div class="chart-box">
            <canvas data-chart-source="chart-analytics-daily-json"></canvas>
        </div>
        <script type="application/json" id="chart-analytics-daily-json">@json($dailyChartConfig)</script>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Status Funnel</h2>
            <span class="badge badge--neutral">{{ number_format((int) ($kpis['orders'] ?? 0)) }} orders</span>
        </div>
        <div class="chart-box">
            <canvas data-chart-source="chart-analytics-funnel-json"></canvas>
        </div>
        <script type="application/json" id="chart-analytics-funnel-json">@json($funnelChartConfig)</script>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">{{ $mixedCurrency ? 'Top Products (Units)' : 'Top Products Chart' }}</h2>
        <span class="badge badge--neutral">Top {{ count($topChartSlice) }}</span>
    </div>
    @if(empty($topChartSlice))
        <div class="empty-state">No product line-item data in selected range. Import an itemized order file or sync items via connector.</div>
    @else
        <div class="chart-box">
            <canvas data-chart-source="chart-analytics-top-products-json"></canvas>
        </div>
        <script type="application/json" id="chart-analytics-top-products-json">@json($topProductsChartConfig)</script>
    @endif
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Top Products Breakdown</h2>
        <span class="badge badge--neutral">{{ number_format($topProducts->total()) }} items</span>
    </div>
    @if($topProducts->total() === 0)
        <div class="empty-state">No product sales in the selected range. Use itemized imports or connector sync to populate product analytics.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="num">Units Sold</th>
                        <th class="num">Revenue</th>
                        <th class="num">Return Units</th>
                        <th class="num">Cancel Units</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($topProducts as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="mono muted">{{ $row['sku'] ?? '—' }}</td>
                            <td class="num">{{ number_format((int) $row['units']) }}</td>
                            <td class="num">
                                @if($mixedCurrency || $row['revenue'] === null)
                                    Hidden
                                @else
                                    {{ $currency }} {{ number_format((float) $row['revenue'], 2) }}
                                @endif
                            </td>
                            <td class="num">{{ number_format((int) $row['return_units']) }}</td>
                            <td class="num">{{ number_format((int) $row['cancel_units']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($topProducts->hasPages())
            <div class="pager">
                @if($topProducts->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $topProducts->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $topProducts->currentPage() }} of {{ $topProducts->lastPage() }}</span>
                @if($topProducts->hasMorePages())
                    <a href="{{ $topProducts->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Courier Scorecard</h2>
            <span class="badge badge--neutral">Shipped / Delivered / Returned</span>
        </div>
        @if($couriers->total() === 0)
            <div class="empty-state">No dispatched orders with courier names found in this range.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Courier</th>
                            <th class="num">Orders</th>
                            <th class="num">Delivered</th>
                            <th class="num">Returned</th>
                            <th class="num">Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($couriers as $row)
                            <tr>
                                <td>{{ $row['courier'] }}</td>
                                <td class="num">{{ number_format((int) $row['total']) }}</td>
                                <td class="num">{{ number_format((int) $row['delivered']) }}</td>
                                <td class="num">{{ number_format((int) $row['returned']) }}</td>
                                <td class="num">
                                    @if($row['insufficient_data'] || $row['success_rate'] === null)
                                        <span class="badge badge--warn">Insufficient data (&lt;20)</span>
                                    @else
                                        {{ number_format((float) $row['success_rate'], 2) }}%
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($couriers->hasPages())
                <div class="pager">
                    @if($couriers->onFirstPage())
                        <span class="is-disabled">Previous</span>
                    @else
                        <a href="{{ $couriers->previousPageUrl() }}">Previous</a>
                    @endif
                    <span class="is-current">Page {{ $couriers->currentPage() }} of {{ $couriers->lastPage() }}</span>
                    @if($couriers->hasMorePages())
                        <a href="{{ $couriers->nextPageUrl() }}">Next</a>
                    @else
                        <span class="is-disabled">Next</span>
                    @endif
                </div>
            @endif
        @endif
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Employee Scorecard</h2>
            <span class="badge badge--neutral">Response from non-import history</span>
        </div>
        @if($employees->total() === 0)
            <div class="empty-state">No employee-assigned orders found in this range.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th class="num">Assigned</th>
                            <th class="num">Confirmed</th>
                            <th class="num">Conf %</th>
                            <th class="num">Cancelled</th>
                            <th class="num">Delivered</th>
                            <th class="num">Returned</th>
                            <th class="num">Avg Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($employees as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="num">{{ number_format((int) $row['assigned']) }}</td>
                                <td class="num">{{ number_format((int) $row['confirmed_count']) }}</td>
                                <td class="num">{{ number_format((float) $row['confirmation_rate'], 1) }}%</td>
                                <td class="num">{{ number_format((int) $row['cancelled']) }}</td>
                                <td class="num">{{ number_format((int) $row['delivered']) }}</td>
                                <td class="num">{{ number_format((int) $row['returned']) }}</td>
                                <td class="num">
                                    {{ $row['avg_response_minutes'] !== null ? number_format((float) $row['avg_response_minutes'], 1) . ' min' : 'n/a' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($employees->hasPages())
                <div class="pager">
                    @if($employees->onFirstPage())
                        <span class="is-disabled">Previous</span>
                    @else
                        <a href="{{ $employees->previousPageUrl() }}">Previous</a>
                    @endif
                    <span class="is-current">Page {{ $employees->currentPage() }} of {{ $employees->lastPage() }}</span>
                    @if($employees->hasMorePages())
                        <a href="{{ $employees->nextPageUrl() }}">Next</a>
                    @else
                        <span class="is-disabled">Next</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Stock Alerts &amp; Cover Days</h2>
        <span class="badge badge--neutral">Stock &le; {{ (int) config('lyp.low_stock_threshold', 10) }} or Cover &le; 15 days</span>
    </div>
    @if($stockAlerts->total() === 0)
        <div class="empty-state">No low-stock or low-cover alerts right now. Sync product inventory levels to monitor stockouts.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="num">Current Stock</th>
                        <th class="num">Sold (30d)</th>
                        <th class="num">Stock Cover</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($stockAlerts as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="mono muted">{{ $row['sku'] ?? '—' }}</td>
                            <td class="num">{{ number_format((int) $row['stock']) }}</td>
                            <td class="num">{{ number_format((int) $row['units_30d']) }}</td>
                            <td class="num">
                                {{ $row['cover_days'] !== null ? number_format((float) $row['cover_days'], 1) . ' days' : 'No 30d sales' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($stockAlerts->hasPages())
            <div class="pager">
                @if($stockAlerts->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $stockAlerts->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $stockAlerts->currentPage() }} of {{ $stockAlerts->lastPage() }}</span>
                @if($stockAlerts->hasMorePages())
                    <a href="{{ $stockAlerts->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Data Quality Breakdown</h2>
        <span class="badge {{ ($dq['score'] ?? 0) >= 85 ? 'badge--ok' : (($dq['score'] ?? 0) >= 60 ? 'badge--warn' : 'badge--danger') }}">
            Score: {{ (int) ($dq['score'] ?? 0) }} / 100
        </span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Indicator</th>
                    <th class="num">Affected / Total</th>
                    <th class="num">Rate</th>
                    <th class="num">Weight</th>
                    <th class="num">Penalty</th>
                    <th>Level</th>
                    <th>What it affects</th>
                </tr>
            </thead>
            <tbody>
                @foreach(($dq['indicators'] ?? []) as $ind)
                    @php
                        $lvlClass = match ($ind['level']) {
                            'ok' => 'badge--ok',
                            'warn' => 'badge--warn',
                            default => 'badge--danger',
                        };
                    @endphp
                    <tr>
                        <td>{{ $ind['label'] }}</td>
                        <td class="num">{{ number_format((int) $ind['numerator']) }} / {{ number_format((int) $ind['denominator']) }}</td>
                        <td class="num">{{ number_format((float) $ind['pct'], 2) }}%</td>
                        <td class="num">{{ (int) $ind['weight'] }}</td>
                        <td class="num">-{{ number_format((float) $ind['penalty'], 2) }}</td>
                        <td><span class="badge {{ $lvlClass }}">{{ strtoupper((string) $ind['level']) }}</span></td>
                        <td class="muted">{{ $ind['message'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Metric Definitions</h2>
        <span class="badge badge--neutral">Single Source of Truth</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Metric</th>
                    <th>Formula &amp; Rules</th>
                </tr>
            </thead>
            <tbody>
                @foreach($definitions as $def)
                    <tr>
                        <td><strong>{{ $def['metric'] }}</strong></td>
                        <td>{{ $def['formula'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($definitions->hasPages())
        <div class="pager">
            @if($definitions->onFirstPage())
                <span class="is-disabled">Previous</span>
            @else
                <a href="{{ $definitions->previousPageUrl() }}">Previous</a>
            @endif
            <span class="is-current">Page {{ $definitions->currentPage() }} of {{ $definitions->lastPage() }}</span>
            @if($definitions->hasMorePages())
                <a href="{{ $definitions->nextPageUrl() }}">Next</a>
            @else
                <span class="is-disabled">Next</span>
            @endif
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/chart.umd.js') }}?v={{ filemtime(public_path('js/chart.umd.js')) }}" defer></script>
@endpush
