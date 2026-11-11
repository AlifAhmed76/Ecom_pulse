<?php

namespace App\Services;

use App\Models\Experiment;
use App\Models\ResearchFinding;
use App\Models\Tenant;
use App\Models\WeeklyReport;
use App\Services\Marketing\MarketingAnalyticsService;
use App\Services\Rnd\FindingTemplates;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WeeklyReportService
{
    /**
     * Resolve Saturday-start Bangladesh business week window.
     */
    public static function defaultWeekStart(?Carbon $anchor = null, bool $lastCompleted = true): Carbon
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $now = ($anchor ?? now($tz))->copy()->setTimezone($tz);

        if ($lastCompleted) {
            return $now->copy()->subDay()->startOfWeek(Carbon::SATURDAY)->startOfDay();
        }

        return $now->copy()->startOfWeek(Carbon::SATURDAY)->startOfDay();
    }

    /**
     * Deterministically compute and persist (idempotently per tenant_id + week_start) one weekly report.
     */
    public function buildForTenant(int $tenantId, ?Carbon $weekStart = null, ?Carbon $referenceNow = null): WeeklyReport
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $now = ($referenceNow ?? now($tz))->copy()->setTimezone($tz);
        $start = ($weekStart ?? self::defaultWeekStart($now, true))->copy()->setTimezone($tz)->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();

        $fromStr = $start->format('Y-m-d');
        $toStr = $end->format('Y-m-d');

        $analytics = AnalyticsService::for($tenantId)->analytics([
            'preset' => 'custom',
            'from' => $fromStr,
            'to' => $toStr,
        ]);

        $courier = CourierAnalyticsService::for($tenantId)->report([
            'preset' => 'custom',
            'from' => $fromStr,
            'to' => $toStr,
        ]);

        $marketing = MarketingAnalyticsService::for($tenantId)->report([
            'preset' => 'custom',
            'from' => $fromStr,
            'to' => $toStr,
        ]);

        $kpis = $analytics['kpis'] ?? [];
        $dq = $analytics['data_quality'] ?? [];
        $lossHeadline = $courier['loss_headline'] ?? [];
        $lossSplits = $courier['loss_splits'] ?? [];
        $scorecard = is_array($courier['courier_scorecard'] ?? null) ? $courier['courier_scorecard'] : [];
        $coverage = $courier['coverage'] ?? [];

        // Top courier rows (up to 5)
        $courierHighlights = array_map(static function (array $row): array {
            return [
                'name' => (string) ($row['name'] ?? 'Unknown'),
                'shipments' => (int) ($row['shipments'] ?? 0),
                'delivered' => (int) ($row['delivered'] ?? 0),
                'returned' => (int) ($row['returned'] ?? 0),
                'success_rate' => $row['success_rate'] !== null ? (float) $row['success_rate'] : null,
                'insufficient_data' => (bool) ($row['insufficient_data'] ?? false),
                'statistically_unusual' => (bool) ($row['statistically_unusual'] ?? false),
                'outlier_direction' => $row['outlier_direction'] ?? null,
                'p_value' => $row['p_value'] ?? null,
            ];
        }, array_slice($scorecard, 0, 5));

        $worstCourier = null;
        foreach ($scorecard as $row) {
            if (! ($row['insufficient_data'] ?? true) && $row['success_rate'] !== null) {
                if ($worstCourier === null || (float) $row['success_rate'] < (float) $worstCourier['success_rate']) {
                    $worstCourier = [
                        'name' => (string) $row['name'],
                        'shipments' => (int) $row['shipments'],
                        'success_rate' => (float) $row['success_rate'],
                        'statistically_unusual' => (bool) ($row['statistically_unusual'] ?? false),
                        'outlier_direction' => $row['outlier_direction'] ?? null,
                    ];
                }
            }
        }

        // Top open R&D findings (ranked by severity, confidence, money at stake)
        $openFindings = ResearchFinding::withoutGlobalScopes()
            ->with('website:id,name,currency')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ResearchFinding::OPEN_STATUSES)
            ->get();

        $openTotal = $openFindings->count();
        $openCriticalHigh = $openFindings->filter(
            fn (ResearchFinding $f): bool => ! $f->is_insufficient_data && in_array($f->severity, ['critical', 'high'], true)
        )->count();

        $sortedFindings = $openFindings->sort(static function (ResearchFinding $a, ResearchFinding $b): int {
            if ($a->is_insufficient_data !== $b->is_insufficient_data) {
                return $a->is_insufficient_data ? 1 : -1;
            }
            $sev = $b->severityWeight() <=> $a->severityWeight();
            if ($sev !== 0) {
                return $sev;
            }
            $conf = $b->confidenceWeight() <=> $a->confidenceWeight();
            if ($conf !== 0) {
                return $conf;
            }
            $money = ((float) ($b->money_at_stake ?? 0.0)) <=> ((float) ($a->money_at_stake ?? 0.0));
            if ($money !== 0) {
                return $money;
            }

            return $b->id <=> $a->id;
        })->take(5)->values();

        $topFindingsPayload = [];
        foreach ($sortedFindings as $f) {
            $params = is_array($f->params) ? $f->params : [];
            $tplKey = (string) $f->template_key;
            $en = FindingTemplates::render($tplKey, $params, 'en', false);
            $bn = FindingTemplates::render($tplKey, $params, 'bn', false);

            $topFindingsPayload[] = [
                'id' => (int) $f->id,
                'rule_code' => (string) $f->rule_code,
                'template_key' => $tplKey,
                'params' => $params,
                'severity' => (string) $f->severity,
                'confidence' => (string) $f->confidence,
                'money_at_stake' => $f->money_at_stake !== null ? round((float) $f->money_at_stake, 2) : null,
                'currency' => (string) ($f->currency ?? 'BDT'),
                'status' => (string) $f->status,
                'website_name' => (string) ($f->website?->name ?? ''),
                'is_insufficient_data' => (bool) $f->is_insufficient_data,
                'title_en' => $en['title'],
                'summary_en' => $en['summary'],
                'action_en' => $en['action'],
                'title_bn' => $bn['title'],
                'summary_bn' => $bn['summary'],
                'action_bn' => $bn['action'],
            ];
        }

        // Experiment status breakdown & latest updates
        $expCountsRaw = DB::table('experiments')
            ->where('tenant_id', $tenantId)
            ->selectRaw('status, COUNT(*) AS cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $expStatusCounts = [];
        foreach (Experiment::STATUSES as $st) {
            $expStatusCounts[$st] = (int) ($expCountsRaw[$st] ?? 0);
        }

        $recentExperiments = Experiment::withoutGlobalScopes()
            ->with(['finding:id,rule_code', 'results'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $expUpdates = [];
        foreach ($recentExperiments as $exp) {
            $latestResult = $exp->results->first();
            $measured = is_array($latestResult?->measured ?? null) ? $latestResult->measured : [];
            $expUpdates[] = [
                'id' => (int) $exp->id,
                'title' => (string) $exp->title,
                'status' => (string) $exp->status,
                'rule_code' => $exp->finding?->rule_code,
                'planned_days' => $exp->planned_days !== null ? (int) $exp->planned_days : null,
                'started_at' => $exp->started_at?->format('Y-m-d'),
                'ended_at' => $exp->ended_at?->format('Y-m-d'),
                'outcome' => $latestResult?->outcome,
                'lesson' => $latestResult?->lesson,
                'measured_value' => $measured['metric_value'] ?? null,
            ];
        }

        $tenantName = (string) (Tenant::query()->where('id', $tenantId)->value('name') ?? "Workspace #{$tenantId}");

        $payload = [
            'tenant_name' => $tenantName,
            'week' => [
                'start' => $fromStr,
                'end' => $toStr,
            ],
            'currency' => (string) ($analytics['currency'] ?? 'BDT'),
            'mixed_currency' => (bool) ($analytics['mixed_currency'] ?? false),
            'orders_summary' => [
                'orders' => (int) ($kpis['orders'] ?? 0),
                'delivered_orders' => (int) ($kpis['delivered_orders'] ?? 0),
                'cancelled_orders' => (int) ($kpis['cancelled_orders'] ?? 0),
                'returned_orders' => (int) ($kpis['returned_orders'] ?? 0),
                'gross_sales' => $kpis['gross_sales'] ?? null,
                'delivered_revenue' => $kpis['delivered_revenue'] ?? null,
                'aov' => $kpis['aov'] ?? null,
                'cancellation_rate' => (float) ($kpis['cancellation_rate'] ?? 0.0),
                'return_rate' => (float) ($kpis['return_rate'] ?? 0.0),
                'data_quality_score' => (int) ($dq['score'] ?? 0),
            ],
            'money_lost' => [
                'cancelled_value' => $lossHeadline['cancelled_value'] ?? null,
                'returned_value' => $lossHeadline['returned_value'] ?? null,
                'total_lost_value' => $lossHeadline['total_lost_value'] ?? null,
                'lost_share_pct' => (float) ($lossHeadline['lost_share_pct'] ?? 0.0),
                'has_return_fee_data' => (bool) ($lossHeadline['has_return_fee_data'] ?? false),
                'estimated_return_handling_cost' => $lossHeadline['estimated_return_handling_cost'] ?? null,
                'top_areas' => array_slice(is_array($lossSplits['by_area'] ?? null) ? $lossSplits['by_area'] : [], 0, 3),
                'top_reasons' => array_slice(is_array($lossSplits['by_cancel_reason'] ?? null) ? $lossSplits['by_cancel_reason'] : [], 0, 3),
            ],
            'courier_highlights' => [
                'scorecard_source' => (string) ($courier['scorecard_source'] ?? 'orders'),
                'shipments_total' => (int) ($coverage['total_shipments'] ?? 0),
                'coverage_pct' => $coverage['coverage_pct'] ?? null,
                'couriers' => $courierHighlights,
                'worst_courier' => $worstCourier,
            ],
            'top_findings' => [
                'open_total' => $openTotal,
                'open_critical_high' => $openCriticalHigh,
                'items' => $topFindingsPayload,
            ],
            'experiment_updates' => [
                'counts' => $expStatusCounts,
                'total' => array_sum($expStatusCounts),
                'items' => $expUpdates,
            ],
            'marketing_summary' => [
                'has_ad_data' => (bool) ($marketing['has_ad_data'] ?? false),
                'spend' => (float) ($marketing['platform_reported']['spend'] ?? 0.0),
                'platform_roas' => $marketing['platform_reported']['roas'] ?? null,
                'real_roas' => $marketing['real_orders']['roas'] ?? null,
                'utm_coverage_pct' => (float) ($marketing['attribution']['utm_coverage_pct'] ?? 0.0),
            ],
            'built_at' => $now->format('Y-m-d H:i:s'),
        ];

        $existing = WeeklyReport::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('week_start', $fromStr)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'week_end' => $toStr,
                'payload' => $payload,
                'built_at' => $now->format('Y-m-d H:i:s'),
            ])->save();

            return $existing;
        }

        $report = new WeeklyReport();
        $report->forceFill([
            'tenant_id' => $tenantId,
            'week_start' => $fromStr,
            'week_end' => $toStr,
            'payload' => $payload,
            'built_at' => $now->format('Y-m-d H:i:s'),
        ])->save();

        return $report;
    }

    /**
     * Render localized report data ('en' or 'bn') with zero unresolved placeholders.
     *
     * @return array<string, mixed>
     */
    public static function render(WeeklyReport $report, string $locale = 'en', bool $bnDigits = false): array
    {
        $loc = FindingTemplates::normalizeLocale($locale);
        $p = is_array($report->payload) ? $report->payload : [];
        $week = $p['week'] ?? [];
        $startStr = (string) ($week['start'] ?? $report->week_start?->format('Y-m-d') ?? '');
        $endStr = (string) ($week['end'] ?? $report->week_end?->format('Y-m-d') ?? '');
        $currency = (string) ($p['currency'] ?? 'BDT');
        $mixed = (bool) ($p['mixed_currency'] ?? false);

        $fmtNum = static function (int|float|null $val, int $dec = 0) use ($bnDigits): string {
            if ($val === null) {
                return '—';
            }

            return FindingTemplates::formatNumber($val, $dec, $bnDigits);
        };

        $fmtMoney = static function (int|float|null $val) use ($currency, $mixed, $fmtNum): string {
            if ($mixed || $val === null) {
                return '—';
            }

            return $currency.' '.$fmtNum($val, 2);
        };

        $ordersSum = is_array($p['orders_summary'] ?? null) ? $p['orders_summary'] : [];
        $loss = is_array($p['money_lost'] ?? null) ? $p['money_lost'] : [];
        $cour = is_array($p['courier_highlights'] ?? null) ? $p['courier_highlights'] : [];
        $findingsBlock = is_array($p['top_findings'] ?? null) ? $p['top_findings'] : [];
        $expBlock = is_array($p['experiment_updates'] ?? null) ? $p['experiment_updates'] : [];
        $mktBlock = is_array($p['marketing_summary'] ?? null) ? $p['marketing_summary'] : [];

        // Re-render top findings using live FindingTemplates so bnDigits always matches UI selection cleanly
        $renderedFindings = [];
        foreach (($findingsBlock['items'] ?? []) as $item) {
            if (! empty($item['template_key'])) {
                $r = FindingTemplates::render(
                    (string) $item['template_key'],
                    is_array($item['params'] ?? null) ? $item['params'] : [],
                    $loc,
                    $bnDigits
                );
                $title = $r['title'];
                $summary = $r['summary'];
                $action = $r['action'];
            } else {
                $title = (string) ($loc === 'bn' ? ($item['title_bn'] ?? $item['title_en'] ?? '') : ($item['title_en'] ?? ''));
                $summary = (string) ($loc === 'bn' ? ($item['summary_bn'] ?? $item['summary_en'] ?? '') : ($item['summary_en'] ?? ''));
                $action = (string) ($loc === 'bn' ? ($item['action_bn'] ?? $item['action_en'] ?? '') : ($item['action_en'] ?? ''));
            }

            $renderedFindings[] = [
                'id' => (int) ($item['id'] ?? 0),
                'rule_code' => (string) ($item['rule_code'] ?? ''),
                'severity' => (string) ($item['severity'] ?? 'medium'),
                'confidence' => (string) ($item['confidence'] ?? 'low'),
                'website_name' => (string) ($item['website_name'] ?? ''),
                'money_display' => $item['money_at_stake'] !== null
                    ? (($item['currency'] ?? $currency).' '.$fmtNum((float) $item['money_at_stake'], 2))
                    : ($loc === 'bn' ? 'তথ্যমূলক' : 'Informational'),
                'title' => $title,
                'summary' => $summary,
                'action' => $action,
            ];
        }

        $totalOrders = (int) ($ordersSum['orders'] ?? 0);
        $cancelRate = $fmtNum((float) ($ordersSum['cancellation_rate'] ?? 0.0), 1).'%';
        $returnRate = $fmtNum((float) ($ordersSum['return_rate'] ?? 0.0), 1).'%';
        $dqScore = $fmtNum((int) ($ordersSum['data_quality_score'] ?? 0), 0).($loc === 'bn' ? '/১০০' : '/100');

        $worst = is_array($cour['worst_courier'] ?? null) ? $cour['worst_courier'] : null;

        if ($loc === 'bn') {
            $labels = [
                'page_title' => 'সাপ্তাহিক সারসংক্ষেপ প্রতিবেদন',
                'subtitle' => 'প্রতি শনিবার সকাল ০৮:০০ টায় (Asia/Dhaka) স্বয়ংক্রিয়ভাবে তৈরি — শুধুমাত্র প্রিভিউ ও প্রিন্ট (ইমেইল/এসএমএস পাঠানো হয় না)',
                'workspace' => 'ওয়ার্কস্পেস',
                'period' => 'প্রতিবেদনের সময়কাল',
                'built_at' => 'তৈরির সময়',
                'print_btn' => 'প্রিন্ট / PDF সংরক্ষণ',
                'build_current_btn' => 'চলতি সপ্তাহ তৈরি করুন',
                'build_last_btn' => 'গত সম্পূর্ণ সপ্তাহ তৈরি করুন',
                'back_btn' => 'সব সাপ্তাহিক প্রতিবেদনে ফিরুন',
                'lang_en' => 'English',
                'lang_bn' => 'বাংলা',
                'kpi_orders' => 'মোট অর্ডার',
                'kpi_gross' => 'মোট বিক্রয় (বাতিল ছাড়া)',
                'kpi_delivered' => 'ডেলিভারড আয়',
                'kpi_lost' => 'বাতিল ও রিটার্নে অনর্জিত অর্থ',
                'kpi_dq' => 'ডেটা কোয়ালিটি স্কোর',
                'sec_overview' => '১. সাপ্তাহিক অর্ডার ও আয়ের সারসংক্ষেপ',
                'sec_loss' => '২. অর্থ ক্ষতির বিশ্লেষণ (বাতিল ও রিটার্ন)',
                'sec_courier' => '৩. কুরিয়ার পারফরম্যান্স হাইলাইটস',
                'sec_findings' => '৪. শীর্ষ আরঅ্যান্ডডি পর্যবেক্ষণ (Action Center)',
                'sec_experiments' => '৫. এক্সপেরিমেন্ট আপডেট',
                'sec_marketing' => '৬. মার্কেটিং ও বাস্তব ROAS টীকা',
                'overview_line' => sprintf(
                    'এই সপ্তাহে (%s থেকে %s) মোট %sটি অর্ডার এসেছে; এর মধ্যে %sটি ডেলিভারড, %sটি বাতিল (%s) এবং %sটি রিটার্ন (%s) হয়েছে। ডেটা মান স্কোর: %s।',
                    $startStr,
                    $endStr,
                    $fmtNum($totalOrders),
                    $fmtNum((int) ($ordersSum['delivered_orders'] ?? 0)),
                    $fmtNum((int) ($ordersSum['cancelled_orders'] ?? 0)),
                    $cancelRate,
                    $fmtNum((int) ($ordersSum['returned_orders'] ?? 0)),
                    $returnRate,
                    $dqScore
                ),
                'loss_line' => $mixed
                    ? 'একাধিক মুদ্রা থাকায় সম্মিলিত অর্থের অঙ্ক দেখানো হয়নি।'
                    : sprintf(
                        'বাতিল (%s) ও রিটার্ন (%s) মিলিয়ে মোট %s অনর্জিত বা সম্ভাব্য ক্ষতি হয়েছে (মোট অর্ডার মূল্যের %s%%)।',
                        $fmtMoney($loss['cancelled_value'] ?? null),
                        $fmtMoney($loss['returned_value'] ?? null),
                        $fmtMoney($loss['total_lost_value'] ?? null),
                        $fmtNum((float) ($loss['lost_share_pct'] ?? 0.0), 1)
                    ),
                'return_cost_line' => ($loss['has_return_fee_data'] ?? false)
                    ? 'কুরিয়ার রিপোর্ট অনুযায়ী আনুমানিক রিটার্ন হ্যান্ডলিং খরচ: '.$fmtMoney($loss['estimated_return_handling_cost'] ?? null)
                    : 'কুরিয়ার রিটার্ন ফি ডেটা এখনো নেই (কুরিয়ার CSV আপলোড প্রয়োজন)।',
                'courier_highlight_line' => $worst !== null
                    ? sprintf(
                        'সর্বনিম্ন ডেলিভারি সাফল্যের কুরিয়ার: %s (%s%% সফলতা, %sটি শিপমেন্ট)%s।',
                        (string) $worst['name'],
                        $fmtNum((float) $worst['success_rate'], 1),
                        $fmtNum((int) $worst['shipments']),
                        ! empty($worst['statistically_unusual']) ? ' — পরিসংখ্যানগতভাবে অস্বাভাবিক' : ''
                    )
                    : 'কমপক্ষে ২০টি সম্পন্ন শিপমেন্টসহ কুরিয়ার তুলনার তথ্য এই সপ্তাহে এখনো পর্যাপ্ত নয়।',
                'findings_empty' => 'এই মুহূর্তে কোনো খোলা আরঅ্যান্ডডি পর্যবেক্ষণ নেই। অ্যাকশন সেন্টারে গিয়ে নতুন স্ক্যান চালান।',
                'exp_summary_line' => sprintf(
                    'মোট এক্সপেরিমেন্ট: %s (চলমান: %s, সম্পন্ন: %s, খসড়া/অনুমোদিত: %s)।',
                    $fmtNum((int) ($expBlock['total'] ?? 0)),
                    $fmtNum((int) ($expBlock['counts']['running'] ?? 0)),
                    $fmtNum((int) ($expBlock['counts']['completed'] ?? 0)),
                    $fmtNum((int) (($expBlock['counts']['draft'] ?? 0) + ($expBlock['counts']['approved'] ?? 0)))
                ),
                'exp_empty' => 'এখনো কোনো এক্সপেরিমেন্ট নথিভুক্ত হয়নি।',
                'marketing_line' => ! empty($mktBlock['has_ad_data'])
                    ? sprintf(
                        'বিজ্ঞাপন খরচ: %s | প্ল্যাটফর্ম ROAS: %s | বাস্তব ডেলিভারড ROAS: %s | UTM কাভারেজ: %s%%। (ROAS মানে লাভ নয়)।',
                        $fmtMoney((float) ($mktBlock['spend'] ?? 0.0)),
                        $mktBlock['platform_roas'] !== null ? $fmtNum((float) $mktBlock['platform_roas'], 2).'x' : '—',
                        $mktBlock['real_roas'] !== null ? $fmtNum((float) $mktBlock['real_roas'], 2).'x' : '—',
                        $fmtNum((float) ($mktBlock['utm_coverage_pct'] ?? 0.0), 1)
                    )
                    : 'এই সপ্তাহে কোনো বিজ্ঞাপন রিপোর্ট আমদানি করা হয়নি।',
                'caveat' => 'দ্রষ্টব্য: সব হিসাব নির্ধারিত কোড থেকে তৈরি। সিস্টেম নিজে থেকে দাম, বাজেট বা অর্ডার পরিবর্তন করে না — কেবল প্রিভিউ ও প্রিন্টের জন্য প্রস্তুত।',
                'th_area' => 'শীর্ষ ক্ষতির এলাকা',
                'th_reason' => 'শীর্ষ বাতিলের কারণ',
                'th_courier' => 'কুরিয়ার',
                'th_shipments' => 'শিপমেন্ট',
                'th_delivered' => 'ডেলিভারড',
                'th_returned' => 'রিটার্ন',
                'th_success' => 'সফলতা হার',
                'th_title' => 'শিরোনাম',
                'th_status' => 'অবস্থা',
                'th_outcome' => 'ফলাফল / শিক্ষা',
                'insufficient_badge' => 'কম নমুনা (<২০)',
            ];
        } else {
            $labels = [
                'page_title' => 'Weekly Executive Summary',
                'subtitle' => 'Built automatically Saturday 08:00 Asia/Dhaka — preview and print only (no email or SMS sent).',
                'workspace' => 'Workspace',
                'period' => 'Report Week',
                'built_at' => 'Built at',
                'print_btn' => 'Print / Save PDF',
                'build_current_btn' => 'Build current week',
                'build_last_btn' => 'Build last completed week',
                'back_btn' => 'Back to Weekly Reports',
                'lang_en' => 'English',
                'lang_bn' => 'বাংলা',
                'kpi_orders' => 'Total Orders',
                'kpi_gross' => 'Gross Sales (Excl. Cancel)',
                'kpi_delivered' => 'Delivered Revenue',
                'kpi_lost' => 'Lost to Cancel & Return',
                'kpi_dq' => 'Data Quality Score',
                'sec_overview' => '1. Orders & Revenue Summary',
                'sec_loss' => '2. Money Lost (Cancellations & Returns)',
                'sec_courier' => '3. Courier Highlights',
                'sec_findings' => '4. Top R&D Findings',
                'sec_experiments' => '5. Experiment Updates',
                'sec_marketing' => '6. Marketing & Real ROAS Note',
                'overview_line' => sprintf(
                    'During %s to %s, the workspace recorded %s orders (%s delivered, %s cancelled [%s], %s returned [%s]). Data-quality score: %s.',
                    $startStr,
                    $endStr,
                    $fmtNum($totalOrders),
                    $fmtNum((int) ($ordersSum['delivered_orders'] ?? 0)),
                    $fmtNum((int) ($ordersSum['cancelled_orders'] ?? 0)),
                    $cancelRate,
                    $fmtNum((int) ($ordersSum['returned_orders'] ?? 0)),
                    $returnRate,
                    $dqScore
                ),
                'loss_line' => $mixed
                    ? 'Mixed currencies detected across orders; combined monetary totals are hidden.'
                    : sprintf(
                        'Estimated sales not realised totalled %s (cancelled: %s, returned: %s), equal to %s%% of all order value placed in the week.',
                        $fmtMoney($loss['total_lost_value'] ?? null),
                        $fmtMoney($loss['cancelled_value'] ?? null),
                        $fmtMoney($loss['returned_value'] ?? null),
                        $fmtNum((float) ($loss['lost_share_pct'] ?? 0.0), 1)
                    ),
                'return_cost_line' => ($loss['has_return_fee_data'] ?? false)
                    ? 'Estimated courier return handling fees: '.$fmtMoney($loss['estimated_return_handling_cost'] ?? null)
                    : 'Courier return handling fee data is missing (import courier CSV to measure return fees).',
                'courier_highlight_line' => $worst !== null
                    ? sprintf(
                        'Lowest delivery rate courier: %s (%s%% success across %s shipments)%s.',
                        (string) $worst['name'],
                        $fmtNum((float) $worst['success_rate'], 1),
                        $fmtNum((int) $worst['shipments']),
                        ! empty($worst['statistically_unusual']) ? ' — flagged statistically unusual' : ''
                    )
                    : 'No courier has >= 20 shipments in this weekly window for statistical rate comparison.',
                'findings_empty' => 'No open R&D findings recorded yet. Run a rule scan from the Action Center.',
                'exp_summary_line' => sprintf(
                    'Total tracked experiments: %s (Running: %s, Completed: %s, Draft/Approved: %s).',
                    $fmtNum((int) ($expBlock['total'] ?? 0)),
                    $fmtNum((int) ($expBlock['counts']['running'] ?? 0)),
                    $fmtNum((int) ($expBlock['counts']['completed'] ?? 0)),
                    $fmtNum((int) (($expBlock['counts']['draft'] ?? 0) + ($expBlock['counts']['approved'] ?? 0)))
                ),
                'exp_empty' => 'No experiments have been created yet.',
                'marketing_line' => ! empty($mktBlock['has_ad_data'])
                    ? sprintf(
                        'Ad spend: %s | Platform-reported ROAS: %s | Real delivered-order ROAS: %s | UTM coverage: %s%%. (ROAS is not profit).',
                        $fmtMoney((float) ($mktBlock['spend'] ?? 0.0)),
                        $mktBlock['platform_roas'] !== null ? $fmtNum((float) $mktBlock['platform_roas'], 2).'x' : '—',
                        $mktBlock['real_roas'] !== null ? $fmtNum((float) $mktBlock['real_roas'], 2).'x' : '—',
                        $fmtNum((float) ($mktBlock['utm_coverage_pct'] ?? 0.0), 1)
                    )
                    : 'No ad performance rows were imported for this week.',
                'caveat' => 'Note: Every metric is computed deterministically by code. No automated pricing, budget, or order actions are taken; offline preview and print only.',
                'th_area' => 'Top Loss Area',
                'th_reason' => 'Top Cancel Reason',
                'th_courier' => 'Courier',
                'th_shipments' => 'Shipments',
                'th_delivered' => 'Delivered',
                'th_returned' => 'Returned',
                'th_success' => 'Success Rate',
                'th_title' => 'Experiment',
                'th_status' => 'Status',
                'th_outcome' => 'Outcome & Lesson',
                'insufficient_badge' => 'Insufficient (<20)',
            ];
        }

        return [
            'locale' => $loc,
            'bn_digits' => $bnDigits,
            'tenant_name' => (string) ($p['tenant_name'] ?? ''),
            'week_start' => $startStr,
            'week_end' => $endStr,
            'built_at' => (string) ($p['built_at'] ?? $report->built_at?->format('Y-m-d H:i:s') ?? ''),
            'currency' => $currency,
            'mixed_currency' => $mixed,
            'labels' => $labels,
            'kpis' => [
                'orders' => $fmtNum($totalOrders),
                'gross_sales' => $fmtMoney($ordersSum['gross_sales'] ?? null),
                'delivered_revenue' => $fmtMoney($ordersSum['delivered_revenue'] ?? null),
                'total_lost_value' => $fmtMoney($loss['total_lost_value'] ?? null),
                'data_quality' => $dqScore,
            ],
            'top_areas' => $loss['top_areas'] ?? [],
            'top_reasons' => $loss['top_reasons'] ?? [],
            'couriers' => array_map(static function (array $c) use ($fmtNum): array {
                return [
                    'name' => (string) ($c['name'] ?? ''),
                    'shipments' => $fmtNum((int) ($c['shipments'] ?? 0)),
                    'delivered' => $fmtNum((int) ($c['delivered'] ?? 0)),
                    'returned' => $fmtNum((int) ($c['returned'] ?? 0)),
                    'success_rate' => $c['success_rate'] !== null ? $fmtNum((float) $c['success_rate'], 1).'%' : null,
                    'insufficient_data' => (bool) ($c['insufficient_data'] ?? false),
                    'statistically_unusual' => (bool) ($c['statistically_unusual'] ?? false),
                ];
            }, $cour['couriers'] ?? []),
            'findings' => $renderedFindings,
            'experiments' => $expBlock['items'] ?? [],
        ];
    }
}
