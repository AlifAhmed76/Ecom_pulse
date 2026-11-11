<?php

namespace App\Services\Rnd;

use App\Models\ResearchFinding;

final class FindingTemplates
{
    public const SUPPORTED_LOCALES = ['en', 'bn'];

    /**
     * All template keys defined by rules R01-R12 (used by unit tests to guarantee zero unfilled placeholders).
     *
     * @return list<string>
     */
    public static function allTemplateKeys(): array
    {
        return [
            'R01.trigger', 'R01.insufficient',
            'R02.trigger', 'R02.insufficient',
            'R03.trigger', 'R03.insufficient',
            'R04.trigger', 'R04.insufficient', 'R04.missing_courier',
            'R05.trigger', 'R05.insufficient',
            'R06.trigger', 'R06.insufficient',
            'R07.trigger', 'R07.insufficient', 'R07.missing_value',
            'R08.trigger', 'R08.insufficient', 'R08.missing_items',
            'R09.trigger', 'R09.insufficient', 'R09.low_coverage',
            'R10.trigger', 'R10.insufficient',
            'R11.trigger', 'R11.insufficient',
            'R12.trigger',
        ];
    }

    /**
     * Format numbers consistently with optional Bangla digits.
     */
    public static function formatNumber(int|float $value, int $decimals = 2, bool $banglaDigits = false): string
    {
        $formatted = number_format((float) $value, $decimals);
        if ($decimals > 0 && str_contains($formatted, '.')) {
            // Keep clean fixed decimals when specified, or integer when decimals=0.
        }

        return $banglaDigits ? self::toBanglaDigits($formatted) : $formatted;
    }

    public static function toBanglaDigits(string $text): string
    {
        return strtr($text, [
            '0' => '০',
            '1' => '১',
            '2' => '২',
            '3' => '৩',
            '4' => '৪',
            '5' => '৫',
            '6' => '৬',
            '7' => '৭',
            '8' => '৮',
            '9' => '৯',
        ]);
    }

    public static function normalizeLocale(?string $locale): string
    {
        return $locale === 'bn' ? 'bn' : 'en';
    }

    /**
     * Render a ResearchFinding instance in the requested UI locale.
     *
     * @return array<string, mixed>
     */
    public static function renderFinding(ResearchFinding $finding, string $locale = 'en', bool $banglaDigits = false): array
    {
        return self::render(
            (string) $finding->template_key,
            is_array($finding->params) ? $finding->params : [],
            $locale,
            $banglaDigits
        );
    }

    /**
     * Render all template sections for a rule key & parameter map in 'en' or 'bn'.
     *
     * @param  array<string, scalar|null>  $params
     * @return array{
     *   title: string,
     *   summary: string,
     *   confidence_reason: string,
     *   hypotheses: list<string>,
     *   missing_data: list<string>,
     *   action: string,
     *   experiment: string,
     *   success_metric: array{metric: string, baseline: string, target: string, duration: string}
     * }
     */
    public static function render(string $templateKey, array $params, string $locale = 'en', bool $banglaDigits = false): array
    {
        $loc = self::normalizeLocale($locale);
        $catalog = $loc === 'bn' ? self::bnTemplates() : self::enTemplates();
        $tpl = $catalog[$templateKey] ?? $catalog['R12.trigger'];

        $confKey = (string) ($params['confidence_reason_key'] ?? 'no_test_stat');
        $confTpl = self::confidenceReasonTemplate($confKey, $loc);

        $strParams = self::stringifyParams($params, $loc, $banglaDigits);
        $replace = static fn (string $text): string => self::interpolate($text, $strParams, $banglaDigits);

        $hypotheses = [];
        foreach ($tpl['hypotheses'] as $h) {
            $prefix = $loc === 'bn' ? 'যাচাইহীন অনুমান: ' : 'Unverified hypothesis: ';
            $hypotheses[] = $prefix.$replace($h);
        }

        $missing = [];
        foreach ($tpl['missing_data'] as $m) {
            $missing[] = $replace($m);
        }

        return [
            'title' => $replace($tpl['title']),
            'summary' => $replace($tpl['summary']),
            'confidence_reason' => $replace($confTpl),
            'hypotheses' => $hypotheses,
            'missing_data' => $missing,
            'action' => $replace($tpl['action']),
            'experiment' => $replace($tpl['experiment']),
            'success_metric' => [
                'metric' => $replace($tpl['success']['metric']),
                'baseline' => $replace($tpl['success']['baseline']),
                'target' => $replace($tpl['success']['target']),
                'duration' => $replace($tpl['success']['duration']),
            ],
        ];
    }

    /**
     * UI dictionary for Action Center and Finding Detail views.
     *
     * @return array<string, string>
     */
    public static function uiLabels(string $locale = 'en'): array
    {
        if (self::normalizeLocale($locale) === 'bn') {
            return [
                'page_title' => 'অ্যাকশন সেন্টার ও আরঅ্যান্ডডি ল্যাব',
                'page_subtitle' => 'নিয়ম ও পরিসংখ্যানভিত্তিক পর্যবেক্ষণ — কেবল প্রস্তাবনা, স্বয়ংক্রিয় কোনো কাজ করা হয় না',
                'run_scan' => 'স্ক্যান চালান',
                'lang_en' => 'English',
                'lang_bn' => 'বাংলা',
                'digits_toggle' => 'বাংলা সংখ্যা',
                'group_today' => 'আজই দেখুন (জরুরি ও উচ্চ ঝুঁকি)',
                'group_week' => 'এই সপ্তাহে পর্যবেক্ষণ করুন',
                'group_info' => 'তথ্য / অপর্যাপ্ত ডেটা নোটিশ',
                'status_open' => 'খোলা ফলাফল',
                'status_all' => 'সব ফলাফল',
                'status_closed' => 'বন্ধ / বাতিলকৃত',
                'empty_title' => 'এখনো কোনো খোলা আরঅ্যান্ডডি ফলাফল নেই।',
                'empty_desc' => 'নিয়মগুলো চালানোর জন্য অর্ডার ডেটা (কানেক্টর সিঙ্ক বা অর্ডার CSV), কুরিয়ার রিপোর্ট CSV এবং পণ্যের খরচ/স্টক ডেটা যোগ করে স্ক্যান চালান।',
                'money_at_stake' => 'ঝুঁকিপূর্ণ অর্থ',
                'informational' => 'তথ্যমূলক',
                'confidence' => 'আস্থা',
                'view_analytics' => 'অ্যানালিটিক্সে প্রমাণ দেখুন',
                'view_detail' => 'বিস্তারিত দেখুন',
                'mark_review' => 'রিভিউ শুরু করুন',
                'dismiss' => 'বাতিল করুন',
                'dismiss_reason_ph' => 'বাতিলের কারণ লিখুন...',
                'back_to_ac' => 'অ্যাকশন সেন্টারে ফিরুন',
                'hypothesis_disclaimer' => 'দ্রষ্টব্য: সব কারণ কেবল অনুমান (যাচাইকৃত নয়)। কোনো পদক্ষেপ সিস্টেম নিজে নেয় না।',
                'field_1' => '১. পর্যবেক্ষণ (কী ঘটেছে)',
                'field_2' => '২. প্রমাণ ও পরিসংখ্যান',
                'field_3' => '৩. ঝুঁকি ও অগ্রাধিকার',
                'field_4' => '৪. আস্থার মাত্রা (Confidence)',
                'field_5' => '৫. সম্ভাব্য কারণসমূহ (সবগুলো যাচাইহীন অনুমান)',
                'field_6' => '৬. অনুপস্থিত তথ্য (Missing Data)',
                'field_7' => '৭. প্রস্তাবিত পদক্ষেপ',
                'field_8' => '৮. এক্সপেরিমেন্ট পরিকল্পনা',
                'field_9' => '৯. সাফল্যের মাপকাঠি',
                'field_10' => '১০. অবস্থা ও ফলাফল',
                'severity_critical' => 'অতি জরুরি',
                'severity_high' => 'উচ্চ',
                'severity_medium' => 'মাঝারি',
                'severity_low' => 'নিম্ন',
                'conf_high' => 'উচ্চ আস্থা',
                'conf_medium' => 'মাঝারি আস্থা',
                'conf_low' => 'নিম্ন আস্থা',
                'status_new' => 'নতুন',
                'status_reviewing' => 'পর্যালোচনা চলছে',
                'status_approved' => 'অনুমোদিত',
                'status_testing' => 'পরীক্ষাধীন',
                'status_validated' => 'প্রমাণিত',
                'status_rejected' => 'প্রত্যাখ্যাত',
                'status_dismissed' => 'বাতিলকৃত',
                'source_tables' => 'উৎস টেবিল',
                'window' => 'সময়সীমা',
                'baseline' => 'তুলনামূলক বেসলাইন',
                'sample_size' => 'নমুনা সংখ্যা (n)',
                'metric_name' => 'মাপকাঠি',
                'baseline_val' => 'বেসলাইন',
                'target_val' => 'লক্ষ্য',
                'duration' => 'সময়কাল',
                'view_only' => 'শুধুমাত্র দেখার অনুমতি (অ্যানালিস্ট বাতিল বা পরিবর্তন করতে পারবেন না)',
            ];
        }

        return [
            'page_title' => 'Action Center & R&D Lab',
            'page_subtitle' => 'Rule and statistics findings in Bangla & English — advisory only, no automatic actions.',
            'run_scan' => 'Run scan',
            'lang_en' => 'English',
            'lang_bn' => 'বাংলা',
            'digits_toggle' => 'Bangla digits',
            'group_today' => 'Today (Critical & High Priority)',
            'group_week' => 'This week',
            'group_info' => 'Info (Insufficient Data Notices)',
            'status_open' => 'Open queue',
            'status_all' => 'All statuses',
            'status_closed' => 'Closed & dismissed',
            'empty_title' => 'No open R&D findings match the current filter.',
            'empty_desc' => 'To evaluate all 12 rules, ensure orders (synced or imported), courier reports (with status/delivery outcomes), product stock/unit costs, and assignments are available, then run a scan.',
            'money_at_stake' => 'Money at stake',
            'informational' => 'Informational',
            'confidence' => 'Confidence',
            'view_analytics' => 'View evidence in Analytics',
            'view_detail' => 'Open finding',
            'mark_review' => 'Review',
            'dismiss' => 'Dismiss',
            'dismiss_reason_ph' => 'Reason for dismissing...',
            'back_to_ac' => 'Back to Action Center',
            'hypothesis_disclaimer' => 'Note: Possible causes listed below are UNVERIFIED HYPOTHESES, not confirmed facts. No automated change is ever made.',
            'field_1' => '1. Finding (What happened)',
            'field_2' => '2. Evidence & statistical metrics',
            'field_3' => '3. Risk / priority & money at stake',
            'field_4' => '4. Confidence level & reason',
            'field_5' => '5. Possible causes (hypotheses — unverified)',
            'field_6' => '6. Missing data',
            'field_7' => '7. Recommended action',
            'field_8' => '8. Experiment plan',
            'field_9' => '9. Success metric',
            'field_10' => '10. Status & outcome',
            'severity_critical' => 'Critical',
            'severity_high' => 'High',
            'severity_medium' => 'Medium',
            'severity_low' => 'Low',
            'conf_high' => 'High confidence',
            'conf_medium' => 'Medium confidence',
            'conf_low' => 'Low confidence',
            'status_new' => 'New',
            'status_reviewing' => 'Reviewing',
            'status_approved' => 'Approved',
            'status_testing' => 'Testing',
            'status_validated' => 'Validated',
            'status_rejected' => 'Rejected',
            'status_dismissed' => 'Dismissed',
            'source_tables' => 'Source tables',
            'window' => 'Window',
            'baseline' => 'Comparison baseline',
            'sample_size' => 'Sample size (n)',
            'metric_name' => 'Metric',
            'baseline_val' => 'Baseline',
            'target_val' => 'Target',
            'duration' => 'Duration',
            'view_only' => 'View only (Analysts cannot review or dismiss findings)',
        ];
    }

    private static function confidenceReasonTemplate(string $key, string $locale): string
    {
        if ($locale === 'bn') {
            return match ($key) {
                'high_stat' => 'নমুনা n={n} (>=১০০), পরিসংখ্যানিক সংকেত p={p_value} (<০.০১) এবং ডেটা মান {dq_score}/১০০ (>=৮৫)।',
                'medium_stat' => 'নমুনা n={n} (>=৩০), পরিসংখ্যানিক সংকেত p={p_value} (<০.০৫) এবং ডেটা মান {dq_score}/১০০।',
                'dq_low' => 'ডেটা-কোয়ালিটি স্কোর {dq_score}/১০০ (<৬০ হওয়ায় আস্থা নিম্ন রাখা হয়েছে); নমুনা n={n}।',
                'insufficient_sample' => 'নমুনা সংখ্যা কম (n={n} < ২০), তাই কেবল প্রাথমিক নোটিশ দেওয়া হয়েছে।',
                default => 'পরিসংখ্যানিক p-মান ছাড়াই নির্ধারিত থ্রেশহোল্ড নিয়মে পাওয়া পর্যবেক্ষণ (n={n}, ডেটা মান {dq_score}/১০০)।',
            };
        }

        return match ($key) {
            'high_stat' => 'Large sample (n={n} >= 100), strong statistical significance (p={p_value} < 0.01), and data-quality score {dq_score}/100 (>= 85).',
            'medium_stat' => 'Adequate sample (n={n} >= 30), statistically significant difference (p={p_value} < 0.05), data-quality {dq_score}/100.',
            'dq_low' => 'Data-quality score is {dq_score}/100 (< 60), so confidence is capped at low (n={n}).',
            'insufficient_sample' => 'Sample size is below 20 (n={n}); emitted as an informational notice only.',
            default => 'Rule triggered on validated deterministic thresholds without an underlying p-test baseline (n={n}, DQ {dq_score}/100).',
        };
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<string, string>
     */
    private static function stringifyParams(array $params, string $locale, bool $banglaDigits): array
    {
        $preserveAscii = ['website', 'courier', 'employee', 'product', 'top_product', 'top1_name', 'currency', 'bad_summary', 'disabled_rules', 'bad_indicators_list'];
        $out = [];
        foreach ($params as $k => $v) {
            if ($v === null) {
                $out[$k] = $locale === 'bn' ? 'অজানা' : 'n/a';
            } elseif (is_bool($v)) {
                $out[$k] = $v ? ($locale === 'bn' ? 'হ্যাঁ' : 'yes') : ($locale === 'bn' ? 'না' : 'no');
            } else {
                $str = (string) $v;
                $out[$k] = ($banglaDigits && ! in_array((string) $k, $preserveAscii, true))
                    ? self::toBanglaDigits($str)
                    : $str;
            }
        }

        $defaults = [
            'website' => $locale === 'bn' ? 'স্টোর' : 'Store',
            'currency' => 'BDT',
            'days' => $banglaDigits ? self::toBanglaDigits('7') : '7',
            'n' => $banglaDigits ? self::toBanglaDigits('0') : '0',
            'dq_score' => $banglaDigits ? self::toBanglaDigits('0') : '0',
            'p_value' => $locale === 'bn' ? 'প্রযোজ্য নয়' : 'n/a',
        ];

        return $out + $defaults;
    }

    /**
     * @param  array<string, string>  $strParams
     */
    private static function interpolate(string $template, array $strParams, bool $banglaDigits): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function (array $m) use ($strParams): string {
            $key = $m[1];

            return $strParams[$key] ?? '0';
        }, $template) ?? $template;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function enTemplates(): array
    {
        return [
            'R01.trigger' => [
                'title' => 'Sales dropped {drop_pct}% vs previous 7 days ({website})',
                'summary' => 'Gross sales for {website} fell from {currency} {prev_gross} ({prev_orders} orders) to {currency} {cur_gross} ({cur_orders} orders), a {drop_pct}% decline ({diff_amount} {currency} gap).',
                'hypotheses' => [
                    'Top-selling product stock-out or price mismatch slowed checkout conversion.',
                    'Ad campaign pause, creative fatigue, or tracking disruption reduced traffic quality.',
                    'Checkout or payment/COD confirmation friction emerged after a recent site change.',
                    'Seasonal post-campaign cooldown or holiday shift affected buyer demand.',
                ],
                'missing_data' => [
                    'Ad spend & click volume by campaign (available once Ad CSV is imported in Marketing).',
                    'Storefront session & cart abandonment counts.',
                ],
                'action' => 'Compare top SKUs and checkout completion in Analytics for the past 14 days before changing prices or budgets.',
                'experiment' => 'Test restoring visibility or offer for the top 3 declining SKUs vs holding baseline catalog steady.',
                'success' => [
                    'metric' => '7-day gross sales ({currency})',
                    'baseline' => '{currency} {cur_gross}',
                    'target' => '>= {currency} {target_gross}',
                    'duration' => '14 days',
                ],
            ],
            'R01.insufficient' => [
                'title' => 'Insufficient order volume to evaluate sales drop ({website})',
                'summary' => 'Previous 7 days had {prev_orders} orders and current 7 days had {cur_orders} orders (< 20 minimum sample). No sales-drop alert is raised until order volume reaches 20.',
                'hypotheses' => [
                    'Store traffic or import coverage for the recent 14-day window may still be incomplete.',
                ],
                'missing_data' => [
                    'At least 20 orders in the prior 7-day baseline window.',
                ],
                'action' => 'Confirm connector sync or order file imports cover the full last 14 days.',
                'experiment' => 'Collect 2 more weeks of order volume before benchmarking week-over-week trends.',
                'success' => [
                    'metric' => 'Weekly baseline order count',
                    'baseline' => '{prev_orders} orders',
                    'target' => '>= 20 orders/week',
                    'duration' => '14 days',
                ],
            ],
            'R02.trigger' => [
                'title' => 'Cancellation rate spiked from {base}% to {now}% ({website})',
                'summary' => 'Cancellation rate rose from {base}% (trailing 28-day baseline, {base_n} orders) to {now}% (last {days} days, {n} orders; +{rise_pp} pp, p={p_value}). {n_c} orders were cancelled.',
                'hypotheses' => [
                    'Confirmation call delays caused COD buyers to lose intent or buy elsewhere.',
                    'Duplicate or unverified fake/burst orders increased in recent campaigns.',
                    'Unclear shipping fee or delivery timeline on checkout triggered post-order cancels.',
                    'Stock unavailability forced internal cancellations.',
                ],
                'missing_data' => [
                    'Structured cancel_reason on every cancelled order ({missing_reason_pct}% missing).',
                    'Non-import call confirmation timestamps for every assigned order.',
                ],
                'action' => 'Review cancel reasons for the {n_c} cancelled orders and test a confirmation call within 2 hours for new COD orders.',
                'experiment' => 'Apply 2-hour confirmation callback SLA on 50% of incoming COD orders vs current call timing.',
                'success' => [
                    'metric' => 'Cancellation rate (%)',
                    'baseline' => '{now}%',
                    'target' => '<= {target}%',
                    'duration' => '14 days',
                ],
            ],
            'R02.insufficient' => [
                'title' => 'Insufficient orders to test cancellation spike ({website})',
                'summary' => 'Last {days} days have {n} orders (baseline {base_n} orders), below the 20-order minimum needed for reliable cancellation comparisons.',
                'hypotheses' => [
                    'Low order count in window prevents distinguishing real spike from normal noise.',
                ],
                'missing_data' => [
                    'Minimum 30 orders in 7d window and 20 in trailing baseline.',
                ],
                'action' => 'Keep syncing order statuses and re-check once order sample reaches 30.',
                'experiment' => 'Monitor cancellation rate passively until sufficient sample accumulates.',
                'success' => [
                    'metric' => 'Evaluated orders in 7d',
                    'baseline' => '{n} orders',
                    'target' => '>= 30 orders',
                    'duration' => '14 days',
                ],
            ],
            'R03.trigger' => [
                'title' => 'Delivery return rate jumped from {base}% to {now}% ({website})',
                'summary' => 'Return rate on completed deliveries rose from {base}% (trailing 28d, {base_n} completed) to {now}% (last {days}d, {n} completed; +{rise_pp} pp, p={p_value}). {n_r} orders returned.',
                'hypotheses' => [
                    'Mismatch between product photos/specs and what arrived lowered customer acceptance at doorstep.',
                    'Delayed courier transit or missed rider calls left COD buyers unreachable.',
                    'New ad audience or discount buyers had lower purchase commitment.',
                    'Packaging damage during delivery prompted doorstep rejections.',
                ],
                'missing_data' => [
                    'Standardised courier return reason codes from courier CSV report.',
                    'Courier delivery time timestamps (shipped_at and returned_at).',
                ],
                'action' => 'Inspect the {n_r} returned orders in Courier & Loss by product and destination area, and call 10 returned buyers for qualitative feedback.',
                'experiment' => 'Test pre-dispatch WhatsApp/SMS delivery reminder + parcel value confirmation for high-return zones.',
                'success' => [
                    'metric' => 'Return rate on completed deliveries (%)',
                    'baseline' => '{now}%',
                    'target' => '<= {target}%',
                    'duration' => '21 days',
                ],
            ],
            'R03.insufficient' => [
                'title' => 'Insufficient completed deliveries to test return spike ({website})',
                'summary' => 'Completed orders (delivered + returned) in last {days} days = {n} (baseline = {base_n}), below the minimum 20 sample.',
                'hypotheses' => [
                    'Orders may still be in shipped/in-transit status pending final courier CSV update.',
                ],
                'missing_data' => [
                    'Updated courier CSV report showing final delivered/returned status.',
                ],
                'action' => 'Import the latest courier delivery report once current shipments settle.',
                'experiment' => 'Wait for >= 30 completed shipments in 7d window before comparing return rates.',
                'success' => [
                    'metric' => 'Completed deliveries (7d)',
                    'baseline' => '{n} orders',
                    'target' => '>= 30 orders',
                    'duration' => '14 days',
                ],
            ],
            'R04.trigger' => [
                'title' => 'Courier "{courier}" shows unusual return rate ({rate}% vs {other_rate}%)',
                'summary' => 'Over the last 30 days on {website}, {courier} recorded {returned} returns across {n} completed shipments ({rate}% return rate) vs {other_rate}% across other couriers (p={p_value}, z={z_score}).',
                'hypotheses' => [
                    'Rider call discipline or hub backlog with {courier} in specific areas may be increasing failed doorstep handoffs.',
                    'Zone/district mix routed to {courier} (e.g., outside-Dhaka vs inside-Dhaka) may naturally carry higher return risk.',
                    'Parcel weight/value mix or delayed pickup handoff affected customer readiness.',
                ],
                'missing_data' => [
                    'Zone-adjusted delivery SLA and failed-attempt timestamps from courier CSV.',
                ],
                'action' => 'Compare inside-city vs outside-city shipments on {courier} in Courier & Loss before shifting volume.',
                'experiment' => 'Split-route 50% of inside-city shipments for one area to an alternate courier for 14 days and compare return rate.',
                'success' => [
                    'metric' => 'Return rate on shifted route (%)',
                    'baseline' => '{rate}%',
                    'target' => '<= {target}%',
                    'duration' => '14 days',
                ],
            ],
            'R04.insufficient' => [
                'title' => 'Courier comparison has insufficient shipment sample ({website})',
                'summary' => 'Recent courier shipments have only {n} completed orders (< 20 minimum sample), so courier outlier detection cannot claim statistical difference.',
                'hypotheses' => [
                    'Recent dispatches have fewer than 20 completed deliveries per courier.',
                ],
                'missing_data' => [
                    'Courier report CSV with >= 30 completed shipments per courier.',
                ],
                'action' => 'Import full monthly courier settlement CSVs once >= 30 shipments settle.',
                'experiment' => 'Track courier success rate passively until sample reaches 30.',
                'success' => [
                    'metric' => 'Completed shipments per courier',
                    'baseline' => '{n} shipments',
                    'target' => '>= 30 shipments',
                    'duration' => '30 days',
                ],
            ],
            'R04.missing_courier' => [
                'title' => 'Missing data: courier name absent on dispatched orders ({website})',
                'summary' => 'Missing data: courier — {eligible} shipped/delivered/returned orders exist in 30 days on {website}, but none include courier attribution.',
                'hypotheses' => [
                    'Courier CSV has not been imported yet or order export mapping omitted the courier column.',
                ],
                'missing_data' => [
                    'Courier report CSV import or courier column mapping on orders.',
                ],
                'action' => 'Go to Courier & Loss -> Import Courier CSV to match tracking IDs or order references.',
                'experiment' => 'Backfill last 30 days of courier CSVs and re-run R&D scan.',
                'success' => [
                    'metric' => 'Courier attribution coverage (%)',
                    'baseline' => '0%',
                    'target' => '>= 90%',
                    'duration' => '7 days',
                ],
            ],
            'R05.trigger' => [
                'title' => 'Higher cancel rate noted on orders assigned to {employee} ({rate}% vs {other_rate}%)',
                'summary' => 'Note: workload and customer mix may differ. Over the last 30 days on {website}, {employee} had {cancelled} cancelled out of {n} assigned orders ({rate}%) compared to {other_rate}% for the rest of the team (p={p_value}).',
                'hypotheses' => [
                    'Shift timing or higher share of late-night/ad-burst COD leads in {employee} queue (workload and customer mix may differ).',
                    'Differences in confirmation script, callback speed, or duplicate-order flagging workflow.',
                    'Temporary backlog delaying first confirmation contact.',
                ],
                'missing_data' => [
                    'Call attempt outcome logs and exact queue lead-source breakdown per agent.',
                ],
                'action' => 'Review a sample of 15 cancelled orders with {employee} to check lead quality, script objections, and callback speed (remember: workload and customer mix may differ).',
                'experiment' => 'Standardise 2-attempt SMS + call confirmation script on night/high-risk leads for 14 days.',
                'success' => [
                    'metric' => 'Assigned order cancel rate (%)',
                    'baseline' => '{rate}%',
                    'target' => '<= {target}%',
                    'duration' => '14 days',
                ],
            ],
            'R05.insufficient' => [
                'title' => 'Insufficient assigned orders to compare staff cancel rates ({website})',
                'summary' => 'Assigned orders per employee are below minimum comparison volume ({n} orders, < 20 minimum; team members={emp_count}). Workload and customer mix may differ.',
                'hypotheses' => [
                    'Order assignments are missing on imported orders or active volume per agent is small.',
                ],
                'missing_data' => [
                    'Employee assignments on >= 30 orders per staff member.',
                ],
                'action' => 'Ensure employee column is mapped during order import or recorded in connector events.',
                'experiment' => 'Collect 30+ assigned orders per active staff member before benchmarking.',
                'success' => [
                    'metric' => 'Orders with employee assignment',
                    'baseline' => '{n} orders',
                    'target' => '>= 30 orders/staff',
                    'duration' => '30 days',
                ],
            ],
            'R06.trigger' => [
                'title' => 'Stock cover below 7 days on top seller "{product}" ({cover_days} days left)',
                'summary' => '"{product}" (top 20% by 30-day revenue: {currency} {rev_30d}, {units_30d} units sold) has {stock} units in stock — roughly {cover_days} days of stock cover at current velocity.',
                'hypotheses' => [
                    'Reorder lead time was underestimated during recent promo/sales momentum.',
                    'Product stock count in ERP/spreadsheet may differ from physical warehouse shelves.',
                ],
                'missing_data' => [
                    'Supplier reorder lead time (days) and incoming purchase-order quantity.',
                ],
                'action' => 'Verify physical shelf stock of "{product}" today and initiate replenishment if supplier lead time exceeds {cover_days} days.',
                'experiment' => 'Test moderating ad spend on "{product}" or activating pre-order messaging if restock > 7 days away.',
                'success' => [
                    'metric' => 'Days of stock cover (days)',
                    'baseline' => '{cover_days} days',
                    'target' => '>= 14 days',
                    'duration' => '14 days',
                ],
            ],
            'R06.insufficient' => [
                'title' => 'Insufficient sales/stock history to evaluate top-SKU stock cover ({website})',
                'summary' => 'Top product sales sample over 30 days is below 20 ({n} units/orders), or product stock levels are unset.',
                'hypotheses' => [
                    'Products lack current stock quantities or SKU links on recent order items.',
                ],
                'missing_data' => [
                    'Current stock numbers in Products & SKU matching on order items.',
                ],
                'action' => 'Sync product catalogue stock levels and confirm SKU mapping on orders.',
                'experiment' => 'Track 30 days of linked order items before triggering low-cover alerts.',
                'success' => [
                    'metric' => 'Products with linked 30d sales & stock',
                    'baseline' => '{n}',
                    'target' => '>= 20 units/orders',
                    'duration' => '30 days',
                ],
            ],
            'R07.trigger' => [
                'title' => 'Dead stock alert: {sku_count} SKU(s) with zero sales in 60 days ({currency} {dead_value})',
                'summary' => '{sku_count} active product(s) on {website} carry stock > 0 with zero non-cancelled sales in the last {dead_days} days, locking {currency} {dead_value} in inventory value. Top item: "{top_product}" ({top_stock} units = {currency} {top_value}).',
                'hypotheses' => [
                    'Pricing or product positioning has become uncompetitive against newer listings.',
                    'Broken size/color variants or missing traffic/ads keep these items unseen.',
                    'Stock balance represents damaged or obsolete goods not written off.',
                ],
                'missing_data' => [
                    'Actual purchase cost on SKUs where retail price was used as fallback.',
                    'Product page traffic / view counts.',
                ],
                'action' => 'Audit physical condition of top dead-stock items ("{top_product}") and consider bundling with fast-moving hero products.',
                'experiment' => 'Test a 14-day bundle offer pairing "{top_product}" with a top seller vs standalone markdown.',
                'success' => [
                    'metric' => 'Dead-stock inventory cleared ({currency})',
                    'baseline' => '0 units moved in 60d',
                    'target' => '>= 25% of locked stock',
                    'duration' => '21 days',
                ],
            ],
            'R07.insufficient' => [
                'title' => 'Insufficient 60-day history to flag dead stock ({website})',
                'summary' => 'Website has {n} orders in the last 60 days (< 20 minimum sample), so zero-sale products are not flagged as dead stock yet.',
                'hypotheses' => [
                    'Workspace may have recently started syncing orders with fewer than 60 days of history.',
                ],
                'missing_data' => [
                    'At least 60 days of order history (>= 20 orders).',
                ],
                'action' => 'Import historical orders for the past 60 days to identify slow-moving inventory accurately.',
                'experiment' => 'Wait for 60 days of continuous order data before clearing inventory.',
                'success' => [
                    'metric' => '60-day order sample',
                    'baseline' => '{n} orders',
                    'target' => '>= 20 orders',
                    'duration' => '60 days',
                ],
            ],
            'R07.missing_value' => [
                'title' => 'Dead-stock candidates lack cost & price data ({website})',
                'summary' => '{missing_count} product(s) have stock > 0 and no sales in {dead_days} days, but have neither unit cost nor retail price recorded to rank tied-up value.',
                'hypotheses' => [
                    'Product import did not include price or unit_cost columns.',
                ],
                'missing_data' => [
                    'Product cost or price on active catalog SKUs.',
                ],
                'action' => 'Update product prices and unit costs on the Products page to rank tied-up stock value.',
                'experiment' => 'Populate SKU costs for top stocked items first and re-run R&D scan.',
                'success' => [
                    'metric' => 'SKUs with cost or price populated (%)',
                    'baseline' => '0%',
                    'target' => '100%',
                    'duration' => '7 days',
                ],
            ],
            'R08.trigger' => [
                'title' => 'High product revenue concentration ({top1_pct}% in top product, {top3_pct}% in top 3)',
                'summary' => 'On {website} over 30 days, "{top1_name}" alone drives {top1_pct}% of gross sales ({currency} {top1_rev}), and top 3 products account for {top3_pct}% ({currency} {top3_rev}) vs safe thresholds (40% / 70%).',
                'hypotheses' => [
                    'Ad budget is overwhelmingly focused on a single hero product creative.',
                    'Secondary SKUs lack social proof, bundling, or cross-sell visibility on checkout.',
                ],
                'missing_data' => [
                    'Ad spend split by SKU/product.',
                    'Secondary catalog margin comparison.',
                ],
                'action' => 'Protect stock continuity for "{top1_name}" and review profit/return rates of the next 3-5 promising SKUs.',
                'experiment' => 'Test an add-on cross-sell offer for the #2/#3 products at confirmation call or checkout over 21 days.',
                'success' => [
                    'metric' => 'Non-top-1 product revenue share (%)',
                    'baseline' => '{rest_pct}%',
                    'target' => '>= {target_rest_pct}%',
                    'duration' => '30 days',
                ],
            ],
            'R08.insufficient' => [
                'title' => 'Insufficient order sample for product concentration check ({website})',
                'summary' => 'Only {n} non-cancelled orders exist in 30 days (< 20 minimum), so concentration percentages are informational only.',
                'hypotheses' => [
                    'Small sample of early orders naturally concentrates on 1-2 items.',
                ],
                'missing_data' => [
                    'At least 20 non-cancelled orders with line items.',
                ],
                'action' => 'Continue collecting orders before diversifying hero-SKU ad allocation.',
                'experiment' => 'Re-evaluate concentration once monthly order count reaches 20+.',
                'success' => [
                    'metric' => 'Non-cancelled 30d orders',
                    'baseline' => '{n} orders',
                    'target' => '>= 20 orders',
                    'duration' => '30 days',
                ],
            ],
            'R08.missing_items' => [
                'title' => 'Missing data: order line items absent on 30d orders ({website})',
                'summary' => '{n} orders exist in the last 30 days on {website}, but order_items revenue data is missing, blocking product concentration & stock checks.',
                'hypotheses' => [
                    'Whole-order CSV import was used without product_name/SKU item columns.',
                ],
                'missing_data' => [
                    'Line-item product rows (order_items) for recent orders.',
                ],
                'action' => 'Map product_name / SKU and quantity columns on your next import or connector sync.',
                'experiment' => 'Re-import recent orders with line-item details included.',
                'success' => [
                    'metric' => 'Orders with line items (%)',
                    'baseline' => '0%',
                    'target' => '>= 90%',
                    'duration' => '7 days',
                ],
            ],
            'R09.trigger' => [
                'title' => 'Estimated contribution margin fell {drop_pp} pp (from {prev_margin}% to {cur_margin}%)',
                'summary' => 'With {cur_cov}% cost coverage on {website}, estimated contribution margin dropped from {prev_margin}% (previous 30d) to {cur_margin}% (current 30d), a {drop_pp} percentage-point decline before ads/shipping/returns.',
                'hypotheses' => [
                    'Unit supplier cost increased on top-selling delivered items.',
                    'Higher discount depth or low-margin product mix shifted recent deliveries.',
                    'Product cost updates in CSV imports lowered realized margin.',
                ],
                'missing_data' => [
                    'Remaining {uncovered_pct}% uncosted delivered item revenue.',
                    'Ad spend & courier return fees (deducted separately outside product contribution).',
                ],
                'action' => 'Inspect SKU-level unit cost changes and recent discounts on the top 10 delivered products before adjusting retail prices.',
                'experiment' => 'Test raising price or trimming discount on the lowest-margin fast mover while holding volume stable.',
                'success' => [
                    'metric' => 'Estimated contribution margin (%)',
                    'baseline' => '{cur_margin}%',
                    'target' => '>= {target}%',
                    'duration' => '30 days',
                ],
            ],
            'R09.insufficient' => [
                'title' => 'Insufficient delivered orders to compare 30d margin trend ({website})',
                'summary' => 'Current 30d ({cur_delivered}) or previous 30d ({prev_delivered}) delivered order count is below 20, preventing reliable margin trend comparison.',
                'hypotheses' => [
                    'Fewer than 20 delivered orders exist in either the current or prior 30-day window.',
                ],
                'missing_data' => [
                    '>= 20 delivered orders in both current and previous 30-day periods.',
                ],
                'action' => 'Allow another 30-day period of delivered order history with unit costs.',
                'experiment' => 'Wait for sufficient delivered volume in both windows.',
                'success' => [
                    'metric' => 'Delivered orders per 30d window',
                    'baseline' => '{n} orders',
                    'target' => '>= 20 orders',
                    'duration' => '30 days',
                ],
            ],
            'R09.low_coverage' => [
                'title' => 'Cost data insufficient ({cur_cov}% / {prev_cov}%) for margin trend ({website})',
                'summary' => 'Estimated contribution margin change cannot be evaluated safely because item unit-cost coverage is below 60% (current 30d: {cur_cov}%, prior 30d: {prev_cov}%).',
                'hypotheses' => [
                    'Unit cost was omitted on imported products or line items.',
                ],
                'missing_data' => [
                    'unit_cost on order_items covering at least 60% of delivered item revenue.',
                ],
                'action' => 'Upload/fill unit costs on Products so delivered items achieve >= 60% revenue coverage.',
                'experiment' => 'Backfill costs for top 20 SKUs by revenue and re-run R&D scan.',
                'success' => [
                    'metric' => 'Costed revenue coverage (%)',
                    'baseline' => '{cur_cov}%',
                    'target' => '>= 60%',
                    'duration' => '7 days',
                ],
            ],
            'R10.trigger' => [
                'title' => 'Discounted orders show higher return rate ({disc_ret}% vs {undisc_ret}%)',
                'summary' => 'Over 30 days on {website}, discounted orders ({disc_n} completed) returned at {disc_ret}% (AOV {currency} {disc_aov}) vs {undisc_ret}% on full-price orders ({undisc_n} completed, AOV {currency} {undisc_aov}; p={p_value}).',
                'hypotheses' => [
                    'Heavy promotional discounts attracted impulse COD buyers who cancel/refuse at delivery.',
                    'Bundle/promo conditions were misunderstood at checkout compared to standard listings.',
                    'Coupon abuse or duplicate multi-account discount orders inflated return exposure.',
                ],
                'missing_data' => [
                    'Coupon/promo code identifiers & ad creative promises linked to discounted orders.',
                ],
                'action' => 'Audit which discount tiers or promo campaigns generate returns and verify promise-vs-product alignment.',
                'experiment' => 'Test small prepaid incentive / confirmation call instead of deep COD discount on the next campaign.',
                'success' => [
                    'metric' => 'Discounted order return rate (%)',
                    'baseline' => '{disc_ret}%',
                    'target' => '<= {target}%',
                    'duration' => '21 days',
                ],
            ],
            'R10.insufficient' => [
                'title' => 'Insufficient discounted vs undiscounted sample ({website})',
                'summary' => 'Discounted ({disc_n}) or undiscounted ({undisc_n}) completed orders over 30 days are below 20, too small for a reliable return-rate test.',
                'hypotheses' => [
                    'Recent promotions have generated fewer than 20 settled deliveries so far.',
                ],
                'missing_data' => [
                    'At least 20 completed discounted and 20 undiscounted orders in 30 days.',
                ],
                'action' => 'Monitor current discounted orders until >= 20 reach delivered or returned status.',
                'experiment' => 'Re-evaluate discount impact after promo cohort settles.',
                'success' => [
                    'metric' => 'Settled discounted orders',
                    'baseline' => '{disc_n} orders',
                    'target' => '>= 20 orders',
                    'duration' => '30 days',
                ],
            ],
            'R11.trigger' => [
                'title' => 'Repeat purchase rate dropped {drop_pp} pp (from {prev_rate}% to {cur_rate}%)',
                'summary' => 'Share of {website} buyers with >= 2 lifetime orders dropped from {prev_rate}% ({prev_buyers} buyers, prior 60d) to {cur_rate}% ({cur_buyers} buyers, recent 60d; -{drop_pp} pp, p={p_value}).',
                'hypotheses' => [
                    'Recent customer acquisition brought one-time promo hunters with lower product fit.',
                    'Post-delivery follow-up, reorder reminders, or packaging insert CTAs slowed down.',
                    'Hero consumable/repeat SKU went out of stock or quality varied.',
                ],
                'missing_data' => [
                    'Hashed customer contact key on {missing_cust_pct}% of orders (undercounts repeat matching if high).',
                ],
                'action' => 'Check whether repeat drop coincides with missing customer_key on imports or lower repeat on a specific first-purchase SKU.',
                'experiment' => 'Test a Day-21 post-delivery WhatsApp/SMS reorder offer on recent delivered first-time buyers.',
                'success' => [
                    'metric' => '60-day repeat purchase rate (%)',
                    'baseline' => '{cur_rate}%',
                    'target' => '>= {target}%',
                    'duration' => '30 days',
                ],
            ],
            'R11.insufficient' => [
                'title' => 'Insufficient buyer count to evaluate 60d repeat-rate trend ({website})',
                'summary' => 'Unique buyers with customer_key in current ({cur_buyers}) or prior 60d ({prev_buyers}) are below minimum threshold (50 buyers required, < 20 present).',
                'hypotheses' => [
                    'Customer contact phone/email was missing during import or store is in early volume phase.',
                ],
                'missing_data' => [
                    'Hashed customer_key across >= 50 unique buyers in both 60-day windows.',
                ],
                'action' => 'Ensure customer contact column is mapped during CSV/XLSX imports so buyers can be matched safely.',
                'experiment' => 'Re-check repeat purchase cohorts after 60 days of buyer tracking.',
                'success' => [
                    'metric' => 'Buyers with customer_key per 60d',
                    'baseline' => '{cur_buyers} buyers',
                    'target' => '>= 50 buyers',
                    'duration' => '60 days',
                ],
            ],
            'R12.trigger' => [
                'title' => 'Data-quality blocker disables reliable analysis ({website}: score {dq_score}/100)',
                'summary' => 'Critical data gaps (> 20% "bad" threshold) detected on {website}: {bad_summary}. Disables affected rules: {disabled_rules}.',
                'hypotheses' => [
                    'Recent CSV mapping left out courier, product SKU/cost, customer phone, or employee columns.',
                    'Live store connector sync paused or unmapped raw statuses remain mapped to "other".',
                ],
                'missing_data' => [
                    '{bad_indicators_list}',
                ],
                'action' => 'Inspect Data Quality Breakdown in Analytics for {website} and fix the highest-weight missing fields via CSV mapping or connector check.',
                'experiment' => 'Re-import or update missing fields on recent 30d orders to raise data-quality score >= 85/100.',
                'success' => [
                    'metric' => 'Data-quality score (0-100)',
                    'baseline' => '{dq_score}/100',
                    'target' => '>= 85/100',
                    'duration' => '7 days',
                ],
            ],
        ];
    }

    /**
     * Handwritten natural Bengali translations (never runtime machine-translated).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function bnTemplates(): array
    {
        return [
            'R01.trigger' => [
                'title' => 'গত ৭ দিনে বিক্রি {drop_pct}% কমেছে ({website})',
                'summary' => '{website}-এ গ্রস বিক্রি আগের ৭ দিনের {currency} {prev_gross} ({prev_orders}টি অর্ডার) থেকে কমে {currency} {cur_gross} ({cur_orders}টি অর্ডার) হয়েছে — অর্থাৎ {drop_pct}% হ্রাস ({diff_amount} {currency} কম)।',
                'hypotheses' => [
                    'বেশি বিক্রি হওয়া পণ্যের স্টক শেষ হয়ে যাওয়া বা দামের অসামঞ্জস্যের কারণে কনভার্সন কমে থাকতে পারে।',
                    'বিজ্ঞাপন বন্ধ থাকা, অ্যাড ক্রিয়েটিভ পুরনো হওয়া বা ট্র্যাকিং সমস্যায় ভিজিটরের মান কমে থাকতে পারে।',
                    'চেকআউট বা অর্ডার কনফার্মেশন ধাপে কোনো প্রযুক্তিগত জটিলতা তৈরি হয়ে থাকতে পারে।',
                    'মৌসুমি চাহিদা পরিবর্তন বা পূর্ববর্তী ক্যাম্পেইন শেষ হওয়ায় অর্ডার কমে থাকতে পারে।',
                ],
                'missing_data' => [
                    'ক্যাম্পেইন অনুযায়ী বিজ্ঞাপন খরচ ও ক্লিকের তথ্য (মার্কেটিং অ্যাড CSV ইমপোর্ট করলে পাওয়া যাবে)।',
                    'ওয়েবসাইটের ভিজিটর ও কার্ট অ্যাবান্ডনমেন্ট তথ্য।',
                ],
                'action' => 'দাম বা অ্যাড বাজেট পরিবর্তনের আগে অ্যানালিটিক্স পেজে গত ১৪ দিনের শীর্ষ পণ্য এবং অর্ডার ফানেল মিলিয়ে দেখুন।',
                'experiment' => 'বিক্রি কমে যাওয়া শীর্ষ ৩টি পণ্যের ভিজিবিলিটি/অফার পুনরায় চালু করে ১৪ দিন পর্যবেক্ষণ করুন।',
                'success' => [
                    'metric' => '৭ দিনের গ্রস বিক্রি ({currency})',
                    'baseline' => '{currency} {cur_gross}',
                    'target' => '>= {currency} {target_gross}',
                    'duration' => '১৪ দিন',
                ],
            ],
            'R01.insufficient' => [
                'title' => 'বিক্রি কমার তুলনার জন্য যথেষ্ট অর্ডার নেই ({website})',
                'summary' => 'আগের ৭ দিনে {prev_orders}টি এবং চলতি ৭ দিনে {cur_orders}টি অর্ডার পাওয়া গেছে (ন্যূনতম ২০টি প্রয়োজন)। পর্যাপ্ত অর্ডার না হওয়া পর্যন্ত সতর্কতা দেওয়া হচ্ছে না।',
                'hypotheses' => [
                    'সাম্প্রতিক ১৪ দিনের সব অর্ডার এখনো সিঙ্ক বা ইমপোর্ট না হয়ে থাকতে পারে।',
                ],
                'missing_data' => [
                    'আগের ৭ দিনের বেসলাইনে ন্যূনতম ২০টি অর্ডার।',
                ],
                'action' => 'কানেক্টর সিঙ্ক বা অর্ডার ফাইল ইমপোর্ট সম্পূর্ণ ১৪ দিন কভার করছে কি না যাচাই করুন।',
                'experiment' => 'সাপ্তাহিক তুলনার আগে আরও ২ সপ্তাহ অর্ডার ডেটা সংগ্রহ করুন।',
                'success' => [
                    'metric' => 'সাপ্তাহিক বেসলাইন অর্ডার সংখ্যা',
                    'baseline' => '{prev_orders}টি অর্ডার',
                    'target' => '>= ২০টি অর্ডার/সপ্তাহ',
                    'duration' => '১৪ দিন',
                ],
            ],
            'R02.trigger' => [
                'title' => 'অর্ডার বাতিলের হার {base}% থেকে বেড়ে {now}% হয়েছে ({website})',
                'summary' => 'বাতিলের হার আগের ২৮ দিনের বেসলাইন {base}% ({base_n}টি অর্ডার) থেকে বেড়ে গত {days} দিনে {now}% ({n}টি অর্ডার) হয়েছে (+{rise_pp} শতাংশ-পয়েন্ট, p={p_value})। মোট {n_c}টি অর্ডার বাতিল হয়েছে।',
                'hypotheses' => [
                    'কনফার্মেশন কল দেরিতে যাওয়ায় ক্যাশ-অন-ডেলিভারি ক্রেতারা আগ্রহ হারিয়ে থাকতে পারেন।',
                    'ভুয়া বা ডুপ্লিকেট অর্ডারের সংখ্যা সাম্প্রতিক ক্যাম্পেইনে বেড়ে থাকতে পারে।',
                    'ডেলিভারি চার্জ বা সময় নিয়ে ক্রেতার কাছে অস্পষ্টতা থাকায় অর্ডার বাতিল হয়ে থাকতে পারে।',
                    'পণ্যের স্টক না থাকায় স্টাফরা অর্ডার বাতিল করতে বাধ্য হয়ে থাকতে পারেন।',
                ],
                'missing_data' => [
                    'বাতিল হওয়া প্রতিটি অর্ডারের কারণ (cancel_reason {missing_reason_pct}% অনুপস্থিত)।',
                    'অ্যাসাইন করা অর্ডারের প্রথম কল/কনফার্মেশনের সময়।',
                ],
                'action' => 'বাতিল হওয়া {n_c}টি অর্ডারের কারণ পর্যালোচনা করুন এবং নতুন সিওডি অর্ডারে ২ ঘণ্টার মধ্যে কনফার্মেশন কল পরীক্ষা করুন।',
                'experiment' => 'নতুন সিওডি অর্ডারের অর্ধেকে ২ ঘণ্টার মধ্যে কনফার্মেশন কল SLA চালু করে বর্তমান সময়ের সাথে তুলনা করুন।',
                'success' => [
                    'metric' => 'অর্ডার বাতিলের হার (%)',
                    'baseline' => '{now}%',
                    'target' => '<= {target}%',
                    'duration' => '১৪ দিন',
                ],
            ],
            'R02.insufficient' => [
                'title' => 'বাতিলের হার বৃদ্ধির পরীক্ষার জন্য পর্যাপ্ত অর্ডার নেই ({website})',
                'summary' => 'গত {days} দিনে অর্ডার সংখ্যা {n}টি (বেসলাইন {base_n}টি), যা নির্ভরযোগ্য পরিসংখ্যানিক তুলনার ন্যূনতম মান (২০/৩০) থেকে কম।',
                'hypotheses' => [
                    'কম নমুনার কারণে স্বাভাবিক ওঠানামা থেকে প্রকৃত বৃদ্ধি আলাদা করা যাচ্ছে না।',
                ],
                'missing_data' => [
                    '৭ দিনের উইন্ডোতে ন্যূনতম ৩০টি এবং বেসলাইনে ২০টি অর্ডার।',
                ],
                'action' => 'অর্ডার সিঙ্ক চালু রাখুন এবং ৩০টি অর্ডার পূর্ণ হলে পুনরায় স্ক্যান করুন।',
                'experiment' => 'পর্যাপ্ত অর্ডার জমা হওয়া পর্যন্ত বাতিলের হার পর্যবেক্ষণে রাখুন।',
                'success' => [
                    'metric' => '৭ দিনে মূল্যায়িত অর্ডার',
                    'baseline' => '{n}টি',
                    'target' => '>= ৩০টি অর্ডার',
                    'duration' => '১৪ দিন',
                ],
            ],
            'R03.trigger' => [
                'title' => 'ডেলিভারি রিটার্ন হার {base}% থেকে বেড়ে {now}% হয়েছে ({website})',
                'summary' => 'সম্পন্ন ডেলিভারিতে রিটার্ন হার আগের ২৮ দিনের {base}% ({base_n}টি সম্পন্ন) থেকে বেড়ে গত {days} দিনে {now}% ({n}টি সম্পন্ন; +{rise_pp} শতাংশ-পয়েন্ট, p={p_value}) হয়েছে। {n_r}টি অর্ডার রিটার্ন হয়েছে।',
                'hypotheses' => [
                    'পণ্যের ছবি/বিবরণের সাথে বাস্তব পণ্যের অমিল থাকায় ডেলিভারির সময় ক্রেতা ফেরত দিয়ে থাকতে পারেন।',
                    'কুরিয়ার ডেলিভারিতে দেরি বা রাইডার কল মিস হওয়ায় ক্রেতার কাছে পার্সেল পৌঁছায়নি।',
                    'অ্যাড অডিয়েন্স বা ছাড়ের অফারের ক্রেতাদের কেনার আগ্রহ তুলনামূলক কম ছিল।',
                    'পরিবহনকালে প্যাকেজিং ক্ষতিগ্রস্ত হওয়ায় ক্রেতা পার্সেল গ্রহণ করেননি।',
                ],
                'missing_data' => [
                    'কুরিয়ার CSV থেকে পাওয়া সুনির্দিষ্ট রিটার্ন কারণ কোড।',
                    'কুরিয়ার ডিসপ্যাচ ও রিটার্নের exact তারিখ (shipped_at, returned_at)।',
                ],
                'action' => 'কুরিয়ার ও লস পেজে {n_r}টি রিটার্ন অর্ডার পণ্য ও এলাকা অনুযায়ী দেখুন এবং ১০ জন রিটার্ন ক্রেতার সাথে কথা বলে কারণ জানুন।',
                'experiment' => 'বেশি রিটার্ন হওয়া এলাকায় ডিসপ্যাচের আগে হোয়াটসঅ্যাপ/এসএমএস রিমাইন্ডার চালু করে ২১ দিন ফলাফল মাপুন।',
                'success' => [
                    'metric' => 'ডেলিভারি রিটার্ন হার (%)',
                    'baseline' => '{now}%',
                    'target' => '<= {target}%',
                    'duration' => '২১ দিন',
                ],
            ],
            'R03.insufficient' => [
                'title' => 'রিটার্ন হার বৃদ্ধির তুলনার জন্য পর্যাপ্ত সম্পন্ন ডেলিভারি নেই ({website})',
                'summary' => 'গত {days} দিনে ডেলিভারি+রিটার্ন মিলিয়ে {n}টি সম্পন্ন অর্ডার পাওয়া গেছে (বেসলাইন {base_n}টি), যা ন্যূনতম ২০টি নমুনার কম।',
                'hypotheses' => [
                    'সাম্প্রতিক পার্সেলগুলো এখনো পথে (In Transit) থাকায় চূড়ান্ত স্ট্যাটাস আপডেট হয়নি।',
                ],
                'missing_data' => [
                    'চূড়ান্ত ডেলিভারি/রিটার্ন স্ট্যাটাসসহ আপডেটেড কুরিয়ার CSV রিপোর্ট।',
                ],
                'action' => 'চলতি শিপমেন্টগুলো নিষ্পত্তি হলে সর্বশেষ কুরিয়ার রিপোর্ট CSV ইমপোর্ট করুন।',
                'experiment' => '৭ দিনে অন্তত ৩০টি সম্পন্ন ডেলিভারি হলে পুনরায় স্ক্যান করুন।',
                'success' => [
                    'metric' => '৭ দিনে সম্পন্ন ডেলিভারি',
                    'baseline' => '{n}টি',
                    'target' => '>= ৩০টি',
                    'duration' => '১৪ দিন',
                ],
            ],
            'R04.trigger' => [
                'title' => '"{courier}" কুরিয়ারের রিটার্ন হার পরিসংখ্যানিকভাবে ব্যতিক্রমী ({rate}% বনাম {other_rate}%)',
                'summary' => '{website}-এ গত ৩০ দিনে "{courier}" কুরিয়ারে {n}টি সম্পন্ন শিপমেন্টের মধ্যে {returned}টি রিটার্ন হয়েছে ({rate}%), যা অন্য কুরিয়ারগুলোর গড় {other_rate}% থেকে উল্লেখযোগ্যভাবে ভিন্ন (p={p_value}, z={z_score})।',
                'hypotheses' => [
                    'নির্দিষ্ট এলাকায় "{courier}"-এর ডেলিভারি হাব বা রাইডার কল প্রচেষ্টায় ঘাটতি থাকতে পারে।',
                    'দূরবর্তী বা ঝুঁকিপূর্ণ জেলা/এলাকার পার্সেল বেশি "{courier}"-এ পাঠানো হয়ে থাকতে পারে।',
                    'পিকআপ দেরি হওয়ায় ক্রেতার কাছে পৌঁছাতে সময় বেশি লেগেছে।',
                ],
                'missing_data' => [
                    'কুরিয়ার CSV থেকে এলাকা-ভিত্তিক ডেলিভারি সময় ও কল ব্যর্থতার রেকর্ড।',
                ],
                'action' => 'শিপমেন্ট সরানোর আগে কুরিয়ার ও লস পেজে "{courier}"-এর সিটি বনাম আউটসাইড-সিটি ফলাফল তুলনা করুন।',
                'experiment' => 'একটি নির্দিষ্ট এলাকার ৫০% পার্সেল ১৪ দিনের জন্য বিকল্প কুরিয়ারে পাঠিয়ে রিটার্ন হার তুলনা করুন।',
                'success' => [
                    'metric' => 'পরীক্ষামূলক রুটের রিটার্ন হার (%)',
                    'baseline' => '{rate}%',
                    'target' => '<= {target}%',
                    'duration' => '১৪ দিন',
                ],
            ],
            'R04.insufficient' => [
                'title' => 'কুরিয়ার তুলনার জন্য পর্যাপ্ত শিপমেন্ট নমুনা নেই ({website})',
                'summary' => 'সাম্প্রতিক কুরিয়ার শিপমেন্টে সম্পন্ন অর্ডার মাত্র {n}টি (ন্যূনতম ২০টি প্রয়োজন), তাই কুরিয়ার ব্যতিক্রম নির্ণয় সম্ভব নয়।',
                'hypotheses' => [
                    'প্রতি কুরিয়ারে ন্যূনতম ২০/৩০টি সম্পন্ন ডেলিভারি এখনো জমা হয়নি।',
                ],
                'missing_data' => [
                    'প্রতি কুরিয়ারে >= ৩০টি সম্পন্ন শিপমেন্টসহ কুরিয়ার CSV রিপোর্ট।',
                ],
                'action' => 'মাসিক কুরিয়ার সেটেলমেন্ট CSV ইমপোর্ট করুন এবং শিপমেন্ট ৩০+ হলে পুনরায় দেখুন।',
                'experiment' => 'ন্যূনতম ৩০টি শিপমেন্ট পূর্ণ হওয়া পর্যন্ত পর্যবেক্ষণে রাখুন।',
                'success' => [
                    'metric' => 'কুরিয়ার প্রতি সম্পন্ন শিপমেন্ট',
                    'baseline' => '{n}টি',
                    'target' => '>= ৩০টি',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R04.missing_courier' => [
                'title' => 'অনুপস্থিত তথ্য: ডিসপ্যাচকৃত অর্ডারে কুরিয়ারের নাম নেই ({website})',
                'summary' => 'Missing data: courier — গত ৩০ দিনে {website}-এ {eligible}টি শিপড/ডেলিভার্ড/রিটার্ন অর্ডার আছে, কিন্তু কোনোটিতেই কুরিয়ারের নাম পাওয়া যায়নি।',
                'hypotheses' => [
                    'কুরিয়ার রিপোর্ট CSV এখনো ইমপোর্ট করা হয়নি বা অর্ডার ইমপোর্টে কুরিয়ার কলাম ম্যাপ করা হয়নি।',
                ],
                'missing_data' => [
                    'কুরিয়ার রিপোর্ট CSV ইমপোর্ট অথবা অর্ডারে কুরিয়ার কলাম সংযোগ।',
                ],
                'action' => 'কুরিয়ার ও লস -> কুরিয়ার CSV ইমপোর্ট মেনু থেকে ট্র্যাকিং বা অর্ডার রেফারেন্স ম্যাচ করুন।',
                'experiment' => 'গত ৩০ দিনের কুরিয়ার CSV আপলোড করে পুনরায় আরঅ্যান্ডডি স্ক্যান চালান।',
                'success' => [
                    'metric' => 'কুরিয়ার তথ্যযুক্ত অর্ডারের হার (%)',
                    'baseline' => '0%',
                    'target' => '>= ৯০%',
                    'duration' => '৭ দিন',
                ],
            ],
            'R05.trigger' => [
                'title' => '{employee}-এর অ্যাসাইন করা অর্ডারে বাতিলের হার বেশি ({rate}% বনাম {other_rate}%)',
                'summary' => 'সতর্কতা: কাজের চাপ ও ক্রেতার ধরন ভিন্ন হতে পারে — এটি ব্যক্তিগত দোষারোপ নয়। {website}-এ গত ৩০ দিনে {employee}-কে অ্যাসাইন করা {n}টি অর্ডারের মধ্যে {cancelled}টি বাতিল হয়েছে ({rate}%), যা দলের বাকি সদস্যদের গড় {other_rate}% থেকে ভিন্ন (p={p_value})।',
                'hypotheses' => [
                    'শিফটের সময় (যেমন রাতের লিড বা ক্যাম্পেইন-বার্স্ট অর্ডার) অনুযায়ী ঝুঁকিপূর্ণ অর্ডার বেশি পড়ে থাকতে পারে — কাজের চাপ ও ক্রেতার ধরন ভিন্ন হতে পারে।',
                    'কনফার্মেশন কথোপকথন বা ডুপ্লিকেট যাচাই পদ্ধতিতে ভিন্নতা থাকতে পারে।',
                    'কলব্যাক করতে দেরি হওয়ায় ক্রেতার সম্মতি কমে থাকতে পারে।',
                ],
                'missing_data' => [
                    'প্রতিটি অর্ডারে কলের সংখ্যা, কলের সময় এবং লিড উৎসের বিবরণ।',
                ],
                'action' => '{employee}-এর সাথে বসে বাতিল হওয়া ১৫টি অর্ডার পর্যালোচনা করুন (মনে রাখুন: কাজের চাপ ও ক্রেতার ধরন ভিন্ন হতে পারে)।',
                'experiment' => '১৪ দিন রাতের বা ঝুঁকিপূর্ণ লিডগুলোতে অভিন্ন ২-ধাপের কল+এসএমএস কনফার্মেশন স্ক্রিপ্ট ব্যবহার করুন।',
                'success' => [
                    'metric' => 'অ্যাসাইনকৃত অর্ডারে বাতিলের হার (%)',
                    'baseline' => '{rate}%',
                    'target' => '<= {target}%',
                    'duration' => '১৪ দিন',
                ],
            ],
            'R05.insufficient' => [
                'title' => 'স্টাফ বাতিলের হার তুলনার জন্য পর্যাপ্ত অ্যাসাইনকৃত অর্ডার নেই ({website})',
                'summary' => 'কর্মীপ্রতি অ্যাসাইন করা অর্ডারের সংখ্যা ন্যূনতম মানের নিচে ({n}টি অর্ডার < ২০; কর্মী সংখ্যা={emp_count})। কাজের চাপ ও ক্রেতার ধরন ভিন্ন হতে পারে।',
                'hypotheses' => [
                    'অর্ডার ইমপোর্টের সময় অ্যাসাইন করা কর্মীর নাম ম্যাপ করা হয়নি বা অর্ডার সংখ্যা কম।',
                ],
                'missing_data' => [
                    'প্রতি কর্মীর জন্য অন্তত ৩০টি অ্যাসাইনকৃত অর্ডার।',
                ],
                'action' => 'অর্ডার ইমপোর্ট বা কানেক্টরে employee কলাম যুক্ত আছে কি না দেখুন।',
                'experiment' => 'কর্মীপ্রতি ৩০+ অর্ডার অ্যাসাইন হওয়া পর্যন্ত অপেক্ষা করুন।',
                'success' => [
                    'metric' => 'কর্মী-অ্যাসাইনকৃত অর্ডার',
                    'baseline' => '{n}টি',
                    'target' => '>= ৩০টি/কর্মী',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R06.trigger' => [
                'title' => 'শীর্ষ বিক্রিত পণ্য "{product}"-এর স্টক ৭ দিনের নিচে ({cover_days} দিন বাকি)',
                'summary' => '৩০ দিনের আয়ের শীর্ষ ২০%-এ থাকা পণ্য "{product}" (বিক্রি {currency} {rev_30d}, {units_30d} পিস)-এর বর্তমান স্টক {stock}টি — চলমান গতিতে প্রায় {cover_days} দিন চলবে।',
                'hypotheses' => [
                    'সাম্প্রতিক বিক্রির গতির তুলনায় রিস্টক লিড-টাইম কম হিসাব করা হয়ে থাকতে পারে।',
                    'গুদামের বাস্তব স্টকের সাথে ওয়েবসাইট বা শীটের স্টক সংখ্যার অমিল থাকতে পারে।',
                ],
                'missing_data' => [
                    'সাপ্লায়ার ডেলিভারি লিড-টাইম (দিন) এবং আসন্ন ক্রয় আদেশের (PO) পরিমাণ।',
                ],
                'action' => 'আজই "{product}"-এর গুদাম স্টক যাচাই করুন এবং রিস্টক করতে {cover_days} দিনের বেশি সময় লাগলে নতুন অর্ডার শুরু করুন।',
                'experiment' => 'রিস্টক করতে ৭ দিনের বেশি লাগলে বিজ্ঞাপন বাজেট কিছুটা কমিয়ে বা প্রি-অর্ডার বার্তা দেখিয়ে পরীক্ষা করুন।',
                'success' => [
                    'metric' => 'স্টক কভার (দিন)',
                    'baseline' => '{cover_days} দিন',
                    'target' => '>= ১৪ দিন',
                    'duration' => '১৪ দিন',
                ],
            ],
            'R06.insufficient' => [
                'title' => 'শীর্ষ পণ্যের স্টক কভার মূল্যায়নের জন্য পর্যাপ্ত তথ্য নেই ({website})',
                'summary' => '৩০ দিনে শীর্ষ পণ্যের বিক্রি নমুনা ২০-এর কম ({n}) অথবা পণ্যের বর্তমান স্টক সংখ্যা সেট করা নেই।',
                'hypotheses' => [
                    'পণ্য তালিকায় বর্তমান স্টক সংখ্যা বা অর্ডারের সাথে SKU সংযোগ অনুপস্থিত থাকতে পারে।',
                ],
                'missing_data' => [
                    'পণ্য ক্যাটালগে বর্তমান স্টক এবং অর্ডার লাইন-আইটেমে SKU মিল।',
                ],
                'action' => 'Products পেজে পণ্যের স্টক আপডেট করুন এবং অর্ডারে SKU ম্যাচিং চালু রাখুন।',
                'experiment' => 'সক্রিয় বিক্রির পর ৩০ দিনের তথ্য জমা হলে পুনরায় স্ক্যান করুন।',
                'success' => [
                    'metric' => 'স্টক ও বিক্রি-সংযুক্ত পণ্য নমুনা',
                    'baseline' => '{n}',
                    'target' => '>= ২০ ইউনিট/অর্ডার',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R07.trigger' => [
                'title' => 'ডেড-স্টক সতর্কতা: ৬০ দিনে বিক্রি না হওয়া {sku_count}টি পণ্য ({currency} {dead_value})',
                'summary' => '{website}-এ {sku_count}টি সক্রিয় পণ্যের স্টক > ০ থাকা সত্ত্বেও গত {dead_days} দিনে কোনো অর্ডার হয়নি, যাতে {currency} {dead_value} মূল্যের পুঁজি আটকে আছে। শীর্ষ পণ্য: "{top_product}" ({top_stock} পিস = {currency} {top_value})।',
                'hypotheses' => [
                    'প্রতিযোগিতামূলক বাজারের তুলনায় দাম বা উপস্থাপনা আকর্ষণীয় না-ও হতে পারে।',
                    'এই পণ্যগুলোতে পর্যাপ্ত বিজ্ঞাপন বা ক্রস-সেল ভিজিবিলিটি নেই।',
                    'গুদামে থাকা স্টক নষ্ট বা অচল হলেও ইনভেন্টরি থেকে বাদ দেওয়া হয়নি।',
                ],
                'missing_data' => [
                    'যেখানে বিক্রয়মূল্য ব্যবহার হয়েছে, সেসব SKU-এর প্রকৃত ক্রয়মূল্য (cost)।',
                    'পণ্য পেজের ভিজিট ও ক্লিকের তথ্য।',
                ],
                'action' => '"{top_product}"-সহ শীর্ষ ডেড-স্টক পণ্যগুলোর বাস্তব অবস্থা যাচাই করুন এবং চালু পণ্যের সাথে বান্ডেল অফারের কথা ভাবুন।',
                'experiment' => '১৪ দিনের জন্য "{top_product}"-কে শীর্ষ পণ্যের সাথে বান্ডেল অফার বনাম আলাদা মূল্যছাড়ে পরীক্ষা করুন।',
                'success' => [
                    'metric' => 'ডেড-স্টক ছাড়কৃত মূল্য ({currency})',
                    'baseline' => '৬০ দিনে ০ বিক্রি',
                    'target' => '>= আটকে থাকা স্টকের ২৫%',
                    'duration' => '২১ দিন',
                ],
            ],
            'R07.insufficient' => [
                'title' => 'ডেড-স্টক যাচাইয়ের জন্য পর্যাপ্ত ৬০ দিনের অর্ডার ইতিহাস নেই ({website})',
                'summary' => 'গত ৬০ দিনে ওয়েবসাইটে {n}টি অর্ডার পাওয়া গেছে (ন্যূনতম ২০টি প্রয়োজন), তাই বিক্রিহীন পণ্যকে এখনো ডেড-স্টক বলা হচ্ছে না।',
                'hypotheses' => [
                    'নতুন স্টোর হওয়ায় এখনো ৬০ দিনের অর্ডার ইতিহাস জমা হয়নি।',
                ],
                'missing_data' => [
                    'কমপক্ষে ৬০ দিনের অর্ডার ইতিহাস (>= ২০টি অর্ডার)।',
                ],
                'action' => 'সঠিকভাবে ডেড-স্টক বের করতে গত ৬০ দিনের পুরনো অর্ডার ফাইল ইমপোর্ট করুন।',
                'experiment' => '৬০ দিনের ধারাবাহিক ডেটা জমা হওয়া পর্যন্ত অপেক্ষা করুন।',
                'success' => [
                    'metric' => '৬০ দিনের অর্ডার সংখ্যা',
                    'baseline' => '{n}টি',
                    'target' => '>= ২০টি অর্ডার',
                    'duration' => '৬০ দিন',
                ],
            ],
            'R07.missing_value' => [
                'title' => 'ডেড-স্টক পণ্যগুলোর ক্রয় বা বিক্রয়মূল্য নেই ({website})',
                'summary' => '{missing_count}টি পণ্যের স্টক > ০ এবং {dead_days} দিনে বিক্রি নেই, কিন্তু মজুদ মূল্য হিসাবের জন্য cost বা price কোনোটিই দেওয়া নেই।',
                'hypotheses' => [
                    'পণ্য ইমপোর্টের সময় দাম বা ক্রয়মূল্যের কলাম যোগ করা হয়নি।',
                ],
                'missing_data' => [
                    'সক্রিয় SKU-এর ক্রয়মূল্য (cost) বা বিক্রয়মূল্য (price)।',
                ],
                'action' => 'আটকে থাকা পুঁজির তালিকা দেখতে Products পেজে পণ্যের মূল্য যোগ করুন।',
                'experiment' => 'বেশি স্টকের পণ্যগুলোর মূল্য আপডেট করে পুনরায় স্ক্যান করুন।',
                'success' => [
                    'metric' => 'মূল্যযুক্ত SKU-এর হার (%)',
                    'baseline' => '0%',
                    'target' => '১০০%',
                    'duration' => '৭ দিন',
                ],
            ],
            'R08.trigger' => [
                'title' => 'পণ্যের ওপর অতিরিক্ত নির্ভরতা (শীর্ষ পণ্যে {top1_pct}%, শীর্ষ ৩টিতে {top3_pct}%)',
                'summary' => '{website}-এ গত ৩০ দিনে কেবল "{top1_name}" থেকেই এসেছে মোট গ্রস বিক্রির {top1_pct}% ({currency} {top1_rev}), এবং শীর্ষ ৩টি পণ্যে এসেছে {top3_pct}% ({currency} {top3_rev}) (সীমা: ৪০% / ৭০%)।',
                'hypotheses' => [
                    'বিজ্ঞাপনের প্রায় পুরো বাজেট একটি মাত্র পণ্যে কেন্দ্রীভূত থাকতে পারে।',
                    'অন্যান্য পণ্যে পর্যাপ্ত ক্রস-সেল, বান্ডেল বা সামাজিক প্রমাণ (রিভিউ) কম থাকতে পারে।',
                ],
                'missing_data' => [
                    'পণ্যভিত্তিক বিজ্ঞাপন খরচ ও কনভার্সন বণ্টন।',
                    'বিকল্প পণ্যগুলোর সম্ভাব্য মার্জিন তথ্য।',
                ],
                'action' => '"{top1_name}"-এর স্টক নিরাপদ রাখুন এবং পরবর্তী ৩-৫টি সম্ভাবনাময় পণ্যের রিটার্ন ও মার্জিন যাচাই করুন।',
                'experiment' => '২১ দিনের জন্য অর্ডার কনফার্মেশন কল বা চেকআউটে ২য়/৩য় পণ্যের অ্যাড-অন অফার পরীক্ষা করুন।',
                'success' => [
                    'metric' => 'শীর্ষ ১ পণ্যের বাইরে বিক্রির অংশ (%)',
                    'baseline' => '{rest_pct}%',
                    'target' => '>= {target_rest_pct}%',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R08.insufficient' => [
                'title' => 'পণ্য কেন্দ্রীভূত ঝুঁকি মাপার জন্য পর্যাপ্ত অর্ডার নেই ({website})',
                'summary' => 'গত ৩০ দিনে বাতিল ছাড়া মাত্র {n}টি অর্ডার রয়েছে (ন্যূনতম ২০টি প্রয়োজন), তাই নির্ভরতার শতকরা হার কেবল প্রাথমিক তথ্য হিসেবে দেখানো হচ্ছে।',
                'hypotheses' => [
                    'কম অর্ডার থাকায় স্বাভাবিকভাবেই ১-২টি পণ্যে বিক্রি বেশি দেখায়।',
                ],
                'missing_data' => [
                    'লাইন-আইটেমসহ অন্তত ২০টি অ-বাতিল অর্ডার।',
                ],
                'action' => 'মাসিক অর্ডার সংখ্যা ২০+ হওয়া পর্যন্ত ডেটা সংগ্রহ চালিয়ে যান।',
                'experiment' => 'পর্যাপ্ত অর্ডার হলে পুনরায় স্ক্যান করুন।',
                'success' => [
                    'metric' => '৩০ দিনের অ-বাতিল অর্ডার',
                    'baseline' => '{n}টি',
                    'target' => '>= ২০টি অর্ডার',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R08.missing_items' => [
                'title' => 'অনুপস্থিত তথ্য: অর্ডারে পণ্যের লাইন-আইটেম নেই ({website})',
                'summary' => 'গত ৩০ দিনে {website}-এ {n}টি অর্ডার আছে, কিন্তু পণ্যের বিস্তারিত (order_items) না থাকায় শীর্ষ পণ্য নির্ভরতা বা স্টক ঝুঁকি হিসাব করা যাচ্ছে না।',
                'hypotheses' => [
                    'হোল-অর্ডার CSV ইমপোর্টে পণ্যের নাম/SKU কলাম ছিল না।',
                ],
                'missing_data' => [
                    'সাম্প্রতিক অর্ডারগুলোর লাইন-আইটেম (product_name / SKU / quantity)।',
                ],
                'action' => 'পরবর্তী CSV/XLSX ইমপোর্টে পণ্যের নাম, SKU ও সংখ্যা ম্যাপ করে আপলোড করুন।',
                'experiment' => 'পণ্য লাইন-আইটেমসহ সাম্প্রতিক অর্ডার পুনরায় ইমপোর্ট করুন।',
                'success' => [
                    'metric' => 'লাইন-আইটেমযুক্ত অর্ডার (%)',
                    'baseline' => '0%',
                    'target' => '>= ৯০%',
                    'duration' => '৭ দিন',
                ],
            ],
            'R09.trigger' => [
                'title' => 'আনুমানিক কন্ট্রিবিউশন মার্জিন {drop_pp} পয়েন্ট কমেছে ({prev_margin}% থেকে {cur_margin}%)',
                'summary' => '{website}-এ {cur_cov}% ক্রয়মূল্য কভারেজসহ ডেলিভার্ড পণ্যের আনুমানিক কন্ট্রিবিউশন মার্জিন আগের ৩০ দিনের {prev_margin}% থেকে কমে চলতি ৩০ দিনে {cur_margin}% হয়েছে (-{drop_pp} pp; বিজ্ঞাপন, ডেলিভারি ও রিটার্ন খরচের আগে)।',
                'hypotheses' => [
                    'বেশি বিক্রি হওয়া পণ্যের সাপ্লায়ার বা উৎপাদন খরচ বেড়ে থাকতে পারে।',
                    'সাম্প্রতিক ছাড় বা কম মার্জিনের পণ্যের বিক্রি বেশি হয়ে থাকতে পারে।',
                    'সাম্প্রতিক পণ্যের ক্রয়মূল্য (unit_cost) আপডেটে খরচ বেশি ধরা হয়ে থাকতে পারে।',
                ],
                'missing_data' => [
                    'বাকি {uncovered_pct}% ডেলিভার্ড পণ্যের unit_cost তথ্য।',
                    'বিজ্ঞাপন খরচ ও কুরিয়ার রিটার্ন চার্জ (আলাদাভাবে হিসাব করা হয়)।',
                ],
                'action' => 'দাম বাড়ানোর আগে শীর্ষ ১০টি ডেলিভার্ড পণ্যের unit_cost পরিবর্তন ও ডিসকাউন্ট হিসাব পরীক্ষা করুন।',
                'experiment' => 'কম মার্জিনের জনপ্রিয় একটি পণ্যে ডিসকাউন্ট কমিয়ে বা দাম সমন্বয় করে ৩০ দিন বিক্রির পরিমাণ পর্যবেক্ষণ করুন।',
                'success' => [
                    'metric' => 'আনুমানিক কন্ট্রিবিউশন মার্জিন (%)',
                    'baseline' => '{cur_margin}%',
                    'target' => '>= {target}%',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R09.insufficient' => [
                'title' => 'মার্জিন প্রবণতা তুলনার জন্য পর্যাপ্ত ডেলিভার্ড অর্ডার নেই ({website})',
                'summary' => 'চলতি ৩০ দিন ({cur_delivered}টি) বা আগের ৩০ দিনে ({prev_delivered}টি) ডেলিভার্ড অর্ডার ২০-এর কম হওয়ায় মার্জিন প্রবণতা নির্ণয় নিরাপদ নয়।',
                'hypotheses' => [
                    'দুটি সময়ের কোনো একটিতে ডেলিভারি সংখ্যা ২০টির কম রয়েছে।',
                ],
                'missing_data' => [
                    'বর্তমান ও পূর্ববর্তী উভয় ৩০ দিনের উইন্ডোতে >= ২০টি ডেলিভার্ড অর্ডার।',
                ],
                'action' => 'ক্রয়মূল্যসহ পর্যাপ্ত ডেলিভার্ড অর্ডার জমা হলে পুনরায় স্ক্যান করুন।',
                'experiment' => 'নমুনা ২০+ পূর্ণ না হওয়া পর্যন্ত মার্জিন পর্যবেক্ষণে রাখুন।',
                'success' => [
                    'metric' => '৩০ দিনে ডেলিভার্ড অর্ডার',
                    'baseline' => '{n}টি',
                    'target' => '>= ২০টি',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R09.low_coverage' => [
                'title' => 'মার্জিন হিসাবের জন্য ক্রয়মূল্য (Cost) তথ্য অপর্যাপ্ত ({cur_cov}% / {prev_cov}%)',
                'summary' => 'ডেলিভার্ড পণ্যের ক্রয়মূল্য কভারেজ ৬০%-এর নিচে থাকায় (চলতি: {cur_cov}%, আগের ৩০ দিন: {prev_cov}%) মার্জিন পরিবর্তন নির্ণয় বন্ধ রাখা হয়েছে।',
                'hypotheses' => [
                    'পণ্য বা অর্ডার ইমপোর্টে unit_cost কলাম বাদ পড়েছে।',
                ],
                'missing_data' => [
                    'কমপক্ষে ৬০% ডেলিভার্ড পণ্যের আয়ের ওপর unit_cost তথ্য।',
                ],
                'action' => 'Products পেজে মূল পণ্যগুলোর unit_cost পূরণ করুন যাতে কভারেজ >= ৬০% হয়।',
                'experiment' => 'শীর্ষ ২০টি পণ্যের ক্রয়মূল্য বসিয়ে পুনরায় স্ক্যান চালান।',
                'success' => [
                    'metric' => 'ক্রয়মূল্য কভারেজ (%)',
                    'baseline' => '{cur_cov}%',
                    'target' => '>= ৬০%',
                    'duration' => '৭ দিন',
                ],
            ],
            'R10.trigger' => [
                'title' => 'ডিসকাউন্ট অর্ডারে রিটার্ন হার বেশি ({disc_ret}% বনাম {undisc_ret}%)',
                'summary' => '{website}-এ গত ৩০ দিনে ডিসকাউন্টযুক্ত অর্ডারে ({disc_n}টি সম্পন্ন) রিটার্ন হার {disc_ret}% (গড় অর্ডার মূল্য {currency} {disc_aov}) বনাম পূর্ণমূল্যের অর্ডারে {undisc_ret}% ({undisc_n}টি সম্পন্ন, গড় মূল্য {currency} {undisc_aov}; p={p_value})।',
                'hypotheses' => [
                    'বেশি ছাড়ের অফারে তাৎক্ষণিক ঝোঁকে অর্ডার দেওয়া সিওডি ক্রেতারা ডেলিভারিতে ফিরিয়ে দিয়ে থাকতে পারেন।',
                    'প্রোমো ক্যাম্পেইনের প্রতিশ্রুতি ও মূল পণ্যের বিবরণে প্রত্যাশার পার্থক্য থাকতে পারে।',
                    'একই ব্যক্তির একাধিক কুপন অর্ডার রিটার্ন বাড়িয়ে থাকতে পারে।',
                ],
                'missing_data' => [
                    'ডিসকাউন্ট কোড ও সংশ্লিষ্ট অ্যাড ক্রিয়েটিভের তথ্য।',
                ],
                'action' => 'কোন ডিসকাউন্ট স্তর বা কুপন ক্যাম্পেইন থেকে বেশি রিটার্ন আসছে তা আলাদা করে যাচাই করুন।',
                'experiment' => 'বড় সিওডি ডিসকাউন্টের বদলে পরবর্তী ক্যাম্পেইনে অগ্রিম পেমেন্টে ছোট ছাড় বা বাড়তি কনফার্মেশন কল পরীক্ষা করুন।',
                'success' => [
                    'metric' => 'ডিসকাউন্ট অর্ডারের রিটার্ন হার (%)',
                    'baseline' => '{disc_ret}%',
                    'target' => '<= {target}%',
                    'duration' => '২১ দিন',
                ],
            ],
            'R10.insufficient' => [
                'title' => 'ডিসকাউন্ট বনাম সাধারণ অর্ডার তুলনার জন্য পর্যাপ্ত নমুনা নেই ({website})',
                'summary' => 'ডিসকাউন্টযুক্ত ({disc_n}টি) অথবা সাধারণ ({undisc_n}টি) সম্পন্ন অর্ডারের সংখ্যা ২০-এর নিচে থাকায় পরিসংখ্যানিক পরীক্ষা চালানো হয়নি।',
                'hypotheses' => [
                    'সাম্প্রতিক ছাড়ের অর্ডারগুলো এখনো ডেলিভারি/রিটার্ন পর্যায়ে নিষ্পত্তি হয়নি।',
                ],
                'missing_data' => [
                    'উভয় গ্রুপেই অন্তত ২০টি সম্পন্ন অর্ডার (ডেলিভার্ড + রিটার্নড)।',
                ],
                'action' => 'ডিসকাউন্ট অর্ডারগুলো ২০+ সম্পন্ন হলে পুনরায় স্ক্যান করুন।',
                'experiment' => 'প্রোমো অর্ডার নিষ্পত্তি হওয়া পর্যন্ত অপেক্ষা করুন।',
                'success' => [
                    'metric' => 'নিষ্পত্তিকৃত ডিসকাউন্ট অর্ডার',
                    'baseline' => '{disc_n}টি',
                    'target' => '>= ২০টি অর্ডার',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R11.trigger' => [
                'title' => 'রিপিট ক্রেতার হার {drop_pp} পয়েন্ট কমেছে ({prev_rate}% থেকে {cur_rate}%)',
                'summary' => '{website}-এ একাধিকবার কেনাকাটা করা ক্রেতার হার আগের ৬০ দিনের {prev_rate}% ({prev_buyers} জন ক্রেতা) থেকে কমে গত ৬০ দিনে {cur_rate}% ({cur_buyers} জন ক্রেতা) হয়েছে (-{drop_pp} pp, p={p_value})।',
                'hypotheses' => [
                    'নতুন প্রোমোশনাল ক্যাম্পেইনে এককালীন ক্রেতার অনুপাত বেশি বেড়ে থাকতে পারে।',
                    'ডেলিভারির পর ফলো-আপ, রিমাইন্ডার বা প্যাকেজিং কুপন কম কাজ করতে পারে।',
                    'পুনরায় কেনা হয় এমন মূল পণ্যের স্টক শেষ বা মানের ভিন্নতা হয়ে থাকতে পারে।',
                ],
                'missing_data' => [
                    '{missing_cust_pct}% অর্ডারে ক্রেতার হ্যাশ করা যোগাযোগ কী (customer_key) অনুপস্থিত।',
                ],
                'action' => 'customer_key অনুপস্থিত থাকার কারণে গণনা কমেছে কি না এবং প্রথম পণ্যে সন্তুষ্টি ঠিক আছে কি না যাচাই করুন।',
                'experiment' => 'প্রথমবার ডেলিভারি পাওয়া ক্রেতাদের ২১তম দিনে হোয়াটসঅ্যাপ/এসএমএস-এ রি-অর্ডার অফার পাঠিয়ে ৩০ দিন পরীক্ষা করুন।',
                'success' => [
                    'metric' => '৬০ দিনের রিপিট ক্রেতার হার (%)',
                    'baseline' => '{cur_rate}%',
                    'target' => '>= {target}%',
                    'duration' => '৩০ দিন',
                ],
            ],
            'R11.insufficient' => [
                'title' => 'রিপিট ক্রেতার প্রবণতা মাপার জন্য পর্যাপ্ত ক্রেতা নমুনা নেই ({website})',
                'summary' => 'চলতি ({cur_buyers} জন) অথবা পূর্ববর্তী ৬০ দিনে ({prev_buyers} জন) customer_key সহ ক্রেতার সংখ্যা নির্ধারিত সীমার নিচে (৫০ জন প্রয়োজন, < ২০ উপস্থিত)।',
                'hypotheses' => [
                    'অর্ডার ইমপোর্টে ক্রেতার ফোন/ইমেইল কলাম ম্যাপ না করায় ক্রেতা ম্যাচিং হচ্ছে না।',
                ],
                'missing_data' => [
                    'উভয় ৬০ দিনের সময়ে customer_key যুক্ত >= ৫০ জন স্বতন্ত্র ক্রেতা।',
                ],
                'action' => 'ফাইল ইমপোর্টে ক্রেতার ফোন/ইমেইল কলাম ম্যাপ করুন (কেবল SHA-256 হ্যাশ সংরক্ষিত হয়)।',
                'experiment' => '৬০ দিনের ক্রেতা ডেটা জমা হওয়ার পর পুনরায় পরীক্ষা করুন।',
                'success' => [
                    'metric' => '৬০ দিনে শনাক্তকৃত ক্রেতা',
                    'baseline' => '{cur_buyers} জন',
                    'target' => '>= ৫০ জন',
                    'duration' => '৬০ দিন',
                ],
            ],
            'R12.trigger' => [
                'title' => 'ডেটা-মানের প্রতিবন্ধকতা অন্য নিয়মগুলো বাধাগ্রস্ত করছে ({website}: স্কোর {dq_score}/১০০)',
                'summary' => '{website}-এ ২০%-এর বেশি গুরুত্বপূর্ণ ডেটা ঘাটতি ("bad") ধরা পড়েছে: {bad_summary}। এর ফলে অকার্যকর বা অনির্ভরযোগ্য নিয়ম: {disabled_rules}।',
                'hypotheses' => [
                    'সাম্প্রতিক CSV ম্যাপিংয়ে কুরিয়ার, পণ্যের খরচ/SKU, ক্রেতার যোগাযোগ বা কর্মীর কলাম বাদ পড়েছে।',
                    'লাইভ স্টোর কানেক্টর সিঙ্ক বন্ধ হয়ে আছে অথবা অচেনা স্ট্যাটাস "other" হিসেবে পড়ে আছে।',
                ],
                'missing_data' => [
                    '{bad_indicators_list}',
                ],
                'action' => 'অ্যানালিটিক্স পেজের Data Quality Breakdown অংশে গিয়ে সর্বোচ্চ ওয়েটের অনুপস্থিত তথ্য ইমপোর্ট বা সিঙ্ক করে ঠিক করুন।',
                'experiment' => 'গত ৩০ দিনের অর্ডারগুলোর অনুপস্থিত ক্ষেত্রগুলো আপডেট করে ডেটা-কোয়ালিটি স্কোর >= ৮৫/১০০-এ তুলুন।',
                'success' => [
                    'metric' => 'ডেটা-কোয়ালিটি স্কোর (০-১০০)',
                    'baseline' => '{dq_score}/১০০',
                    'target' => '>= ৮৫/১০০',
                    'duration' => '৭ দিন',
                ],
            ],
        ];
    }
}
