@extends('layouts.app')

@section('title', 'Workspace - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Workspace Settings &amp; Members</h1>
        <p class="page-subtitle">Tenant profile, demo mode, GDPR export, and access roles for {{ $tenant->name }}</p>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Company</div>
        <div class="kpi-value">{{ $tenant->name }}</div>
        <div class="kpi-meta mono">{{ $tenant->slug }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Team Members</div>
        <div class="kpi-value">{{ $members->total() }}</div>
        <div class="kpi-meta">Scoped strictly to tenant #{{ $tenant->id }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Demo Dataset</div>
        <div class="kpi-value">{{ $demoSummary['has_demo'] ? 'Loaded' : 'Off' }}</div>
        <div class="kpi-meta">
            {{ number_format($demoSummary['demo_orders']) }} demo orders / {{ number_format($demoSummary['non_demo_orders']) }} non-demo orders
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Authorized Users</h2>
        <span class="badge badge--info">Owner / Admin Access Only</span>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>
                @forelse($members as $member)
                    <tr>
                        <td><strong>{{ $member->name }}</strong></td>
                        <td class="mono">{{ $member->email }}</td>
                        <td>
                            @if($member->isOwner())
                                <span class="badge badge--ok">Owner</span>
                            @elseif($member->canManage())
                                <span class="badge badge--info">Admin</span>
                            @else
                                <span class="badge badge--neutral">Analyst</span>
                            @endif
                        </td>
                        <td class="muted tabular">{{ $member->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="empty-state">No users found for this tenant.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($members->hasPages())
        <div class="pager">
            {{ $members->links() }}
        </div>
    @endif
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">Demo Mode (Offline Presentation Dataset)</h2>
            <p class="help-text">
                Deterministically seeds 1 website, 40 products with costs, 2,500 orders over 120 days, 3 couriers, 4 employees, courier shipments, and ad rows marked <span class="mono">source=demo</span>.
            </p>
        </div>
        <span class="badge {{ $demoSummary['has_demo'] ? 'badge--warn' : 'badge--neutral' }}">
            {{ $demoSummary['has_demo'] ? 'Demo Active' : 'Not Loaded' }}
        </span>
    </div>

    @if($user->isOwner())
        <div class="inline-actions">
            @if(! $demoSummary['has_demo'])
                <form method="POST" action="{{ route('workspace.demo.load') }}">
                    @csrf
                    <button type="submit" class="btn btn--primary">Load demo data</button>
                </form>
            @else
                <form method="POST" action="{{ route('workspace.demo.remove') }}" data-confirm="Remove all source=demo records from {{ $tenant->name }}? Real/non-demo records will stay intact.">
                    @csrf
                    <button type="submit" class="btn btn--danger">Remove demo data</button>
                </form>
            @endif
        </div>
    @else
        <div class="alert alert--info mb-sm" role="note">
            Only the workspace <strong>Owner</strong> may load or remove the demo dataset.
        </div>
    @endif
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">Workspace Data Export (CSV ZIP)</h2>
            <p class="help-text">
                Queued archive of tenant-owned tables as RFC-4180 CSVs with spreadsheet formula injection neutralized (<span class="mono">= + - @</span> prefixed) and secrets omitted.
            </p>
        </div>
        @if($user->isOwner())
            <form method="POST" action="{{ route('workspace.export') }}">
                @csrf
                <button type="submit" class="btn btn--secondary">Queue workspace export</button>
            </form>
        @endif
    </div>

    @if(! $user->isOwner())
        <div class="alert alert--info mb-sm" role="note">
            Only the workspace <strong>Owner</strong> may export full workspace records.
        </div>
    @elseif($exports->isEmpty())
        <div class="empty-state">No workspace exports requested yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Archive</th>
                        <th>Status</th>
                        <th class="num">Rows</th>
                        <th class="num">Size</th>
                        <th>Requested</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($exports as $exp)
                        <tr>
                            <td class="mono">{{ $exp->filename }}</td>
                            <td>
                                @if($exp->status === 'ready')
                                    <span class="badge badge--ok">Ready</span>
                                @elseif($exp->status === 'failed')
                                    <span class="badge badge--danger">Failed</span>
                                @else
                                    <span class="badge badge--info">{{ ucfirst($exp->status) }}</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format((int) $exp->rows_exported) }}</td>
                            <td class="num">{{ $exp->size_bytes !== null ? number_format((int) ceil($exp->size_bytes / 1024)).' KB' : '—' }}</td>
                            <td class="muted tabular">{{ $exp->created_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($exp->isReady())
                                    <a href="{{ route('workspace.exports.download', $exp) }}" class="btn btn--primary btn--sm">Download ZIP</a>
                                @elseif($exp->error)
                                    <span class="help-text">{{ $exp->error }}</span>
                                @else
                                    <span class="muted">Processing…</span>
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
        <div>
            <h2 class="panel-title">Danger Zone: Permanent Workspace Deletion</h2>
            <p class="help-text">
                Queues permanent deletion of this company workspace, all users, websites, orders, shipments, R&amp;D findings, chatbots, and private files. Tamper-evident audit entries remain sealed.
            </p>
        </div>
        <span class="badge badge--danger">Owner Only</span>
    </div>

    @if($user->isOwner())
        <form method="POST" action="{{ route('workspace.delete') }}" class="form-stack" data-confirm="Permanently delete workspace '{{ $tenant->name }}' and all its data? This cannot be undone.">
            @csrf
            <div class="field">
                <label for="confirm_slug">Type the workspace slug <span class="mono">{{ $tenant->slug }}</span> to confirm permanent deletion</label>
                <input id="confirm_slug" name="confirm_slug" type="text" class="input" required placeholder="{{ $tenant->slug }}">
                @error('confirm_slug')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>
            <button type="submit" class="btn btn--danger">Delete workspace permanently</button>
        </form>
    @else
        <div class="alert alert--warn mb-sm" role="note">
            Only the workspace <strong>Owner</strong> may permanently delete tenant data.
        </div>
    @endif
</div>
@endsection
