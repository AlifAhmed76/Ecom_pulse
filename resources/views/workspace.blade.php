@extends('layouts.app')

@section('title', 'Workspace - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Workspace Settings &amp; Members</h1>
        <p class="page-subtitle">Tenant profile and access roles for {{ $tenant->name }}</p>
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
@endsection
