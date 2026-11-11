@extends('layouts.app')

@section('title', $labels['page_title'].' - Last Year Project')

@section('content')
@php
    $sevBadge = static fn (string $sev): string => match ($sev) {
        'critical' => 'badge--danger',
        'high' => 'badge--warn',
        'medium' => 'badge--info',
        default => 'badge--neutral',
    };
    $confBadge = static fn (string $conf): string => match ($conf) {
        'high' => 'badge--ok',
        'medium' => 'badge--info',
        default => 'badge--neutral',
    };
    $sevLabel = static fn (string $sev): string => $labels['severity_'.$sev] ?? ucfirst($sev);
    $confLabel = static fn (string $conf): string => $labels['conf_'.$conf] ?? ucfirst($conf);
    $statusLabel = static fn (string $st): string => $labels['status_'.$st] ?? ucfirst($st);
@endphp

<div class="page-header">
    <div>
        <h1 class="page-title">{{ $labels['page_title'] }}</h1>
        <p class="page-subtitle">{{ $labels['page_subtitle'] }}</p>
    </div>
    <div class="page-actions">
        <span class="badge {{ $openCount > 0 ? 'badge--warn' : 'badge--ok' }}">
            {{ $bnDigits ? \App\Services\Rnd\FindingTemplates::toBanglaDigits((string) $openCount) : $openCount }} {{ $labels['status_open'] }}
        </span>

        <form method="POST" action="{{ route('locale.update') }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $locale === 'bn' ? 'en' : 'bn' }}">
            <button type="submit" class="btn btn--secondary btn--sm">
                {{ $locale === 'bn' ? $labels['lang_en'] : $labels['lang_bn'] }}
            </button>
        </form>

        @if($locale === 'bn')
            <form method="POST" action="{{ route('locale.update') }}">
                @csrf
                <input type="hidden" name="locale" value="bn">
                <input type="hidden" name="bn_digits" value="{{ $bnDigits ? '0' : '1' }}">
                <button type="submit" class="btn btn--ghost btn--sm">
                    {{ $labels['digits_toggle'] }}: {{ $bnDigits ? 'ON' : 'OFF' }}
                </button>
            </form>
        @endif

        @if($user->canManage())
            <form method="POST" action="{{ route('action-center.scan') }}">
                @csrf
                <button type="submit" class="btn btn--primary">{{ $labels['run_scan'] }}</button>
            </form>
        @endif
    </div>
</div>

<div class="alert alert--info mb-sm" role="note">
    {{ $labels['hypothesis_disclaimer'] }}
</div>

<div class="panel">
    <form method="GET" action="{{ route('action-center.index') }}" class="filter-bar">
        <div class="field">
            <label for="view">Queue</label>
            <select id="view" name="view" class="select">
                <option value="open" @selected($filters['view'] === 'open')>{{ $labels['status_open'] }}</option>
                <option value="closed" @selected($filters['view'] === 'closed')>{{ $labels['status_closed'] }}</option>
                <option value="all" @selected($filters['view'] === 'all')>{{ $labels['status_all'] }}</option>
            </select>
        </div>
        <div class="field">
            <label for="website_id">Website</label>
            <select id="website_id" name="website_id" class="select">
                <option value="">All websites</option>
                @foreach($websites as $site)
                    <option value="{{ $site->id }}" @selected($filters['website_id'] === $site->id)>
                        {{ $site->name }} ({{ $site->currency }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="rule">Rule</label>
            <select id="rule" name="rule" class="select">
                <option value="all" @selected($filters['rule'] === 'all')>All rules (R01-R12)</option>
                @foreach(\App\Models\ResearchFinding::RULES as $rCode)
                    <option value="{{ $rCode }}" @selected($filters['rule'] === $rCode)>{{ $rCode }}</option>
                @endforeach
            </select>
        </div>
        <div class="field field--fit">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="{{ route('action-center.index') }}" class="btn btn--ghost">Reset</a>
        </div>
    </form>
</div>

@if($todayCards === [] && $weekCards === [] && $infoCards === [])
    <div class="panel">
        <div class="empty-state">
            <strong>{{ $labels['empty_title'] }}</strong>
            <p class="help-text">{{ $labels['empty_desc'] }}</p>
        </div>
    </div>
@else
    @foreach([
        ['label' => $labels['group_today'], 'cards' => $todayCards],
        ['label' => $labels['group_week'], 'cards' => $weekCards],
        ['label' => $labels['group_info'], 'cards' => $infoCards],
    ] as $group)
        @if($group['cards'] !== [])
            <div class="panel">
                <div class="panel-head">
                    <h2 class="panel-title">{{ $group['label'] }} ({{ count($group['cards']) }})</h2>
                </div>
                <div class="finding-list">
                    @foreach($group['cards'] as $card)
                        @php
                            /** @var \App\Models\ResearchFinding $f */
                            $f = $card['model'];
                            $txt = $card['rendered'];
                            $moneyStr = $f->money_at_stake !== null
                                ? $f->currency.' '.\App\Services\Rnd\FindingTemplates::formatNumber((float) $f->money_at_stake, 2, $bnDigits)
                                : $labels['informational'];
                        @endphp
                        <article class="finding-card finding-card--{{ $f->severity }}">
                            <div class="finding-card-head">
                                <div>
                                    <div class="finding-meta">
                                        <span class="mono badge badge--neutral">{{ $f->rule_code }}</span>
                                        <span class="badge {{ $sevBadge($f->severity) }}">{{ $sevLabel($f->severity) }}</span>
                                        <span class="badge {{ $confBadge($f->confidence) }}">{{ $confLabel($f->confidence) }}</span>
                                        <span class="badge badge--neutral">{{ $statusLabel($f->status) }}</span>
                                        <span class="help-text">{{ $f->website?->name ?? 'Store' }} &middot; {{ $labels['money_at_stake'] }}: <strong>{{ $moneyStr }}</strong></span>
                                    </div>
                                    <h3 class="finding-card-title">
                                        <a href="{{ route('action-center.findings.show', $f) }}">{{ $txt['title'] }}</a>
                                    </h3>
                                </div>
                                <div class="inline-actions">
                                    <a href="{{ route('action-center.findings.show', $f) }}" class="btn btn--secondary btn--sm">{{ $labels['view_detail'] }}</a>
                                    <a href="{{ route('experiments.create', ['finding_id' => $f->id]) }}" class="btn btn--primary btn--sm">{{ $labels['create_experiment'] }}</a>
                                    <a href="{{ $card['analytics_url'] }}" class="btn btn--ghost btn--sm">{{ $labels['view_analytics'] }}</a>
                                </div>
                            </div>

                            <p>{{ $txt['summary'] }}</p>
                            <p class="help-text">
                                <strong>{{ $labels['confidence'] }}:</strong> {{ $txt['confidence_reason'] }}
                            </p>
                            <p class="help-text">
                                <strong>{{ $labels['field_7'] }}:</strong> {{ $txt['action'] }}
                            </p>

                            @if($user->canManage() && $f->isOpen())
                                <div class="inline-actions">
                                    @if($f->status === 'new')
                                        <form method="POST" action="{{ route('action-center.findings.review', $f) }}">
                                            @csrf
                                            <button type="submit" class="btn btn--secondary btn--sm">{{ $labels['mark_review'] }}</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('action-center.findings.dismiss', $f) }}" class="inline-actions">
                                        @csrf
                                        <input type="text" name="dismiss_reason" class="input input--compact" placeholder="{{ $labels['dismiss_reason_ph'] }}" required minlength="3" maxlength="255">
                                        <button type="submit" class="btn btn--danger btn--sm">{{ $labels['dismiss'] }}</button>
                                    </form>
                                </div>
                            @elseif(! $user->canManage())
                                <div class="help-text">{{ $labels['view_only'] }}</div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
@endif
@endsection
