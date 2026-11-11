<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Website;
use App\Services\Marketing\AdMapper;
use App\Services\Marketing\MarketingAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class MarketingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = [
            'preset' => (string) $request->query('preset', '30'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'website_id' => $request->query('website_id'),
            'platform' => $request->query('platform'),
        ];

        try {
            $report = MarketingAnalyticsService::for($user->tenant_id)->report($filters);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $websites = Website::query()->orderBy('name')->get(['id', 'name', 'currency']);
        $platforms = AdMapper::SUPPORTED_PLATFORMS;
        $recentBatches = ImportBatch::query()
            ->where('kind', 'ads')
            ->with(['website:id,name', 'user:id,name'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('marketing.index', compact('report', 'websites', 'platforms', 'recentBatches'));
    }
}
