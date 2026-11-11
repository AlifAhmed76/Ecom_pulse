<?php

namespace App\Http\Controllers;

use App\Jobs\BuildTenantWeeklyReport;
use App\Models\AuditLog;
use App\Models\WeeklyReport;
use App\Services\Rnd\FindingTemplates;
use App\Services\WeeklyReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class WeeklyReportController extends Controller
{
    public function index(Request $request): View
    {
        [$locale, $bnDigits] = $this->resolveLocale($request);
        $user = $request->user();

        $reports = WeeklyReport::query()
            ->orderByDesc('week_start')
            ->paginate(12)
            ->withQueryString();

        $latestRendered = null;
        if ($reports->count() > 0) {
            /** @var WeeklyReport $first */
            $first = $reports->first();
            $latestRendered = WeeklyReportService::render($first, $locale, $bnDigits);
        }

        return view('weekly-reports.index', [
            'user' => $user,
            'reports' => $reports,
            'latestReport' => $reports->first(),
            'latestRendered' => $latestRendered,
            'locale' => $locale,
            'bnDigits' => $bnDigits,
            'currentWeekStart' => WeeklyReportService::defaultWeekStart(null, false)->format('Y-m-d'),
            'lastWeekStart' => WeeklyReportService::defaultWeekStart(null, true)->format('Y-m-d'),
        ]);
    }

    public function show(Request $request, WeeklyReport $weeklyReport): View
    {
        [$locale, $bnDigits] = $this->resolveLocale($request);

        return view('weekly-reports.show', [
            'user' => $request->user(),
            'report' => $weeklyReport,
            'rendered' => WeeklyReportService::render($weeklyReport, $locale, $bnDigits),
            'locale' => $locale,
            'bnDigits' => $bnDigits,
        ]);
    }

    public function build(Request $request, WeeklyReportService $service): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'period' => ['nullable', 'in:current,last_completed'],
        ]);

        $lastCompleted = ($data['period'] ?? 'current') === 'last_completed';
        $weekStart = WeeklyReportService::defaultWeekStart(null, $lastCompleted);
        $weekStartStr = $weekStart->format('Y-m-d');

        if (Cache::get('sys:worker_beat') !== null) {
            BuildTenantWeeklyReport::dispatch((int) $user->tenant_id, $weekStartStr)->onQueue('default');

            AuditLog::record(
                'weekly_report.build',
                "Queued weekly report build for week starting {$weekStartStr}",
                (int) $user->tenant_id,
                (int) $user->id
            );

            return redirect()
                ->route('weekly-reports.index')
                ->with('ok', "Weekly report build queued for week starting {$weekStartStr}.");
        }

        $report = $service->buildForTenant((int) $user->tenant_id, $weekStart);

        AuditLog::record(
            'weekly_report.build',
            "Built weekly report #{$report->id} for week starting {$weekStartStr}",
            (int) $user->tenant_id,
            (int) $user->id
        );

        return redirect()
            ->route('weekly-reports.show', $report)
            ->with('ok', "Weekly summary ready for {$weekStartStr} — {$report->week_end?->format('Y-m-d')}.");
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveLocale(Request $request): array
    {
        $langParam = $request->query('lang');
        $locale = is_string($langParam) && in_array($langParam, ['en', 'bn'], true)
            ? $langParam
            : FindingTemplates::normalizeLocale((string) session('locale', 'en'));

        $bnDigitsParam = $request->query('bn_digits');
        $bnDigits = $bnDigitsParam !== null
            ? ($bnDigitsParam === '1' || $bnDigitsParam === 'true')
            : (bool) session('bn_digits', false);

        return [$locale, $bnDigits];
    }
}
