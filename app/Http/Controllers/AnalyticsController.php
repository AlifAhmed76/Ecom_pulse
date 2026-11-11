<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use InvalidArgumentException;

class AnalyticsController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;

        $preset = (string) $request->query('preset', '30');
        $from = $request->query('from');
        $to = $request->query('to');
        $websiteId = $request->query('website_id');

        if ($websiteId !== null && $websiteId !== '' && ! ctype_digit((string) $websiteId)) {
            abort(404);
        }

        try {
            $analytics = AnalyticsService::for($tenantId)->analytics([
                'preset' => $preset,
                'from' => is_string($from) ? $from : null,
                'to' => is_string($to) ? $to : null,
                'website_id' => $websiteId,
            ]);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $topProducts = $this->paginateArray($analytics['top_products'] ?? [], 10, 'prod_page', $request);
        $couriers = $this->paginateArray($analytics['couriers'] ?? [], 10, 'courier_page', $request);
        $employees = $this->paginateArray($analytics['employees'] ?? [], 10, 'emp_page', $request);
        $stockAlerts = $this->paginateArray($analytics['stock_alerts'] ?? [], 10, 'stock_page', $request);
        $definitions = $this->paginateArray($analytics['definitions'] ?? [], 20, 'def_page', $request);

        return view('analytics.index', [
            'analytics' => $analytics,
            'topProducts' => $topProducts,
            'couriers' => $couriers,
            'employees' => $employees,
            'stockAlerts' => $stockAlerts,
            'definitions' => $definitions,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function paginateArray(array $items, int $perPage, string $pageName, Request $request): LengthAwarePaginator
    {
        $rawPage = $request->query($pageName, 1);
        $page = is_numeric($rawPage) ? max(1, (int) $rawPage) : 1;
        $collection = collect($items);
        $slice = $collection->slice(($page - 1) * $perPage, $perPage)->values();

        return (new LengthAwarePaginator(
            $slice,
            $collection->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => $pageName,
            ]
        ))->withQueryString();
    }
}
