@extends('layouts.app')

@section('title', '#'.$experiment->id.' '.$experiment->title.' - Experiments')

@section('content')
@php
    $badgeClass = match ($experiment->status) {
        'completed' => 'badge--ok',
        'running' => 'badge--info',
        'approved' => 'badge--warn',
        'cancelled' => 'badge--danger',
        default => 'badge--neutral',
    };
    $baseline = is_array($experiment->baseline) ? $experiment->baseline : [];
    $baselineMetrics = is_array($baseline['metrics'] ?? null) ? $baseline['metrics'] : [];
    $sm = is_array($experiment->success_metric) ? $experiment->success_metric : [];

    $stepState = [
        'draft' => $experiment->status === 'draft' ? 'is-active' : 'is-done',
        'approved' => $experiment->status === 'approved'
            ? 'is-active'
            : (in_array($experiment->status, ['running', 'completed'], true) ? 'is-done' : ''),
        'running' => $experiment->status === 'running'
            ? 'is-active'
            : ($experiment->status === 'completed' ? 'is-done' : ''),
        'completed' => $experiment->status === 'completed'
            ? 'is-done'
            : ($experiment->status === 'cancelled' ? 'is-cancelled' : ''),
    ];
@endphp

<div class="page-header">
    <div>
        <div class="finding-meta">
            <span class="mono badge badge--neutral">Experiment #{{ $experiment->id }}</span>
            <span class="badge {{ $badgeClass }}">{{ ucfirst($experiment->status) }}</span>
            @if($experiment->finding)
                <a href="{{ route('action-center.findings.show', $experiment->finding) }}" class="mono badge badge--info">
                    Finding {{ $experiment->finding->rule_code }} #{{ $experiment->finding->id }} ({{ ucfirst($experiment->finding->status) }})
                </a>
            @endif
            <span class="help-text">{{ $experiment->website?->name ?? 'Workspace-wide' }}</span>
        </div>
        <h1 class="page-title">{{ $experiment->title }}</h1>
    </div>
    <div class="page-actions">
        @if($experiment->finding)
            <a href="{{ route('action-center.findings.show', $experiment->finding) }}" class="btn btn--secondary">Open linked finding</a>
        @endif
        <a href="{{ route('experiments.index') }}" class="btn btn--ghost">All experiments</a>
    </div>
</div>

<div class="exp-steps" aria-label="Experiment workflow stages">
    <span class="exp-step {{ $stepState['draft'] }}">1. Draft</span>
    <span class="exp-step {{ $stepState['approved'] }}">2. Approved</span>
    <span class="exp-step {{ $stepState['running'] }}">3. Running</span>
    <span class="exp-step {{ $stepState['completed'] }}">
        @if($experiment->status === 'cancelled')
            4. Cancelled
        @else
            4. Completed
        @endif
    </span>
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Hypothesis &amp; Protocol</h2>
        </div>

        <div class="finding-section">
            <div class="kv-item-label">Hypothesis</div>
            <p>{{ $experiment->hypothesis }}</p>
        </div>

        <div class="finding-section">
            <div class="kv-item-label">Design &amp; Test Protocol</div>
            <p>{{ $experiment->design }}</p>
        </div>

        <div class="finding-section">
            <div class="kv-grid">
                <div>
                    <div class="kv-item-label">Experiment Owner</div>
                    <div class="kv-item-value">{{ $experiment->owner?->name ?? 'Unassigned' }}</div>
                </div>
                <div>
                    <div class="kv-item-label">Minimum Sample / Group</div>
                    <div class="kv-item-value mono">{{ $experiment->min_sample ?? 30 }}</div>
                </div>
                <div>
                    <div class="kv-item-label">Planned Duration</div>
                    <div class="kv-item-value mono">{{ $experiment->planned_days ?? 14 }} days</div>
                </div>
                <div>
                    <div class="kv-item-label">Approved By</div>
                    <div class="kv-item-value">
                        @if($experiment->approver)
                            {{ $experiment->approver->name }} ({{ $experiment->approved_at?->format('Y-m-d H:i') }})
                        @else
                            Pending approval
                        @endif
                    </div>
                </div>
                <div>
                    <div class="kv-item-label">Started At</div>
                    <div class="kv-item-value mono">{{ $experiment->started_at?->format('Y-m-d H:i') ?? 'Not started' }}</div>
                </div>
                <div>
                    <div class="kv-item-label">Ended At</div>
                    <div class="kv-item-value mono">{{ $experiment->ended_at?->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="baseline-box">
            <div class="panel-head">
                <h2 class="panel-title">Immutable Baseline Snapshot</h2>
                <span class="badge badge--info">Frozen {{ $baseline['captured_at'] ?? $experiment->created_at?->format('Y-m-d H:i') }}</span>
            </div>
            <div class="kv-grid">
                <div>
                    <div class="kv-item-label">Source</div>
                    <div class="kv-item-value mono">{{ $baseline['source'] ?? 'finding' }}</div>
                </div>
                <div>
                    <div class="kv-item-label">Metric</div>
                    <div class="kv-item-value">{{ $baseline['metric'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="kv-item-label">Baseline Value</div>
                    <div class="kv-item-value mono"><strong>{{ $baseline['value'] ?? 'n/a' }}</strong></div>
                </div>
                <div>
                    <div class="kv-item-label">Baseline Sample</div>
                    <div class="kv-item-value mono">{{ $baseline['sample_size'] ?? '—' }}</div>
                </div>
                @if(($baseline['window_from'] ?? null) || ($baseline['window_to'] ?? null))
                    <div>
                        <div class="kv-item-label">Window</div>
                        <div class="kv-item-value mono">{{ $baseline['window_from'] ?? '—' }} to {{ $baseline['window_to'] ?? '—' }}</div>
                    </div>
                @endif
                @if(isset($baseline['p_value']) && $baseline['p_value'] !== null)
                    <div>
                        <div class="kv-item-label">Baseline p-value</div>
                        <div class="kv-item-value mono">{{ $baseline['p_value'] }}</div>
                    </div>
                @endif
            </div>

            @if($baselineMetrics !== [])
                <div class="table-wrap mt-sm">
                    <table>
                        <thead>
                            <tr>
                                <th>Snapshot Metric</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($baselineMetrics as $bm)
                                <tr>
                                    <td>{{ $bm['label'] ?? '' }}</td>
                                    <td class="mono">{{ $bm['value'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Success Metric Target</h2>
            </div>
            <div class="kv-grid">
                <div>
                    <div class="kv-item-label">Metric</div>
                    <div class="kv-item-value">{{ $sm['metric'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="kv-item-label">Baseline</div>
                    <div class="kv-item-value mono">{{ $sm['baseline'] ?? 'n/a' }}</div>
                </div>
                <div>
                    <div class="kv-item-label">Target</div>
                    <div class="kv-item-value mono"><strong>{{ $sm['target'] ?? '—' }}</strong></div>
                </div>
                <div>
                    <div class="kv-item-label">Duration</div>
                    <div class="kv-item-value">{{ $sm['duration'] ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Workflow Controls --}}
<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Workflow Control</h2>
        <span class="badge {{ $badgeClass }}">Current status: {{ ucfirst($experiment->status) }}</span>
    </div>

    @if(! $user->canManage())
        <div class="alert alert--info" role="note">
            View only: Only owner or admin users may approve, start, complete, or cancel experiments.
        </div>
    @elseif($experiment->status === 'draft')
        <p class="help-text mb-sm">This draft cannot start until an owner or admin approves it.</p>
        <div class="inline-actions">
            <form method="POST" action="{{ route('experiments.approve', $experiment) }}">
                @csrf
                <button type="submit" class="btn btn--primary">Approve experiment</button>
            </form>

            <form method="POST" action="{{ route('experiments.cancel', $experiment) }}" class="inline-actions">
                @csrf
                <input type="text" name="cancel_reason" class="input input--compact" placeholder="Optional cancellation reason..." maxlength="255">
                <button type="submit" class="btn btn--danger">Cancel draft</button>
            </form>
        </div>
    @elseif($experiment->status === 'approved')
        <p class="help-text mb-sm">Approved by {{ $experiment->approver?->name ?? 'Admin' }}. Start the experiment when the team begins testing manually.</p>
        <div class="inline-actions">
            <form method="POST" action="{{ route('experiments.start', $experiment) }}">
                @csrf
                <button type="submit" class="btn btn--primary">Start running</button>
            </form>

            <form method="POST" action="{{ route('experiments.cancel', $experiment) }}" class="inline-actions">
                @csrf
                <input type="text" name="cancel_reason" class="input input--compact" placeholder="Optional cancellation reason..." maxlength="255">
                <button type="submit" class="btn btn--danger">Cancel</button>
            </form>
        </div>
    @elseif($experiment->status === 'running')
        <p class="help-text mb-sm">Record the measured result, select the human-evaluated outcome, and write the operational lesson learned. Closing updates the linked finding automatically.</p>

        <form method="POST" action="{{ route('experiments.complete', $experiment) }}">
            @csrf

            <div class="grid-2">
                <div>
                    <div class="field">
                        <label for="measured_value">Measured Metric Value</label>
                        <input id="measured_value" name="measured_value" type="text" class="input" required maxlength="255" value="{{ old('measured_value') }}" placeholder="e.g. 11.40%">
                        @error('measured_value') <div class="field-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="measured_summary">Measured Summary (optional)</label>
                        <input id="measured_summary" name="measured_summary" type="text" class="input" maxlength="1000" value="{{ old('measured_summary') }}" placeholder="e.g. Cancel rate dropped across 95 orders in test cohort">
                        @error('measured_summary') <div class="field-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="outcome">Human-Evaluated Outcome</label>
                        <select id="outcome" name="outcome" class="select" required>
                            <option value="">Select outcome...</option>
                            <option value="validated" @selected(old('outcome') === 'validated')>Validated (Hypothesis confirmed)</option>
                            <option value="rejected" @selected(old('outcome') === 'rejected')>Rejected (Did not improve / disproved)</option>
                            <option value="inconclusive" @selected(old('outcome') === 'inconclusive')>Inconclusive (Needs further testing or more sample)</option>
                        </select>
                        @error('outcome') <div class="field-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="lesson">Lesson Learned (stored &amp; shown on finding)</label>
                        <textarea id="lesson" name="lesson" class="textarea" required minlength="5" maxlength="2000" placeholder="Document what worked, what failed, operational constraints, and next recommendation...">{{ old('lesson') }}</textarea>
                        @error('lesson') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div>
                    <div class="baseline-box">
                        <h3 class="panel-title">Optional Two-Proportion Significance Helper (Rate Metrics)</h3>
                        <p class="help-text mb-sm">Computes pooled two-proportion z-test as advisory evidence. Below minimum sample ({{ $experiment->min_sample ?? 30 }} per group), significance is strictly withheld.</p>

                        <div class="grid-2">
                            <div class="field">
                                <label for="control_success">Control / Baseline Successes</label>
                                <input id="control_success" name="control_success" type="number" min="0" class="input" value="{{ old('control_success') }}" placeholder="e.g. 42">
                                @error('control_success') <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="field">
                                <label for="control_n">Control / Baseline Sample (n)</label>
                                <input id="control_n" name="control_n" type="number" min="1" class="input" value="{{ old('control_n') }}" placeholder="e.g. 100">
                                @error('control_n') <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="field">
                                <label for="variant_success">Variant / Test Successes</label>
                                <input id="variant_success" name="variant_success" type="number" min="0" class="input" value="{{ old('variant_success') }}" placeholder="e.g. 68">
                                @error('variant_success') <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="field">
                                <label for="variant_n">Variant / Test Sample (n)</label>
                                <input id="variant_n" name="variant_n" type="number" min="1" class="input" value="{{ old('variant_n') }}" placeholder="e.g. 100">
                                @error('variant_n') <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="inline-actions mt-sm">
                <button type="submit" class="btn btn--primary">Complete experiment &amp; update finding</button>
            </div>
        </form>

        <hr>

        <form method="POST" action="{{ route('experiments.cancel', $experiment) }}" class="inline-actions mt-sm">
            @csrf
            <input type="text" name="cancel_reason" class="input input--compact" placeholder="Optional cancellation reason..." maxlength="255">
            <button type="submit" class="btn btn--danger btn--sm">Cancel running experiment</button>
        </form>
    @else
        <p class="muted">This experiment is closed in terminal status <strong>{{ $experiment->status }}</strong> and cannot transition further.</p>
    @endif
</div>

{{-- Recorded Results --}}
<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Recorded Results &amp; Lessons ({{ $experiment->results->count() }})</h2>
    </div>

    @if($experiment->results->isEmpty())
        <p class="muted">No result recorded yet.</p>
    @else
        <div class="finding-list">
            @foreach($experiment->results as $res)
                @php
                    $resBadge = match ($res->outcome) {
                        'validated' => 'badge--ok',
                        'rejected' => 'badge--danger',
                        default => 'badge--warn',
                    };
                    $measured = is_array($res->measured) ? $res->measured : [];
                    $sig = is_array($measured['significance'] ?? null) ? $measured['significance'] : null;
                @endphp
                <div class="finding-card">
                    <div class="finding-card-head">
                        <div class="finding-meta">
                            <span class="badge {{ $resBadge }}">Outcome: {{ ucfirst($res->outcome) }}</span>
                            <span class="help-text">Recorded by {{ $res->recorder?->name ?? 'Staff' }} at {{ $res->recorded_at?->format('Y-m-d H:i') }}</span>
                        </div>
                    </div>

                    <div class="kv-grid mb-sm">
                        <div>
                            <div class="kv-item-label">Measured Metric</div>
                            <div class="kv-item-value mono"><strong>{{ $measured['metric_value'] ?? '—' }}</strong></div>
                        </div>
                        @if(($measured['summary'] ?? '') !== '')
                            <div>
                                <div class="kv-item-label">Summary</div>
                                <div class="kv-item-value">{{ $measured['summary'] }}</div>
                            </div>
                        @endif
                    </div>

                    <p><strong>Lesson:</strong> {{ $res->lesson }}</p>

                    @if($sig !== null)
                        <div class="baseline-box mt-sm">
                            <div class="panel-head">
                                <span class="kv-item-label">Two-Proportion Significance Helper</span>
                                @if(! empty($sig['statistically_significant']))
                                    <span class="badge badge--ok">Statistically significant (p &lt; 0.05)</span>
                                @elseif(empty($sig['sufficient_sample']))
                                    <span class="badge badge--warn">Below minimum sample ({{ $sig['min_sample_required'] ?? 30 }} per group)</span>
                                @else
                                    <span class="badge badge--neutral">Not statistically significant</span>
                                @endif
                            </div>
                            <div class="kv-grid">
                                <div>
                                    <div class="kv-item-label">Control Rate</div>
                                    <div class="kv-item-value mono">{{ $sig['control_success'] ?? 0 }}/{{ $sig['control_n'] ?? 0 }} ({{ $sig['control_rate_pct'] ?? '—' }}%)</div>
                                </div>
                                <div>
                                    <div class="kv-item-label">Variant Rate</div>
                                    <div class="kv-item-value mono">{{ $sig['variant_success'] ?? 0 }}/{{ $sig['variant_n'] ?? 0 }} ({{ $sig['variant_rate_pct'] ?? '—' }}%)</div>
                                </div>
                                <div>
                                    <div class="kv-item-label">Diff / p-value</div>
                                    <div class="kv-item-value mono">{{ $sig['diff_pp'] ?? '—' }} pp &middot; p={{ $sig['p_value'] ?? '—' }}</div>
                                </div>
                            </div>
                            <p class="help-text mt-sm">{{ $sig['note'] ?? '' }}</p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
