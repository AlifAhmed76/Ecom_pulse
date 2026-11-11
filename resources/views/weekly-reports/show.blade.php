@extends('layouts.app')

@section('title', $rendered['labels']['page_title'].' ('.$rendered['week_start'].') - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $rendered['labels']['page_title'] }}</h1>
        <p class="page-subtitle">
            <strong>{{ $rendered['tenant_name'] }}</strong>
            &middot;
            <span class="mono">{{ $rendered['week_start'] }} — {{ $rendered['week_end'] }}</span>
            &middot;
            {{ $rendered['labels']['built_at'] }}: <span class="mono">{{ $rendered['built_at'] }}</span>
        </p>
    </div>
    <div class="page-actions no-print">
        <a href="{{ route('weekly-reports.show', ['weeklyReport' => $report->id, 'lang' => 'en', 'bn_digits' => 0]) }}"
           class="btn btn--sm {{ $locale === 'en' ? 'btn--primary' : 'btn--secondary' }}">
            {{ $rendered['labels']['lang_en'] }}
        </a>
        <a href="{{ route('weekly-reports.show', ['weeklyReport' => $report->id, 'lang' => 'bn', 'bn_digits' => 1]) }}"
           class="btn btn--sm {{ $locale === 'bn' ? 'btn--primary' : 'btn--secondary' }}">
            {{ $rendered['labels']['lang_bn'] }}
        </a>
        <button type="button" class="btn btn--primary btn--sm" data-print-button>
            {{ $rendered['labels']['print_btn'] }}
        </button>
        <a href="{{ route('weekly-reports.index') }}" class="btn btn--secondary btn--sm">
            {{ $rendered['labels']['back_btn'] }}
        </a>
    </div>
</div>

<div class="alert alert--info no-print" role="note">
    {{ $rendered['labels']['subtitle'] }}
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">{{ $rendered['labels']['kpi_orders'] }}</div>
        <div class="kpi-value">{{ $rendered['kpis']['orders'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">{{ $rendered['labels']['kpi_gross'] }}</div>
        <div class="kpi-value">{{ $rendered['kpis']['gross_sales'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">{{ $rendered['labels']['kpi_delivered'] }}</div>
        <div class="kpi-value">{{ $rendered['kpis']['delivered_revenue'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">{{ $rendered['labels']['kpi_lost'] }}</div>
        <div class="kpi-value">{{ $rendered['kpis']['total_lost_value'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">{{ $rendered['labels']['kpi_dq'] }}</div>
        <div class="kpi-value">{{ $rendered['kpis']['data_quality'] }}</div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">{{ $rendered['labels']['sec_overview'] }}</h2>
    </div>
    <p>{{ $rendered['labels']['overview_line'] }}</p>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">{{ $rendered['labels']['sec_loss'] }}</h2>
    </div>
    <p>{{ $rendered['labels']['loss_line'] }}</p>
    <p class="help-text mb-sm">{{ $rendered['labels']['return_cost_line'] }}</p>

    <div class="grid-2">
        @if(! empty($rendered['top_areas']))
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>{{ $rendered['labels']['th_area'] }}</th>
                            <th class="num">Cancelled</th>
                            <th class="num">Returned</th>
                            <th class="num">Lost Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rendered['top_areas'] as $area)
                            <tr>
                                <td>{{ $area['label'] ?? '' }}</td>
                                <td class="num">{{ (int) ($area['cancelled_orders'] ?? 0) }}</td>
                                <td class="num">{{ (int) ($area['returned_orders'] ?? 0) }}</td>
                                <td class="num">
                                    {{ isset($area['total_lost_value']) && $area['total_lost_value'] !== null ? $rendered['currency'].' '.number_format((float) $area['total_lost_value'], 2) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(! empty($rendered['top_reasons']))
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>{{ $rendered['labels']['th_reason'] }}</th>
                            <th class="num">Orders</th>
                            <th class="num">Cancelled Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rendered['top_reasons'] as $reason)
                            <tr>
                                <td>{{ $reason['reason'] ?? '' }}</td>
                                <td class="num">{{ (int) ($reason['orders_count'] ?? 0) }}</td>
                                <td class="num">
                                    {{ isset($reason['cancelled_value']) && $reason['cancelled_value'] !== null ? $rendered['currency'].' '.number_format((float) $reason['cancelled_value'], 2) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">{{ $rendered['labels']['sec_courier'] }}</h2>
    </div>
    <p>{{ $rendered['labels']['courier_highlight_line'] }}</p>

    @if(! empty($rendered['couriers']))
        <div class="table-wrap mt-sm">
            <table>
                <thead>
                    <tr>
                        <th>{{ $rendered['labels']['th_courier'] }}</th>
                        <th class="num">{{ $rendered['labels']['th_shipments'] }}</th>
                        <th class="num">{{ $rendered['labels']['th_delivered'] }}</th>
                        <th class="num">{{ $rendered['labels']['th_returned'] }}</th>
                        <th class="num">{{ $rendered['labels']['th_success'] }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rendered['couriers'] as $c)
                        <tr>
                            <td>
                                <strong>{{ $c['name'] }}</strong>
                                @if($c['statistically_unusual'])
                                    <span class="badge badge--danger">Statistically unusual</span>
                                @endif
                            </td>
                            <td class="num">{{ $c['shipments'] }}</td>
                            <td class="num">{{ $c['delivered'] }}</td>
                            <td class="num">{{ $c['returned'] }}</td>
                            <td class="num">
                                @if($c['insufficient_data'] || $c['success_rate'] === null)
                                    <span class="badge badge--neutral">{{ $rendered['labels']['insufficient_badge'] }}</span>
                                @else
                                    {{ $c['success_rate'] }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">{{ $rendered['labels']['sec_findings'] }}</h2>
    </div>
    @if(empty($rendered['findings']))
        <p class="muted">{{ $rendered['labels']['findings_empty'] }}</p>
    @else
        <div class="finding-list">
            @foreach($rendered['findings'] as $f)
                <div class="finding-card finding-card--{{ $f['severity'] }}">
                    <div class="finding-card-head">
                        <div>
                            <div class="finding-meta">
                                <span class="badge badge--neutral mono">{{ $f['rule_code'] }}</span>
                                <span class="badge badge--warn">{{ strtoupper($f['severity']) }}</span>
                                <span class="badge badge--info">{{ ucfirst($f['confidence']) }} confidence</span>
                                @if($f['website_name'] !== '')
                                    <span class="muted">{{ $f['website_name'] }}</span>
                                @endif
                            </div>
                            <h3 class="finding-card-title">{{ $f['title'] }}</h3>
                        </div>
                        <span class="badge badge--neutral">{{ $f['money_display'] }}</span>
                    </div>
                    <p>{{ $f['summary'] }}</p>
                    <p class="help-text"><strong>Action:</strong> {{ $f['action'] }}</p>
                </div>
            @endforeach
        </div>
    @endif
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">{{ $rendered['labels']['sec_experiments'] }}</h2>
    </div>
    <p>{{ $rendered['labels']['exp_summary_line'] }}</p>

    @if(empty($rendered['experiments']))
        <p class="muted">{{ $rendered['labels']['exp_empty'] }}</p>
    @else
        <div class="table-wrap mt-sm">
            <table>
                <thead>
                    <tr>
                        <th>{{ $rendered['labels']['th_title'] }}</th>
                        <th>{{ $rendered['labels']['th_status'] }}</th>
                        <th>{{ $rendered['labels']['th_outcome'] }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rendered['experiments'] as $exp)
                        <tr>
                            <td>
                                <strong>{{ $exp['title'] ?? '' }}</strong>
                                @if(! empty($exp['rule_code']))
                                    <span class="badge badge--neutral mono">{{ $exp['rule_code'] }}</span>
                                @endif
                            </td>
                            <td><span class="badge badge--info">{{ strtoupper((string) ($exp['status'] ?? '')) }}</span></td>
                            <td>
                                @if(! empty($exp['outcome']))
                                    <strong>{{ strtoupper((string) $exp['outcome']) }}</strong>
                                    @if(! empty($exp['measured_value']))
                                        — {{ $exp['measured_value'] }}
                                    @endif
                                    @if(! empty($exp['lesson']))
                                        <div class="help-text">{{ $exp['lesson'] }}</div>
                                    @endif
                                @else
                                    <span class="muted">{{ $exp['started_at'] ? 'Started '.$exp['started_at'] : 'Awaiting completion' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">{{ $rendered['labels']['sec_marketing'] }}</h2>
    </div>
    <p>{{ $rendered['labels']['marketing_line'] }}</p>
    <p class="help-text mt-sm">{{ $rendered['labels']['caveat'] }}</p>
</div>
@endsection
