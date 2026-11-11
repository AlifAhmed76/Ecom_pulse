@extends('layouts.app')

@section('title', 'Create Experiment - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">
            @if($finding)
                New Experiment from Finding {{ $finding->rule_code }} (#{{ $finding->id }})
            @else
                New Manual Experiment
            @endif
        </h1>
        <p class="page-subtitle">Starts in Draft status with an immutable baseline snapshot. Owner or admin approval is required before starting.</p>
    </div>
    <div class="page-actions">
        @if($finding)
            <a href="{{ route('action-center.findings.show', $finding) }}" class="btn btn--secondary">Back to finding</a>
        @endif
        <a href="{{ route('experiments.index') }}" class="btn btn--ghost">All experiments</a>
    </div>
</div>

@if($finding && $prefill)
    <div class="baseline-box">
        <div class="panel-head">
            <h2 class="panel-title">Immutable Baseline Snapshot (Copied from {{ $finding->rule_code }})</h2>
            <span class="badge badge--info">Locked on creation</span>
        </div>
        <div class="kv-grid">
            <div>
                <div class="kv-item-label">Website</div>
                <div class="kv-item-value">{{ $finding->website?->name ?? 'Workspace-wide' }}</div>
            </div>
            <div>
                <div class="kv-item-label">Baseline Metric</div>
                <div class="kv-item-value">{{ $prefill['baseline']['metric'] ?? 'Finding KPI' }}</div>
            </div>
            <div>
                <div class="kv-item-label">Baseline Value</div>
                <div class="kv-item-value mono">{{ $prefill['baseline']['value'] ?? 'n/a' }}</div>
            </div>
            <div>
                <div class="kv-item-label">Sample Size (n)</div>
                <div class="kv-item-value mono">{{ $prefill['baseline']['sample_size'] ?? '—' }}</div>
            </div>
            <div>
                <div class="kv-item-label">Observation Window</div>
                <div class="kv-item-value mono">{{ $prefill['baseline']['window_from'] ?? '—' }} to {{ $prefill['baseline']['window_to'] ?? '—' }}</div>
            </div>
            <div>
                <div class="kv-item-label">Money at Stake</div>
                <div class="kv-item-value mono">
                    @if(($prefill['baseline']['money_at_stake'] ?? null) !== null)
                        {{ $prefill['baseline']['currency'] ?? 'BDT' }} {{ number_format((float) $prefill['baseline']['money_at_stake'], 2) }}
                    @else
                        Informational
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

<div class="panel">
    <form method="POST" action="{{ route('experiments.store') }}">
        @csrf

        @if($finding)
            <input type="hidden" name="finding_id" value="{{ $finding->id }}">
        @endif

        <div class="grid-2">
            <div>
                @if(! $finding)
                    <div class="field">
                        <label for="website_id">Website (optional)</label>
                        <select id="website_id" name="website_id" class="select">
                            <option value="">Workspace-wide (all stores)</option>
                            @foreach($websites as $site)
                                <option value="{{ $site->id }}" @selected((string) old('website_id') === (string) $site->id)>
                                    {{ $site->name }} ({{ $site->currency }})
                                </option>
                            @endforeach
                        </select>
                        @error('website_id') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @endif

                <div class="field">
                    <label for="title">Experiment Title</label>
                    <input id="title" name="title" type="text" class="input" required minlength="3" maxlength="255" value="{{ old('title', $prefill['title'] ?? '') }}">
                    @error('title') <div class="field-error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="hypothesis">Hypothesis (must remain falsifiable)</label>
                    <textarea id="hypothesis" name="hypothesis" class="textarea" required minlength="5" maxlength="2000">{{ old('hypothesis', $prefill['hypothesis'] ?? '') }}</textarea>
                    @error('hypothesis') <div class="field-error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="design">Experiment Design &amp; Guardrails</label>
                    <textarea id="design" name="design" class="textarea" required minlength="5" maxlength="4000">{{ old('design', $prefill['design'] ?? '') }}</textarea>
                    @error('design') <div class="field-error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <div class="field">
                    <label for="owner_user_id">Experiment Owner</label>
                    <select id="owner_user_id" name="owner_user_id" class="select">
                        @foreach($tenantUsers as $member)
                            <option value="{{ $member->id }}" @selected((string) old('owner_user_id', $user->id) === (string) $member->id)>
                                {{ $member->name }} ({{ ucfirst($member->role) }})
                            </option>
                        @endforeach
                    </select>
                    @error('owner_user_id') <div class="field-error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="metric_name">Success Metric Name</label>
                    <input id="metric_name" name="metric_name" type="text" class="input" required minlength="2" maxlength="255" value="{{ old('metric_name', $prefill['success_metric']['metric'] ?? '') }}">
                    @error('metric_name') <div class="field-error">{{ $message }}</div> @enderror
                </div>

                @if(! $finding)
                    <div class="grid-2">
                        <div class="field">
                            <label for="baseline_value">Baseline Value (frozen on save)</label>
                            <input id="baseline_value" name="baseline_value" type="text" class="input" maxlength="255" value="{{ old('baseline_value') }}" placeholder="e.g. 22.5%">
                            @error('baseline_value') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="field">
                            <label for="baseline_sample">Baseline Sample Size (n)</label>
                            <input id="baseline_sample" name="baseline_sample" type="number" min="0" max="1000000" class="input" value="{{ old('baseline_sample') }}" placeholder="e.g. 120">
                            @error('baseline_sample') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                    </div>
                @endif

                <div class="field">
                    <label for="target_value">Success Target</label>
                    <input id="target_value" name="target_value" type="text" class="input" required minlength="1" maxlength="255" value="{{ old('target_value', $prefill['success_metric']['target'] ?? '') }}" placeholder="e.g. <= 14.0%">
                    @error('target_value') <div class="field-error">{{ $message }}</div> @enderror
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label for="min_sample">Minimum Sample per Group</label>
                        <input id="min_sample" name="min_sample" type="number" min="1" max="1000000" class="input" value="{{ old('min_sample', $prefill['min_sample'] ?? 30) }}">
                        <div class="help-text">System never declares statistical significance below this count.</div>
                        @error('min_sample') <div class="field-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="planned_days">Planned Duration (days)</label>
                        <input id="planned_days" name="planned_days" type="number" min="1" max="365" class="input" value="{{ old('planned_days', $prefill['planned_days'] ?? 14) }}">
                        @error('planned_days') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="inline-actions mt-sm">
            <button type="submit" class="btn btn--primary">Create draft experiment</button>
            <a href="{{ $finding ? route('action-center.findings.show', $finding) : route('experiments.index') }}" class="btn btn--ghost">Cancel</a>
        </div>
    </form>
</div>
@endsection
