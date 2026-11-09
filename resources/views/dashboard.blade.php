@extends('layouts.app')

@section('title', 'Dashboard - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Workspace Overview</h1>
        <p class="page-subtitle">Isolated tenant workspace for {{ $tenant->name }}</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Account &amp; Tenancy Active</h2>
        <span class="badge badge--ok">Tenant Isolated</span>
    </div>
    <p class="muted">
        Signed in as <strong>{{ $user->name }}</strong> ({{ $user->email }}) with role <strong>{{ ucfirst($user->role) }}</strong>.
        Workspace identifier: <span class="mono">{{ $tenant->slug }}</span>.
    </p>
</div>

@if($user->canManage())
    <div class="quick-links">
        <a href="{{ route('workspace.show') }}" class="quick-link-card">
            <span class="quick-link-title">Workspace &amp; Members</span>
            <span class="quick-link-desc">Inspect company workspace isolation and active user roles.</span>
        </a>
    </div>
@endif
@endsection
