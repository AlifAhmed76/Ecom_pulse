@extends('layouts.app')

@section('title', 'Quick File Analysis - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Quick File Analysis</h1>
        <p class="page-subtitle">Upload an order export for an instant summary and chart without saving orders to your main database.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('imports.index') }}" class="btn btn--secondary">Full Order Import</a>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Analyze Export File</h2>
        <span class="muted">Accepted: .csv, .xlsx, .xml (max 20 MB)</span>
    </div>

    <form method="POST" action="{{ route('analysis.analyze') }}" enctype="multipart/form-data" class="form-stack">
        @csrf

        <div class="field">
            <label for="file">Export file</label>
            <input id="file" name="file" type="file" class="input" accept=".csv,.xlsx,.xml" required>
            @error('file')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn--primary">Analyze file now</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Recent File Analyses</h2>
    </div>

    @if($analyses->isEmpty())
        <div class="empty-state">No standalone file analyses saved yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>File</th>
                        <th class="num">Rows Read</th>
                        <th class="num">Orders</th>
                        <th class="num">Gross Sales</th>
                        <th>Analyzed At</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($analyses as $item)
                        <tr>
                            <td><strong>{{ $item->filename }}</strong></td>
                            <td class="num">{{ number_format($item->rows_count) }}</td>
                            <td class="num">{{ number_format((int) ($item->result['orders_count'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((float) ($item->result['gross_sales'] ?? 0), 2) }}</td>
                            <td class="muted">{{ $item->created_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <a href="{{ route('analysis.show', $item) }}" class="btn btn--secondary btn--sm">View report</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($analyses->hasPages())
            <div class="pager">
                @if($analyses->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $analyses->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $analyses->currentPage() }} of {{ $analyses->lastPage() }}</span>
                @if($analyses->hasMorePages())
                    <a href="{{ $analyses->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
