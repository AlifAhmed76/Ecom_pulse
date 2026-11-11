@extends('layouts.app')

@section('title', 'Experiments - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Experiments (R&amp;D Tracker)</h1>
        <p class="page-subtitle">Closed-loop tracking from finding &rarr; hypothesis &rarr; owner/admin approval &rarr; measured result &rarr; lesson.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('action-center.index') }}" class="btn btn--secondary">Action Center</a>
        <a href="{{ route('experiments.create') }}" class="btn btn--primary">+ New experiment</a>
    </div>
</div>

<div class="alert alert--info mb-sm" role="note">
    Experiments require owner or admin approval before running, freeze their baseline snapshot at creation, and never mutate live store pricing or budgets automatically.
</div>

<div class="panel">
    <form method="GET" action="{{ route('experiments.index') }}" class="filter-bar">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" class="select">
                <option value="all" @selected($filters['status'] === 'all')>All statuses</option>
                @foreach(\App\Models\Experiment::STATUSES as $st)
                    <option value="{{ $st }}" @selected($filters['status'] === $st)>
                        {{ ucfirst($st) }} ({{ (int) ($statusCounts[$st] ?? 0) }})
                    </option>
                @endforeach
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

        <div class="field field--fit">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="{{ route('experiments.index') }}" class="btn btn--ghost">Reset</a>
        </div>
    </form>
</div>

<div class="panel">
    @if($experiments->isEmpty())
        <div class="empty-state">
            <p><strong>No experiments match the current filter.</strong></p>
            <p class="help-text">Open a finding in Action Center and click &ldquo;Create experiment&rdquo; to prefill baseline metrics and test protocol, or start a manual experiment above.</p>
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID &amp; Title</th>
                        <th>Finding</th>
                        <th>Website</th>
                        <th>Baseline &rarr; Target</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Outcome &amp; Lesson</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($experiments as $exp)
                        @php
                            $badgeClass = match ($exp->status) {
                                'completed' => 'badge--ok',
                                'running' => 'badge--info',
                                'approved' => 'badge--warn',
                                'cancelled' => 'badge--danger',
                                default => 'badge--neutral',
                            };
                            $latestResult = $exp->results->first();
                            $resBadge = $latestResult ? match ($latestResult->outcome) {
                                'validated' => 'badge--ok',
                                'rejected' => 'badge--danger',
                                default => 'badge--warn',
                            } : null;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('experiments.show', $exp) }}"><strong>#{{ $exp->id }} {{ $exp->title }}</strong></a>
                                <div class="help-text">Min sample: {{ $exp->min_sample ?? 30 }} &middot; {{ $exp->planned_days ?? 14 }}d</div>
                            </td>
                            <td>
                                @if($exp->finding)
                                    <a href="{{ route('action-center.findings.show', $exp->finding) }}" class="mono badge badge--neutral">
                                        {{ $exp->finding->rule_code }} #{{ $exp->finding->id }}
                                    </a>
                                @else
                                    <span class="muted">Manual</span>
                                @endif
                            </td>
                            <td>{{ $exp->website?->name ?? 'Workspace-wide' }}</td>
                            <td>
                                <span class="mono">{{ $exp->baseline['value'] ?? '—' }}</span>
                                &rarr;
                                <span class="mono">{{ $exp->success_metric['target'] ?? '—' }}</span>
                            </td>
                            <td>
                                <div>{{ $exp->owner?->name ?? 'Unassigned' }}</div>
                                @if($exp->approver)
                                    <div class="help-text">Approved: {{ $exp->approver->name }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $badgeClass }}">{{ ucfirst($exp->status) }}</span>
                            </td>
                            <td>
                                @if($latestResult)
                                    <span class="badge {{ $resBadge }}">{{ ucfirst($latestResult->outcome) }}</span>
                                    <div class="help-text">{{ \Illuminate\Support\Str::limit($latestResult->lesson, 70) }}</div>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('experiments.show', $exp) }}" class="btn btn--secondary btn--sm">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($experiments->hasPages())
            <div class="pager">
                @if($experiments->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $experiments->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $experiments->currentPage() }} of {{ $experiments->lastPage() }}</span>
                @if($experiments->hasMorePages())
                    <a href="{{ $experiments->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
