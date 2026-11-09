@extends('layouts.guest')

@section('title', 'Last Year Project - E-commerce Intelligence & R&D SaaS')

@section('content')
<div class="landing-wrap">
    <nav class="landing-nav" aria-label="Public navigation">
        <a href="{{ url('/') }}" class="auth-brand">
            <span class="brand-mark" aria-hidden="true">LY</span>
            <span>Last Year Project</span>
        </a>
        <div class="landing-cta">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn--primary">Open Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="btn btn--secondary">Sign in</a>
                <a href="{{ route('register') }}" class="btn btn--primary">Create workspace</a>
            @endauth
        </div>
    </nav>

    <section class="landing-hero">
        <span class="landing-eyebrow">E-commerce Intelligence &amp; R&amp;D Lab</span>
        <h1 class="landing-title">See where revenue is leaking, why it may happen, and what to test next.</h1>
        <p class="landing-lead">
            Connect existing stores and courier/ad exports into one multi-tenant workspace. Deterministic code computes every metric and financial figure; optional AI explains evidence without guessing or altering store state.
        </p>
        <div class="landing-cta">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn--primary">Go to Dashboard</a>
            @else
                <a href="{{ route('register') }}" class="btn btn--primary">Get started</a>
                <a href="{{ route('login') }}" class="btn btn--secondary">Sign in to existing workspace</a>
            @endauth
        </div>
    </section>

    <div class="grid-2">
        <div class="panel">
            <h2 class="panel-title">Tenant-isolated foundation</h2>
            <p class="muted">Every store workspace, credential, and report is strictly isolated by company tenant and role-based policies.</p>
        </div>
        <div class="panel">
            <h2 class="panel-title">Evidence before hypothesis</h2>
            <p class="muted">Courier loss, cancellation spikes, and R&amp;D experiments rely on deterministic calculations and transparent sample sizes.</p>
        </div>
    </div>
</div>
@endsection
