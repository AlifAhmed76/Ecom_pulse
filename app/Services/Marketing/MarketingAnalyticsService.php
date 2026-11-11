<?php

namespace App\Services\Marketing;

use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarketingAnalyticsService
{
    public function __construct(private readonly int $tenantId)
    {
    }

    public static function for(int $tenantId): self
    {
        return new self($tenantId);
    }

    /**
     * @param  array{preset?: string, from?: ?string, to?: ?string, website_id?: int|string|null, platform?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters = []): array
    {
        $resolved = $this->resolveFilters($filters);
        $cacheKey = $this->cacheKey($resolved);

        $cached = TenantCache::get($this->tenantId, $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($resolved['website_id'] !== null && ! $this->websiteBelongsToTenant($resolved['website_id'])) {
            throw new InvalidArgumentException('Selected website does not belong to this tenant.');
        }

        return TenantCache::remember(
            $this->tenantId,
            $cacheKey,
            fn (): array => $this->compute($resolved)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuildDefault(): array
    {
        $resolved = $this->resolveFilters(['preset' => '30']);
        $cacheKey = $this->cacheKey($resolved);

        $data = $this->compute($resolved);
        TenantCache::put($this->tenantId, $cacheKey, $data);

        return $data;
    }

    /**
     * @param  array{preset?: string, from?: ?string, to?: ?string, website_id?: int|string|null, platform?: ?string}  $filters
     * @return array{preset: string, from: string, to: string, from_ts: string, to_ts: string, website_id: ?int, platform: ?string}
     */
    private function resolveFilters(array $filters): array
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $preset = (string) ($filters['preset'] ?? '30');

        $rawSite = $filters['website_id'] ?? null;
        $websiteId = null;
        if ($rawSite !== null && $rawSite !== '') {
            if (is_int($rawSite)) {
                $websiteId = $rawSite;
            } elseif (is_string($rawSite) && ctype_digit($rawSite)) {
                $websiteId = (int) $rawSite;
            } else {
                throw new InvalidArgumentException('Invalid website filter.');
            }
            if ($websiteId <= 0) {
                throw new InvalidArgumentException('Invalid website filter.');
            }
        }

        $rawPlatform = strtolower(trim((string) ($filters['platform'] ?? '')));
        $platform = in_array($rawPlatform, AdMapper::SUPPORTED_PLATFORMS, true) ? $rawPlatform : null;

        $today = now($tz)->endOfDay();
        if ($preset === '7') {
            $from = $today->copy()->subDays(6)->startOfDay();
            $to = $today;
        } elseif ($preset === '90') {
            $from = $today->copy()->subDays(89)->startOfDay();
            $to = $today;
        } elseif ($preset === 'custom' && ! empty($filters['from']) && ! empty($filters['to'])) {
            try {
                $from = Carbon::parse((string) $filters['from'], $tz)->startOfDay();
                $to = Carbon::parse((string) $filters['to'], $tz)->endOfDay();
            } catch (\Throwable) {
                $from = $today->copy()->subDays(29)->startOfDay();
                $to = $today;
                $preset = '30';
            }
            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
        } else {
            $preset = '30';
            $from = $today->copy()->subDays(29)->startOfDay();
            $to = $today;
        }

        return [
            'preset' => $preset,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'from_ts' => $from->format('Y-m-d H:i:s'),
            'to_ts' => $to->format('Y-m-d H:i:s'),
            'website_id' => $websiteId,
            'platform' => $platform,
        ];
    }

    /**
     * @param  array{preset: string, from: string, to: string, from_ts: string, to_ts: string, website_id: ?int, platform: ?string}  $resolved
     */
    private function cacheKey(array $resolved): string
    {
        return sprintf(
            'marketing:%s:%s:%s:%s:%s',
            $resolved['preset'],
            $resolved['from'],
            $resolved['to'],
            $resolved['website_id'] ?? 'all',
            $resolved['platform'] ?? 'all'
        );
    }

    private function websiteBelongsToTenant(int $websiteId): bool
    {
        return DB::table('websites')
            ->where('tenant_id', $this->tenantId)
            ->where('id', $websiteId)
            ->exists();
    }

    /**
     * @param  array{preset: string, from: string, to: string, from_ts: string, to_ts: string, website_id: ?int, platform: ?string}  $resolved
     * @return array<string, mixed>
     */
    private function compute(array $resolved): array
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $siteId = $resolved['website_id'];
        $platform = $resolved['platform'];

        // 1. Platform-reported daily rows strictly scoped by tenant (and optional website/platform)
        $adRows = DB::table('ad_performance_daily')
            ->where('tenant_id', $this->tenantId)
            ->when($siteId !== null, function ($q) use ($siteId): void {
                // Include rows tied to this site or workspace-wide ad imports (website_id NULL)
                $q->where(fn ($sub) => $sub->where('website_id', $siteId)->orWhereNull('website_id'));
            })
            ->when($platform !== null, fn ($q) => $q->where('platform', $platform))
            ->whereBetween('date', [$resolved['from'], $resolved['to']])
            ->orderBy('campaign')
            ->get([
                'platform',
                'campaign',
                'date',
                'spend',
                'impressions',
                'clicks',
                'platform_conversions',
                'platform_revenue',
            ]);

        // 2. Real store orders in the EXACT same inclusive ordered_at range
        $orders = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->when($siteId !== null, fn ($q) => $q->where('website_id', $siteId))
            ->whereBetween('ordered_at', [$resolved['from_ts'], $resolved['to_ts']])
            ->get(['status', 'total', 'currency', 'utm_campaign']);

        $currencies = [];
        $ordersTotal = 0;
        $ordersWithUtm = 0;
        $ordersByCampaignKey = [];

        foreach ($orders as $order) {
            $ordersTotal++;
            $cur = strtoupper(trim((string) ($order->currency ?? '')));
            if ($cur !== '') {
                $currencies[$cur] = true;
            }

            $utm = trim((string) ($order->utm_campaign ?? ''));
            if ($utm === '') {
                continue;
            }

            $ordersWithUtm++;
            $key = mb_strtolower($utm, 'UTF-8');
            if (! isset($ordersByCampaignKey[$key])) {
                $ordersByCampaignKey[$key] = [
                    'raw_label' => $utm,
                    'total_orders' => 0,
                    'delivered_orders' => 0,
                    'delivered_revenue' => 0.0,
                    'non_cancelled_orders' => 0,
                    'gross_sales' => 0.0,
                    'cancelled_orders' => 0,
                    'returned_orders' => 0,
                ];
            }

            $amt = (float) ($order->total ?? 0.0);
            $status = (string) $order->status;

            $ordersByCampaignKey[$key]['total_orders']++;
            if ($status !== 'cancelled') {
                $ordersByCampaignKey[$key]['non_cancelled_orders']++;
                $ordersByCampaignKey[$key]['gross_sales'] += $amt;
            }
            if ($status === 'delivered') {
                $ordersByCampaignKey[$key]['delivered_orders']++;
                $ordersByCampaignKey[$key]['delivered_revenue'] += $amt;
            } elseif ($status === 'cancelled') {
                $ordersByCampaignKey[$key]['cancelled_orders']++;
            } elseif ($status === 'returned') {
                $ordersByCampaignKey[$key]['returned_orders']++;
            }
        }

        // Group ad spend per (campaignKey, platform) and track campaign keys across imported ads
        $campaignAgg = [];
        $importedCampaignKeys = [];
        $totalSpend = 0.0;
        $totalImpressions = 0;
        $totalClicks = 0;
        $totalPlatformConversions = 0.0;
        $hasPlatformRevenue = false;
        $totalPlatformRevenue = 0.0;

        foreach ($adRows as $row) {
            $campName = trim((string) $row->campaign);
            $campKey = mb_strtolower($campName, 'UTF-8');
            $plat = (string) $row->platform;
            $bucketKey = $plat.'|'.$campKey;
            $importedCampaignKeys[$campKey] = true;

            $spend = (float) $row->spend;
            $imp = (int) $row->impressions;
            $clk = (int) $row->clicks;
            $pConv = (float) $row->platform_conversions;
            $pRev = $row->platform_revenue !== null ? (float) $row->platform_revenue : null;

            $totalSpend += $spend;
            $totalImpressions += $imp;
            $totalClicks += $clk;
            $totalPlatformConversions += $pConv;
            if ($pRev !== null) {
                $hasPlatformRevenue = true;
                $totalPlatformRevenue += $pRev;
            }

            if (! isset($campaignAgg[$bucketKey])) {
                $campaignAgg[$bucketKey] = [
                    'platform' => $plat,
                    'campaign' => $campName,
                    'campaign_key' => $campKey,
                    'spend' => 0.0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'platform_conversions' => 0.0,
                    'platform_revenue' => null,
                ];
            }

            $campaignAgg[$bucketKey]['spend'] += $spend;
            $campaignAgg[$bucketKey]['impressions'] += $imp;
            $campaignAgg[$bucketKey]['clicks'] += $clk;
            $campaignAgg[$bucketKey]['platform_conversions'] += $pConv;
            if ($pRev !== null) {
                $campaignAgg[$bucketKey]['platform_revenue'] = (float) ($campaignAgg[$bucketKey]['platform_revenue'] ?? 0.0) + $pRev;
            }
        }

        // Compute matched real-order aggregate across imported campaign keys ONCE (no double-counting if same utm runs on 2 platforms, though rare)
        $matchedOrdersCount = 0;
        $matchedDeliveredOrders = 0;
        $matchedDeliveredRevenue = 0.0;
        $matchedGrossSales = 0.0;
        $unmatchedUtmOrdersCount = 0;
        $unmatchedUtmCampaigns = [];

        foreach ($ordersByCampaignKey as $cKey => $ordBucket) {
            if (isset($importedCampaignKeys[$cKey])) {
                $matchedOrdersCount += $ordBucket['total_orders'];
                $matchedDeliveredOrders += $ordBucket['delivered_orders'];
                $matchedDeliveredRevenue += $ordBucket['delivered_revenue'];
                $matchedGrossSales += $ordBucket['gross_sales'];
            } else {
                $unmatchedUtmOrdersCount += $ordBucket['total_orders'];
                $unmatchedUtmCampaigns[] = [
                    'utm_campaign' => $ordBucket['raw_label'],
                    'orders' => $ordBucket['total_orders'],
                    'delivered_orders' => $ordBucket['delivered_orders'],
                    'delivered_revenue' => round($ordBucket['delivered_revenue'], 2),
                ];
            }
        }

        // Build per-campaign rows with strictly separate platform-reported vs real-order metrics
        $campaignRows = [];
        foreach ($campaignAgg as $c) {
            $ord = $ordersByCampaignKey[$c['campaign_key']] ?? [
                'total_orders' => 0,
                'delivered_orders' => 0,
                'delivered_revenue' => 0.0,
                'non_cancelled_orders' => 0,
                'gross_sales' => 0.0,
                'cancelled_orders' => 0,
                'returned_orders' => 0,
            ];

            $spend = round($c['spend'], 2);
            $pConv = round($c['platform_conversions'], 2);
            $pRev = $c['platform_revenue'] !== null ? round((float) $c['platform_revenue'], 2) : null;
            $realDelivRev = round($ord['delivered_revenue'], 2);
            $realDelivCount = $ord['delivered_orders'];

            $platformCpa = $pConv > 0.0 ? round($spend / $pConv, 2) : null;
            $platformRoas = ($spend > 0.0 && $pRev !== null) ? round($pRev / $spend, 2) : null;

            $realCpa = $realDelivCount > 0 ? round($spend / $realDelivCount, 2) : null;
            $realRoas = $spend > 0.0 ? round($realDelivRev / $spend, 2) : null;

            $campaignRows[] = [
                'platform' => $c['platform'],
                'campaign' => $c['campaign'],
                'campaign_key' => $c['campaign_key'],
                'spend' => $spend,
                'impressions' => $c['impressions'],
                'clicks' => $c['clicks'],
                'ctr_pct' => $c['impressions'] > 0 ? round(($c['clicks'] / $c['impressions']) * 100, 2) : null,
                'cpc' => $c['clicks'] > 0 ? round($spend / $c['clicks'], 2) : null,
                'platform_conversions' => $pConv,
                'platform_revenue' => $pRev,
                'platform_cpa' => $platformCpa,
                'platform_roas' => $platformRoas,
                'real_total_orders' => $ord['total_orders'],
                'real_delivered_orders' => $realDelivCount,
                'real_delivered_revenue' => $realDelivRev,
                'real_gross_sales' => round($ord['gross_sales'], 2),
                'real_cpa' => $realCpa,
                'real_roas' => $realRoas,
            ];
        }

        usort($campaignRows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend'] ?: strcmp($a['campaign'], $b['campaign']));

        $currencyList = array_keys($currencies);
        $mixedCurrency = count($currencyList) > 1;
        $siteCurrency = 'BDT';
        if ($siteId !== null) {
            $siteCur = DB::table('websites')->where('id', $siteId)->value('currency');
            if (is_string($siteCur) && $siteCur !== '') {
                $siteCurrency = strtoupper($siteCur);
            }
        } elseif (count($currencyList) === 1) {
            $siteCurrency = $currencyList[0];
        }

        $totalSpendRounded = round($totalSpend, 2);
        $totalPlatformConvRounded = round($totalPlatformConversions, 2);
        $totalPlatformRevRounded = $hasPlatformRevenue ? round($totalPlatformRevenue, 2) : null;
        $matchedDeliveredRevRounded = round($matchedDeliveredRevenue, 2);

        $utmCoveragePct = $ordersTotal > 0 ? round(($ordersWithUtm / $ordersTotal) * 100, 1) : 0.0;
        $campaignMatchOfUtmPct = $ordersWithUtm > 0 ? round(($matchedOrdersCount / $ordersWithUtm) * 100, 1) : 0.0;
        $campaignMatchOfAllOrdersPct = $ordersTotal > 0 ? round(($matchedOrdersCount / $ordersTotal) * 100, 1) : 0.0;

        $lastAdUpdated = DB::table('ad_performance_daily')
            ->where('tenant_id', $this->tenantId)
            ->max('updated_at');

        return [
            'filters' => [
                'preset' => $resolved['preset'],
                'from' => $resolved['from'],
                'to' => $resolved['to'],
                'website_id' => $siteId,
                'platform' => $platform,
            ],
            'currency' => $siteCurrency,
            'currencies' => $currencyList,
            'mixed_currency' => $mixedCurrency,
            'computed_at' => now($tz)->format('Y-m-d H:i:s'),
            'ad_data_updated_at' => $lastAdUpdated,
            'has_ad_data' => count($campaignRows) > 0,
            'platform_reported' => [
                'spend' => $totalSpendRounded,
                'impressions' => $totalImpressions,
                'clicks' => $totalClicks,
                'ctr_pct' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : null,
                'cpc' => $totalClicks > 0 ? round($totalSpendRounded / $totalClicks, 2) : null,
                'conversions' => $totalPlatformConvRounded,
                'cpa' => $totalPlatformConvRounded > 0.0 ? round($totalSpendRounded / $totalPlatformConvRounded, 2) : null,
                'revenue' => $totalPlatformRevRounded,
                'roas' => ($totalSpendRounded > 0.0 && $totalPlatformRevRounded !== null)
                    ? round($totalPlatformRevRounded / $totalSpendRounded, 2)
                    : null,
            ],
            'real_orders' => [
                'matched_orders' => $matchedOrdersCount,
                'delivered_orders' => $matchedDeliveredOrders,
                'delivered_revenue' => $mixedCurrency ? null : $matchedDeliveredRevRounded,
                'gross_sales' => $mixedCurrency ? null : round($matchedGrossSales, 2),
                'cpa' => $matchedDeliveredOrders > 0 ? round($totalSpendRounded / $matchedDeliveredOrders, 2) : null,
                'roas' => (! $mixedCurrency && $totalSpendRounded > 0.0)
                    ? round($matchedDeliveredRevRounded / $totalSpendRounded, 2)
                    : null,
            ],
            'attribution' => [
                'orders_total' => $ordersTotal,
                'orders_with_utm' => $ordersWithUtm,
                'orders_without_utm' => max(0, $ordersTotal - $ordersWithUtm),
                'matched_orders' => $matchedOrdersCount,
                'unmatched_utm_orders' => $unmatchedUtmOrdersCount,
                'utm_coverage_pct' => $utmCoveragePct,
                'campaign_match_of_utm_pct' => $campaignMatchOfUtmPct,
                'campaign_match_of_all_orders_pct' => $campaignMatchOfAllOrdersPct,
                'unmatched_utm_campaigns' => $unmatchedUtmCampaigns,
                'note' => 'Matching compares normalized orders.utm_campaign strings strictly against imported campaign names within the same date range. Direct/WhatsApp/phone orders without UTM tags and cross-device view-through conversions are excluded from real-order attribution. Delivered order revenue is pre-COGS, pre-shipping, and pre-return-fee (never net profit).',
            ],
            'campaigns' => $campaignRows,
        ];
    }
}
