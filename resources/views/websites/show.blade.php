@extends('layouts.app')

@section('title', $website->name . ' - Websites')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $website->name }}</h1>
        <p class="page-subtitle">
            {{ $website->platform === 'laravel' ? 'Laravel connector store' : 'File-only import store' }}
            @if($website->domain)
                &middot; <span class="mono">{{ $website->domain }}</span>
            @endif
        </p>
    </div>
    <div class="page-actions">
        <a href="{{ route('websites.index') }}" class="btn btn--secondary">Back to websites</a>
        @can('delete', $website)
            <form method="POST" action="{{ route('websites.destroy', $website) }}" data-confirm="Remove this website and revoke all its API credentials?">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn--danger">Delete website</button>
            </form>
        @endcan
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Store Overview</h2>
        @if($website->isVerified())
            <span class="badge badge--ok">Verified</span>
        @else
            <span class="badge badge--warn">Not verified</span>
        @endif
    </div>

    <div class="kv-grid">
        <div>
            <div class="kv-item-label">Platform</div>
            <div class="kv-item-value">{{ $website->platform === 'laravel' ? 'Laravel connector' : 'File only' }}</div>
        </div>
        <div>
            <div class="kv-item-label">Normalized Domain</div>
            <div class="kv-item-value mono">{{ $website->domain ?? 'None (file-only)' }}</div>
        </div>
        <div>
            <div class="kv-item-label">Currency</div>
            <div class="kv-item-value">{{ $website->currency }}</div>
        </div>
        <div>
            <div class="kv-item-label">Status</div>
            <div class="kv-item-value">{{ ucfirst($website->status) }}</div>
        </div>
        <div>
            <div class="kv-item-label">Ownership Status</div>
            <div class="kv-item-value">{{ $website->isVerified() ? 'Verified' : 'Not verified' }}</div>
        </div>
        <div>
            <div class="kv-item-label">Last Heartbeat</div>
            <div class="kv-item-value muted">{{ $website->last_heartbeat_at ? $website->last_heartbeat_at->format('Y-m-d H:i') : 'No heartbeat received yet' }}</div>
        </div>
    </div>
</div>

@can('manage', $website)
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Issue Connector Credential</h2>
        </div>

        <form method="POST" action="{{ route('websites.credentials.store', $website) }}" class="form-stack">
            @csrf

            <div class="field">
                <label>Allowed scopes</label>
                @foreach(\App\Models\ApiCredential::SCOPES as $scopeKey => $scopeLabel)
                    <label class="checkbox-row">
                        <input type="checkbox" name="scopes[]" value="{{ $scopeKey }}" @checked(in_array($scopeKey, old('scopes', ['heartbeat']), true))>
                        <span><strong>{{ $scopeKey }}</strong> &mdash; {{ $scopeLabel }}</span>
                    </label>
                @endforeach
                @error('scopes')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="expires_in_days">Expiry (optional days)</label>
                <input id="expires_in_days" name="expires_in_days" type="number" class="input" min="1" max="3650" value="{{ old('expires_in_days') }}" placeholder="Leave blank for no automatic expiry">
                @error('expires_in_days')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn btn--primary">Generate credential</button>
        </form>
    </div>
@endcan

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">API Credentials</h2>
        <span class="muted">Secrets are encrypted at rest and never shown after creation.</span>
    </div>

    @if($credentials->isEmpty())
        <div class="empty-state">
            No API credentials issued yet for this website.
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Key ID</th>
                        <th>Scopes</th>
                        <th>State</th>
                        <th>Expires</th>
                        <th>Last Used</th>
                        @can('manage', $website)
                            <th>Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($credentials as $credential)
                        @php $state = $credential->state(); @endphp
                        <tr>
                            <td class="mono">{{ $credential->key_id }}</td>
                            <td>
                                <div class="scope-list">
                                    @foreach($credential->scopes ?? [] as $scope)
                                        <span class="badge badge--neutral">{{ $scope }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @if($state === 'active')
                                    <span class="badge badge--ok">Active</span>
                                @elseif($state === 'revoked')
                                    <span class="badge badge--danger">Revoked</span>
                                @else
                                    <span class="badge badge--warn">Expired</span>
                                @endif
                            </td>
                            <td class="muted">{{ $credential->expires_at ? $credential->expires_at->format('Y-m-d H:i') : 'Never' }}</td>
                            <td class="muted">{{ $credential->last_used_at ? $credential->last_used_at->format('Y-m-d H:i') : 'Never' }}</td>
                            @can('manage', $website)
                                <td>
                                    @if($credential->isUsable())
                                        <div class="inline-actions">
                                            <form method="POST" action="{{ route('websites.credentials.rotate', [$website, $credential]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn--secondary btn--sm">Rotate</button>
                                            </form>
                                            <form method="POST" action="{{ route('websites.credentials.revoke', [$website, $credential]) }}" data-confirm="Revoke this credential immediately? Any connector using it will receive 401.">
                                                @csrf
                                                <button type="submit" class="btn btn--danger btn--sm">Revoke</button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="muted">No actions</span>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
