<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Website;
use App\Support\StatusMapper;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status', ''));
        $websiteId = $request->query('website_id');
        $search = trim((string) $request->query('search', ''));

        $query = Order::query()
            ->with(['website:id,name', 'customer:id,name,customer_key', 'assignment.employee:id,name'])
            ->withCount('items');

        if ($status !== '' && in_array($status, StatusMapper::CANONICAL, true)) {
            $query->where('status', $status);
        }

        if ($websiteId !== null && $websiteId !== '' && ctype_digit((string) $websiteId)) {
            $query->where('website_id', (int) $websiteId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('external_id', 'like', '%'.$search.'%')
                    ->orWhere('consignment_id', 'like', '%'.$search.'%');
            });
        }

        $orders = $query
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $websites = Website::query()->orderBy('name')->get(['id', 'name']);

        return view('orders.index', [
            'orders' => $orders,
            'websites' => $websites,
            'statuses' => StatusMapper::CANONICAL,
            'filters' => [
                'status' => $status,
                'website_id' => $websiteId,
                'search' => $search,
            ],
        ]);
    }

    public function show(Order $order): View
    {
        $order->load([
            'website',
            'customer',
            'items.product',
            'statusHistory.employee',
            'assignment.employee',
        ]);

        return view('orders.show', compact('order'));
    }
}
