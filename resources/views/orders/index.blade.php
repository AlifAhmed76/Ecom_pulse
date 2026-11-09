@extends('layouts.app')

@section('title', 'Orders - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Orders</h1>
        <p class="page-subtitle">Read-only normalised order ledger synced from stores or imported from spreadsheets.</p>
    </div>
</div>

<div class="panel">
    <form method="GET" action="{{ route('orders.index') }}" class="filter-bar">
        <div class="field">
            <label for="search">Order / Consignment ID</label>
            <input id="search" name="search" type="text" class="input" value="{{ $filters['search'] }}" placeholder="Search external ID or consignment...">
        </div>

        <div class="field">
            <label for="status">Canonical Status</label>
            <select id="status" name="status" class="select">
                <option value="">All statuses</option>
                @foreach($statuses as $st)
                    <option value="{{ $st }}" @selected($filters['status'] === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="website_id">Website</label>
            <select id="website_id" name="website_id" class="select">
                <option value="">All websites</option>
                @foreach($websites as $site)
                    <option value="{{ $site->id }}" @selected((string) $filters['website_id'] === (string) $site->id)>{{ $site->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field field--fit">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="{{ route('orders.index') }}" class="btn btn--ghost">Reset</a>
        </div>
    </form>
</div>

<div class="panel">
    @if($orders->isEmpty())
        <div class="empty-state">
            <p>No orders match the current filter.</p>
            <p>Connect a store via API or import orders (available in the Import module) to populate this ledger.</p>
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Store</th>
                        <th>Ordered At</th>
                        <th>Status</th>
                        <th>Customer</th>
                        <th>Courier</th>
                        <th>Employee</th>
                        <th class="num">Items</th>
                        <th class="num">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $order)
                        @php
                            $badgeClass = match ($order->status) {
                                'delivered', 'confirmed' => 'badge--ok',
                                'shipped' => 'badge--info',
                                'pending', 'returned' => 'badge--warn',
                                'cancelled' => 'badge--danger',
                                default => 'badge--neutral',
                            };
                        @endphp
                        <tr>
                            <td class="mono">
                                <a href="{{ route('orders.show', $order) }}"><strong>{{ $order->external_id }}</strong></a>
                            </td>
                            <td>{{ $order->website?->name ?? '—' }}</td>
                            <td class="mono muted">{{ $order->ordered_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <span class="badge {{ $badgeClass }}">{{ ucfirst($order->status) }}</span>
                            </td>
                            <td>{{ $order->customer?->name ?? ($order->customer ? 'Customer #'.substr($order->customer->customer_key, 0, 8) : 'Anonymous') }}</td>
                            <td>{{ $order->courier ?? '—' }}</td>
                            <td>{{ $order->assignment?->employee?->name ?? 'Unassigned' }}</td>
                            <td class="num">{{ number_format((int) $order->items_count) }}</td>
                            <td class="num">{{ number_format((float) $order->total, 2) }} {{ $order->currency }}</td>
                            <td>
                                <a href="{{ route('orders.show', $order) }}" class="btn btn--secondary btn--sm">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($orders->hasPages())
            <div class="pager">
                @if($orders->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $orders->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $orders->currentPage() }} of {{ $orders->lastPage() }}</span>
                @if($orders->hasMorePages())
                    <a href="{{ $orders->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
