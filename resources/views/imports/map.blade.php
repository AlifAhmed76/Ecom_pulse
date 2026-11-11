@extends('layouts.app')

@section('title', 'Map Columns - ' . $batch->filename)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Map Order Columns</h1>
        <p class="page-subtitle">{{ $batch->filename }} &middot; {{ strtoupper($batch->format) }} &middot; Store: {{ $batch->website?->name ?? 'Store' }}</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('imports.discard', $batch) }}" data-confirm="Discard this uploaded file without importing?">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn--danger">Discard file</button>
        </form>
    </div>
</div>

<div class="alert alert--info" role="note">
    <strong>Columns are matched by common names. Check the mapping before importing.</strong>
    Ambiguous slash dates like <code>01/02/2026</code> (both numbers &le; 12) are read as month/day (MM/DD/YYYY). Fields marked with <strong>*</strong> are required (Order ID, plus either Line/Order Total or Unit Price).
</div>

<form method="POST" action="{{ route('imports.confirm', $batch) }}">
    @csrf

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Column Mapping</h2>
            <span class="muted">Detected {{ count($header) }} source columns</span>
        </div>

        @error('map')
            <div class="alert alert--err" role="alert">{{ $message }}</div>
        @enderror

        <div class="grid-2">
            @foreach($fields as $fieldKey => $label)
                <div class="field">
                    <label for="map_{{ $fieldKey }}">{{ $label }}</label>
                    <select id="map_{{ $fieldKey }}" name="map[{{ $fieldKey }}]" class="select">
                        <option value="">&mdash; Not mapped &mdash;</option>
                        @foreach($header as $col)
                            <option value="{{ $col }}" @selected(old("map.{$fieldKey}", $mapping[$fieldKey] ?? null) === $col)>
                                {{ $col }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>

        <div class="inline-actions">
            <button type="submit" class="btn btn--primary">Confirm &amp; import orders</button>
            <a href="{{ route('imports.index') }}" class="btn btn--ghost">Back</a>
        </div>
    </div>
</form>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Sample Preview (First {{ count($sample) }} Rows)</h2>
    </div>

    @if(empty($sample))
        <div class="empty-state">No sample data rows found beneath the header row.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        @foreach($header as $col)
                            <th>{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($sample as $row)
                        <tr>
                            @foreach($header as $col)
                                <td>{{ $row[$col] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
