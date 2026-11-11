@extends('layouts.app')

@section('title', 'Import Courier Report - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Import Courier Report</h1>
        <p class="page-subtitle">Upload a courier statement (.csv, .xlsx, .xml) to match shipments, compute fee coverage, and reconcile COD.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('courier-loss.index') }}" class="btn btn--secondary">Back to Courier &amp; Loss</a>
    </div>
</div>

<div class="alert alert--warn" role="note">
    <strong>UNVERIFIED AGAINST REAL COURIER DATA:</strong>
    Courier column names vary by provider and are <strong>never guessed automatically</strong>. After uploading, explicitly map each column on the next screen using your file's preview rows.
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Upload Courier Statement</h2>
        <span class="muted">Accepted: .csv, .xlsx, .xml (max 20 MB &mdash; private storage, auto-deleted after import)</span>
    </div>

    <form method="POST" action="{{ route('courier-imports.upload') }}" enctype="multipart/form-data" class="form-stack">
        @csrf

        <div class="field">
            <label for="courier_name">Courier Provider Name *</label>
            <input id="courier_name" name="courier_name" type="text" class="input" value="{{ old('courier_name') }}" placeholder="e.g. Pathao, Steadfast, RedX" required>
            @error('courier_name')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="website_id">Limit matching to a specific Website (optional)</label>
            <select id="website_id" name="website_id" class="select">
                <option value="">All websites in workspace</option>
                @foreach($websites as $site)
                    <option value="{{ $site->id }}" @selected((string) old('website_id') === (string) $site->id)>
                        {{ $site->name }} ({{ $site->platform }})
                    </option>
                @endforeach
            </select>
            @error('website_id')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="file">Courier report file (.csv, .xlsx, .xml) *</label>
            <input id="file" name="file" type="file" class="input" accept=".csv,.xlsx,.xml" required>
            @error('file')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn--primary">Upload &amp; map columns</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Courier Import History</h2>
    </div>

    @if($batches->isEmpty())
        <div class="empty-state">No courier reports imported yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Courier</th>
                        <th>File</th>
                        <th>Status</th>
                        <th class="num">Matched / Total</th>
                        <th class="num">Coverage</th>
                        <th>Uploaded</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($batches as $batch)
                        @php
                            $stats = $batch->stats ?? [];
                            $processed = (int) (($stats['matched'] ?? 0) + ($stats['unmatched'] ?? 0));
                            $badge = match ($batch->status) {
                                'done' => 'badge--ok',
                                'failed' => 'badge--danger',
                                'queued', 'processing' => 'badge--info',
                                default => 'badge--warn',
                            };
                        @endphp
                        <tr>
                            <td class="mono">#{{ $batch->id }}</td>
                            <td><strong>{{ $stats['courier'] ?? (($batch->mapping ?? [])['_courier'] ?? '—') }}</strong></td>
                            <td>{{ $batch->filename }}</td>
                            <td><span class="badge {{ $badge }}">{{ ucfirst($batch->status) }}</span></td>
                            <td class="num muted">
                                @if($batch->status === 'done')
                                    {{ number_format((int) ($stats['matched'] ?? 0)) }} / {{ number_format($processed) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="num muted">
                                {{ $batch->status === 'done' && isset($stats['coverage_pct']) ? number_format((float) $stats['coverage_pct'], 1).'%' : '—' }}
                            </td>
                            <td class="muted">{{ $batch->created_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($batch->status === 'uploaded')
                                    <a href="{{ route('courier-imports.map', $batch) }}" class="btn btn--primary btn--sm">Map columns</a>
                                @else
                                    <a href="{{ route('courier-imports.show', $batch) }}" class="btn btn--secondary btn--sm">View result</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($batches->hasPages())
            <div class="pager">
                @if($batches->onFirstPage()) <span class="is-disabled">Previous</span> @else <a href="{{ $batches->previousPageUrl() }}">Previous</a> @endif
                <span class="is-current">Page {{ $batches->currentPage() }} of {{ $batches->lastPage() }}</span>
                @if($batches->hasMorePages()) <a href="{{ $batches->nextPageUrl() }}">Next</a> @else <span class="is-disabled">Next</span> @endif
            </div>
        @endif
    @endif
</div>
@endsection
