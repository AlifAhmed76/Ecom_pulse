<?php

namespace App\Services\Rnd;

use App\Models\ResearchFinding;
use App\Models\Website;
use App\Services\AnalyticsService;
use App\Services\Marketing\MarketingAnalyticsService;
use App\Support\Stats;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RuleEngine
{
    /**
     * Evaluate R01-R12 across all active websites of a tenant using AnalyticsService numbers only.
     *
     * @return array{websites_scanned: int, created: int, updated: int, skipped_closed: int, findings_total: int}
     */
    public function scanTenant(int $tenantId, ?Carbon $referenceNow = null): array
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $now = ($referenceNow ?? now($tz))->copy()->setTimezone($tz);
        $nowStr = $now->format('Y-m-d H:i:s');
        $weekKey = $now->format('o-\WW');

        $websites = Website::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', '<>', 'disabled')
            ->orderBy('id')
            ->get(['id', 'name', 'currency']);

        $counts = [
            'websites_scanned' => $websites->count(),
            'created' => 0,
            'updated' => 0,
            'skipped_closed' => 0,
            'findings_total' => 0,
        ];

        $analytics = AnalyticsService::for($tenantId);
        $cfg = config('lyp.rnd', []);

        $todayStr = $now->format('Y-m-d');
        $from7 = $now->copy()->subDays(6)->format('Y-m-d');
        $fromPrev7 = $now->copy()->subDays(13)->format('Y-m-d');
        $toPrev7 = $now->copy()->subDays(7)->format('Y-m-d');
        $fromBase28 = $now->copy()->subDays(34)->format('Y-m-d');
        $from30 = $now->copy()->subDays(29)->format('Y-m-d');
        $fromPrev30 = $now->copy()->subDays(59)->format('Y-m-d');
        $toPrev30 = $now->copy()->subDays(30)->format('Y-m-d');
        $from60 = $now->copy()->subDays(59)->format('Y-m-d');
        $fromPrev60 = $now->copy()->subDays(119)->format('Y-m-d');
        $toPrev60 = $now->copy()->subDays(60)->format('Y-m-d');

        foreach ($websites as $site) {
            $siteId = (int) $site->id;
            $siteName = (string) $site->name;

            $sup = $analytics->rndSupplement($siteId);
            if ((int) ($sup['total_site_orders'] ?? 0) === 0) {
                continue;
            }

            $w7 = $analytics->analytics(['preset' => 'custom', 'from' => $from7, 'to' => $todayStr, 'website_id' => $siteId]);
            $wPrev7 = $analytics->analytics(['preset' => 'custom', 'from' => $fromPrev7, 'to' => $toPrev7, 'website_id' => $siteId]);
            $wBase28 = $analytics->analytics(['preset' => 'custom', 'from' => $fromBase28, 'to' => $toPrev7, 'website_id' => $siteId]);
            $w30 = $analytics->analytics(['preset' => 'custom', 'from' => $from30, 'to' => $todayStr, 'website_id' => $siteId]);
            $wPrev30 = $analytics->analytics(['preset' => 'custom', 'from' => $fromPrev30, 'to' => $toPrev30, 'website_id' => $siteId]);
            $w60 = $analytics->analytics(['preset' => 'custom', 'from' => $from60, 'to' => $todayStr, 'website_id' => $siteId]);
            $wPrev60 = $analytics->analytics(['preset' => 'custom', 'from' => $fromPrev60, 'to' => $toPrev60, 'website_id' => $siteId]);

            $currency = ! ($w30['mixed_currency'] ?? false)
                ? (string) ($w30['currency'] ?? $site->currency ?? 'BDT')
                : (string) ($site->currency ?? 'BDT');
            $gross30Ref = $w30['kpis']['gross_sales'] ?? null;
            $dqScore = (int) ($w30['data_quality']['score'] ?? 0);

            $candidates = [];

            // ---------------------------------------------------------
            // R01: Sales drop
            // ---------------------------------------------------------
            $curOrders7 = (int) $w7['kpis']['orders'];
            $prevOrders7 = (int) $wPrev7['kpis']['orders'];
            $minPrevOrders = (int) ($cfg['r01_min_prev_orders'] ?? 20);
            $dropThreshold = (float) ($cfg['r01_drop_pct'] ?? 20.0);

            if (($curOrders7 + $prevOrders7) > 0 && $prevOrders7 < (int) ($cfg['min_sample_info'] ?? 20)) {
                $candidates[] = $this->insufficientCandidate(
                    'R01', 'R01.insufficient', $siteId, $siteName, $currency, $prevOrders7,
                    ['from' => $fromPrev7, 'to' => $todayStr],
                    ['orders'],
                    ['prev_orders' => $prevOrders7, 'cur_orders' => $curOrders7],
                    $dqScore
                );
            } elseif ($prevOrders7 >= $minPrevOrders && ! $w7['mixed_currency'] && ! $wPrev7['mixed_currency']) {
                $curGross7 = (float) ($w7['kpis']['gross_sales'] ?? 0.0);
                $prevGross7 = (float) ($wPrev7['kpis']['gross_sales'] ?? 0.0);
                $pctChange = Stats::percentChange($prevGross7, $curGross7, $prevOrders7, $minPrevOrders);

                if ($pctChange !== null && $pctChange <= -$dropThreshold) {
                    $dropPct = round(abs($pctChange), 2);
                    $money = round(max(0.0, $prevGross7 - $curGross7), 2);

                    $zStat = null;
                    $weeklySeries = $sup['weekly_gross_sales'] ?? [];
                    $allTrailingPopulated = count($weeklySeries) >= (int) ($cfg['r01_trailing_weeks'] ?? 8)
                        && collect($weeklySeries)->every(fn (array $w): bool => (int) ($w['orders'] ?? 0) > 0);
                    if ($allTrailingPopulated && ! ($sup['weekly_mixed_currency'] ?? false)) {
                        $vals = array_map(fn (array $w): float => (float) ($w['gross_sales'] ?? 0.0), $weeklySeries);
                        $zStat = Stats::trailingZScore($vals, $curGross7, (int) ($cfg['r01_trailing_weeks'] ?? 8));
                    }

                    $conf = $this->confidenceFor(
                        $prevOrders7 + $curOrders7,
                        $zStat['p_value'] ?? null,
                        isset($zStat['z']) && $zStat['z'] !== null ? abs((float) $zStat['z']) : null,
                        $dqScore
                    );

                    $candidates[] = [
                        'rule_code' => 'R01',
                        'subject_key' => 'site',
                        'is_insufficient_data' => false,
                        'template_key' => 'R01.trigger',
                        'severity' => $this->severityFor($money, ($gross30Ref !== null && $gross30Ref > 0.0) ? $gross30Ref : $prevGross7),
                        'money_at_stake' => $money,
                        'currency' => $currency,
                        'confidence' => $conf['level'],
                        'params' => [
                            'website' => $siteName,
                            'currency' => $currency,
                            'drop_pct' => number_format($dropPct, 2),
                            'prev_gross' => number_format($prevGross7, 2),
                            'cur_gross' => number_format($curGross7, 2),
                            'diff_amount' => number_format($money, 2),
                            'target_gross' => number_format($prevGross7 * 0.95, 2),
                            'prev_orders' => $prevOrders7,
                            'cur_orders' => $curOrders7,
                            'days' => 7,
                            'n' => $prevOrders7 + $curOrders7,
                            'dq_score' => $dqScore,
                            'p_value' => isset($zStat['p_value']) && $zStat['p_value'] !== null ? number_format((float) $zStat['p_value'], 4) : null,
                            'confidence_reason_key' => $conf['reason_key'],
                        ],
                        'evidence' => [
                            'metrics' => [
                                ['label' => 'Last 7d gross sales', 'value' => "{$currency} ".number_format($curGross7, 2)." ({$curOrders7} orders)"],
                                ['label' => 'Previous 7d gross sales', 'value' => "{$currency} ".number_format($prevGross7, 2)." ({$prevOrders7} orders)"],
                                ['label' => 'Change', 'value' => "-{$dropPct}%"],
                            ],
                            'source_tables' => ['orders'],
                            'window_from' => $from7,
                            'window_to' => $todayStr,
                            'window_days' => 7,
                            'comparison_baseline' => "Previous 7d ({$fromPrev7} to {$toPrev7})",
                            'sample_size' => $prevOrders7 + $curOrders7,
                            'p_value' => $zStat['p_value'] ?? null,
                            'z_score' => $zStat['z'] ?? null,
                            'wilson_ci' => null,
                            'data_quality_score' => $dqScore,
                            'analytics_params' => ['preset' => 'custom', 'from' => $fromPrev7, 'to' => $todayStr, 'website_id' => $siteId],
                        ],
                    ];
                }
            }

            // ---------------------------------------------------------
            // R02: Cancellation spike
            // ---------------------------------------------------------
            $c1 = (int) $w7['kpis']['cancelled_orders'];
            $n1 = $curOrders7;
            $c2 = (int) $wBase28['kpis']['cancelled_orders'];
            $n2 = (int) $wBase28['kpis']['orders'];
            $minInfo = (int) ($cfg['min_sample_info'] ?? 20);
            $r02Min = (int) ($cfg['r02_min_orders'] ?? 30);

            if ($n1 > 0 && ($n1 < $minInfo || $n2 < $minInfo)) {
                $candidates[] = $this->insufficientCandidate(
                    'R02', 'R02.insufficient', $siteId, $siteName, $currency, $n1,
                    ['from' => $from7, 'to' => $todayStr],
                    ['orders', 'order_status_history'],
                    ['days' => 7, 'n' => $n1, 'base_n' => $n2],
                    $dqScore
                );
            } elseif ($n1 >= $r02Min && $n2 >= $minInfo) {
                $z = Stats::twoProportionZTest($c1, $n1, $c2, $n2);
                if ($z !== null && $z['diff_pp'] >= (float) ($cfg['r02_min_rise_pp'] ?? 5.0) && $z['p_value'] < (float) ($cfg['r02_p_max'] ?? 0.05)) {
                    $nowRate = (float) $w7['kpis']['cancellation_rate'];
                    $baseRate = (float) $wBase28['kpis']['cancellation_rate'];
                    $targetRate = round(max($baseRate, $nowRate - $z['diff_pp'] * 0.6), 2);
                    $money = ! $w7['mixed_currency'] ? (float) ($w7['kpis']['lost_to_cancel'] ?? 0.0) : null;
                    $conf = $this->confidenceFor($n1, $z['p_value'], abs($z['z']), $dqScore);
                    $wilson = Stats::wilsonInterval($c1, $n1);

                    $candidates[] = [
                        'rule_code' => 'R02',
                        'subject_key' => 'site',
                        'is_insufficient_data' => false,
                        'template_key' => 'R02.trigger',
                        'severity' => $this->severityFor($money, $gross30Ref ?: $w7['kpis']['gross_sales']),
                        'money_at_stake' => $money,
                        'currency' => $currency,
                        'confidence' => $conf['level'],
                        'params' => [
                            'website' => $siteName,
                            'currency' => $currency,
                            'base' => number_format($baseRate, 2),
                            'now' => number_format($nowRate, 2),
                            'rise_pp' => number_format($z['diff_pp'], 2),
                            'target' => number_format($targetRate, 2),
                            'days' => 7,
                            'n' => $n1,
                            'base_n' => $n2,
                            'n_c' => $c1,
                            'missing_reason_pct' => number_format((float) ($sup['cancel_missing_reason_pct_7d'] ?? 0.0), 1),
                            'dq_score' => $dqScore,
                            'p_value' => number_format($z['p_value'], 4),
                            'confidence_reason_key' => $conf['reason_key'],
                        ],
                        'evidence' => [
                            'metrics' => [
                                ['label' => 'Last 7d cancel rate', 'value' => "{$nowRate}% ({$c1}/{$n1})"],
                                ['label' => 'Baseline 28d cancel rate', 'value' => "{$baseRate}% ({$c2}/{$n2})"],
                                ['label' => 'Wilson 95% CI', 'value' => $wilson !== null ? "{$wilson['low_pct']}% - {$wilson['high_pct']}%" : 'n/a'],
                            ],
                            'source_tables' => ['orders', 'order_status_history'],
                            'window_from' => $from7,
                            'window_to' => $todayStr,
                            'window_days' => 7,
                            'comparison_baseline' => "Trailing 28d ({$fromBase28} to {$toPrev7})",
                            'sample_size' => $n1,
                            'baseline_sample_size' => $n2,
                            'p_value' => $z['p_value'],
                            'z_score' => $z['z'],
                            'wilson_ci' => $wilson,
                            'data_quality_score' => $dqScore,
                            'analytics_params' => ['preset' => 'custom', 'from' => $fromBase28, 'to' => $todayStr, 'website_id' => $siteId],
                        ],
                    ];
                }
            }

            // ---------------------------------------------------------
            // R03: Return spike
            // ---------------------------------------------------------
            $ret1 = (int) $w7['kpis']['returned_orders'];
            $comp1 = (int) $w7['kpis']['delivered_orders'] + $ret1;
            $ret2 = (int) $wBase28['kpis']['returned_orders'];
            $comp2 = (int) $wBase28['kpis']['delivered_orders'] + $ret2;
            $r03Min = (int) ($cfg['r03_min_completed'] ?? 30);

            if ($comp1 > 0 && ($comp1 < $minInfo || $comp2 < $minInfo)) {
                $candidates[] = $this->insufficientCandidate(
                    'R03', 'R03.insufficient', $siteId, $siteName, $currency, $comp1,
                    ['from' => $from7, 'to' => $todayStr],
                    ['orders', 'courier_shipments'],
                    ['days' => 7, 'n' => $comp1, 'base_n' => $comp2],
                    $dqScore
                );
            } elseif ($comp1 >= $r03Min && $comp2 >= $minInfo) {
                $z = Stats::twoProportionZTest($ret1, $comp1, $ret2, $comp2);
                if ($z !== null && $z['diff_pp'] >= (float) ($cfg['r03_min_rise_pp'] ?? 5.0) && $z['p_value'] < (float) ($cfg['r03_p_max'] ?? 0.05)) {
                    $nowRate = (float) $w7['kpis']['return_rate'];
                    $baseRate = (float) $wBase28['kpis']['return_rate'];
                    $targetRate = round(max($baseRate, $nowRate - $z['diff_pp'] * 0.6), 2);
                    $money = ! $w7['mixed_currency'] ? (float) ($w7['kpis']['lost_to_return'] ?? 0.0) : null;
                    $conf = $this->confidenceFor($comp1, $z['p_value'], abs($z['z']), $dqScore);
                    $wilson = Stats::wilsonInterval($ret1, $comp1);

                    $candidates[] = [
                        'rule_code' => 'R03',
                        'subject_key' => 'site',
                        'is_insufficient_data' => false,
                        'template_key' => 'R03.trigger',
                        'severity' => $this->severityFor($money, $gross30Ref ?: $w7['kpis']['gross_sales']),
                        'money_at_stake' => $money,
                        'currency' => $currency,
                        'confidence' => $conf['level'],
                        'params' => [
                            'website' => $siteName,
                            'currency' => $currency,
                            'base' => number_format($baseRate, 2),
                            'now' => number_format($nowRate, 2),
                            'rise_pp' => number_format($z['diff_pp'], 2),
                            'target' => number_format($targetRate, 2),
                            'days' => 7,
                            'n' => $comp1,
                            'base_n' => $comp2,
                            'n_r' => $ret1,
                            'dq_score' => $dqScore,
                            'p_value' => number_format($z['p_value'], 4),
                            'confidence_reason_key' => $conf['reason_key'],
                        ],
                        'evidence' => [
                            'metrics' => [
                                ['label' => 'Last 7d return rate', 'value' => "{$nowRate}% ({$ret1}/{$comp1})"],
                                ['label' => 'Baseline 28d return rate', 'value' => "{$baseRate}% ({$ret2}/{$comp2})"],
                                ['label' => 'Wilson 95% CI', 'value' => $wilson !== null ? "{$wilson['low_pct']}% - {$wilson['high_pct']}%" : 'n/a'],
                            ],
                            'source_tables' => ['orders', 'courier_shipments'],
                            'window_from' => $from7,
                            'window_to' => $todayStr,
                            'window_days' => 7,
                            'comparison_baseline' => "Trailing 28d ({$fromBase28} to {$toPrev7})",
                            'sample_size' => $comp1,
                            'baseline_sample_size' => $comp2,
                            'p_value' => $z['p_value'],
                            'z_score' => $z['z'],
                            'wilson_ci' => $wilson,
                            'data_quality_score' => $dqScore,
                            'analytics_params' => ['preset' => 'custom', 'from' => $fromBase28, 'to' => $todayStr, 'website_id' => $siteId],
                        ],
                    ];
                }
            }

            // ---------------------------------------------------------
            // R04: Courier outlier
            // ---------------------------------------------------------
            $couriers = $w30['couriers'] ?? [];
            $eligibleDispatched30 = (int) $w30['kpis']['delivered_orders'] + (int) $w30['kpis']['returned_orders'];
            $r04Triggered = false;
            $smallCourierSample = null;

            if ($couriers === []) {
                if ($eligibleDispatched30 > 0) {
                    $candidates[] = $this->insufficientCandidate(
                        'R04', 'R04.missing_courier', $siteId, $siteName, $currency, 0,
                        ['from' => $from30, 'to' => $todayStr],
                        ['orders', 'courier_shipments'],
                        ['eligible' => $eligibleDispatched30],
                        $dqScore
                    );
                }
            } else {
                $totalCourierCompleted = 0;
                $totalCourierReturned = 0;
                foreach ($couriers as $c) {
                    $comp = (int) $c['delivered'] + (int) $c['returned'];
                    $totalCourierCompleted += $comp;
                    $totalCourierReturned += (int) $c['returned'];
                    if ($comp > 0 && $comp < $minInfo) {
                        $smallCourierSample = $smallCourierSample === null ? $comp : min($smallCourierSample, $comp);
                    }
                }

                $r04Min = (int) ($cfg['r04_min_shipments'] ?? 30);
                foreach ($couriers as $c) {
                    $cComp = (int) $c['delivered'] + (int) $c['returned'];
                    $cRet = (int) $c['returned'];
                    $oComp = $totalCourierCompleted - $cComp;
                    $oRet = $totalCourierReturned - $cRet;

                    if ($cComp < $r04Min || $oComp < $minInfo) {
                        continue;
                    }

                    $z = Stats::twoProportionZTest($cRet, $cComp, $oRet, $oComp);
                    if ($z !== null && $z['diff_pp'] > 0 && $z['p_value'] < (float) ($cfg['r04_p_max'] ?? 0.05)) {
                        $r04Triggered = true;
                        $cRate = round(($cRet / $cComp) * 100, 2);
                        $oRate = round(($oRet / $oComp) * 100, 2);
                        $money = isset($c['returned_value']) && $c['returned_value'] !== null ? (float) $c['returned_value'] : null;
                        $conf = $this->confidenceFor($cComp, $z['p_value'], abs($z['z']), $dqScore);
                        $wilson = Stats::wilsonInterval($cRet, $cComp);
                        $cName = (string) $c['courier'];

                        $candidates[] = [
                            'rule_code' => 'R04',
                            'subject_key' => 'courier:'.strtolower(trim($cName)),
                            'is_insufficient_data' => false,
                            'template_key' => 'R04.trigger',
                            'severity' => $this->severityFor($money, $gross30Ref),
                            'money_at_stake' => $money,
                            'currency' => $currency,
                            'confidence' => $conf['level'],
                            'params' => [
                                'website' => $siteName,
                                'currency' => $currency,
                                'courier' => $cName,
                                'rate' => number_format($cRate, 2),
                                'other_rate' => number_format($oRate, 2),
                                'target' => number_format(min($cRate, $oRate + 2.0), 2),
                                'returned' => $cRet,
                                'n' => $cComp,
                                'dq_score' => $dqScore,
                                'p_value' => number_format($z['p_value'], 4),
                                'z_score' => number_format($z['z'], 2),
                                'confidence_reason_key' => $conf['reason_key'],
                            ],
                            'evidence' => [
                                'metrics' => [
                                    ['label' => "{$cName} return rate", 'value' => "{$cRate}% ({$cRet}/{$cComp})"],
                                    ['label' => 'Other couriers average', 'value' => "{$oRate}% ({$oRet}/{$oComp})"],
                                    ['label' => 'Wilson 95% CI', 'value' => $wilson !== null ? "{$wilson['low_pct']}% - {$wilson['high_pct']}%" : 'n/a'],
                                ],
                                'source_tables' => ['orders'],
                                'window_from' => $from30,
                                'window_to' => $todayStr,
                                'window_days' => 30,
                                'comparison_baseline' => 'Other active couriers in 30d window',
                                'sample_size' => $cComp,
                                'baseline_sample_size' => $oComp,
                                'p_value' => $z['p_value'],
                                'z_score' => $z['z'],
                                'wilson_ci' => $wilson,
                                'data_quality_score' => $dqScore,
                                'analytics_params' => ['preset' => 'custom', 'from' => $from30, 'to' => $todayStr, 'website_id' => $siteId],
                            ],
                        ];
                    }
                }

                if (! $r04Triggered && $smallCourierSample !== null) {
                    $candidates[] = $this->insufficientCandidate(
                        'R04', 'R04.insufficient', $siteId, $siteName, $currency, $smallCourierSample,
                        ['from' => $from30, 'to' => $todayStr],
                        ['orders'],
                        ['n' => $smallCourierSample],
                        $dqScore
                    );
                }
            }

            // ---------------------------------------------------------
            // R05: Employee cancel outlier (non-blaming wording mandatory)
            // ---------------------------------------------------------
            $employees = $w30['employees'] ?? [];
            if ($employees !== []) {
                $totalEmpAssigned = 0;
                $totalEmpCancelled = 0;
                $smallEmpSample = null;
                foreach ($employees as $emp) {
                    $asg = (int) $emp['assigned'];
                    $totalEmpAssigned += $asg;
                    $totalEmpCancelled += (int) $emp['cancelled'];
                    if ($asg > 0 && $asg < $minInfo) {
                        $smallEmpSample = $smallEmpSample === null ? $asg : min($smallEmpSample, $asg);
                    }
                }

                $r05Triggered = false;
                $r05Min = (int) ($cfg['r05_min_assigned'] ?? 30);

                foreach ($employees as $emp) {
                    $eAsg = (int) $emp['assigned'];
                    $eCan = (int) $emp['cancelled'];
                    $oAsg = $totalEmpAssigned - $eAsg;
                    $oCan = $totalEmpCancelled - $eCan;

                    if ($eAsg < $r05Min || $oAsg < $minInfo) {
                        continue;
                    }

                    $z = Stats::twoProportionZTest($eCan, $eAsg, $oCan, $oAsg);
                    if ($z !== null && $z['diff_pp'] > 0 && $z['p_value'] < (float) ($cfg['r05_p_max'] ?? 0.05)) {
                        $r05Triggered = true;
                        $eRate = (float) $emp['cancel_rate'];
                        $oRate = round(($oCan / $oAsg) * 100, 2);
                        $money = isset($emp['cancelled_value']) && $emp['cancelled_value'] !== null ? (float) $emp['cancelled_value'] : null;
                        $conf = $this->confidenceFor($eAsg, $z['p_value'], abs($z['z']), $dqScore);
                        $wilson = Stats::wilsonInterval($eCan, $eAsg);
                        $empName = (string) $emp['name'];

                        $candidates[] = [
                            'rule_code' => 'R05',
                            'subject_key' => 'employee:'.(int) $emp['employee_id'],
                            'is_insufficient_data' => false,
                            'template_key' => 'R05.trigger',
                            'severity' => $this->severityFor($money, $gross30Ref),
                            'money_at_stake' => $money,
                            'currency' => $currency,
                            'confidence' => $conf['level'],
                            'params' => [
                                'website' => $siteName,
                                'currency' => $currency,
                                'employee' => $empName,
                                'rate' => number_format($eRate, 2),
                                'other_rate' => number_format($oRate, 2),
                                'target' => number_format(min($eRate, $oRate + 2.0), 2),
                                'cancelled' => $eCan,
                                'n' => $eAsg,
                                'dq_score' => $dqScore,
                                'p_value' => number_format($z['p_value'], 4),
                                'confidence_reason_key' => $conf['reason_key'],
                            ],
                            'evidence' => [
                                'metrics' => [
                                    ['label' => "{$empName} cancel rate", 'value' => "{$eRate}% ({$eCan}/{$eAsg})"],
                                    ['label' => 'Rest of team cancel rate', 'value' => "{$oRate}% ({$oCan}/{$oAsg})"],
                                    ['label' => 'Wilson 95% CI', 'value' => $wilson !== null ? "{$wilson['low_pct']}% - {$wilson['high_pct']}%" : 'n/a'],
                                ],
                                'source_tables' => ['orders', 'order_assignments', 'employees'],
                                'window_from' => $from30,
                                'window_to' => $todayStr,
                                'window_days' => 30,
                                'comparison_baseline' => 'Rest of assigned staff in 30d window (workload and customer mix may differ)',
                                'sample_size' => $eAsg,
                                'baseline_sample_size' => $oAsg,
                                'p_value' => $z['p_value'],
                                'z_score' => $z['z'],
                                'wilson_ci' => $wilson,
                                'data_quality_score' => $dqScore,
                                'analytics_params' => ['preset' => 'custom', 'from' => $from30, 'to' => $todayStr, 'website_id' => $siteId],
                            ],
                        ];
                    }
                }

                if (! $r05Triggered && $smallEmpSample !== null) {
                    $candidates[] = $this->insufficientCandidate(
                        'R05', 'R05.insufficient', $siteId, $siteName, $currency, $smallEmpSample,
                        ['from' => $from30, 'to' => $todayStr],
                        ['orders', 'order_assignments', 'employees'],
                        ['n' => $smallEmpSample, 'emp_count' => count($employees)],
                        $dqScore
                    );
                }
            }

            // ---------------------------------------------------------
            // R06: Stock risk (< 7d cover for top 20% revenue SKUs)
            // ---------------------------------------------------------
            $prodCover = $sup['product_stock_cover'] ?? [];
            if ($prodCover !== []) {
                $topFraction = (float) ($cfg['r06_top_share_fraction'] ?? 0.20);
                $topCount = max(1, (int) ceil(count($prodCover) * $topFraction));
                $topSlice = array_slice($prodCover, 0, $topCount);
                $coverLimit = (float) ($cfg['r06_cover_days'] ?? 7.0);
                $immLimit = (float) ($cfg['r06_immediate_cover_days'] ?? 2.0);
                $r06MinSample = (int) ($cfg['r06_min_sample'] ?? 20);
                $r06Triggered = false;
                $r06SmallSample = null;

                foreach ($topSlice as $prod) {
                    if ($prod['stock'] === null || $prod['cover_days'] === null) {
                        continue;
                    }
                    $cover = (float) $prod['cover_days'];
                    if ($cover >= $coverLimit) {
                        continue;
                    }
                    $sample = max((int) $w30['kpis']['orders'], (int) $prod['units_30d']);
                    if ($sample < $r06MinSample) {
                        $r06SmallSample = $r06SmallSample === null ? $sample : min($r06SmallSample, $sample);
                        continue;
                    }

                    $r06Triggered = true;
                    $immediate = ((int) $prod['stock'] <= 0) || ($cover <= $immLimit);
                    $money = ! $w30['mixed_currency']
                        ? round(((float) $prod['revenue_30d'] / 30.0) * max(1.0, $coverLimit - $cover), 2)
                        : null;
                    $conf = $this->confidenceFor($sample, null, null, $dqScore);

                    $candidates[] = [
                        'rule_code' => 'R06',
                        'subject_key' => 'product:'.(int) $prod['product_id'],
                        'is_insufficient_data' => false,
                        'template_key' => 'R06.trigger',
                        'severity' => $this->severityFor($money, $gross30Ref, $immediate),
                        'money_at_stake' => $money,
                        'currency' => $currency,
                        'confidence' => $conf['level'],
                        'params' => [
                            'website' => $siteName,
                            'currency' => $currency,
                            'product' => (string) $prod['name'],
                            'stock' => (int) $prod['stock'],
                            'cover_days' => number_format($cover, 1),
                            'units_30d' => (int) $prod['units_30d'],
                            'rev_30d' => number_format((float) $prod['revenue_30d'], 2),
                            'n' => $sample,
                            'dq_score' => $dqScore,
                            'p_value' => null,
                            'confidence_reason_key' => $conf['reason_key'],
                        ],
                        'evidence' => [
                            'metrics' => [
                                ['label' => 'Stock remaining', 'value' => ((int) $prod['stock']).' units'],
                                ['label' => '30d velocity', 'value' => ((int) $prod['units_30d']).' units ('.$currency.' '.number_format((float) $prod['revenue_30d'], 2).')'],
                                ['label' => 'Stock cover', 'value' => number_format($cover, 1).' days'],
                            ],
                            'source_tables' => ['products', 'order_items', 'orders'],
                            'window_from' => $from30,
                            'window_to' => $todayStr,
                            'window_days' => 30,
                            'comparison_baseline' => 'Top 20% 30-day revenue SKUs (< 7 days cover threshold)',
                            'sample_size' => $sample,
                            'p_value' => null,
                            'z_score' => null,
                            'wilson_ci' => null,
                            'data_quality_score' => $dqScore,
                            'analytics_params' => ['preset' => 'custom', 'from' => $from30, 'to' => $todayStr, 'website_id' => $siteId],
                        ],
                    ];
                }

                if (! $r06Triggered && $r06SmallSample !== null) {
                    $candidates[] = $this->insufficientCandidate(
                        'R06', 'R06.insufficient', $siteId, $siteName, $currency, $r06SmallSample,
                        ['from' => $from30, 'to' => $todayStr],
                        ['products', 'order_items', 'orders'],
                        ['n' => $r06SmallSample],
                        $dqScore
                    );
                }
            }

            // ---------------------------------------------------------
            // R07: Dead stock (stock > 0, no sale in 60 days)
            // ---------------------------------------------------------
            $deadData = $sup['dead_stock'] ?? [];
            $deadItems = $deadData['items'] ?? [];
            $deadMissingVal = (int) ($deadData['missing_value_count'] ?? 0);
            $orders60d = (int) ($sup['site_orders_60d'] ?? 0);
            $r07MinSiteOrders = (int) ($cfg['r07_min_site_orders'] ?? 20);

            if ($deadItems !== [] || $deadMissingVal > 0) {
                if ($orders60d < $r07MinSiteOrders) {
                    if ($orders60d > 0) {
                        $candidates[] = $this->insufficientCandidate(
                            'R07', 'R07.insufficient', $siteId, $siteName, $currency, $orders60d,
                            ['from' => $from60, 'to' => $todayStr],
                            ['products', 'orders', 'order_items'],
                            ['n' => $orders60d],
                            $dqScore
                        );
                    }
                } elseif ($deadItems !== []) {
                    $deadValue = round((float) array_sum(array_column($deadItems, 'stock_value')), 2);
                    $topDead = $deadItems[0];
                    $conf = $this->confidenceFor($orders60d, null, null, $dqScore);

                    $candidates[] = [
                        'rule_code' => 'R07',
                        'subject_key' => 'dead_stock',
                        'is_insufficient_data' => false,
                        'template_key' => 'R07.trigger',
                        'severity' => $this->severityFor(! $w30['mixed_currency'] ? $deadValue : null, $gross30Ref),
                        'money_at_stake' => ! $w30['mixed_currency'] ? $deadValue : null,
                        'currency' => $currency,
                        'confidence' => $conf['level'],
                        'params' => [
                            'website' => $siteName,
                            'currency' => $currency,
                            'sku_count' => count($deadItems),
                            'dead_days' => (int) ($deadData['dead_days'] ?? 60),
                            'dead_value' => number_format($deadValue, 2),
                            'top_product' => (string) $topDead['name'],
                            'top_stock' => (int) $topDead['stock'],
                            'top_value' => number_format((float) $topDead['stock_value'], 2),
                            'n' => $orders60d,
                            'dq_score' => $dqScore,
                            'p_value' => null,
                            'confidence_reason_key' => $conf['reason_key'],
                        ],
                        'evidence' => [
                            'metrics' => array_map(
                                fn (array $d): array => [
                                    'label' => $d['name'].($d['sku'] ? " ({$d['sku']})" : ''),
                                    'value' => "{$d['stock']} units x {$currency} ".number_format($d['unit_value'], 2)." ({$d['value_source']}) = {$currency} ".number_format($d['stock_value'], 2),
                                ],
                                array_slice($deadItems, 0, 5)
                            ),
                            'source_tables' => ['products', 'order_items', 'orders'],
                            'window_from' => $from60,
                            'window_to' => $todayStr,
                            'window_days' => 60,
                            'comparison_baseline' => 'Zero non-cancelled units sold in 60 days',
                            'sample_size' => $orders60d,
                            'p_value' => null,
                            'z_score' => null,
                            'wilson_ci' => null,
                            'data_quality_score' => $dqScore,
                            'analytics_params' => ['preset' => 'custom', 'from' => $from60, 'to' => $todayStr, 'website_id' => $siteId],
                        ],
                    ];
                } else {
                    $candidates[] = $this->insufficientCandidate(
                        'R07', 'R07.missing_value', $siteId, $siteName, $currency, $deadMissingVal,
                        ['from' => $from60, 'to' => $todayStr],
                        ['products'],
                        ['missing_count' => $deadMissingVal, 'dead_days' => (int) ($deadData['dead_days'] ?? 60)],
                        $dqScore
                    );
                }
            }

            // ---------------------------------------------------------
            // R08: Concentration (top 1 > 40% OR top 3 > 70%)
            // ---------------------------------------------------------
            $nonCan30 = (int) $w30['kpis']['non_cancelled_orders'];
            $gross30 = $w30['kpis']['gross_sales'];
            $topProds30 = $w30['top_products'] ?? [];
            $r08Min = (int) ($cfg['r08_min_orders'] ?? 20);

            if ($nonCan30 > 0 && $nonCan30 < $r08Min) {
                $candidates[] = $this->insufficientCandidate(
                    'R08', 'R08.insufficient', $siteId, $siteName, $currency, $nonCan30,
                    ['from' => $from30, 'to' => $todayStr],
                    ['orders', 'order_items'],
                    ['n' => $nonCan30],
                    $dqScore
                );
            } elseif ($nonCan30 >= $r08Min && ! ($w30['mixed_currency'] ?? false) && $gross30 !== null && $gross30 > 0.0) {
                if ($topProds30 === []) {
                    $candidates[] = $this->insufficientCandidate(
                        'R08', 'R08.missing_items', $siteId, $siteName, $currency, $nonCan30,
                        ['from' => $from30, 'to' => $todayStr],
                        ['orders', 'order_items'],
                        ['n' => $nonCan30],
                        $dqScore
                    );
                } else {
                    $top1Rev = (float) ($topProds30[0]['revenue'] ?? 0.0);
                    $top3Rev = (float) array_sum(array_map(fn (array $p): float => (float) ($p['revenue'] ?? 0.0), array_slice($topProds30, 0, 3)));
                    $top1Pct = min(100.0, round(($top1Rev / $gross30) * 100, 2));
                    $top3Pct = min(100.0, round(($top3Rev / $gross30) * 100, 2));

                    if ($top1Pct > (float) ($cfg['r08_top1_pct'] ?? 40.0) || $top3Pct > (float) ($cfg['r08_top3_pct'] ?? 70.0)) {
                        $money = $top1Pct > (float) ($cfg['r08_top1_pct'] ?? 40.0) ? $top1Rev : $top3Rev;
                        $restPct = round(max(0.0, 100.0 - $top1Pct), 2);
                        $targetRestPct = round(min(65.0, $restPct + 10.0), 2);
                        $conf = $this->confidenceFor($nonCan30, null, null, $dqScore);

                        $candidates[] = [
                            'rule_code' => 'R08',
                            'subject_key' => 'site',
                            'is_insufficient_data' => false,
                            'template_key' => 'R08.trigger',
                            'severity' => $this->severityFor($money, $gross30),
                            'money_at_stake' => round($money, 2),
                            'currency' => $currency,
                            'confidence' => $conf['level'],
                            'params' => [
                                'website' => $siteName,
                                'currency' => $currency,
                                'top1_name' => (string) $topProds30[0]['name'],
                                'top1_pct' => number_format($top1Pct, 2),
                                'top1_rev' => number_format($top1Rev, 2),
                                'top3_pct' => number_format($top3Pct, 2),
                                'top3_rev' => number_format($top3Rev, 2),
                                'rest_pct' => number_format($restPct, 2),
                                'target_rest_pct' => number_format($targetRestPct, 2),
                                'n' => $nonCan30,
                                'dq_score' => $dqScore,
                                'p_value' => null,
                                'confidence_reason_key' => $conf['reason_key'],
                            ],
                            'evidence' => [
                                'metrics' => [
                                    ['label' => 'Top 1 share', 'value' => "{$top1Pct}% ({$currency} ".number_format($top1Rev, 2).")"],
                                    ['label' => 'Top 3 share', 'value' => "{$top3Pct}% ({$currency} ".number_format($top3Rev, 2).")"],
                                    ['label' => '30d gross sales', 'value' => "{$currency} ".number_format($gross30, 2)],
                                ],
                                'source_tables' => ['orders', 'order_items'],
                                'window_from' => $from30,
                                'window_to' => $todayStr,
                                'window_days' => 30,
                                'comparison_baseline' => 'Thresholds: top 1 <= 40%, top 3 <= 70%',
                                'sample_size' => $nonCan30,
                                'p_value' => null,
                                'z_score' => null,
                                'wilson_ci' => null,
                                'data_quality_score' => $dqScore,
                                'analytics_params' => ['preset' => 'custom', 'from' => $from30, 'to' => $todayStr, 'website_id' => $siteId],
                            ],
                        ];
                    }
                }
            }

            // ---------------------------------------------------------
            // R09: Margin compression (>= 3 pp drop, only if cost coverage >= 60%)
            // ---------------------------------------------------------
            $curDel30 = (int) $w30['kpis']['delivered_orders'];
            $prevDel30 = (int) $wPrev30['kpis']['delivered_orders'];
            $r09Min = (int) ($cfg['r09_min_delivered'] ?? 20);
            $cCur = $w30['contribution'];
            $cPrev = $wPrev30['contribution'];

            if (($curDel30 + $prevDel30) > 0 && ($curDel30 < $r09Min || $prevDel30 < $r09Min)) {
                $candidates[] = $this->insufficientCandidate(
                    'R09', 'R09.insufficient', $siteId, $siteName, $currency, min($curDel30, $prevDel30),
                    ['from' => $fromPrev30, 'to' => $todayStr],
                    ['orders', 'order_items'],
                    ['cur_delivered' => $curDel30, 'prev_delivered' => $prevDel30, 'n' => min($curDel30, $prevDel30)],
                    $dqScore
                );
            } elseif ($curDel30 >= $r09Min && $prevDel30 >= $r09Min && ! ($w30['mixed_currency'] ?? false) && ! ($wPrev30['mixed_currency'] ?? false)) {
                if (! ($cCur['sufficient_coverage'] ?? false) || ! ($cPrev['sufficient_coverage'] ?? false)) {
                    $candidates[] = $this->insufficientCandidate(
                        'R09', 'R09.low_coverage', $siteId, $siteName, $currency, $curDel30,
                        ['from' => $fromPrev30, 'to' => $todayStr],
                        ['orders', 'order_items'],
                        [
                            'cur_cov' => number_format((float) ($cCur['cost_coverage_pct'] ?? 0.0), 1),
                            'prev_cov' => number_format((float) ($cPrev['cost_coverage_pct'] ?? 0.0), 1),
                        ],
                        $dqScore
                    );
                } elseif ($cCur['margin_pct'] !== null && $cPrev['margin_pct'] !== null) {
                    $dropPp = round((float) $cPrev['margin_pct'] - (float) $cCur['margin_pct'], 2);
                    if ($dropPp >= (float) ($cfg['r09_margin_drop_pp'] ?? 3.0)) {
                        $allDelRev = (float) ($cCur['all_delivered_item_revenue'] ?? 0.0);
                        $money = round(($dropPp / 100.0) * $allDelRev, 2);
                        $cov = (float) $cCur['cost_coverage_pct'];
                        $conf = $this->confidenceFor($curDel30, null, null, $dqScore);

                        $candidates[] = [
                            'rule_code' => 'R09',
                            'subject_key' => 'site',
                            'is_insufficient_data' => false,
                            'template_key' => 'R09.trigger',
                            'severity' => $this->severityFor($money, $gross30Ref),
                            'money_at_stake' => $money,
                            'currency' => $currency,
                            'confidence' => $conf['level'],
                            'params' => [
                                'website' => $siteName,
                                'currency' => $currency,
                                'prev_margin' => number_format((float) $cPrev['margin_pct'], 2),
                                'cur_margin' => number_format((float) $cCur['margin_pct'], 2),
                                'drop_pp' => number_format($dropPp, 2),
                                'target' => number_format((float) $cPrev['margin_pct'] - 1.0, 2),
                                'cur_cov' => number_format($cov, 1),
                                'uncovered_pct' => number_format(max(0.0, 100.0 - $cov), 1),
                                'n' => $curDel30,
                                'dq_score' => $dqScore,
                                'p_value' => null,
                                'confidence_reason_key' => $conf['reason_key'],
                            ],
                            'evidence' => [
                                'metrics' => [
                                    ['label' => 'Current 30d margin', 'value' => number_format((float) $cCur['margin_pct'], 2)."% (cov {$cov}%)"],
                                    ['label' => 'Previous 30d margin', 'value' => number_format((float) $cPrev['margin_pct'], 2)."% (cov {$cPrev['cost_coverage_pct']}%)"],
                                    ['label' => 'Change', 'value' => "-{$dropPp} pp"],
                                ],
                                'source_tables' => ['orders', 'order_items', 'products'],
                                'window_from' => $from30,
                                'window_to' => $todayStr,
                                'window_days' => 30,
                                'comparison_baseline' => "Previous 30d ({$fromPrev30} to {$toPrev30})",
                                'sample_size' => $curDel30,
                                'baseline_sample_size' => $prevDel30,
                                'p_value' => null,
                                'z_score' => null,
                                'wilson_ci' => null,
                                'data_quality_score' => $dqScore,
                                'analytics_params' => ['preset' => 'custom', 'from' => $fromPrev30, 'to' => $todayStr, 'website_id' => $siteId],
                            ],
                        ];
                    }
                }
            }

            // ---------------------------------------------------------
            // R10: Discount impact
            // ---------------------------------------------------------
            $discCohorts = $sup['discount_cohorts_30d'] ?? [];
            $disc = $discCohorts['discounted'] ?? ['orders' => 0, 'completed' => 0, 'returned' => 0, 'return_rate' => 0.0, 'aov' => null, 'returned_value' => null];
            $undisc = $discCohorts['undiscounted'] ?? ['orders' => 0, 'completed' => 0, 'returned' => 0, 'return_rate' => 0.0, 'aov' => null, 'returned_value' => null];
            $r10Min = (int) ($cfg['r10_min_completed_per_group'] ?? 20);

            if ((int) $disc['orders'] > 0) {
                if ((int) $disc['completed'] < $r10Min || (int) $undisc['completed'] < $r10Min) {
                    $candidates[] = $this->insufficientCandidate(
                        'R10', 'R10.insufficient', $siteId, $siteName, $currency, min((int) $disc['completed'], (int) $undisc['completed']),
                        ['from' => $from30, 'to' => $todayStr],
                        ['orders'],
                        ['disc_n' => (int) $disc['completed'], 'undisc_n' => (int) $undisc['completed']],
                        $dqScore
                    );
                } else {
                    $z = Stats::twoProportionZTest(
                        (int) $disc['returned'], (int) $disc['completed'],
                        (int) $undisc['returned'], (int) $undisc['completed']
                    );

                    if ($z !== null && $z['diff_pp'] > 0 && $z['p_value'] < (float) ($cfg['r10_p_max'] ?? 0.05)) {
                        $totalN = (int) $disc['completed'] + (int) $undisc['completed'];
                        $money = isset($disc['returned_value']) && $disc['returned_value'] !== null ? (float) $disc['returned_value'] : null;
                        $conf = $this->confidenceFor($totalN, $z['p_value'], abs($z['z']), $dqScore);
                        $wilson = Stats::wilsonInterval((int) $disc['returned'], (int) $disc['completed']);

                        $candidates[] = [
                            'rule_code' => 'R10',
                            'subject_key' => 'site',
                            'is_insufficient_data' => false,
                            'template_key' => 'R10.trigger',
                            'severity' => $this->severityFor($money, $gross30Ref),
                            'money_at_stake' => $money,
                            'currency' => $currency,
                            'confidence' => $conf['level'],
                            'params' => [
                                'website' => $siteName,
                                'currency' => $currency,
                                'disc_ret' => number_format((float) $disc['return_rate'], 2),
                                'undisc_ret' => number_format((float) $undisc['return_rate'], 2),
                                'disc_aov' => $disc['aov'] !== null ? number_format((float) $disc['aov'], 2) : null,
                                'undisc_aov' => $undisc['aov'] !== null ? number_format((float) $undisc['aov'], 2) : null,
                                'target' => number_format(min((float) $disc['return_rate'], (float) $undisc['return_rate'] + 2.0), 2),
                                'disc_n' => (int) $disc['completed'],
                                'undisc_n' => (int) $undisc['completed'],
                                'n' => $totalN,
                                'dq_score' => $dqScore,
                                'p_value' => number_format($z['p_value'], 4),
                                'confidence_reason_key' => $conf['reason_key'],
                            ],
                            'evidence' => [
                                'metrics' => [
                                    ['label' => 'Discounted return rate / AOV', 'value' => "{$disc['return_rate']}% ({$disc['returned']}/{$disc['completed']}), AOV {$currency} ".($disc['aov'] ?? 'n/a')],
                                    ['label' => 'Undiscounted return rate / AOV', 'value' => "{$undisc['return_rate']}% ({$undisc['returned']}/{$undisc['completed']}), AOV {$currency} ".($undisc['aov'] ?? 'n/a')],
                                    ['label' => 'Wilson 95% CI (discounted)', 'value' => $wilson !== null ? "{$wilson['low_pct']}% - {$wilson['high_pct']}%" : 'n/a'],
                                ],
                                'source_tables' => ['orders'],
                                'window_from' => $from30,
                                'window_to' => $todayStr,
                                'window_days' => 30,
                                'comparison_baseline' => 'Undiscounted completed orders in same 30d window',
                                'sample_size' => $totalN,
                                'p_value' => $z['p_value'],
                                'z_score' => $z['z'],
                                'wilson_ci' => $wilson,
                                'data_quality_score' => $dqScore,
                                'analytics_params' => ['preset' => 'custom', 'from' => $from30, 'to' => $todayStr, 'website_id' => $siteId],
                            ],
                        ];
                    }
                }
            }

            // ---------------------------------------------------------
            // R11: Repeat-rate decline (>= 5 pp vs previous 60d, >= 50 buyers)
            // ---------------------------------------------------------
            $curBuyers = (int) ($w60['kpis']['buyers_in_range'] ?? 0);
            $curRepeat = (int) ($w60['kpis']['repeat_buyers'] ?? 0);
            $curRepeatRate = (float) ($w60['kpis']['repeat_purchase_rate'] ?? 0.0);
            $prevBuyers = (int) ($wPrev60['kpis']['buyers_in_range'] ?? 0);
            $prevRepeat = (int) ($wPrev60['kpis']['repeat_buyers'] ?? 0);
            $prevRepeatRate = (float) ($wPrev60['kpis']['repeat_purchase_rate'] ?? 0.0);
            $r11MinBuyers = (int) ($cfg['r11_min_buyers'] ?? 50);

            if ($curBuyers > 0 && ($curBuyers < $minInfo || ($prevBuyers > 0 && $prevBuyers < $minInfo))) {
                $candidates[] = $this->insufficientCandidate(
                    'R11', 'R11.insufficient', $siteId, $siteName, $currency, min($curBuyers, max($curBuyers, $prevBuyers)),
                    ['from' => $from60, 'to' => $todayStr],
                    ['orders', 'customers'],
                    ['cur_buyers' => $curBuyers, 'prev_buyers' => $prevBuyers],
                    $dqScore
                );
            } elseif ($curBuyers >= $r11MinBuyers && $prevBuyers >= $r11MinBuyers) {
                $dropPp = round($prevRepeatRate - $curRepeatRate, 2);
                if ($dropPp >= (float) ($cfg['r11_drop_pp'] ?? 5.0)) {
                    $z = Stats::twoProportionZTest($curRepeat, $curBuyers, $prevRepeat, $prevBuyers);
                    $aov60 = $w60['kpis']['aov'] ?? null;
                    $money = (! ($w60['mixed_currency'] ?? false) && $aov60 !== null)
                        ? round(($dropPp / 100.0) * $curBuyers * (float) $aov60, 2)
                        : null;
                    $conf = $this->confidenceFor(
                        $curBuyers,
                        $z['p_value'] ?? null,
                        isset($z['z']) ? abs($z['z']) : null,
                        $dqScore
                    );
                    $wilson = Stats::wilsonInterval($curRepeat, $curBuyers);

                    $missingCustPct = 0.0;
                    foreach ($w30['data_quality']['indicators'] ?? [] as $ind) {
                        if ($ind['key'] === 'no_customer_key') {
                            $missingCustPct = (float) $ind['pct'];
                            break;
                        }
                    }

                    $candidates[] = [
                        'rule_code' => 'R11',
                        'subject_key' => 'site',
                        'is_insufficient_data' => false,
                        'template_key' => 'R11.trigger',
                        'severity' => $this->severityFor($money, ! ($w60['mixed_currency'] ?? false) ? ($w60['kpis']['gross_sales'] ?? $gross30Ref) : null),
                        'money_at_stake' => $money,
                        'currency' => $currency,
                        'confidence' => $conf['level'],
                        'params' => [
                            'website' => $siteName,
                            'currency' => $currency,
                            'prev_rate' => number_format($prevRepeatRate, 2),
                            'cur_rate' => number_format($curRepeatRate, 2),
                            'drop_pp' => number_format($dropPp, 2),
                            'target' => number_format(min(100.0, $curRepeatRate + ($dropPp * 0.5)), 2),
                            'prev_buyers' => $prevBuyers,
                            'cur_buyers' => $curBuyers,
                            'missing_cust_pct' => number_format($missingCustPct, 1),
                            'n' => $curBuyers,
                            'dq_score' => $dqScore,
                            'p_value' => $z !== null ? number_format($z['p_value'], 4) : null,
                            'confidence_reason_key' => $conf['reason_key'],
                        ],
                        'evidence' => [
                            'metrics' => [
                                ['label' => 'Current 60d repeat rate', 'value' => "{$curRepeatRate}% ({$curRepeat}/{$curBuyers})"],
                                ['label' => 'Previous 60d repeat rate', 'value' => "{$prevRepeatRate}% ({$prevRepeat}/{$prevBuyers})"],
                                ['label' => 'Wilson 95% CI', 'value' => $wilson !== null ? "{$wilson['low_pct']}% - {$wilson['high_pct']}%" : 'n/a'],
                            ],
                            'source_tables' => ['orders', 'customers'],
                            'window_from' => $from60,
                            'window_to' => $todayStr,
                            'window_days' => 60,
                            'comparison_baseline' => "Previous 60d ({$fromPrev60} to {$toPrev60})",
                            'sample_size' => $curBuyers,
                            'baseline_sample_size' => $prevBuyers,
                            'p_value' => $z['p_value'] ?? null,
                            'z_score' => $z['z'] ?? null,
                            'wilson_ci' => $wilson,
                            'data_quality_score' => $dqScore,
                            'analytics_params' => ['preset' => 'custom', 'from' => $fromPrev60, 'to' => $todayStr, 'website_id' => $siteId],
                        ],
                    ];
                }
            }

            // ---------------------------------------------------------
            // R12: Data-quality blocker (any indicator "bad" > 20%)
            // ---------------------------------------------------------
            $badIndicators = array_values(array_filter(
                $w30['data_quality']['indicators'] ?? [],
                fn (array $ind): bool => ($ind['level'] ?? 'ok') === 'bad'
            ));

            if ((int) $w30['kpis']['orders'] > 0 && $badIndicators !== []) {
                $blockedMap = [
                    'no_items' => 'R06, R07, R08',
                    'status_other' => 'R02, R03, R04',
                    'no_customer_key' => 'R11',
                    'missing_courier' => 'R04',
                    'no_employee' => 'R05',
                    'missing_item_cost' => 'R09',
                    'stale_connected_site' => 'R01-R03',
                ];

                $summaryParts = [];
                $disabledSet = [];
                $missingParts = [];
                foreach ($badIndicators as $ind) {
                    $summaryParts[] = "{$ind['label']} ({$ind['pct']}%)";
                    $missingParts[] = "{$ind['label']}: {$ind['pct']}% — {$ind['message']}";
                    if (isset($blockedMap[$ind['key']])) {
                        $disabledSet[] = $blockedMap[$ind['key']];
                    }
                }
                $conf = $this->confidenceFor((int) $w30['kpis']['orders'], null, null, $dqScore);

                $candidates[] = [
                    'rule_code' => 'R12',
                    'subject_key' => 'dq_blocker',
                    'is_insufficient_data' => false,
                    'template_key' => 'R12.trigger',
                    'severity' => 'low', // Informational blocker per severity rubric
                    'money_at_stake' => null,
                    'currency' => $currency,
                    'confidence' => $conf['level'],
                    'params' => [
                        'website' => $siteName,
                        'currency' => $currency,
                        'dq_score' => $dqScore,
                        'bad_summary' => implode('; ', $summaryParts),
                        'disabled_rules' => implode(', ', array_unique($disabledSet)),
                        'bad_indicators_list' => implode(' | ', $missingParts),
                        'n' => (int) $w30['kpis']['orders'],
                        'p_value' => null,
                        'confidence_reason_key' => $conf['reason_key'],
                    ],
                    'evidence' => [
                        'metrics' => array_map(
                            fn (array $ind): array => [
                                'label' => $ind['label'],
                                'value' => "{$ind['pct']}% ({$ind['numerator']}/{$ind['denominator']}) [BAD]",
                            ],
                            $badIndicators
                        ),
                        'source_tables' => ['orders', 'order_items', 'customers', 'order_assignments', 'websites'],
                        'window_from' => $from30,
                        'window_to' => $todayStr,
                        'window_days' => 30,
                        'comparison_baseline' => 'Data-quality bad threshold > 20%',
                        'sample_size' => (int) $w30['kpis']['orders'],
                        'p_value' => null,
                        'z_score' => null,
                        'wilson_ci' => null,
                        'data_quality_score' => $dqScore,
                        'analytics_params' => ['preset' => 'custom', 'from' => $from30, 'to' => $todayStr, 'website_id' => $siteId],
                    ],
                ];
            }

            // ---------------------------------------------------------
            // R13: Platform-vs-real ROAS gap (T15; coverage-gated)
            // ---------------------------------------------------------
            $mkt = MarketingAnalyticsService::for($tenantId)->report([
                'preset' => 'custom',
                'from' => $from30,
                'to' => $todayStr,
                'website_id' => $siteId,
            ]);

            if (! empty($mkt['has_ad_data']) && ! ($mkt['mixed_currency'] ?? false)) {
                $adSpend = (float) ($mkt['platform_reported']['spend'] ?? 0.0);
                $platRoas = $mkt['platform_reported']['roas'] ?? null;
                $platRev = $mkt['platform_reported']['revenue'] ?? null;
                $realRoas = $mkt['real_orders']['roas'] ?? null;
                $realRev = (float) ($mkt['real_orders']['delivered_revenue'] ?? 0.0);
                $utmCovPct = (float) ($mkt['attribution']['utm_coverage_pct'] ?? 0.0);
                $orders30Cnt = (int) ($mkt['attribution']['orders_total'] ?? 0);

                $minCovPct = (float) ($cfg['r13_min_coverage_pct'] ?? 50.0);
                $minSpend = (float) ($cfg['r13_min_spend'] ?? 1000.0);
                $minRatio = (float) ($cfg['r13_min_gap_ratio'] ?? 1.5);
                $minDiff = (float) ($cfg['r13_min_gap_diff'] ?? 0.5);

                if ($adSpend >= $minSpend && $platRoas !== null) {
                    if ($utmCovPct < $minCovPct) {
                        $candidates[] = $this->insufficientCandidate(
                            'R13',
                            'R13.low_coverage',
                            $siteId,
                            $siteName,
                            $currency,
                            $orders30Cnt,
                            ['from' => $from30, 'to' => $todayStr],
                            ['ad_performance_daily', 'orders'],
                            [
                                'spend' => number_format($adSpend, 2),
                                'plat_roas' => number_format((float) $platRoas, 2),
                                'coverage_pct' => number_format($utmCovPct, 1),
                                'min_coverage_pct' => number_format($minCovPct, 1),
                            ],
                            $dqScore
                        );
                    } elseif (
                        $realRoas !== null
                        && (float) $platRoas >= ((float) $realRoas * $minRatio)
                        && ((float) $platRoas - (float) $realRoas) >= $minDiff
                    ) {
                        $gapMoney = round(max(0.0, ((float) ($platRev ?? 0.0)) - $realRev), 2);
                        $untaggedPct = round(max(0.0, 100.0 - $utmCovPct), 1);
                        $targetRoas = round(max((float) $realRoas + 0.5, ((float) $platRoas + (float) $realRoas) / 2), 2);
                        $conf = $this->confidenceFor($orders30Cnt, null, null, $dqScore);

                        $candidates[] = [
                            'rule_code' => 'R13',
                            'subject_key' => 'roas_gap',
                            'is_insufficient_data' => false,
                            'template_key' => 'R13.trigger',
                            'severity' => $this->severityFor($gapMoney, $gross30Ref),
                            'money_at_stake' => $gapMoney,
                            'currency' => $currency,
                            'confidence' => $conf['level'],
                            'params' => [
                                'website' => $siteName,
                                'currency' => $currency,
                                'days' => 30,
                                'spend' => number_format($adSpend, 2),
                                'plat_roas' => number_format((float) $platRoas, 2),
                                'real_roas' => number_format((float) $realRoas, 2),
                                'plat_rev' => number_format((float) ($platRev ?? 0.0), 2),
                                'real_rev' => number_format($realRev, 2),
                                'coverage_pct' => number_format($utmCovPct, 1),
                                'untagged_pct' => number_format($untaggedPct, 1),
                                'target_roas' => number_format($targetRoas, 2),
                                'n' => $orders30Cnt,
                                'dq_score' => $dqScore,
                                'p_value' => null,
                                'confidence_reason_key' => $conf['reason_key'],
                            ],
                            'evidence' => [
                                'metrics' => [
                                    ['label' => 'Platform ROAS (reported)', 'value' => number_format((float) $platRoas, 2).'x'],
                                    ['label' => 'Real ROAS (delivered)', 'value' => number_format((float) $realRoas, 2).'x'],
                                    ['label' => 'UTM coverage', 'value' => number_format($utmCovPct, 1).'%'],
                                    ['label' => 'Ad spend (30d)', 'value' => "{$currency} ".number_format($adSpend, 2)],
                                ],
                                'source_tables' => ['ad_performance_daily', 'orders'],
                                'window_from' => $from30,
                                'window_to' => $todayStr,
                                'window_days' => 30,
                                'comparison_baseline' => 'Delivered real ROAS vs platform-reported ROAS',
                                'sample_size' => $orders30Cnt,
                                'p_value' => null,
                                'z_score' => null,
                                'wilson_ci' => null,
                                'data_quality_score' => $dqScore,
                                'analytics_params' => ['preset' => 'custom', 'from' => $from30, 'to' => $todayStr, 'website_id' => $siteId],
                            ],
                        ];
                    }
                }
            }

            // Persist / deduplicate all candidates cleanly
            foreach ($candidates as $cand) {
                $outcome = $this->upsertCandidate($tenantId, $siteId, $weekKey, $nowStr, $cand);
                $counts[$outcome]++;
                $counts['findings_total']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array{from: string, to: string}  $window
     * @param  list<string>  $tables
     * @param  array<string, scalar|null>  $extraParams
     * @return array<string, mixed>
     */
    private function insufficientCandidate(
        string $ruleCode,
        string $templateKey,
        int $websiteId,
        string $websiteName,
        string $currency,
        int $sample,
        array $window,
        array $tables,
        array $extraParams,
        int $dqScore
    ): array {
        $params = array_merge([
            'website' => $websiteName,
            'currency' => $currency,
            'n' => $sample,
            'dq_score' => $dqScore,
            'p_value' => null,
            'confidence_reason_key' => 'insufficient_sample',
        ], $extraParams);

        return [
            'rule_code' => $ruleCode,
            'subject_key' => 'insufficient',
            'is_insufficient_data' => true,
            'template_key' => $templateKey,
            'severity' => 'low',
            'money_at_stake' => null,
            'currency' => $currency,
            'confidence' => 'low',
            'params' => $params,
            'evidence' => [
                'metrics' => [
                    ['label' => 'Observed sample (n)', 'value' => (string) $sample],
                    ['label' => 'Minimum required for test', 'value' => '20+'],
                ],
                'source_tables' => $tables,
                'window_from' => $window['from'],
                'window_to' => $window['to'],
                'window_days' => null,
                'comparison_baseline' => 'Minimum sample guard (< 20)',
                'sample_size' => $sample,
                'p_value' => null,
                'z_score' => null,
                'wilson_ci' => null,
                'data_quality_score' => $dqScore,
                'analytics_params' => ['preset' => 'custom', 'from' => $window['from'], 'to' => $window['to'], 'website_id' => $websiteId],
            ],
        ];
    }

    private function severityFor(?float $moneyAtStake, ?float $grossSalesWindow, bool $immediateStockoutTop20 = false): string
    {
        if ($immediateStockoutTop20) {
            return 'critical';
        }

        if ($moneyAtStake === null || $grossSalesWindow === null || $grossSalesWindow <= 0.0) {
            return 'low';
        }

        $pct = ($moneyAtStake / $grossSalesWindow) * 100.0;
        $crit = (float) config('lyp.rnd.severity_critical_pct', 10.0);
        $high = (float) config('lyp.rnd.severity_high_pct', 5.0);
        $med = (float) config('lyp.rnd.severity_medium_pct', 1.0);

        if ($pct >= $crit) {
            return 'critical';
        }
        if ($pct >= $high) {
            return 'high';
        }
        if ($pct >= $med) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array{level: string, reason_key: string}
     */
    private function confidenceFor(int $sample, ?float $pValue, ?float $absZ, int $dqScore): array
    {
        $dqLow = (int) config('lyp.rnd.dq_low_max', 60);
        $dqHigh = (int) config('lyp.rnd.dq_high_min', 85);
        $nHigh = (int) config('lyp.rnd.confidence_high_sample', 100);
        $pHigh = (float) config('lyp.rnd.confidence_high_p', 0.01);
        $zHigh = (float) config('lyp.rnd.confidence_high_z', 2.58);
        $nMed = (int) config('lyp.rnd.confidence_medium_sample', 30);
        $pMed = (float) config('lyp.rnd.confidence_medium_p', 0.05);
        $zMed = (float) config('lyp.rnd.confidence_medium_z', 1.96);

        if ($dqScore < $dqLow) {
            return ['level' => 'low', 'reason_key' => 'dq_low'];
        }

        $sigHigh = ($pValue !== null && $pValue < $pHigh) || ($absZ !== null && $absZ >= $zHigh);
        if ($sample >= $nHigh && $sigHigh && $dqScore >= $dqHigh) {
            return ['level' => 'high', 'reason_key' => 'high_stat'];
        }

        $sigMed = ($pValue !== null && $pValue < $pMed) || ($absZ !== null && $absZ >= $zMed);
        if ($sample >= $nMed && $sigMed) {
            return ['level' => 'medium', 'reason_key' => 'medium_stat'];
        }

        return ['level' => 'low', 'reason_key' => 'no_test_stat'];
    }

    /**
     * @param  array<string, mixed>  $cand
     * @return 'created'|'updated'|'skipped_closed'
     */
    private function upsertCandidate(int $tenantId, int $websiteId, string $weekKey, string $nowStr, array $cand): string
    {
        $fingerprint = hash('sha256', "{$cand['rule_code']}|w{$websiteId}|{$cand['subject_key']}|{$weekKey}");
        $renderedEn = FindingTemplates::render($cand['template_key'], $cand['params'], 'en', false);

        return DB::transaction(function () use ($tenantId, $websiteId, $weekKey, $nowStr, $cand, $fingerprint, $renderedEn): string {
            $existing = ResearchFinding::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! $existing->isOpen()) {
                    $existing->forceFill(['last_seen_at' => $nowStr])->save();

                    return 'skipped_closed';
                }

                $existing->forceFill([
                    'is_insufficient_data' => $cand['is_insufficient_data'],
                    'template_key' => $cand['template_key'],
                    'params' => $cand['params'],
                    'title' => mb_substr($renderedEn['title'], 0, 255),
                    'summary' => $renderedEn['summary'],
                    'evidence' => $cand['evidence'],
                    'severity' => $cand['severity'],
                    'money_at_stake' => $cand['money_at_stake'],
                    'currency' => $cand['currency'],
                    'confidence' => $cand['confidence'],
                    'confidence_reason' => mb_substr($renderedEn['confidence_reason'], 0, 255),
                    'hypotheses' => $renderedEn['hypotheses'],
                    'missing_data' => $renderedEn['missing_data'],
                    'recommended_action' => $renderedEn['action'],
                    'experiment_plan' => $renderedEn['experiment'],
                    'success_metric' => $renderedEn['success_metric'],
                    'last_seen_at' => $nowStr,
                ])->save();

                return 'updated';
            }

            $finding = new ResearchFinding();
            $finding->forceFill([
                'tenant_id' => $tenantId,
                'website_id' => $websiteId,
                'rule_code' => $cand['rule_code'],
                'subject_key' => $cand['subject_key'],
                'week_key' => $weekKey,
                'fingerprint' => $fingerprint,
                'is_insufficient_data' => $cand['is_insufficient_data'],
                'template_key' => $cand['template_key'],
                'params' => $cand['params'],
                'title' => mb_substr($renderedEn['title'], 0, 255),
                'summary' => $renderedEn['summary'],
                'evidence' => $cand['evidence'],
                'severity' => $cand['severity'],
                'money_at_stake' => $cand['money_at_stake'],
                'currency' => $cand['currency'],
                'confidence' => $cand['confidence'],
                'confidence_reason' => mb_substr($renderedEn['confidence_reason'], 0, 255),
                'hypotheses' => $renderedEn['hypotheses'],
                'missing_data' => $renderedEn['missing_data'],
                'recommended_action' => $renderedEn['action'],
                'experiment_plan' => $renderedEn['experiment'],
                'success_metric' => $renderedEn['success_metric'],
                'status' => 'new',
                'first_seen_at' => $nowStr,
                'last_seen_at' => $nowStr,
            ])->save();

            return 'created';
        });
    }
}
