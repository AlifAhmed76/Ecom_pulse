@extends('layouts.app')

@section('title', 'Products - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Products</h1>
        <p class="page-subtitle">Read-only catalogue synced from connected stores (low-stock threshold: &le; {{ $lowStockThreshold }}).</p>
    </div>
</div>

<div class="panel">
    <form method="GET" action="{{ route('products.index') }}" class="filter-bar">
        <div class="field">
            <label for="search">Search name, SKU, or ID</label>
            <input id="search" name="search" type="text" class="input" value="{{ $filters['search'] }}" placeholder="Name, SKU, external ID...">
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
            <label class="checkbox-row">
                <input type="checkbox" name="low_stock" value="1" @checked($filters['low_stock'])>
                <span>Low stock only</span>
            </label>
        </div>

        <div class="field field--fit">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="{{ route('products.index') }}" class="btn btn--ghost">Reset</a>
        </div>
    </form>
</div>

<div class="panel">
    @if($products->isEmpty())
        <div class="empty-state">
            <p>No products found in this workspace.</p>
            <p>Sync your catalog via <code>POST /api/v1/connector/products</code> or import orders to start tracking stock and unit costs.</p>
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Store</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th class="num">Price</th>
                        <th class="num">Cost</th>
                        <th class="num">Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $product)
                        <tr>
                            <td>
                                <strong>{{ $product->name }}</strong>
                                <div class="mono muted">{{ $product->external_id }}</div>
                            </td>
                            <td>{{ $product->website?->name ?? '—' }}</td>
                            <td class="mono">{{ $product->sku ?? '—' }}</td>
                            <td>{{ $product->category ?? '—' }}</td>
                            <td class="num">{{ $product->price !== null ? number_format((float) $product->price, 2) : '—' }}</td>
                            <td class="num muted">{{ $product->cost !== null ? number_format((float) $product->cost, 2) : '—' }}</td>
                            <td class="num">
                                {{ $product->stock !== null ? number_format($product->stock) : '—' }}
                                @if($product->isLowStock())
                                    <span class="badge badge--warn">Low stock</span>
                                @endif
                            </td>
                            <td>
                                @if($product->active)
                                    <span class="badge badge--ok">Active</span>
                                @else
                                    <span class="badge badge--neutral">Inactive</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($products->hasPages())
            <div class="pager">
                @if($products->onFirstPage())
                    <span class="is-disabled">Previous</span>
                @else
                    <a href="{{ $products->previousPageUrl() }}">Previous</a>
                @endif
                <span class="is-current">Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>
                @if($products->hasMorePages())
                    <a href="{{ $products->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled">Next</span>
                @endif
            </div>
        @endif
    @endif
</div>
@endsection
