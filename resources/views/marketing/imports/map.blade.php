@extends('layouts.app')

@section('title', 'Map Ad Report Columns - ' . $batch->filename)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Map Ad Report Columns</h1>
        <p class="page-subtitle">{{ $batch->filename }} &middot; {{ strtoupper($batch->format) }} &middot; Platform: {{ ucfirst($platform) }}</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('marketing-imports.discard', $batch) }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn--danger">Discard file</button>
        </form>
    </div>
</div>

<div class="alert alert--warn" role="note">
    <strong>Explicit mapping required (no guessed column aliases):</strong>
    Map <strong>Reporting Date *</strong>, <strong>Campaign Name *</strong>, and <strong>Ad Spend *</strong> below. Match Campaign Name cleanly to your store checkout <span class="mono">orders.utm_campaign</span> values for Real ROAS attribution.
</div>

<form method="POST" action="{{ route('marketing-imports.confirm', $batch) }}">
    @csrf

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Column Mapping</h2>
            <span class="muted">Detected {{ count($header) }} source columns</span>
        </div>

        @error('platform')
            <div class="alert alert--err" role="alert">{{ $message }}</div>
        @enderror
        @error('map')
            <div class="alert alert--err" role="alert">{{ $message }}</div>
        @enderror

        <div class="field field--narrow">
            <label for="platform">Ad Platform *</label>
            <select id="platform" name="platform" class="select" required>
                @foreach($platforms as $p)
                    <option value="{{ $p }}" @selected(old('platform', $platform) === $p)>{{ ucfirst($p) }}</option>
                @endforeach
            </select>
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
            <button type="submit" class="btn btn--primary">Confirm &amp; import ad report</button>
            <a href="{{ route('marketing-imports.index') }}" class="btn btn--ghost">Back</a>
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
