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
                <a href="{{ route('orders.index') }}" class="nav-link {{ request()->routeIs('orders.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    <span>Orders</span>
                </a>
                <a href="{{ route('products.index') }}" class="nav-link {{ request()->routeIs('products.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    <span>Products</span>
                </a>
                <a href="{{ route('analytics.index') }}" class="nav-link {{ request()->routeIs('analytics.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    <span>Analytics</span>
                </a>
                <a href="{{ route('courier-loss.index') }}" class="nav-link {{ request()->routeIs('courier-loss.*') || request()->routeIs('courier-imports.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    <span>Courier &amp; Loss</span>
                </a>
                <a href="{{ route('marketing.index') }}" class="nav-link {{ request()->routeIs('marketing.*') || request()->routeIs('marketing-imports.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3v18h18"/><path d="M18.7 8l-5.1 5.2-2.8-2.7L7 14.3"/></svg>
                    <span>Marketing</span>
                </a>
                <a href="{{ route('action-center.index') }}" class="nav-link {{ request()->routeIs('action-center.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.7.5 1 1.3 1 2.3h6c0-1 .3-1.8 1-2.3A7 7 0 0 0 12 2z"/></svg>
                    <span>Action Center</span>
                </a>
                <a href="{{ route('experiments.index') }}" class="nav-link {{ request()->routeIs('experiments.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 3h6"/><path d="M10 3v6l-5 9a2 2 0 0 0 1.7 3h10.6A2 2 0 0 0 19 18l-5-9V3"/><path d="M7.5 14h9"/></svg>
                    <span>Experiments</span>
                </a>
                <a href="{{ route('websites.index') }}" class="nav-link {{ request()->routeIs('websites.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg>
                    <span>Websites</span>
                </a>
                <a href="{{ route('chatbots.index') }}" class="nav-link {{ request()->routeIs('chatbots.*') ? 'is-active' : '' }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>Chatbots</span>
                </a>
                @if($currentUser && $currentUser->canManage())
                    <a href="{{ route('imports.index') }}" class="nav-link {{ request()->routeIs('imports.*') || request()->routeIs('analysis.*') ? 'is-active' : '' }}">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span>Import</span>
                    </a>
                @endif
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
                    <a href="{{ route('security.index') }}" class="nav-link {{ request()->routeIs('security.*') ? 'is-active' : '' }}">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2l7 4v6c0 5-3.5 9.5-7 10-3.5-.5-7-5-7-10V6l7-4z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                        <span>Security</span>
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
                    @php
                        $activeLocale = session('locale', 'en') === 'bn' ? 'bn' : 'en';
                    @endphp
                    <div class="lang-switch" aria-label="Language switch">
                        <form method="POST" action="{{ route('locale.update') }}" class="topbar-form">
                            @csrf
                            <input type="hidden" name="locale" value="en">
                            <button type="submit" class="lang-btn {{ $activeLocale === 'en' ? 'is-active' : '' }}">EN</button>
                        </form>
                        <form method="POST" action="{{ route('locale.update') }}" class="topbar-form">
                            @csrf
                            <input type="hidden" name="locale" value="bn">
                            <button type="submit" class="lang-btn {{ $activeLocale === 'bn' ? 'is-active' : '' }}">বাংলা</button>
                        </form>
                    </div>
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
@stack('scripts')
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
