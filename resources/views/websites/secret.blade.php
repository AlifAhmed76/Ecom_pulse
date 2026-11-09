@extends('layouts.app')

@section('title', 'Credential Secret - ' . $website->name)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Connector Credential {{ $action === 'rotated' ? 'Rotated' : 'Issued' }}</h1>
        <p class="page-subtitle">{{ $website->name }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('websites.show', $website) }}" class="btn btn--primary">Done, return to website</a>
    </div>
</div>

<div class="alert alert--warn" role="alert">
    <strong>Copy this API secret now.</strong> It is displayed only once in this response and cannot be viewed again after you leave or reload this page.
    @if($action === 'rotated')
        The previous credential now expires automatically in 1 hour.
    @endif
</div>

<div class="panel">
    <div class="field">
        <label>Key ID (public identifier)</label>
        <div class="inline-actions">
            <div id="credential-key-id" class="mono-box">{{ $credential->key_id }}</div>
            <button type="button" class="btn btn--secondary btn--sm" data-copy-target="credential-key-id">Copy Key ID</button>
        </div>
    </div>

    <div class="field">
        <label>Signing Secret (HMAC-SHA256)</label>
        <div class="inline-actions">
            <div id="credential-secret" class="mono-box">{{ $secret }}</div>
            <button type="button" class="btn btn--primary btn--sm" data-copy-target="credential-secret">Copy Secret</button>
        </div>
    </div>

    <div class="kv-grid">
        <div>
            <div class="kv-item-label">Scopes</div>
            <div class="scope-list">
                @foreach($credential->scopes ?? [] as $scope)
                    <span class="badge badge--neutral">{{ $scope }}</span>
                @endforeach
            </div>
        </div>
        <div>
            <div class="kv-item-label">Expires At</div>
            <div class="kv-item-value muted">{{ $credential->expires_at ? $credential->expires_at->format('Y-m-d H:i') : 'Never' }}</div>
        </div>
    </div>
</div>
@endsection
