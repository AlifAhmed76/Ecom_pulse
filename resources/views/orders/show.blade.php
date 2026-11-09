@extends('layouts.app')

@section('title', 'Order ' . $order->external_id . ' - Orders')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Order {{ $order->external_id }}</h1>
        <p class="page-subtitle">
            {{ $order->website?->name ?? 'Store' }}
            &middot; Ordered {{ $order->ordered_at?->format('Y-m-d H:i') }}
            &middot; Source: {{ ucfirst($order->source) }}
        </p>
    </div>
    <div class="page-actions">
        <a href="{{ route('orders.index') }}" class="btn btn--secondary">Back to orders</a>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Order Summary</h2>
        <span class="badge badge--neutral">{{ ucfirst($order->status) }}</span>
    </div>

    <div class="kv-grid">
        <div>
            <div class="kv-item-label">Canonical / Source Status</div>
            <div class="kv-item-value">{{ ucfirst($order->status) }} ({{ $order->source_status ?? 'n/a' }})</div>
        </div>
        <div>
            <div class="kv-item-label">Customer</div>
            <div class="kv-item-value">
                {{ $order->customer?->name ?? 'Unnamed' }}
                @if($order->customer)
                    <span class="mono muted">#{{ substr($order->customer->customer_key, 0, 12) }}</span>
                @endif
            </div>
        </div>
        <div>
            <div class="kv-item-label">Assigned Employee</div>
            <div class="kv-item-value">{{ $order->assignment?->employee?->name ?? 'Unassigned' }}</div>
        </div>
        <div>
            <div class="kv-item-label">Courier &amp; Consignment</div>
            <div class="kv-item-value">
                {{ $order->courier ?? 'None' }}
                @if($order->consignment_id)
                    &middot; <span class="mono">{{ $order->consignment_id }}</span>
                @endif
            </div>
        </div>
        <div>
            <div class="kv-item-label">City / Campaign</div>
            <div class="kv-item-value">{{ $order->city ?? '—' }} @if($order->utm_campaign) &middot; {{ $order->utm_campaign }} @endif</div>
        </div>
        <div>
            <div class="kv-item-label">Financial Snapshot ({{ $order->currency }})</div>
            <div class="kv-item-value tabular">
                Total <strong>{{ number_format((float) $order->total, 2) }}</strong>
                <span class="muted">(Sub {{ number_format((float) $order->subtotal, 2) }} &minus; Disc {{ number_format((float) $order->discount, 2) }} + Ship {{ number_format((float) $order->shipping, 2) }})</span>
            </div>
        </div>
    </div>
    @if($order->cancel_reason)
        <div class="alert alert--warn alert--inline-note" role="note">
            Cancel reason: {{ $order->cancel_reason }}
        </div>
    @endif
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Line Items ({{ $order->items->count() }})</h2>
        </div>

        @if($order->items->isEmpty())
            <div class="empty-state">No item-level lines were attached to this order.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th class="num">Qty</th>
                            <th class="num">Price</th>
                            <th class="num">Unit Cost</th>
                            <th class="num">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($order->items as $item)
                            <tr>
                                <td>{{ $item->product_name }}</td>
                                <td class="mono muted">{{ $item->sku ?? '—' }}</td>
                                <td class="num">{{ number_format($item->quantity) }}</td>
                                <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="num muted">{{ $item->unit_cost !== null ? number_format((float) $item->unit_cost, 2) : '—' }}</td>
                                <td class="num"><strong>{{ number_format((float) $item->line_total, 2) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Status History (Append-Only)</h2>
        </div>

        @if($order->statusHistory->isEmpty())
            <div class="empty-state">No status transitions recorded.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Changed At</th>
                            <th>Transition</th>
                            <th>Source</th>
                            <th>Employee</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($order->statusHistory as $event)
                            <tr>
                                <td class="mono muted">{{ $event->changed_at?->format('Y-m-d H:i:s') }}</td>
                                <td>
                                    @if($event->from_status)
                                        <span class="muted">{{ ucfirst($event->from_status) }} &rarr;</span>
                                    @endif
                                    <strong>{{ ucfirst($event->to_status) }}</strong>
                                </td>
                                <td><span class="badge badge--neutral">{{ $event->source }}</span></td>
                                <td>{{ $event->employee?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
