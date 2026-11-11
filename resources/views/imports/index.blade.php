@extends('layouts.app')

@section('title', 'Import Orders - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Import Orders</h1>
        <p class="page-subtitle">Upload CSV, XLSX, or XML order exports. Files are processed privately and deleted automatically afterwards.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('analysis.index') }}" class="btn btn--secondary">Quick File Analysis</a>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Upload Export File</h2>
        <span class="muted">Accepted: .csv, .xlsx, .xml (max 20 MB &mdash; legacy .xls not accepted)</span>
    </div>

    @if($websites->isEmpty())
        <div class="empty-state">
            <p>Register a website (or file-only store) first before importing orders.</p>
            <a href="{{ route('websites.create') }}" class="btn btn--primary">Register website</a>
        </div>
    @else
        <form method="POST" action="{{ route('imports.upload') }}" enctype="multipart/form-data" class="form-stack">
            @csrf

            <div class="field">
                <label for="website_id">Target website / store</label>
                <select id="website_id" name="website_id" class="select" required>
                    @foreach($websites as $site)
                        <option value="{{ $site->id }}" @selected(old('website_id') == $site->id)>
                            {{ $site->name }} ({{ $site->platform }})
                        </option>
                    @endforeach
                </select>
                @error('website_id')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="file">Export file (.csv, .xlsx, .xml)</label>
                <input id="file" name="file" type="file" class="input" accept=".csv,.xlsx,.xml" required>
                <div class="help-text">
                    XML files containing <code>DOCTYPE</code> or entity declarations are automatically rejected.
                </div>
                @error('file')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn btn--primary">Upload &amp; detect columns</button>
        </form>
    @endif
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Import History</h2>
    </div>

    @if($batches->isEmpty())
        <div class="empty-state">No import batches uploaded yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>File</th>
                        <th>Store</th>
                        <th>Format</th>
                        <th>Status</th>
                        <th class="num">Created / Updated</th>
                        <th>Uploaded</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($batches as $batch)
                        @php
                            $stats = $batch->stats ?? [];
                            $badge = match ($batch->status) {
                                'done' => 'badge--ok',
                                'failed' => 'badge--danger',
                                'queued', 'processing' => 'badge--info',
                                default => 'badge--warn',
                            };
                        @endphp
                        <tr>
                            <td class="mono">#{{ $batch->id }}</td>
                            <td><strong>{{ $batch->filename }}</strong></td>
                            <td>{{ $batch->website?->name ?? '—' }}</td>
                            <td><span class="badge badge--neutral">{{ strtoupper($batch->format) }}</span></td>
                            <td><span class="badge {{ $badge }}">{{ ucfirst($batch->status) }}</span></td>
                            <td class="num muted">
                                @if($batch->status === 'done')
                                    {{ number_format((int) ($stats['created'] ?? 0)) }} / {{ number_format((int) ($stats['updated'] ?? 0)) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="muted">{{ $batch->created_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($batch->status === 'uploaded')
                                    <a href="{{ route('imports.map', $batch) }}" class="btn btn--primary btn--sm">Map columns</a>
                                @else
                                    <a href="{{ route('imports.show', $batch) }}" class="btn btn--secondary btn--sm">View result</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($batches->hasPages())
            <div class="pager">
                @if($batches->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $batches->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $batches->currentPage() }} of {{ $batches->lastPage() }}</span>
                @if($batches->hasMorePages())
                    <a href="{{ $batches->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
