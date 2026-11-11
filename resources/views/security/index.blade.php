@extends('layouts.app')

@section('title', 'Security Events & Rules - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Security Events &amp; App-Level Rules</h1>
        <p class="page-subtitle">Workspace security signals for {{ auth()->user()->tenant->name }} (log-only by default, manual review required).</p>
    </div>
</div>

<div class="alert alert--warn">
    <strong>Important Security Policy:</strong> All rules start in <strong>log_only</strong> mode with zero automatic IP blocking. Heuristic matches such as <span class="mono">/wp-admin</span>, <span class="mono">.php</span> probes, or VPS/emulator traffic alone are <strong>NOT proof of malicious intent</strong> — review false positives before enabling opt-in rate throttling. External storefront WAF/DDoS protection requires a staged Cloudflare DNS/WAF rollout (documented in <span class="mono">docs/CLOUDFLARE_WAF.md</span>); a token-only integration cannot protect all traffic of an external site.
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Per-Tenant Security Rules (Log-Only Default)</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Signal Type</th>
                    <th>Mode</th>
                    <th>Window Threshold</th>
                    <th>Status</th>
                    <th>Update</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rules as $rule)
                    <tr>
                        <td class="mono"><strong>{{ $rule->type }}</strong></td>
                        <td>
                            <span class="badge {{ $rule->mode === 'throttle' ? 'badge--warn' : 'badge--neutral' }}">
                                {{ $rule->mode }}
                            </span>
                        </td>
                        <td class="num">{{ $rule->threshold }}</td>
                        <td>
                            <span class="badge {{ $rule->enabled ? 'badge--ok' : 'badge--danger' }}">
                                {{ $rule->enabled ? 'Active' : 'Disabled' }}
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('security.rules.update', $rule) }}" class="inline-actions">
                                @csrf
                                <select name="mode" class="select input--compact" aria-label="Rule mode">
                                    <option value="log_only" @selected($rule->mode === 'log_only')>log_only</option>
                                    <option value="throttle" @selected($rule->mode === 'throttle')>throttle (opt-in)</option>
                                </select>
                                <input type="number" name="threshold" min="1" max="1000" value="{{ $rule->threshold }}" class="input input--compact" aria-label="Threshold">
                                <label class="checkbox-row">
                                    <input type="checkbox" name="enabled" value="1" @checked($rule->enabled)>
                                    <span>Enabled</span>
                                </label>
                                <button type="submit" class="btn btn--secondary btn--sm">Save</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Security Event Log</h2>
        <form method="GET" action="{{ route('security.index') }}" class="filter-bar">
            <div class="field field--fit">
                <select name="type" class="select input--compact" aria-label="Filter type">
                    <option value="">All types</option>
                    @foreach(['login_failed', 'rate_limit_hit', 'signature_failed', 'widget_auth_failed', 'suspicious_request'] as $t)
                        <option value="{{ $t }}" @selected($typeFilter === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field field--fit">
                <select name="review_status" class="select input--compact" aria-label="Filter status">
                    <option value="">All statuses</option>
                    @foreach(['open', 'false_positive', 'confirmed'] as $st)
                        <option value="{{ $st }}" @selected($statusFilter === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn--secondary btn--sm">Filter</button>
        </form>
    </div>

    @if($events->isEmpty())
        <div class="empty-state">No workspace security events recorded yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Type</th>
                        <th>Severity</th>
                        <th>Path (Escaped)</th>
                        <th>Sanitized Meta</th>
                        <th>Review Status</th>
                        <th>Review Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $event)
                        <tr>
                            <td class="mono">{{ $event->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="mono">{{ $event->type }}</td>
                            <td>
                                <span class="badge {{ in_array($event->severity, ['high', 'medium'], true) ? 'badge--warn' : 'badge--neutral' }}">
                                    {{ $event->severity }}
                                </span>
                            </td>
                            <td class="mono">{{ $event->path }}</td>
                            <td class="mono">{{ json_encode($event->meta ?? [], JSON_UNESCAPED_UNICODE) }}</td>
                            <td>
                                <span class="badge {{ $event->review_status === 'confirmed' ? 'badge--danger' : ($event->review_status === 'false_positive' ? 'badge--ok' : 'badge--neutral') }}">
                                    {{ $event->review_status }}
                                </span>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('security.events.review', $event) }}" class="inline-actions">
                                    @csrf
                                    <select name="review_status" class="select input--compact" aria-label="Review decision">
                                        <option value="open" @selected($event->review_status === 'open')>open</option>
                                        <option value="false_positive" @selected($event->review_status === 'false_positive')>false_positive</option>
                                        <option value="confirmed" @selected($event->review_status === 'confirmed')>confirmed</option>
                                    </select>
                                    <button type="submit" class="btn btn--secondary btn--sm">Update</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($events->hasPages())
            <div class="pager">
                @if($events->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $events->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $events->currentPage() }} of {{ $events->lastPage() }}</span>
                @if($events->hasMorePages())
                    <a href="{{ $events->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
