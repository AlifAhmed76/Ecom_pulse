<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Last Year Project')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
@php
    $currentUser = auth()->user();
    $currentTenant = $currentUser?->tenant;
@endphp
<div class="app-shell">
    <aside class="sidebar" data-sidebar aria-label="Primary navigation">
        <a href="{{ route('dashboard') }}" class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true">LY</span>
            <span>Last Year</span>
        </a>

        <nav class="sidebar-nav">
            <div class="nav-group">
                <p class="nav-group-label">Core</p>
                <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    <span>Dashboard</span>
                </a>
                <a href="{{ route('websites.index') }}" class="nav-link {{ request()->routeIs('websites.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg>
                    <span>Websites</span>
                </a>
            </div>

            @if($currentUser && $currentUser->canManage())
                <div class="nav-group">
                    <p class="nav-group-label">Governance</p>
                    <a href="{{ route('workspace.show') }}" class="nav-link {{ request()->routeIs('workspace.*') ? 'is-active' : '' }}">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span>Workspace</span>
                    </a>
                    <a href="{{ route('audit.index') }}" class="nav-link {{ request()->routeIs('audit.*') ? 'is-active' : '' }}">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Audit</span>
                    </a>
                    <a href="{{ route('system.index') }}" class="nav-link {{ request()->routeIs('system.*') ? 'is-active' : '' }}">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>System</span>
                    </a>
                </div>
            @endif
        </nav>

        <div class="sidebar-footer">
            <div>Asia/Dhaka</div>
            <div>Offline Demo Ready</div>
        </div>
    </aside>

    <div class="main-column">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-label="Toggle navigation">
                    Menu
                </button>
                @if($currentTenant)
                    <span class="workspace-pill">
                        <span class="workspace-dot" aria-hidden="true"></span>
                        <span>{{ $currentTenant->name }}</span>
                    </span>
                @endif
            </div>

            <div class="topbar-right">
                @if($currentUser)
                    <div class="user-meta">
                        <span>{{ $currentUser->name }}</span>
                        <span class="badge badge--neutral">{{ ucfirst($currentUser->role) }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="topbar-form">
                        @csrf
                        <button type="submit" class="btn btn--secondary btn--sm">Sign out</button>
                    </form>
                @endif
            </div>
        </header>

        <main class="page-content">
            @if(session('ok'))
                <div class="alert alert--ok" role="status">{{ session('ok') }}</div>
            @endif
            @if(session('err'))
                <div class="alert alert--err" role="alert">{{ session('err') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert--err" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
