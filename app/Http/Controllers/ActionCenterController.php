<?php

namespace App\Http\Controllers;

use App\Jobs\RunRndScan;
use App\Models\AuditLog;
use App\Models\ResearchFinding;
use App\Models\Website;
use App\Services\MetricsService;
use App\Services\Rnd\FindingTemplates;
use App\Services\Rnd\RuleEngine;
use App\Support\TenantCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class ActionCenterController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        [$locale, $bnDigits] = $this->resolveLocale($request);

        $viewMode = (string) $request->query('view', 'open');
        if (! in_array($viewMode, ['open', 'closed', 'all'], true)) {
            $viewMode = 'open';
        }

        $websiteIdParam = $request->query('website_id');
        $websiteId = null;
        if ($websiteIdParam !== null && $websiteIdParam !== '') {
            if (! ctype_digit((string) $websiteIdParam)) {
                abort(404);
            }
            $websiteId = (int) $websiteIdParam;
            $belongs = Website::query()->where('id', $websiteId)->exists();
            if (! $belongs) {
                abort(404);
            }
        }

        $ruleFilter = (string) $request->query('rule', 'all');
        if ($ruleFilter !== 'all' && ! in_array($ruleFilter, ResearchFinding::RULES, true)) {
            $ruleFilter = 'all';
        }

        $websites = Website::query()->orderBy('name')->get(['id', 'name', 'currency']);

        $query = ResearchFinding::query()
            ->with(['website:id,name,currency', 'reviewer:id,name']);

        if ($viewMode === 'open') {
            $query->whereIn('status', ResearchFinding::OPEN_STATUSES);
        } elseif ($viewMode === 'closed') {
            $query->whereNotIn('status', ResearchFinding::OPEN_STATUSES);
        }

        if ($websiteId !== null) {
            $query->where('website_id', $websiteId);
        }

        if ($ruleFilter !== 'all') {
            $query->where('rule_code', $ruleFilter);
        }

        $all = $query->get();

        // Sort strictly by severity (critical > high > medium > low), then confidence (high > medium > low), then money_at_stake desc
        $sorted = $all->sort(static function (ResearchFinding $a, ResearchFinding $b): int {
            $sevCmp = $b->severityWeight() <=> $a->severityWeight();
            if ($sevCmp !== 0) {
                return $sevCmp;
            }
            $confCmp = $b->confidenceWeight() <=> $a->confidenceWeight();
            if ($confCmp !== 0) {
                return $confCmp;
            }
            $moneyCmp = ((float) ($b->money_at_stake ?? 0.0)) <=> ((float) ($a->money_at_stake ?? 0.0));
            if ($moneyCmp !== 0) {
                return $moneyCmp;
            }

            return $b->id <=> $a->id;
        })->values();

        $prepareCard = function (ResearchFinding $f) use ($locale, $bnDigits): array {
            $rendered = FindingTemplates::renderFinding($f, $locale, $bnDigits);
            $ev = is_array($f->evidence) ? $f->evidence : [];
            $analyticsParams = is_array($ev['analytics_params'] ?? null)
                ? $ev['analytics_params']
                : ['preset' => '30', 'website_id' => $f->website_id];

            return [
                'model' => $f,
                'rendered' => $rendered,
                'analytics_url' => route('analytics.index', $analyticsParams),
            ];
        };

        $todayCards = [];
        $weekCards = [];
        $infoCards = [];

        foreach ($sorted as $finding) {
            $card = $prepareCard($finding);
            if ($finding->is_insufficient_data) {
                $infoCards[] = $card;
            } elseif (in_array($finding->severity, ['critical', 'high'], true)) {
                $todayCards[] = $card;
            } else {
                $weekCards[] = $card;
            }
        }

        $openCount = ResearchFinding::query()
            ->whereIn('status', ResearchFinding::OPEN_STATUSES)
            ->count();

        return view('action-center.index', [
            'user' => $user,
            'locale' => $locale,
            'bnDigits' => $bnDigits,
            'labels' => FindingTemplates::uiLabels($locale),
            'websites' => $websites,
            'filters' => [
                'view' => $viewMode,
                'website_id' => $websiteId,
                'rule' => $ruleFilter,
            ],
            'openCount' => $openCount,
            'todayCards' => $todayCards,
            'weekCards' => $weekCards,
            'infoCards' => $infoCards,
        ]);
    }

    public function show(Request $request, ResearchFinding $finding): View
    {
        $finding->load(['website:id,name,currency', 'reviewer:id,name']);
        [$locale, $bnDigits] = $this->resolveLocale($request);

        $rendered = FindingTemplates::renderFinding($finding, $locale, $bnDigits);
        $ev = is_array($finding->evidence) ? $finding->evidence : [];
        $analyticsParams = is_array($ev['analytics_params'] ?? null)
            ? $ev['analytics_params']
            : ['preset' => '30', 'website_id' => $finding->website_id];

        return view('action-center.show', [
            'user' => $request->user(),
            'finding' => $finding,
            'rendered' => $rendered,
            'analyticsUrl' => route('analytics.index', $analyticsParams),
            'locale' => $locale,
            'bnDigits' => $bnDigits,
            'labels' => FindingTemplates::uiLabels($locale),
        ]);
    }

    public function scan(Request $request, RuleEngine $engine): RedirectResponse
    {
        $user = $request->user();
        TenantCache::clear($user->tenant_id);

        if (Cache::get('sys:worker_beat') !== null) {
            RunRndScan::dispatch($user->tenant_id)->onQueue('default');
            AuditLog::record(
                'rnd.scan',
                'Queued R&D rule scan (R01-R12)',
                $user->tenant_id,
                $user->id
            );

            return redirect()
                ->route('action-center.index')
                ->with('ok', 'R&D scan queued for background execution.');
        }

        $counts = $engine->scanTenant($user->tenant_id);
        MetricsService::for($user->tenant_id)->rebuildDashboard();

        AuditLog::record(
            'rnd.scan',
            "Ran R&D scan (R01-R12): sites={$counts['websites_scanned']}, created={$counts['created']}, updated={$counts['updated']}",
            $user->tenant_id,
            $user->id
        );

        return redirect()
            ->route('action-center.index')
            ->with('ok', "R&D scan complete: {$counts['created']} new finding(s), {$counts['updated']} updated.");
    }

    public function review(Request $request, ResearchFinding $finding): RedirectResponse
    {
        $data = $request->validate([
            'outcome_note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $note = isset($data['outcome_note']) && trim((string) $data['outcome_note']) !== ''
            ? trim((string) $data['outcome_note'])
            : $finding->outcome_note;

        $finding->forceFill([
            'status' => 'reviewing',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'outcome_note' => $note,
        ])->save();

        AuditLog::record(
            'rnd.finding.review',
            "Marked finding #{$finding->id} ({$finding->rule_code}) as reviewing",
            $user->tenant_id,
            $user->id
        );

        return back()->with('ok', "Finding {$finding->rule_code} marked as reviewing.");
    }

    public function dismiss(Request $request, ResearchFinding $finding): RedirectResponse
    {
        $data = $request->validate([
            'dismiss_reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $user = $request->user();
        $reason = trim((string) $data['dismiss_reason']);

        $finding->forceFill([
            'status' => 'dismissed',
            'dismiss_reason' => $reason,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ])->save();

        MetricsService::for($user->tenant_id)->rebuildDashboard();

        AuditLog::record(
            'rnd.finding.dismiss',
            "Dismissed finding #{$finding->id} ({$finding->rule_code}): {$reason}",
            $user->tenant_id,
            $user->id
        );

        return back()->with('ok', "Finding {$finding->rule_code} dismissed.");
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveLocale(Request $request): array
    {
        $queryLang = $request->query('lang');
        if (is_string($queryLang) && in_array($queryLang, FindingTemplates::SUPPORTED_LOCALES, true)) {
            $locale = $queryLang;
            session(['locale' => $locale]);
        } else {
            $locale = FindingTemplates::normalizeLocale((string) session('locale', 'en'));
        }

        if ($request->has('bn_digits')) {
            $bnDigits = $request->boolean('bn_digits');
            session(['bn_digits' => $bnDigits]);
        } else {
            $bnDigits = (bool) session('bn_digits', false);
        }

        return [$locale, $bnDigits];
    }
}
