<?php

namespace App\Http\Controllers;

use App\Jobs\RunRiskScan;
use App\Models\AuditLog;
use App\Models\RiskFlag;
use App\Services\CourierAnalyticsService;
use App\Services\RiskScannerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use InvalidArgumentException;

class CourierLossController extends Controller
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
            $report = CourierAnalyticsService::for($tenantId)->report([
                'preset' => $preset,
                'from' => is_string($from) ? $from : null,
                'to' => is_string($to) ? $to : null,
                'website_id' => $websiteId,
            ]);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $splits = $report['loss_splits'] ?? [];
        $lossByCourier = $this->paginateArray($splits['by_courier'] ?? [], 10, 'lc_page', $request);
        $lossByArea = $this->paginateArray($splits['by_area'] ?? [], 10, 'la_page', $request);
        $lossByEmployee = $this->paginateArray($splits['by_employee'] ?? [], 10, 'le_page', $request);
        $lossByProduct = $this->paginateArray($splits['by_product'] ?? [], 10, 'lp_page', $request);
        $lossByCancelReason = $this->paginateArray($splits['by_cancel_reason'] ?? [], 10, 'lr_page', $request);

        $courierScorecard = $this->paginateArray($report['courier_scorecard'] ?? [], 10, 'sc_page', $request);
        $areaScorecard = $this->paginateArray($report['area_scorecard'] ?? [], 10, 'sa_page', $request);

        $flagStatus = (string) $request->query('flag_status', 'all');
        $flagRule = (string) $request->query('flag_rule', 'all');

        $flagsQuery = RiskFlag::query()
            ->with([
                'order:id,external_id,status,total,currency,ordered_at',
                'customer:id,name,customer_key',
                'reviewer:id,name',
            ])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        if (in_array($flagStatus, RiskFlag::STATUSES, true)) {
            $flagsQuery->where('status', $flagStatus);
        }
        if (in_array($flagRule, RiskFlag::RULES, true)) {
            $flagsQuery->where('rule', $flagRule);
        }

        $riskFlags = $flagsQuery->paginate(15, ['*'], 'flags_page')->withQueryString();
        $openFlagsCount = RiskFlag::query()->where('status', 'open')->count();

        return view('courier.index', [
            'report' => $report,
            'lossByCourier' => $lossByCourier,
            'lossByArea' => $lossByArea,
            'lossByEmployee' => $lossByEmployee,
            'lossByProduct' => $lossByProduct,
            'lossByCancelReason' => $lossByCancelReason,
            'courierScorecard' => $courierScorecard,
            'areaScorecard' => $areaScorecard,
            'riskFlags' => $riskFlags,
            'openFlagsCount' => $openFlagsCount,
            'flagFilters' => [
                'status' => $flagStatus,
                'rule' => $flagRule,
            ],
            'user' => $request->user(),
        ]);
    }

    public function scan(Request $request, RiskScannerService $scanner): RedirectResponse
    {
        $user = $request->user();

        if (Cache::get('sys:worker_beat') !== null) {
            RunRiskScan::dispatch($user->tenant_id)->onQueue('default');
            AuditLog::record(
                'risk.scan',
                'Queued suspicious-order scan (S1-S3)',
                $user->tenant_id,
                $user->id
            );

            return redirect()
                ->route('courier-loss.index')
                ->with('ok', 'Suspicious-order scan queued for background execution.');
        }

        $counts = $scanner->scanTenant($user->tenant_id);
        AuditLog::record(
            'risk.scan',
            "Ran suspicious-order scan: S1={$counts['s1']}, S2={$counts['s2']}, S3={$counts['s3']}",
            $user->tenant_id,
            $user->id
        );

        return redirect()
            ->route('courier-loss.index')
            ->with('ok', "Suspicious-order scan complete: {$counts['total_inserted']} new advisory flag(s) added.");
    }

    public function review(Request $request, RiskFlag $flag): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:reviewed_ok,confirmed'],
            'review_note' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $flag->forceFill([
            'status' => $data['status'],
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_note' => isset($data['review_note']) && trim((string) $data['review_note']) !== ''
                ? trim((string) $data['review_note'])
                : null,
        ])->save();

        AuditLog::record(
            'risk_flag.review',
            "Marked risk flag #{$flag->id} ({$flag->rule}) as {$data['status']} (advisory only; orders unchanged)",
            $user->tenant_id,
            $user->id
        );

        return back()->with('ok', "Flag #{$flag->id} marked as ".str_replace('_', ' ', $data['status']).'. No orders were changed.');
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
