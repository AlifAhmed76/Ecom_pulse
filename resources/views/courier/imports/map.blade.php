@extends('layouts.app')

@section('title', 'Map Courier Columns - ' . $batch->filename)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Map Courier Columns</h1>
        <p class="page-subtitle">{{ $batch->filename }} &middot; {{ strtoupper($batch->format) }} &middot; Courier: {{ $courierName }}</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('courier-imports.discard', $batch) }}" data-confirm="Discard this uploaded courier file without importing?">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn--danger">Discard file</button>
        </form>
    </div>
</div>

<div class="alert alert--warn" role="note">
    <strong>Explicit mapping required (no guessed column aliases):</strong>
    Select Courier Delivery Status (*) and at least one order-matching column (Consignment ID, Order Reference, or Customer Contact).
    Matching priority: Consignment ID &rarr; Order Reference &rarr; Hashed Contact + COD Amount (&plusmn;1 day, unique only).
</div>

<form method="POST" action="{{ route('courier-imports.confirm', $batch) }}">
    @csrf

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Column Mapping</h2>
            <span class="muted">Detected {{ count($header) }} source columns</span>
        </div>

        @error('courier_name')
            <div class="alert alert--err" role="alert">{{ $message }}</div>
        @enderror
        @error('map')
            <div class="alert alert--err" role="alert">{{ $message }}</div>
        @enderror

        <div class="field field--narrow">
            <label for="courier_name">Courier Provider Name *</label>
            <input id="courier_name" name="courier_name" type="text" class="input" value="{{ old('courier_name', $courierName) }}" required>
        </div>

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
            <button type="submit" class="btn btn--primary">Confirm &amp; import courier report</button>
            <a href="{{ route('courier-imports.index') }}" class="btn btn--ghost">Back</a>
        </div>
    </div>
</form>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Sample Preview (First {{ count($sample) }} Rows)</h2>
    </div>

    @if(empty($sample))
        <div class="empty-state">No sample rows found beneath the header row.</div>
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
