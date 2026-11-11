@extends('layouts.app')

@section('title', 'Courier & Loss - Last Year Project')

@section('content')
@php
    $filters = $report['filters'] ?? [];
    $headline = $report['loss_headline'] ?? [];
    $coverage = $report['coverage'] ?? [];
    $dq = $report['data_quality'] ?? ['score' => 0, 'indicators' => []];
    $websites = $report['websites'] ?? [];
    $currency = (string) ($report['currency'] ?? 'BDT');
    $mixedCurrency = (bool) ($report['mixed_currency'] ?? false);
    $rangeText = ($filters['from'] ?? '') . ' to ' . ($filters['to'] ?? '') . ' (Asia/Dhaka)';
    $websiteText = $filters['website_name'] ?? 'All websites';
@endphp

<div class="page-header">
    <div>
        <h1 class="page-title">Courier &amp; Loss Report</h1>
        <p class="page-subtitle">
            Estimated sales not realised (before shipping &amp; product costs) &middot;
            Range: <strong>{{ $rangeText }}</strong> &middot;
            Store: <strong>{{ $websiteText }}</strong> &middot;
            Currency: <strong>{{ $currency }}</strong> &middot;
            Computed: <span class="mono">{{ $report['computed_at'] ?? '' }}</span>
        </p>
    </div>
    <div class="page-actions">
        <span class="badge {{ ($dq['score'] ?? 0) >= 85 ? 'badge--ok' : (($dq['score'] ?? 0) >= 60 ? 'badge--warn' : 'badge--danger') }}">
            Data Quality: {{ (int) ($dq['score'] ?? 0) }}/100
        </span>
        @if($coverage['coverage_pct'] !== null)
            <span class="badge badge--info">
                Courier Match: {{ number_format((float) $coverage['coverage_pct'], 1) }}% ({{ (int) $coverage['matched_shipments'] }}/{{ (int) $coverage['total_shipments'] }})
            </span>
        @else
            <span class="badge badge--neutral">No courier report in range</span>
        @endif
        @if($user->canManage())
            <a href="{{ route('courier-imports.index') }}" class="btn btn--secondary">Import Courier CSV</a>
            <form method="POST" action="{{ route('courier-loss.scan') }}">
                @csrf
                <button type="submit" class="btn btn--primary">Run Suspicious-Order Scan</button>
            </form>
        @endif
    </div>
</div>

@if($mixedCurrency && ! empty($report['currency_warning']))
    <div class="alert alert--warn" role="alert">
        <strong>Mixed currencies detected:</strong> {{ $report['currency_warning'] }}
    </div>
@endif

<div class="panel">
    <form method="GET" action="{{ route('courier-loss.index') }}" class="filter-bar">
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
            <label for="from">From</label>
            <input id="from" name="from" type="date" class="input" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div class="field">
            <label for="to">To</label>
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
            <a href="{{ route('courier-loss.index') }}" class="btn btn--ghost">Reset</a>
        </div>
    </form>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total Lost Value (Est. Sales Not Realised)</div>
        <div class="kpi-value">
            @if($mixedCurrency || $headline['total_lost_value'] === null)
                Hidden
            @else
                {{ $currency }} {{ number_format((float) $headline['total_lost_value'], 2) }}
            @endif
        </div>
        <div class="kpi-meta">{{ number_format((float) ($headline['lost_share_pct'] ?? 0), 2) }}% of gross order value</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Cancelled Value</div>
        <div class="kpi-value">
            @if($mixedCurrency || $headline['cancelled_value'] === null)
                Hidden
            @else
                {{ $currency }} {{ number_format((float) $headline['cancelled_value'], 2) }}
            @endif
        </div>
        <div class="kpi-meta">{{ number_format((int) ($headline['cancelled_orders'] ?? 0)) }} cancelled orders</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Returned Value</div>
        <div class="kpi-value">
            @if($mixedCurrency || $headline['returned_value'] === null)
                Hidden
            @else
                {{ $currency }} {{ number_format((float) $headline['returned_value'], 2) }}
            @endif
        </div>
        <div class="kpi-meta">{{ number_format((int) ($headline['returned_orders'] ?? 0)) }} returned orders</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Est. Return Handling Cost</div>
        <div class="kpi-value">
            @if($mixedCurrency)
                Hidden
            @elseif(! ($headline['has_return_fee_data'] ?? false) || $headline['estimated_return_handling_cost'] === null)
                Needs courier report
            @else
                {{ $currency }} {{ number_format((float) $headline['estimated_return_handling_cost'], 2) }}
            @endif
        </div>
        <div class="kpi-meta">Delivery + return fees on returned shipments</div>
    </div>
</div>

{{-- Courier Scorecard (by Courier and by Area) --}}
<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">Courier Scorecard</h2>
                <p class="help-text">
                    Source: {{ ($report['scorecard_source'] ?? 'orders') === 'courier_shipments' ? 'Imported courier shipments' : 'Orders ledger (import courier CSV for COD & delivery time)' }}.
                    Min sample: 20 shipments. Outlier z-test vs other couriers at p &lt; 0.05 (n &ge; 30).
                </p>
            </div>
        </div>
        @if($courierScorecard->total() === 0)
            <div class="empty-state">No dispatched shipments found in this date range.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Courier</th>
                            <th class="num">Shipments</th>
                            <th class="num">Deliv</th>
                            <th class="num">Ret</th>
                            <th class="num">In Transit</th>
                            <th class="num">Success Rate</th>
                            <th class="num">Avg Time</th>
                            <th class="num">COD (Coll/Exp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($courierScorecard as $row)
                            <tr>
                                <td class="mono">{{ $row['rank'] !== null ? '#'.$row['rank'] : '—' }}</td>
                                <td>
                                    <strong>{{ $row['name'] }}</strong>
                                    @if($row['statistically_unusual'])
                                        <span class="badge badge--warn" title="p={{ $row['p_value'] }}, z={{ $row['z_score'] }} vs other couriers ({{ $row['other_avg_rate'] }}%)">
                                            Statistically unusual
                                        </span>
                                    @endif
                                </td>
                                <td class="num">{{ number_format((int) $row['shipments']) }}</td>
                                <td class="num">{{ number_format((int) $row['delivered']) }}</td>
                                <td class="num">{{ number_format((int) $row['returned']) }}</td>
                                <td class="num">{{ number_format((int) $row['in_transit']) }}</td>
                                <td class="num">
                                    @if($row['insufficient_data'] || $row['success_rate'] === null)
                                        <span class="badge badge--neutral">Insufficient data (&lt;20)</span>
                                    @else
                                        {{ number_format((float) $row['success_rate'], 2) }}%
                                    @endif
                                </td>
                                <td class="num muted">
                                    {{ $row['avg_delivery_hours'] !== null ? number_format((float) $row['avg_delivery_hours'], 1).' h' : '—' }}
                                </td>
                                <td class="num muted">
                                    @if(! $mixedCurrency && ($row['cod_collected'] !== null || $row['cod_expected'] !== null))
                                        {{ $row['cod_collected'] !== null ? number_format((float) $row['cod_collected'], 0) : '—' }}
                                        /
                                        {{ $row['cod_expected'] !== null ? number_format((float) $row['cod_expected'], 0) : '—' }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($courierScorecard->hasPages())
                <div class="pager">
                    @if($courierScorecard->onFirstPage())
                        <span class="is-disabled">Previous</span>
                    @else
                        <a href="{{ $courierScorecard->previousPageUrl() }}">Previous</a>
                    @endif
                    <span class="is-current">Page {{ $courierScorecard->currentPage() }} of {{ $courierScorecard->lastPage() }}</span>
                    @if($courierScorecard->hasMorePages())
                        <a href="{{ $courierScorecard->nextPageUrl() }}">Next</a>
                    @else
                        <span class="is-disabled">Next</span>
                    @endif
                </div>
            @endif
        @endif
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">Area / City Scorecard</h2>
                <p class="help-text">Delivery success and statistical outliers by destination area (n &ge; 30, p &lt; 0.05).</p>
            </div>
        </div>
        @if($areaScorecard->total() === 0)
            <div class="empty-state">No area/city shipment data in this range.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Area</th>
                            <th class="num">Shipments</th>
                            <th class="num">Deliv</th>
                            <th class="num">Ret</th>
                            <th class="num">Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($areaScorecard as $row)
                            <tr>
                                <td class="mono">{{ $row['rank'] !== null ? '#'.$row['rank'] : '—' }}</td>
                                <td>
                                    <strong>{{ $row['name'] }}</strong>
                                    @if($row['statistically_unusual'])
                                        <span class="badge badge--warn">Statistically unusual</span>
                                    @endif
                                </td>
                                <td class="num">{{ number_format((int) $row['shipments']) }}</td>
                                <td class="num">{{ number_format((int) $row['delivered']) }}</td>
                                <td class="num">{{ number_format((int) $row['returned']) }}</td>
                                <td class="num">
                                    @if($row['insufficient_data'] || $row['success_rate'] === null)
                                        <span class="badge badge--neutral">Insufficient data (&lt;20)</span>
                                    @else
                                        {{ number_format((float) $row['success_rate'], 2) }}%
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($areaScorecard->hasPages())
                <div class="pager">
                    @if($areaScorecard->onFirstPage())
                        <span class="is-disabled">Previous</span>
                    @else
                        <a href="{{ $areaScorecard->previousPageUrl() }}">Previous</a>
                    @endif
                    <span class="is-current">Page {{ $areaScorecard->currentPage() }} of {{ $areaScorecard->lastPage() }}</span>
                    @if($areaScorecard->hasMorePages())
                        <a href="{{ $areaScorecard->nextPageUrl() }}">Next</a>
                    @else
                        <span class="is-disabled">Next</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>

{{-- Loss Splits --}}
<div class="grid-2">
    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">Estimated Sales Not Realised &mdash; by Courier</h2></div>
        @if($lossByCourier->total() === 0)
            <div class="empty-state">No cancelled or returned orders in range.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Courier</th>
                            <th class="num">Cancelled</th>
                            <th class="num">Returned</th>
                            <th class="num">Lost Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lossByCourier as $r)
                            <tr>
                                <td>{{ $r['label'] }}</td>
                                <td class="num">{{ (int) $r['cancelled_orders'] }}</td>
                                <td class="num">{{ (int) $r['returned_orders'] }}</td>
                                <td class="num">{{ $mixedCurrency || $r['total_lost_value'] === null ? 'Hidden' : $currency.' '.number_format((float) $r['total_lost_value'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($lossByCourier->hasPages())
                <div class="pager">
                    @if($lossByCourier->onFirstPage()) <span class="is-disabled">Previous</span> @else <a href="{{ $lossByCourier->previousPageUrl() }}">Previous</a> @endif
                    <span class="is-current">Page {{ $lossByCourier->currentPage() }} of {{ $lossByCourier->lastPage() }}</span>
                    @if($lossByCourier->hasMorePages()) <a href="{{ $lossByCourier->nextPageUrl() }}">Next</a> @else <span class="is-disabled">Next</span> @endif
                </div>
            @endif
        @endif
    </div>

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">Estimated Sales Not Realised &mdash; by Area / City</h2></div>
        @if($lossByArea->total() === 0)
            <div class="empty-state">No cancelled or returned orders in range.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Area</th>
                            <th class="num">Cancelled</th>
                            <th class="num">Returned</th>
                            <th class="num">Lost Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lossByArea as $r)
                            <tr>
                                <td>{{ $r['label'] }}</td>
                                <td class="num">{{ (int) $r['cancelled_orders'] }}</td>
                                <td class="num">{{ (int) $r['returned_orders'] }}</td>
                                <td class="num">{{ $mixedCurrency || $r['total_lost_value'] === null ? 'Hidden' : $currency.' '.number_format((float) $r['total_lost_value'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($lossByArea->hasPages())
                <div class="pager">
                    @if($lossByArea->onFirstPage()) <span class="is-disabled">Previous</span> @else <a href="{{ $lossByArea->previousPageUrl() }}">Previous</a> @endif
                    <span class="is-current">Page {{ $lossByArea->currentPage() }} of {{ $lossByArea->lastPage() }}</span>
                    @if($lossByArea->hasMorePages()) <a href="{{ $lossByArea->nextPageUrl() }}">Next</a> @else <span class="is-disabled">Next</span> @endif
                </div>
            @endif
        @endif
    </div>

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">Estimated Sales Not Realised &mdash; by Employee</h2></div>
        @if($lossByEmployee->total() === 0)
            <div class="empty-state">No cancelled or returned orders in range.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th class="num">Cancelled</th>
                            <th class="num">Returned</th>
                            <th class="num">Lost Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lossByEmployee as $r)
                            <tr>
                                <td>{{ $r['label'] }}</td>
                                <td class="num">{{ (int) $r['cancelled_orders'] }}</td>
                                <td class="num">{{ (int) $r['returned_orders'] }}</td>
                                <td class="num">{{ $mixedCurrency || $r['total_lost_value'] === null ? 'Hidden' : $currency.' '.number_format((float) $r['total_lost_value'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($lossByEmployee->hasPages())
                <div class="pager">
                    @if($lossByEmployee->onFirstPage()) <span class="is-disabled">Previous</span> @else <a href="{{ $lossByEmployee->previousPageUrl() }}">Previous</a> @endif
                    <span class="is-current">Page {{ $lossByEmployee->currentPage() }} of {{ $lossByEmployee->lastPage() }}</span>
                    @if($lossByEmployee->hasMorePages()) <a href="{{ $lossByEmployee->nextPageUrl() }}">Next</a> @else <span class="is-disabled">Next</span> @endif
                </div>
            @endif
        @endif
    </div>

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">Estimated Sales Not Realised &mdash; by Cancel Reason</h2></div>
        @if($lossByCancelReason->total() === 0)
            <div class="empty-state">No cancelled orders in range.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Cancel Reason</th>
                            <th class="num">Cancelled Orders</th>
                            <th class="num">Cancelled Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lossByCancelReason as $r)
                            <tr>
                                <td>{{ $r['reason'] }}</td>
                                <td class="num">{{ (int) $r['orders_count'] }}</td>
                                <td class="num">{{ $mixedCurrency || $r['cancelled_value'] === null ? 'Hidden' : $currency.' '.number_format((float) $r['cancelled_value'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($lossByCancelReason->hasPages())
                <div class="pager">
                    @if($lossByCancelReason->onFirstPage()) <span class="is-disabled">Previous</span> @else <a href="{{ $lossByCancelReason->previousPageUrl() }}">Previous</a> @endif
                    <span class="is-current">Page {{ $lossByCancelReason->currentPage() }} of {{ $lossByCancelReason->lastPage() }}</span>
                    @if($lossByCancelReason->hasMorePages()) <a href="{{ $lossByCancelReason->nextPageUrl() }}">Next</a> @else <span class="is-disabled">Next</span> @endif
                </div>
            @endif
        @endif
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2 class="panel-title">Estimated Sales Not Realised &mdash; by Product</h2></div>
    @if($lossByProduct->total() === 0)
        <div class="empty-state">No cancelled or returned product line items in range.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="num">Cancel Units</th>
                        <th class="num">Return Units</th>
                        <th class="num">Lost Value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lossByProduct as $r)
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td class="mono muted">{{ $r['sku'] ?? '—' }}</td>
                            <td class="num">{{ (int) $r['cancelled_units'] }}</td>
                            <td class="num">{{ (int) $r['returned_units'] }}</td>
                            <td class="num">{{ $mixedCurrency || $r['total_lost_value'] === null ? 'Hidden' : $currency.' '.number_format((float) $r['total_lost_value'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($lossByProduct->hasPages())
            <div class="pager">
                @if($lossByProduct->onFirstPage()) <span class="is-disabled">Previous</span> @else <a href="{{ $lossByProduct->previousPageUrl() }}">Previous</a> @endif
                <span class="is-current">Page {{ $lossByProduct->currentPage() }} of {{ $lossByProduct->lastPage() }}</span>
                @if($lossByProduct->hasMorePages()) <a href="{{ $lossByProduct->nextPageUrl() }}">Next</a> @else <span class="is-disabled">Next</span> @endif
            </div>
        @endif
    @endif
</div>

{{-- Suspicious-Order Review Queue (Advisory Only) --}}
<div class="panel" id="review-queue">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">Suspicious-Order Review Queue (Advisory Only)</h2>
            <p class="help-text">
                Rules S1-S3 flag patterns for human review only. The system NEVER cancels, blocks, or alters any order.
            </p>
        </div>
        <span class="badge {{ $openFlagsCount > 0 ? 'badge--warn' : 'badge--ok' }}">
            {{ $openFlagsCount }} Open Flag(s)
        </span>
    </div>

    <form method="GET" action="{{ route('courier-loss.index') }}" class="filter-bar mb-sm">
        <input type="hidden" name="preset" value="{{ $filters['preset'] ?? '30' }}">
        @if(($filters['preset'] ?? '') === 'custom')
            <input type="hidden" name="from" value="{{ $filters['from'] ?? '' }}">
            <input type="hidden" name="to" value="{{ $filters['to'] ?? '' }}">
        @endif
        @if(!empty($filters['website_id']))
            <input type="hidden" name="website_id" value="{{ $filters['website_id'] }}">
        @endif

        <div class="field">
            <label for="flag_status">Flag Status</label>
            <select id="flag_status" name="flag_status" class="select">
                <option value="all" @selected(($flagFilters['status'] ?? 'all') === 'all')>All statuses</option>
                <option value="open" @selected(($flagFilters['status'] ?? '') === 'open')>Open</option>
                <option value="reviewed_ok" @selected(($flagFilters['status'] ?? '') === 'reviewed_ok')>Reviewed OK</option>
                <option value="confirmed" @selected(($flagFilters['status'] ?? '') === 'confirmed')>Confirmed</option>
            </select>
        </div>
        <div class="field">
            <label for="flag_rule">Rule</label>
            <select id="flag_rule" name="flag_rule" class="select">
                <option value="all" @selected(($flagFilters['rule'] ?? 'all') === 'all')>All rules (S1-S3)</option>
                <option value="S1" @selected(($flagFilters['rule'] ?? '') === 'S1')>S1: Repeat failure</option>
                <option value="S2" @selected(($flagFilters['rule'] ?? '') === 'S2')>S2: Duplicate 24h</option>
                <option value="S3" @selected(($flagFilters['rule'] ?? '') === 'S3')>S3: Burst 1h</option>
            </select>
        </div>
        <div class="field field--fit">
            <button type="submit" class="btn btn--secondary">Filter Queue</button>
        </div>
    </form>

    @if($riskFlags->total() === 0)
        <div class="empty-state">No suspicious-order flags match the current filter. Run a scan or wait for the hourly scheduled job.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Rule &amp; Advisory Notice</th>
                        <th>Latest Order</th>
                        <th>Evidence (Order IDs &amp; Times)</th>
                        <th>Status</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($riskFlags as $flag)
                        @php
                            $ev = $flag->evidence ?? [];
                            $badgeClass = match ($flag->status) {
                                'open' => 'badge--warn',
                                'reviewed_ok' => 'badge--ok',
                                'confirmed' => 'badge--danger',
                                default => 'badge--neutral',
                            };
                        @endphp
                        <tr>
                            <td>
                                <div><strong>{{ \App\Models\RiskFlag::ruleTitle($flag->rule) }}</strong></div>
                                <div class="help-text">Possible false positives: repeat legitimate buyers, family orders.</div>
                            </td>
                            <td class="mono">
                                @if($flag->order)
                                    <a href="{{ route('orders.show', $flag->order) }}">{{ $flag->order->external_id }}</a>
                                    <div class="help-text">Status: {{ ucfirst($flag->order->status) }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="mono">
                                <div>Refs: {{ implode(', ', $ev['external_ids'] ?? []) }}</div>
                                <div class="help-text">Times: {{ implode(' | ', array_slice($ev['timestamps'] ?? [], -3)) }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $badgeClass }}">{{ str_replace('_', ' ', ucfirst($flag->status)) }}</span>
                                @if($flag->reviewed_at)
                                    <div class="help-text">{{ $flag->reviewer?->name ?? 'Staff' }} &middot; {{ $flag->reviewed_at->format('Y-m-d H:i') }}</div>
                                @endif
                            </td>
                            <td>
                                @if($user->canManage())
                                    <div class="inline-actions">
                                        <form method="POST" action="{{ route('courier-loss.flags.review', $flag) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="reviewed_ok">
                                            <button type="submit" class="btn btn--secondary btn--sm">Reviewed OK</button>
                                        </form>
                                        <form method="POST" action="{{ route('courier-loss.flags.review', $flag) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="confirmed">
                                            <button type="submit" class="btn btn--danger btn--sm">Confirm</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="muted">View only</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($riskFlags->hasPages())
            <div class="pager">
                @if($riskFlags->onFirstPage()) <span class="is-disabled">Previous</span> @else <a href="{{ $riskFlags->previousPageUrl() }}">Previous</a> @endif
                <span class="is-current">Page {{ $riskFlags->currentPage() }} of {{ $riskFlags->lastPage() }}</span>
                @if($riskFlags->hasMorePages()) <a href="{{ $riskFlags->nextPageUrl() }}">Next</a> @else <span class="is-disabled">Next</span> @endif
            </div>
        @endif
    @endif
</div>
@endsection
