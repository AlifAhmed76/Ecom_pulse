@extends('layouts.app')

@section('title', ($locale === 'bn' ? 'সাপ্তাহিক প্রতিবেদন' : 'Weekly Reports').' - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $locale === 'bn' ? 'সাপ্তাহিক সারসংক্ষেপ প্রতিবেদন' : 'Weekly Summary Reports' }}</h1>
        <p class="page-subtitle">
            {{ $locale === 'bn'
                ? 'প্রতি শনিবার সকাল ০৮:০০ টায় (Asia/Dhaka) তৈরি হয় — অফলাইন প্রিভিউ ও প্রিন্ট (ইমেইল/এসএমএস পাঠানো হয় না)।'
                : 'Built automatically Saturdays at 08:00 Asia/Dhaka — preview and print only (no email or SMS sent).' }}
        </p>
    </div>
    @if($user && $user->canManage())
        <div class="page-actions">
            <form method="POST" action="{{ route('weekly-reports.build') }}">
                @csrf
                <input type="hidden" name="period" value="current">
                <button type="submit" class="btn btn--primary">
                    {{ $locale === 'bn' ? 'চলতি সপ্তাহ তৈরি করুন' : 'Build current week' }} ({{ $currentWeekStart }})
                </button>
            </form>
            <form method="POST" action="{{ route('weekly-reports.build') }}">
                @csrf
                <input type="hidden" name="period" value="last_completed">
                <button type="submit" class="btn btn--secondary">
                    {{ $locale === 'bn' ? 'গত সম্পূর্ণ সপ্তাহ তৈরি করুন' : 'Build last completed week' }} ({{ $lastWeekStart }})
                </button>
            </form>
        </div>
    @endif
</div>

@if($latestRendered)
    <div class="panel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">
                    {{ $locale === 'bn' ? 'সর্বশেষ সপ্তাহ:' : 'Latest Weekly Snapshot:' }}
                    <span class="mono">{{ $latestRendered['week_start'] }} — {{ $latestRendered['week_end'] }}</span>
                </h2>
                <p class="help-text">{{ $latestRendered['labels']['overview_line'] }}</p>
            </div>
            <div class="inline-actions">
                <a href="{{ route('weekly-reports.show', ['weeklyReport' => $latestReport->id, 'lang' => 'en']) }}" class="btn btn--secondary btn--sm">English Preview</a>
                <a href="{{ route('weekly-reports.show', ['weeklyReport' => $latestReport->id, 'lang' => 'bn', 'bn_digits' => 1]) }}" class="btn btn--primary btn--sm">বাংলা প্রিভিউ ও প্রিন্ট</a>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">{{ $latestRendered['labels']['kpi_orders'] }}</div>
                <div class="kpi-value">{{ $latestRendered['kpis']['orders'] }}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">{{ $latestRendered['labels']['kpi_gross'] }}</div>
                <div class="kpi-value">{{ $latestRendered['kpis']['gross_sales'] }}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">{{ $latestRendered['labels']['kpi_lost'] }}</div>
                <div class="kpi-value">{{ $latestRendered['kpis']['total_lost_value'] }}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">{{ $latestRendered['labels']['kpi_dq'] }}</div>
                <div class="kpi-value">{{ $latestRendered['kpis']['data_quality'] }}</div>
            </div>
        </div>
    </div>
@endif

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">{{ $locale === 'bn' ? 'সংরক্ষিত সাপ্তাহিক প্রতিবেদনসমূহ' : 'Saved Weekly Reports' }}</h2>
        <span class="badge badge--neutral">Saturday 08:00 Asia/Dhaka</span>
    </div>

    @if($reports->isEmpty())
        <div class="empty-state">
            {{ $locale === 'bn'
                ? 'এখনো কোনো সাপ্তাহিক প্রতিবেদন তৈরি হয়নি। উপরের বাটনে ক্লিক করে চলতি বা গত সপ্তাহের প্রতিবেদন তৈরি করুন।'
                : 'No weekly reports have been generated yet. Click Build current week above (or wait for the Saturday 08:00 schedule) to preview and print a report.' }}
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Week Window (Sat–Fri)</th>
                        <th class="num">Orders</th>
                        <th class="num">Delivered Rev</th>
                        <th class="num">Money Lost</th>
                        <th class="num">DQ Score</th>
                        <th>Built At</th>
                        <th>Preview / Print</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $rep)
                        @php
                            $p = is_array($rep->payload) ? $rep->payload : [];
                            $os = is_array($p['orders_summary'] ?? null) ? $p['orders_summary'] : [];
                            $ml = is_array($p['money_lost'] ?? null) ? $p['money_lost'] : [];
                            $cur = (string) ($p['currency'] ?? 'BDT');
                            $mixed = (bool) ($p['mixed_currency'] ?? false);
                        @endphp
                        <tr>
                            <td class="mono">{{ $rep->week_start?->format('Y-m-d') }} — {{ $rep->week_end?->format('Y-m-d') }}</td>
                            <td class="num">{{ number_format((int) ($os['orders'] ?? 0)) }}</td>
                            <td class="num">
                                {{ ! $mixed && isset($os['delivered_revenue']) ? $cur.' '.number_format((float) $os['delivered_revenue'], 2) : '—' }}
                            </td>
                            <td class="num">
                                {{ ! $mixed && isset($ml['total_lost_value']) ? $cur.' '.number_format((float) $ml['total_lost_value'], 2) : '—' }}
                            </td>
                            <td class="num">{{ (int) ($os['data_quality_score'] ?? 0) }}/100</td>
                            <td class="muted tabular">{{ $rep->built_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <div class="inline-actions">
                                    <a href="{{ route('weekly-reports.show', ['weeklyReport' => $rep->id, 'lang' => 'en']) }}" class="btn btn--secondary btn--sm">EN</a>
                                    <a href="{{ route('weekly-reports.show', ['weeklyReport' => $rep->id, 'lang' => 'bn', 'bn_digits' => 1]) }}" class="btn btn--secondary btn--sm">বাংলা</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($reports->hasPages())
            <div class="pager">{{ $reports->links() }}</div>
        @endif
    @endif
</div>
@endsection
