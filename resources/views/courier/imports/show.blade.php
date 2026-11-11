@extends('layouts.app')

@section('title', 'Courier Import Result #' . $batch->id)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Courier Import Result #{{ $batch->id }}</h1>
        <p class="page-subtitle">{{ $batch->filename }} &middot; Courier: {{ ($batch->stats['courier'] ?? null) ?? (($batch->mapping['_courier'] ?? null) ?? '—') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('courier-imports.index') }}" class="btn btn--secondary">Back to courier imports</a>
        <a href="{{ route('courier-loss.index') }}" class="btn btn--primary">View Courier &amp; Loss Report</a>
    </div>
</div>

@if($batch->status === 'failed')
    <div class="alert alert--err" role="alert">
        <strong>Import failed:</strong> {{ $batch->error ?? 'Unknown error.' }}
    </div>
@elseif(in_array($batch->status, ['queued', 'processing'], true))
    <div class="alert alert--info" role="status">
        Batch is currently <strong>{{ $batch->status }}</strong> in the background queue.
    </div>
@elseif($batch->status === 'done')
    @php
        $stats = $batch->stats ?? [];
        $methods = $stats['match_methods'] ?? [];
        $breakdown = $stats['status_breakdown'] ?? [];
        $skippedReasons = $stats['skipped_reasons'] ?? [];
    @endphp

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Rows Read</div>
            <div class="kpi-value">{{ number_format((int) ($stats['rows_read'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Matched Shipments</div>
            <div class="kpi-value">{{ number_format((int) ($stats['matched'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Unmatched (Kept)</div>
            <div class="kpi-value">{{ number_format((int) ($stats['unmatched'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Matching Coverage</div>
            <div class="kpi-value">{{ number_format((float) ($stats['coverage_pct'] ?? 0), 2) }}%</div>
            <div class="kpi-meta">
                By Consignment: {{ (int) ($methods['consignment_id'] ?? 0) }} &middot;
                Order Ref: {{ (int) ($methods['order_ref'] ?? 0) }} &middot;
                Contact+COD: {{ (int) ($methods['customer_amount'] ?? 0) }}
            </div>
        </div>
    </div>

    <div class="grid-2">
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Status Mapping Report</h2></div>
            @if(empty($breakdown))
                <div class="empty-state">No rows processed.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Raw Status</th>
                                <th>Canonical Status</th>
                                <th class="num">Count</th>
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
            <div class="panel-head"><h2 class="panel-title">Skipped Rows</h2></div>
            @if(empty($skippedReasons))
                <div class="alert alert--ok alert--inline-note">Zero rows skipped due to formatting errors.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reason</th>
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
