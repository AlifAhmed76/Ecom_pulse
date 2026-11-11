@extends('layouts.app')

@section('title', $finding->rule_code.' - '.$rendered['title'])

@section('content')
@php
    $ev = is_array($finding->evidence) ? $finding->evidence : [];
    $metrics = is_array($ev['metrics'] ?? null) ? $ev['metrics'] : [];
    $tables = is_array($ev['source_tables'] ?? null) ? $ev['source_tables'] : [];
    $moneyStr = $finding->money_at_stake !== null
        ? $finding->currency.' '.\App\Services\Rnd\FindingTemplates::formatNumber((float) $finding->money_at_stake, 2, $bnDigits)
        : $labels['informational'];

    $sevBadge = match ($finding->severity) {
        'critical' => 'badge--danger',
        'high' => 'badge--warn',
        'medium' => 'badge--info',
        default => 'badge--neutral',
    };
    $confBadge = match ($finding->confidence) {
        'high' => 'badge--ok',
        'medium' => 'badge--info',
        default => 'badge--neutral',
    };
@endphp

<div class="page-header">
    <div>
        <div class="finding-meta">
            <span class="mono badge badge--neutral">{{ $finding->rule_code }}</span>
            <span class="badge {{ $sevBadge }}">{{ $labels['severity_'.$finding->severity] ?? ucfirst($finding->severity) }}</span>
            <span class="badge {{ $confBadge }}">{{ $labels['conf_'.$finding->confidence] ?? ucfirst($finding->confidence) }}</span>
            <span class="badge badge--neutral">{{ $labels['status_'.$finding->status] ?? ucfirst($finding->status) }}</span>
            <span class="help-text">{{ $finding->website?->name ?? 'Store' }} &middot; {{ $finding->week_key }}</span>
        </div>
        <h1 class="page-title">{{ $rendered['title'] }}</h1>
    </div>
    <div class="page-actions">
        <a href="{{ $analyticsUrl }}" class="btn btn--secondary">{{ $labels['view_analytics'] }}</a>
        <a href="{{ route('action-center.index') }}" class="btn btn--ghost">{{ $labels['back_to_ac'] }}</a>
        <form method="POST" action="{{ route('locale.update') }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $locale === 'bn' ? 'en' : 'bn' }}">
            <button type="submit" class="btn btn--secondary btn--sm">
                {{ $locale === 'bn' ? $labels['lang_en'] : $labels['lang_bn'] }}
            </button>
        </form>
    </div>
</div>

<div class="alert alert--info mb-sm" role="note">
    {{ $labels['hypothesis_disclaimer'] }}
</div>

<div class="panel">
    {{-- 1. Finding (what) --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_1'] }}</h2>
        <p>{{ $rendered['summary'] }}</p>
    </section>

    {{-- 2. Evidence --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_2'] }}</h2>
        <div class="kv-grid mb-sm">
            <div>
                <div class="kv-item-label">{{ $labels['window'] }}</div>
                <div class="kv-item-value mono">{{ $ev['window_from'] ?? '—' }} to {{ $ev['window_to'] ?? '—' }}</div>
            </div>
            <div>
                <div class="kv-item-label">{{ $labels['baseline'] }}</div>
                <div class="kv-item-value">{{ $ev['comparison_baseline'] ?? '—' }}</div>
            </div>
            <div>
                <div class="kv-item-label">{{ $labels['sample_size'] }}</div>
                <div class="kv-item-value mono">{{ $ev['sample_size'] ?? '—' }}</div>
            </div>
            <div>
                <div class="kv-item-label">{{ $labels['source_tables'] }}</div>
                <div class="kv-item-value mono">{{ $tables !== [] ? implode(', ', $tables) : '—' }}</div>
            </div>
            @if(isset($ev['p_value']) && $ev['p_value'] !== null)
                <div>
                    <div class="kv-item-label">p-value / z-score</div>
                    <div class="kv-item-value mono">p={{ $ev['p_value'] }}@if(isset($ev['z_score']) && $ev['z_score'] !== null), z={{ $ev['z_score'] }}@endif</div>
                </div>
            @endif
            <div>
                <div class="kv-item-label">Data-quality score</div>
                <div class="kv-item-value mono">{{ $ev['data_quality_score'] ?? '—' }}/100</div>
            </div>
        </div>
        @if($metrics !== [])
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Measured Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($metrics as $m)
                            <tr>
                                <td>{{ $m['label'] ?? '' }}</td>
                                <td class="mono">{{ $m['value'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- 3. Risk / priority --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_3'] }}</h2>
        <div class="kv-grid">
            <div>
                <div class="kv-item-label">Severity</div>
                <div class="kv-item-value">
                    <span class="badge {{ $sevBadge }}">{{ $labels['severity_'.$finding->severity] ?? ucfirst($finding->severity) }}</span>
                </div>
            </div>
            <div>
                <div class="kv-item-label">{{ $labels['money_at_stake'] }}</div>
                <div class="kv-item-value"><strong>{{ $moneyStr }}</strong></div>
            </div>
        </div>
    </section>

    {{-- 4. Confidence --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_4'] }}</h2>
        <p>
            <span class="badge {{ $confBadge }}">{{ $labels['conf_'.$finding->confidence] ?? ucfirst($finding->confidence) }}</span>
            <span>{{ $rendered['confidence_reason'] }}</span>
        </p>
    </section>

    {{-- 5. Hypotheses (all marked unverified) --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_5'] }}</h2>
        <ul class="hypothesis-list">
            @foreach($rendered['hypotheses'] as $h)
                <li>{{ $h }}</li>
            @endforeach
        </ul>
    </section>

    {{-- 6. Missing data --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_6'] }}</h2>
        @if($rendered['missing_data'] === [])
            <p class="muted">None recorded for this check.</p>
        @else
            <ul class="hypothesis-list">
                @foreach($rendered['missing_data'] as $md)
                    <li>{{ $md }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 7. Recommended action --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_7'] }}</h2>
        <p>{{ $rendered['action'] }}</p>
    </section>

    {{-- 8. Experiment plan --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_8'] }}</h2>
        <p>{{ $rendered['experiment'] }}</p>
    </section>

    {{-- 9. Success metric --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_9'] }}</h2>
        @php $sm = $rendered['success_metric']; @endphp
        <div class="kv-grid">
            <div>
                <div class="kv-item-label">{{ $labels['metric_name'] }}</div>
                <div class="kv-item-value">{{ $sm['metric'] }}</div>
            </div>
            <div>
                <div class="kv-item-label">{{ $labels['baseline_val'] }}</div>
                <div class="kv-item-value mono">{{ $sm['baseline'] }}</div>
            </div>
            <div>
                <div class="kv-item-label">{{ $labels['target_val'] }}</div>
                <div class="kv-item-value mono">{{ $sm['target'] }}</div>
            </div>
            <div>
                <div class="kv-item-label">{{ $labels['duration'] }}</div>
                <div class="kv-item-value">{{ $sm['duration'] }}</div>
            </div>
        </div>
    </section>

    {{-- 10. Status & outcome --}}
    <section class="finding-section">
        <h2 class="finding-section-title">{{ $labels['field_10'] }}</h2>
        <div class="kv-grid mb-sm">
            <div>
                <div class="kv-item-label">Status</div>
                <div class="kv-item-value">{{ $labels['status_'.$finding->status] ?? ucfirst($finding->status) }}</div>
            </div>
            <div>
                <div class="kv-item-label">First / Last seen</div>
                <div class="kv-item-value mono">{{ $finding->first_seen_at?->format('Y-m-d H:i') }} / {{ $finding->last_seen_at?->format('Y-m-d H:i') }}</div>
            </div>
            @if($finding->reviewed_at)
                <div>
                    <div class="kv-item-label">Reviewed by</div>
                    <div class="kv-item-value">{{ $finding->reviewer?->name ?? 'Staff' }} ({{ $finding->reviewed_at->format('Y-m-d H:i') }})</div>
                </div>
            @endif
            @if($finding->dismiss_reason)
                <div>
                    <div class="kv-item-label">Dismiss reason</div>
                    <div class="kv-item-value">{{ $finding->dismiss_reason }}</div>
                </div>
            @endif
            @if($finding->outcome_note)
                <div>
                    <div class="kv-item-label">Outcome note</div>
                    <div class="kv-item-value">{{ $finding->outcome_note }}</div>
                </div>
            @endif
        </div>

        @if($user->canManage() && $finding->isOpen())
            <div class="grid-2">
                <form method="POST" action="{{ route('action-center.findings.review', $finding) }}" class="panel">
                    @csrf
                    <div class="field">
                        <label for="outcome_note">{{ $labels['mark_review'] }} note (optional)</label>
                        <input id="outcome_note" name="outcome_note" type="text" class="input" value="{{ old('outcome_note', $finding->outcome_note) }}" maxlength="500">
                    </div>
                    <button type="submit" class="btn btn--secondary">{{ $labels['mark_review'] }}</button>
                </form>

                <form method="POST" action="{{ route('action-center.findings.dismiss', $finding) }}" class="panel">
                    @csrf
                    <div class="field">
                        <label for="dismiss_reason">{{ $labels['dismiss'] }} reason (required)</label>
                        <input id="dismiss_reason" name="dismiss_reason" type="text" class="input" placeholder="{{ $labels['dismiss_reason_ph'] }}" required minlength="3" maxlength="255">
                    </div>
                    <button type="submit" class="btn btn--danger">{{ $labels['dismiss'] }}</button>
                </form>
            </div>
        @elseif(! $user->canManage())
            <p class="help-text">{{ $labels['view_only'] }}</p>
        @endif
    </section>
</div>
@endsection
