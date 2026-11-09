@extends('layouts.guest')

@section('title', 'Sign in - Last Year Project')

@section('content')
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <a href="{{ url('/') }}" class="auth-brand">
                <span class="brand-mark" aria-hidden="true">LY</span>
                <span>Last Year Project</span>
            </a>
            <h1 class="auth-title">Sign in to your workspace</h1>
            <p class="auth-subtitle">Enter your company account credentials.</p>
        </div>

        @if($errors->any())
            <div class="alert alert--err" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.post') }}">
            @csrf

            <div class="field">
                <label for="email">Work email</label>
                <input id="email" name="email" type="email" class="input" value="{{ old('email') }}" required autofocus autocomplete="email">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" class="input" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn--primary btn-block">Sign in</button>
        </form>

        <div class="auth-footer">
            Need a new company workspace? <a href="{{ route('register') }}">Create workspace</a>
        </div>
    </div>
</div>
@endsection
