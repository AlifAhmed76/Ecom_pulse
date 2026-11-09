@extends('layouts.app')

@section('title', 'Audit Log - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Audit Log</h1>
        <p class="page-subtitle">Tamper-evident hash-chained activity ledger scoped to {{ auth()->user()->tenant->name }}.</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('audit.verify') }}">
            @csrf
            <button type="submit" class="btn btn--primary">Verify chain integrity</button>
        </form>
    </div>
</div>

@if($verifyResult !== null)
    @if(!empty($verifyResult['valid']))
        <div class="alert alert--ok" role="status">
            <strong>Chain valid.</strong> Hash linkage verified with zero tampering across {{ number_format((int) $verifyResult['tenant_rows']) }} workspace record(s).
        </div>
    @else
        <div class="alert alert--err" role="alert">
            <strong>Verification failed.</strong> Hash-chain mismatch detected (an audit entry was edited or removed).
        </div>
    @endif
@endif

<div class="panel">
    @if($logs->isEmpty())
        <div class="empty-state">
            No audit events recorded in this workspace yet.
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Action</th>
                        <th>Actor</th>
                        <th>Details</th>
                        <th>Entry Hash</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td class="mono">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td><span class="badge badge--neutral">{{ $log->action }}</span></td>
                            <td>{{ $log->user ? $log->user->name : 'System / Unauthenticated' }}</td>
                            <td>{{ $log->details }}</td>
                            <td class="mono muted">{{ substr($log->hash, 0, 12) }}&hellip;</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="pager">
                @if($logs->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $logs->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
                @if($logs->hasMorePages())
                    <a href="{{ $logs->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
