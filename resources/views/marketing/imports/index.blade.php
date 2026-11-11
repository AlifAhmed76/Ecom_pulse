@extends('layouts.app')

@section('title', 'Import Ad Report - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Import Ad Report</h1>
        <p class="page-subtitle">Upload a daily ad report (.csv, .xlsx, .xml) to compare platform-reported metrics against real delivered store orders.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('marketing.index') }}" class="btn btn--secondary">Back to Marketing</a>
    </div>
</div>

<div class="alert alert--warn" role="note">
    <strong>UNVERIFIED AGAINST REAL AD CSV DATA:</strong>
    Ad manager column headers vary by export locale and account setup and are <strong>never guessed automatically</strong>. Select the platform first, upload your export, and map columns explicitly on the next screen. Re-importing the same file updates existing <span class="mono">(platform, campaign, date)</span> rows idempotently without duplicating records.
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Upload Ad Platform Export</h2>
        <span class="muted">Accepted: .csv, .xlsx, .xml (max 20 MB &mdash; private storage, auto-deleted after import)</span>
    </div>

    <form method="POST" action="{{ route('marketing-imports.upload') }}" enctype="multipart/form-data" class="form-stack">
        @csrf

        <div class="field">
            <label for="platform">Ad Platform *</label>
            <select id="platform" name="platform" class="select" required>
                @foreach($platforms as $p)
                    <option value="{{ $p }}" @selected(old('platform', 'meta') === $p)>{{ ucfirst($p) }}</option>
                @endforeach
            </select>
            @error('platform')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="website_id">Assign to Website (optional)</label>
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
            <label for="file">Ad report file (.csv, .xlsx, .xml) *</label>
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
        <h2 class="panel-title">Ad Import History</h2>
    </div>

    @if($batches->isEmpty())
        <div class="empty-state">No ad reports imported yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Platform</th>
                        <th>File</th>
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
                            <td><strong>{{ ucfirst((string) ($stats['platform'] ?? ($batch->mapping['_platform'] ?? 'other'))) }}</strong></td>
                            <td>{{ $batch->filename }}</td>
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
                                    <a href="{{ route('marketing-imports.map', $batch) }}" class="btn btn--primary btn--sm">Map columns</a>
                                @else
                                    <a href="{{ route('marketing-imports.show', $batch) }}" class="btn btn--secondary btn--sm">View result</a>
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
