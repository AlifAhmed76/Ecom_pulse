@extends('layouts.app')

@section('title', 'Marketing & Real ROAS - Last Year Project')

@section('content')
@php
    $filters = $report['filters'];
    $plat = $report['platform_reported'];
    $real = $report['real_orders'];
    $attr = $report['attribution'];
    $cur = $report['currency'];
    $mixedCur = !empty($report['mixed_currency']);
@endphp

<div class="page-header">
    <div>
        <h1 class="page-title">Marketing &amp; Real ROAS</h1>
        <p class="page-subtitle">
            Window: <span class="mono">{{ $filters['from'] }}</span> to <span class="mono">{{ $filters['to'] }}</span>
            &middot; Currency: {{ $mixedCur ? 'Mixed (see warning)' : $cur }}
            &middot; Computed: {{ $report['computed_at'] }}
            @if(!empty($report['ad_data_updated_at']))
                &middot; Ad report updated: {{ $report['ad_data_updated_at'] }}
            @endif
        </p>
    </div>
    @if(auth()->user()->canManage())
        <div class="page-actions">
            <a href="{{ route('marketing-imports.index') }}" class="btn btn--primary">Import Ad Report</a>
        </div>
    @endif
</div>

@if($mixedCur)
    <div class="alert alert--err" role="alert">
        <strong>Mixed currency orders detected ({{ implode(', ', $report['currencies']) }}):</strong>
        Cross-currency real-order revenue and Real ROAS are withheld. Select a single website above to compare amounts accurately.
    </div>
@endif

<div class="alert alert--info" role="note">
    <strong>Attribution &amp; No-Profit Notice:</strong>
    Platform-reported metrics (self-reported by Meta/Google/TikTok) and Real-order metrics (matched strictly via <span class="mono">orders.utm_campaign</span> on <strong>delivered</strong> orders) are always kept separate. Real ROAS measures delivered order total divided by ad spend before product cost (COGS), courier fees, or overhead &mdash; it is <strong>not profit</strong>.
</div>

<div class="panel">
    <form method="GET" action="{{ route('marketing.index') }}" class="filter-bar">
        <div class="field field--fit">
            <label for="preset">Range</label>
            <select id="preset" name="preset" class="select input--compact">
                <option value="7" @selected($filters['preset'] === '7')>Last 7 days</option>
                <option value="30" @selected($filters['preset'] === '30')>Last 30 days</option>
                <option value="90" @selected($filters['preset'] === '90')>Last 90 days</option>
                <option value="custom" @selected($filters['preset'] === 'custom')>Custom dates</option>
            </select>
        </div>

        <div class="field field--fit">
            <label for="from">From</label>
            <input id="from" type="date" name="from" class="input input--compact" value="{{ $filters['from'] }}">
        </div>

        <div class="field field--fit">
            <label for="to">To</label>
            <input id="to" type="date" name="to" class="input input--compact" value="{{ $filters['to'] }}">
        </div>

        <div class="field field--fit">
            <label for="website_id">Website</label>
            <select id="website_id" name="website_id" class="select input--compact">
                <option value="">All websites</option>
                @foreach($websites as $site)
                    <option value="{{ $site->id }}" @selected((string) $filters['website_id'] === (string) $site->id)>
                        {{ $site->name }} ({{ $site->currency }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field field--fit">
            <label for="platform">Platform</label>
            <select id="platform" name="platform" class="select input--compact">
                <option value="">All platforms</option>
                @foreach($platforms as $p)
                    <option value="{{ $p }}" @selected($filters['platform'] === $p)>{{ ucfirst($p) }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn btn--secondary btn--sm">Apply filters</button>
    </form>
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Platform-Reported Results (Ad Manager CSV)</h2>
            <span class="badge badge--neutral">Self-reported by ad platform</span>
        </div>

        <div class="kv-grid">
            <div>
                <div class="kv-item-label">Platform Spend</div>
                <div class="kv-item-value tabular">{{ $cur }} {{ number_format((float) $plat['spend'], 2) }}</div>
            </div>
            <div>
                <div class="kv-item-label">Impressions / Clicks</div>
                <div class="kv-item-value tabular">
                    {{ number_format((int) $plat['impressions']) }} / {{ number_format((int) $plat['clicks']) }}
                    @if($plat['ctr_pct'] !== null)
                        <span class="muted">({{ number_format((float) $plat['ctr_pct'], 2) }}% CTR)</span>
                    @endif
                </div>
            </div>
            <div>
                <div class="kv-item-label">Platform Conversions</div>
                <div class="kv-item-value tabular">{{ number_format((float) $plat['conversions'], 2) }}</div>
            </div>
            <div>
                <div class="kv-item-label">Platform CPA</div>
                <div class="kv-item-value tabular">
                    {{ $plat['cpa'] !== null ? $cur.' '.number_format((float) $plat['cpa'], 2) : 'n/a' }}
                </div>
            </div>
            <div>
                <div class="kv-item-label">Platform Revenue (Reported)</div>
                <div class="kv-item-value tabular">
                    {{ $plat['revenue'] !== null ? $cur.' '.number_format((float) $plat['revenue'], 2) : 'n/a' }}
                </div>
            </div>
            <div>
                <div class="kv-item-label">Platform ROAS</div>
                <div class="kv-item-value tabular">
                    {{ $plat['roas'] !== null ? number_format((float) $plat['roas'], 2).'x' : 'n/a' }}
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Real-Order Results (Matched Store Orders)</h2>
            <span class="badge {{ $attr['utm_coverage_pct'] >= 50 ? 'badge--ok' : 'badge--warn' }}">
                UTM coverage: {{ number_format((float) $attr['utm_coverage_pct'], 1) }}%
            </span>
        </div>

        <div class="kv-grid mb-sm">
            <div>
                <div class="kv-item-label">Matched Orders (utm_campaign)</div>
                <div class="kv-item-value tabular">{{ number_format((int) $real['matched_orders']) }}</div>
            </div>
            <div>
                <div class="kv-item-label">Matched Delivered Orders</div>
                <div class="kv-item-value tabular">{{ number_format((int) $real['delivered_orders']) }}</div>
            </div>
            <div>
                <div class="kv-item-label">Delivered Revenue (Pre-COGS)</div>
                <div class="kv-item-value tabular">
                    {{ $real['delivered_revenue'] !== null ? $cur.' '.number_format((float) $real['delivered_revenue'], 2) : '—' }}
                </div>
            </div>
            <div>
                <div class="kv-item-label">Real CPA (Delivered)</div>
                <div class="kv-item-value tabular">
                    {{ $real['cpa'] !== null ? $cur.' '.number_format((float) $real['cpa'], 2) : 'n/a' }}
                </div>
            </div>
            <div>
                <div class="kv-item-label">Real ROAS (Delivered / Spend)</div>
                <div class="kv-item-value tabular">
                    {{ $real['roas'] !== null ? number_format((float) $real['roas'], 2).'x' : 'n/a' }}
                </div>
            </div>
            <div>
                <div class="kv-item-label">Orders With UTM / All Orders</div>
                <div class="kv-item-value tabular">
                    {{ number_format((int) $attr['orders_with_utm']) }} / {{ number_format((int) $attr['orders_total']) }}
                    ({{ number_format((float) $attr['campaign_match_of_all_orders_pct'], 1) }}% matched)
                </div>
            </div>
        </div>

        <p class="help-text">{{ $attr['note'] }}</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Campaign Comparison: Platform-Reported vs Real Store Orders</h2>
        <span class="muted">{{ count($report['campaigns']) }} campaign(s) in range</span>
    </div>

    @if(empty($report['campaigns']))
        <div class="empty-state">
            <p>No ad performance rows imported for this date range.</p>
            @if(auth()->user()->canManage())
                <a href="{{ route('marketing-imports.index') }}" class="btn btn--primary btn--sm">Import ad report CSV</a>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Platform</th>
                        <th class="num">Spend ({{ $cur }})</th>
                        <th class="num">Impr.</th>
                        <th class="num">Clicks</th>
                        <th class="num">Platform Conv.</th>
                        <th class="num">Platform CPA</th>
                        <th class="num">Platform ROAS</th>
                        <th class="num">Real Orders</th>
                        <th class="num">Real Delivered</th>
                        <th class="num">Delivered Rev.</th>
                        <th class="num">Real CPA</th>
                        <th class="num">Real ROAS</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['campaigns'] as $c)
                        <tr>
                            <td><strong>{{ $c['campaign'] }}</strong></td>
                            <td><span class="badge badge--neutral">{{ ucfirst($c['platform']) }}</span></td>
                            <td class="num">{{ number_format((float) $c['spend'], 2) }}</td>
                            <td class="num">{{ number_format((int) $c['impressions']) }}</td>
                            <td class="num">{{ number_format((int) $c['clicks']) }}</td>
                            <td class="num">{{ number_format((float) $c['platform_conversions'], 2) }}</td>
                            <td class="num">{{ $c['platform_cpa'] !== null ? number_format((float) $c['platform_cpa'], 2) : 'n/a' }}</td>
                            <td class="num">{{ $c['platform_roas'] !== null ? number_format((float) $c['platform_roas'], 2).'x' : 'n/a' }}</td>
                            <td class="num">{{ number_format((int) $c['real_total_orders']) }}</td>
                            <td class="num">{{ number_format((int) $c['real_delivered_orders']) }}</td>
                            <td class="num">{{ number_format((float) $c['real_delivered_revenue'], 2) }}</td>
                            <td class="num">{{ $c['real_cpa'] !== null ? number_format((float) $c['real_cpa'], 2) : 'n/a' }}</td>
                            <td class="num">
                                <strong>{{ $c['real_roas'] !== null ? number_format((float) $c['real_roas'], 2).'x' : 'n/a' }}</strong>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if(!empty($attr['unmatched_utm_campaigns']))
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Orders With Unmatched utm_campaign Tags</h2>
            <span class="muted">No matching imported campaign found for these tags in range</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>utm_campaign Tag</th>
                        <th class="num">Orders</th>
                        <th class="num">Delivered Orders</th>
                        <th class="num">Delivered Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($attr['unmatched_utm_campaigns'] as $u)
                        <tr>
                            <td class="mono">{{ $u['utm_campaign'] }}</td>
                            <td class="num">{{ number_format((int) $u['orders']) }}</td>
                            <td class="num">{{ number_format((int) $u['delivered_orders']) }}</td>
                            <td class="num">{{ $cur }} {{ number_format((float) $u['delivered_revenue'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
