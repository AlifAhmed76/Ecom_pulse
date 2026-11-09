<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $websiteId = $request->query('website_id');
        $lowStockOnly = $request->boolean('low_stock');
        $threshold = (int) config('lyp.low_stock_threshold', 10);

        $query = Product::query()->with('website:id,name');

        if ($websiteId !== null && $websiteId !== '' && ctype_digit((string) $websiteId)) {
            $query->where('website_id', (int) $websiteId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('external_id', 'like', '%'.$search.'%');
            });
        }

        if ($lowStockOnly) {
            $query->whereNotNull('stock')->where('stock', '<=', $threshold);
        }

        $products = $query
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $websites = Website::query()->orderBy('name')->get(['id', 'name']);

        return view('products.index', [
            'products' => $products,
            'websites' => $websites,
            'lowStockThreshold' => $threshold,
            'filters' => [
                'search' => $search,
                'website_id' => $websiteId,
                'low_stock' => $lowStockOnly,
            ],
        ]);
    }
}
