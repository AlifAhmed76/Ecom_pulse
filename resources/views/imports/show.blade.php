@extends('layouts.app')

@section('title', 'Import Result #' . $batch->id)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Import Result #{{ $batch->id }}</h1>
        <p class="page-subtitle">{{ $batch->filename }} &middot; {{ strtoupper($batch->format) }} &middot; Store: {{ $batch->website?->name ?? '—' }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('imports.index') }}" class="btn btn--secondary">Back to imports</a>
        <a href="{{ route('orders.index') }}" class="btn btn--primary">View Orders</a>
    </div>
</div>

@if($batch->status === 'failed')
    <div class="alert alert--err" role="alert">
        <strong>Import failed:</strong> {{ $batch->error ?? 'Unknown processing error.' }}
    </div>
@elseif(in_array($batch->status, ['queued', 'processing'], true))
    <div class="alert alert--info" role="status">
        Batch is currently <strong>{{ $batch->status }}</strong> in the background queue. Reload in a few seconds for completed counts.
    </div>
@elseif($batch->status === 'done')
    @php
        $stats = $batch->stats ?? [];
        $breakdown = $stats['status_breakdown'] ?? [];
        $unmapped = $stats['unmapped_statuses'] ?? [];
        $skippedReasons = $stats['skipped_reasons'] ?? [];
    @endphp

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Rows Read</div>
            <div class="kpi-value">{{ number_format((int) ($stats['rows_read'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Orders Created</div>
            <div class="kpi-value">{{ number_format((int) ($stats['created'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Orders Updated</div>
            <div class="kpi-value">{{ number_format((int) ($stats['updated'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Rows Skipped</div>
            <div class="kpi-value">{{ number_format((int) ($stats['skipped'] ?? 0)) }}</div>
        </div>
    </div>

    @if(!empty($unmapped))
        <div class="alert alert--warn" role="note">
            <strong>Unmapped status words detected:</strong>
            @foreach($unmapped as $rawWord => $cnt)
                <span class="badge badge--neutral">{{ $rawWord }} ({{ $cnt }})</span>
            @endforeach
            These were stored under canonical status <code>other</code> with the original source status preserved.
        </div>
    @endif

    <div class="grid-2">
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Status Mapping Report</h2>
            </div>
            @if(empty($breakdown))
                <div class="empty-state">No status rows processed.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Raw Status</th>
                                <th>Canonical Status</th>
                                <th class="num">Row Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($breakdown as $raw => $info)
                                <tr>
                                    <td>{{ $raw }}</td>
                                    <td><span class="badge badge--neutral">{{ ucfirst($info['canonical'] ?? 'other') }}</span></td>
                                    <td class="num">{{ number_format((int) ($info['count'] ?? 0)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Skipped Rows &amp; Data Hints</h2>
            </div>
            @if(empty($skippedReasons))
                <div class="alert alert--ok alert--inline-note">Zero rows were skipped due to formatting errors.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Skip Reason</th>
                                <th class="num">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($skippedReasons as $reason => $cnt)
                                <tr>
                                    <td class="mono">{{ $reason }}</td>
                                    <td class="num">{{ number_format((int) $cnt) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endif
@endsection
