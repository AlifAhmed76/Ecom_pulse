@extends('layouts.app')

@section('title', 'Websites - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Websites</h1>
        <p class="page-subtitle">Stores registered for signed connector feeds or file-only imports.</p>
    </div>
    @can('create', \App\Models\Website::class)
        <div class="page-actions">
            <a href="{{ route('websites.create') }}" class="btn btn--primary">Add website</a>
        </div>
    @endcan
</div>

<div class="panel">
    @if($websites->isEmpty())
        <div class="empty-state">
            <p>No websites registered in this workspace yet.</p>
            @can('create', \App\Models\Website::class)
                <p>Register your Laravel store domain or add a file-only store to start syncing orders.</p>
                <a href="{{ route('websites.create') }}" class="btn btn--primary">Register first website</a>
            @else
                <p>Ask a workspace owner or admin to register your first store.</p>
            @endcan
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Platform</th>
                        <th>Domain</th>
                        <th>Currency</th>
                        <th>Ownership</th>
                        <th>Status</th>
                        <th class="num">Credentials</th>
                        <th>Last Heartbeat</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($websites as $site)
                        <tr>
                            <td>
                                <a href="{{ route('websites.show', $site) }}"><strong>{{ $site->name }}</strong></a>
                            </td>
                            <td>
                                <span class="badge badge--neutral">{{ $site->platform === 'laravel' ? 'Laravel connector' : 'File only' }}</span>
                            </td>
                            <td class="mono">{{ $site->domain ?? '—' }}</td>
                            <td>{{ $site->currency }}</td>
                            <td>
                                @if($site->isVerified())
                                    <span class="badge badge--ok">Verified</span>
                                @else
                                    <span class="badge badge--warn">Not verified</span>
                                @endif
                            </td>
                            <td>
                                @if($site->status === 'active')
                                    <span class="badge badge--ok">Active</span>
                                @elseif($site->status === 'disabled')
                                    <span class="badge badge--danger">Disabled</span>
                                @else
                                    <span class="badge badge--neutral">Pending</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format($site->credentials_count) }}</td>
                            <td class="muted">{{ $site->last_heartbeat_at ? $site->last_heartbeat_at->format('Y-m-d H:i') : 'Never' }}</td>
                            <td>
                                <a href="{{ route('websites.show', $site) }}" class="btn btn--secondary btn--sm">Manage</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($websites->hasPages())
            <div class="pager">
                @if($websites->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $websites->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $websites->currentPage() }} of {{ $websites->lastPage() }}</span>
                @if($websites->hasMorePages())
                    <a href="{{ $websites->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
