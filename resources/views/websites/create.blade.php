@extends('layouts.app')

@section('title', 'Add Website - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Register Website</h1>
        <p class="page-subtitle">Add a Laravel store connector or a file-only store for CSV/Excel imports.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('websites.index') }}" class="btn btn--secondary">Back to websites</a>
    </div>
</div>

<div class="panel">
    <form method="POST" action="{{ route('websites.store') }}" class="form-stack">
        @csrf

        <div class="field">
            <label for="name">Store name</label>
            <input id="name" name="name" type="text" class="input" value="{{ old('name') }}" maxlength="150" required autofocus>
            @error('name')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="platform">Platform mode</label>
            <select id="platform" name="platform" class="select" required>
                <option value="laravel" @selected(old('platform', 'laravel') === 'laravel')>Laravel connector (API sync)</option>
                <option value="file" @selected(old('platform') === 'file')>File-only imports (no domain required)</option>
            </select>
            @error('platform')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="domain">Store domain</label>
            <input id="domain" name="domain" type="text" class="input" value="{{ old('domain') }}" placeholder="shop.example.com" maxlength="253">
            <div class="help-text">
                Normalized automatically (scheme, <code>www.</code>, port, and path stripped). Required for Laravel connectors; optional for file-only stores.
            </div>
            @error('domain')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="currency">Primary currency</label>
            <input id="currency" name="currency" type="text" class="input" value="{{ old('currency', 'BDT') }}" maxlength="3" pattern="[A-Za-z]{3}">
            <div class="help-text">3-letter ISO currency code such as BDT or USD.</div>
            @error('currency')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="inline-actions">
            <button type="submit" class="btn btn--primary">Register website</button>
            <a href="{{ route('websites.index') }}" class="btn btn--ghost">Cancel</a>
        </div>
    </form>
</div>
@endsection
