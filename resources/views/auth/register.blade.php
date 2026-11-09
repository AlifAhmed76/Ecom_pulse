@extends('layouts.guest')

@section('title', 'Create Workspace - Last Year Project')

@section('content')
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <a href="{{ url('/') }}" class="auth-brand">
                <span class="brand-mark" aria-hidden="true">LY</span>
                <span>Last Year Project</span>
            </a>
            <h1 class="auth-title">Create company workspace</h1>
            <p class="auth-subtitle">Sets up an isolated tenant and your owner account.</p>
        </div>

        @if($errors->any())
            <div class="alert alert--err" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('register.post') }}">
            @csrf

            <div class="field">
                <label for="company_name">Company / store name</label>
                <input id="company_name" name="company_name" type="text" class="input" value="{{ old('company_name') }}" required maxlength="100" autofocus>
            </div>

            <div class="field">
                <label for="name">Your full name</label>
                <input id="name" name="name" type="text" class="input" value="{{ old('name') }}" required maxlength="100" autocomplete="name">
            </div>

            <div class="field">
                <label for="email">Work email</label>
                <input id="email" name="email" type="email" class="input" value="{{ old('email') }}" required autocomplete="email">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" class="input" required minlength="10" autocomplete="new-password">
                <p class="help-text">At least 10 characters with both letters and numbers.</p>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="input" required minlength="10" autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn--primary btn-block">Create workspace</button>
        </form>

        <div class="auth-footer">
            Already have a workspace? <a href="{{ route('login') }}">Sign in</a>
        </div>
    </div>
</div>
@endsection
