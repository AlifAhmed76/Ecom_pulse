@extends('layouts.app')

@section('title', 'Ad Import Result #' . $batch->id)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Ad Import Result #{{ $batch->id }}</h1>
        <p class="page-subtitle">
            {{ $batch->filename }} &middot; Platform: {{ ucfirst((string) (($batch->stats['platform'] ?? null) ?? ($batch->mapping['_platform'] ?? 'other'))) }}
        </p>
    </div>
    <div class="page-actions">
        <a href="{{ route('marketing-imports.index') }}" class="btn btn--secondary">Back to ad imports</a>
        <a href="{{ route('marketing.index') }}" class="btn btn--primary">Open Marketing Dashboard</a>
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
        $skippedReasons = $stats['skipped_reasons'] ?? [];
    @endphp

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Rows Read</div>
            <div class="kpi-value">{{ number_format((int) ($stats['rows_read'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Daily Rows Created</div>
            <div class="kpi-value">{{ number_format((int) ($stats['created'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Daily Rows Updated</div>
            <div class="kpi-value">{{ number_format((int) ($stats['updated'] ?? 0)) }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Skipped Rows</div>
            <div class="kpi-value">{{ number_format((int) ($stats['skipped'] ?? 0)) }}</div>
            @if(!empty($stats['date_min']) && !empty($stats['date_max']))
                <div class="kpi-meta">{{ $stats['date_min'] }} &ndash; {{ $stats['date_max'] }}</div>
            @endif
        </div>
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
@endif
@endsection
