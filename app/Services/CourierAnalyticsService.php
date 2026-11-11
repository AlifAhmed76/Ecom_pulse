<?php

namespace App\Services;

use App\Support\StatusMapper;
use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class CourierAnalyticsService
{
    public const COURIER_MIN_SAMPLE = 20;

    public const ZTEST_MIN_SAMPLE = 30;

    public const ZTEST_ALPHA = 0.05;

    public function __construct(private readonly int $tenantId)
    {
    }

    public static function for(int $tenantId): self
    {
        return new self($tenantId);
    }

    /**
     * @param  array{preset?: string, from?: ?string, to?: ?string, website_id?: int|string|null}  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters = []): array
    {
        $resolved = $this->resolveDateRange($filters);
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
            fn (): array => $this->compute($resolved, $filters)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuildDefault(): array
    {
        $resolved = $this->resolveDateRange(['preset' => '30']);
        $cacheKey = $this->cacheKey($resolved);

        $data = $this->compute($resolved, ['preset' => '30']);
        TenantCache::put($this->tenantId, $cacheKey, $data);

        return $data;
    }

    /**
     * @param  array{preset?: string, from?: ?string, to?: ?string, website_id?: int|string|null}  $filters
     * @return array{preset: string, from: string, to: string, from_ts: string, to_ts: string, website_id: ?int}
     */
    private function resolveDateRange(array $filters): array
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

        $fromDt = $this->parseDateBoundary($filters['from'] ?? null, $tz, false);
        $toDt = $this->parseDateBoundary($filters['to'] ?? null, $tz, true);

        if ($preset === 'custom' && $fromDt !== null && $toDt !== null) {
            if ($fromDt->gt($toDt)) {
                [$fromDt, $toDt] = [$toDt->copy()->startOfDay(), $fromDt->copy()->endOfDay()];
            }
        } else {
            $days = match ($preset) {
                '7' => 7,
                '90' => 90,
                default => 30,
            };
            $preset = (string) $days;
            $toDt = now($tz)->endOfDay();
            $fromDt = now($tz)->subDays($days - 1)->startOfDay();
        }

        return [
            'preset' => $preset,
            'from' => $fromDt->format('Y-m-d'),
            'to' => $toDt->format('Y-m-d'),
            'from_ts' => $fromDt->format('Y-m-d H:i:s'),
            'to_ts' => $toDt->format('Y-m-d H:i:s'),
            'website_id' => $websiteId,
        ];
    }

    private function parseDateBoundary(mixed $raw, string $tz, bool $endOfDay): ?Carbon
    {
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return null;
        }

        try {
            $dt = Carbon::createFromFormat('!Y-m-d', $trimmed, $tz);
        } catch (Throwable) {
            return null;
        }

        $errors = Carbon::getLastErrors();
        if ($dt === false || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            return null;
        }

        return $endOfDay ? $dt->endOfDay() : $dt->startOfDay();
    }

    private function websiteBelongsToTenant(int $websiteId): bool
    {
        return DB::table('websites')
            ->where('tenant_id', $this->tenantId)
            ->where('id', $websiteId)
            ->exists();
    }

    /**
     * @param  array{preset: string, from: string, to: string, from_ts: string, to_ts: string, website_id: ?int}  $resolved
     */
    private function cacheKey(array $resolved): string
    {
        return 'courier_loss:'.md5((string) json_encode([
            'from' => $resolved['from'],
            'to' => $resolved['to'],
            'website_id' => $resolved['website_id'],
        ]));
    }

    /**
     * @param  array{preset: string, from: string, to: string, from_ts: string, to_ts: string, website_id: ?int}  $resolved
     * @param  array<string, mixed>  $rawFilters
     * @return array<string, mixed>
     */
    private function compute(array $resolved, array $rawFilters): array
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $now = now($tz);
        $fromTs = $resolved['from_ts'];
        $toTs = $resolved['to_ts'];
        $websiteId = $resolved['website_id'];

        $analyticsData = AnalyticsService::for($this->tenantId)->analytics($rawFilters);
        $websites = $analyticsData['websites'];
        $currency = $analyticsData['currency'];
        $mixedCurrency = (bool) $analyticsData['mixed_currency'];
        $currencyWarning = $analyticsData['currency_warning'];
        $dataQuality = $analyticsData['data_quality'];
        $websiteName = $analyticsData['filters']['website_name'] ?? null;

        // 1. Orders headline for money lost ("estimated sales not realised", never "profit lost")
        $baseOrders = DB::table('orders as o')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs);

        if ($websiteId !== null) {
            $baseOrders->where('o.website_id', $websiteId);
        }

        $headlineRow = (clone $baseOrders)->selectRaw("
            COUNT(*) AS orders_count,
            SUM(o.total) AS all_orders_value,
            SUM(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE 0 END) AS gross_sales,
            SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN o.status = 'cancelled' THEN o.total ELSE 0 END) AS cancelled_value,
            SUM(CASE WHEN o.status = 'returned' THEN 1 ELSE 0 END) AS returned_count,
            SUM(CASE WHEN o.status = 'returned' THEN o.total ELSE 0 END) AS returned_value
        ")->first();

        $allOrdersValueFloat = (float) ($headlineRow->all_orders_value ?? 0);
        $cancelledFloat = (float) ($headlineRow->cancelled_value ?? 0);
        $returnedFloat = (float) ($headlineRow->returned_value ?? 0);
        $totalLostFloat = $cancelledFloat + $returnedFloat;
        $lostSharePct = $allOrdersValueFloat > 0
            ? round(($totalLostFloat / $allOrdersValueFloat) * 100, 2)
            : 0.0;

        // Courier shipments in range (matched use order ordered_at, unmatched use shipment timestamps)
        $shipmentsQuery = DB::table('courier_shipments as cs')
            ->leftJoin('orders as o', function ($join): void {
                $join->on('o.id', '=', 'cs.order_id')
                    ->whereColumn('o.tenant_id', 'cs.tenant_id');
            })
            ->where('cs.tenant_id', $this->tenantId)
            ->whereRaw('COALESCE(o.ordered_at, cs.returned_at, cs.delivered_at, cs.shipped_at, cs.created_at) >= ?', [$fromTs])
            ->whereRaw('COALESCE(o.ordered_at, cs.returned_at, cs.delivered_at, cs.shipped_at, cs.created_at) <= ?', [$toTs]);

        if ($websiteId !== null) {
            $shipmentsQuery->whereRaw('COALESCE(o.website_id, cs.website_id) = ?', [$websiteId]);
        }

        $shipmentRows = $shipmentsQuery->get([
            'cs.id',
            'cs.courier',
            'cs.area',
            'cs.status',
            'cs.cod_amount',
            'cs.collected_amount',
            'cs.delivery_fee',
            'cs.return_fee',
            'cs.shipped_at',
            'cs.delivered_at',
            'cs.matched',
            DB::raw('o.city AS order_city'),
        ]);

        // Return handling cost from returned courier shipments when fee data exists
        $returnedShipmentsWithFees = 0;
        $returnHandlingCostFloat = 0.0;
        $totalShipmentsCount = $shipmentRows->count();
        $matchedShipmentsCount = 0;

        foreach ($shipmentRows as $sr) {
            if ((bool) $sr->matched) {
                $matchedShipmentsCount++;
            }
            if ($sr->status === 'returned' && ($sr->delivery_fee !== null || $sr->return_fee !== null)) {
                $returnedShipmentsWithFees++;
                $returnHandlingCostFloat += (float) ($sr->delivery_fee ?? 0) + (float) ($sr->return_fee ?? 0);
            }
        }

        $hasReturnFeeData = $returnedShipmentsWithFees > 0;
        $estimatedReturnHandlingCost = (! $mixedCurrency && $hasReturnFeeData)
            ? round($returnHandlingCostFloat, 2)
            : null;
        $shipmentCoveragePct = $totalShipmentsCount > 0
            ? round(($matchedShipmentsCount / $totalShipmentsCount) * 100, 2)
            : null;

        $lossHeadline = [
            'orders_count' => (int) ($headlineRow->orders_count ?? 0),
            'cancelled_orders' => (int) ($headlineRow->cancelled_count ?? 0),
            'returned_orders' => (int) ($headlineRow->returned_count ?? 0),
            'all_orders_value' => $mixedCurrency ? null : round($allOrdersValueFloat, 2),
            'gross_sales' => $mixedCurrency ? null : round((float) ($headlineRow->gross_sales ?? 0), 2),
            'cancelled_value' => $mixedCurrency ? null : round($cancelledFloat, 2),
            'returned_value' => $mixedCurrency ? null : round($returnedFloat, 2),
            'total_lost_value' => $mixedCurrency ? null : round($totalLostFloat, 2),
            'lost_share_pct' => $lostSharePct,
            'has_return_fee_data' => $hasReturnFeeData,
            'estimated_return_handling_cost' => $estimatedReturnHandlingCost,
        ];

        // 2. Loss breakdowns: by courier, area/city, employee, product, cancel_reason
        $lossSplits = [
            'by_courier' => $this->lostSplitByDimension(clone $baseOrders, "COALESCE(NULLIF(o.courier, ''), 'Unassigned')", 'label', $mixedCurrency),
            'by_area' => $this->lostSplitByDimension(clone $baseOrders, "COALESCE(NULLIF(o.city, ''), 'Unknown area')", 'label', $mixedCurrency),
            'by_employee' => $this->lostSplitByEmployee($fromTs, $toTs, $websiteId, $mixedCurrency),
            'by_product' => $this->lostSplitByProduct($fromTs, $toTs, $websiteId, $mixedCurrency),
            'by_cancel_reason' => $this->lostSplitByCancelReason(clone $baseOrders, $mixedCurrency),
        ];

        // 3. Courier & Area scorecards with >= 20 minimum sample and two-proportion z-test (n >= 30, p < 0.05)
        if ($totalShipmentsCount > 0) {
            $scorecardSource = 'courier_shipments';
            $courierScorecard = $this->buildScorecardFromShipments(
                $shipmentRows->all(),
                fn (object $r): string => trim((string) $r->courier) !== '' ? trim((string) $r->courier) : 'Unknown',
                $mixedCurrency,
                $tz
            );
            $areaScorecard = $this->buildScorecardFromShipments(
                $shipmentRows->all(),
                function (object $r): string {
                    $area = trim((string) ($r->area ?? ''));
                    if ($area !== '') {
                        return $area;
                    }
                    $city = trim((string) ($r->order_city ?? ''));

                    return $city !== '' ? $city : 'Unknown area';
                },
                $mixedCurrency,
                $tz
            );
        } else {
            $scorecardSource = 'orders';
            $orderShipRows = (clone $baseOrders)
                ->whereIn('o.status', ['shipped', 'delivered', 'returned'])
                ->get(['o.courier', 'o.city', 'o.status']);

            $courierScorecard = $this->buildScorecardFromOrders(
                $orderShipRows->all(),
                fn (object $r): string => trim((string) ($r->courier ?? '')) !== '' ? trim((string) $r->courier) : 'Unassigned'
            );
            $areaScorecard = $this->buildScorecardFromOrders(
                $orderShipRows->all(),
                fn (object $r): string => trim((string) ($r->city ?? '')) !== '' ? trim((string) $r->city) : 'Unknown area'
            );
        }

        return [
            'computed_at' => $now->toIso8601String(),
            'updated_hhmm' => $now->format('H:i'),
            'ttl' => TenantCache::ttl(),
            'filters' => [
                'preset' => $resolved['preset'],
                'from' => $resolved['from'],
                'to' => $resolved['to'],
                'website_id' => $websiteId,
                'website_name' => $websiteName,
            ],
            'websites' => $websites,
            'currency' => $currency,
            'mixed_currency' => $mixedCurrency,
            'currency_warning' => $currencyWarning,
            'data_quality' => $dataQuality,
            'coverage' => [
                'total_shipments' => $totalShipmentsCount,
                'matched_shipments' => $matchedShipmentsCount,
                'unmatched_shipments' => $totalShipmentsCount - $matchedShipmentsCount,
                'coverage_pct' => $shipmentCoveragePct,
            ],
            'loss_headline' => $lossHeadline,
            'loss_splits' => $lossSplits,
            'scorecard_source' => $scorecardSource,
            'courier_scorecard' => $courierScorecard,
            'area_scorecard' => $areaScorecard,
        ];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $baseOrders
     * @return list<array<string, mixed>>
     */
    private function lostSplitByDimension($baseOrders, string $expr, string $key, bool $mixedCurrency): array
    {
        $rows = $baseOrders
            ->whereIn('o.status', ['cancelled', 'returned'])
            ->selectRaw("
                {$expr} AS grp_label,
                SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                SUM(CASE WHEN o.status = 'returned' THEN 1 ELSE 0 END) AS returned_orders,
                SUM(CASE WHEN o.status = 'cancelled' THEN o.total ELSE 0 END) AS cancelled_value,
                SUM(CASE WHEN o.status = 'returned' THEN o.total ELSE 0 END) AS returned_value,
                SUM(o.total) AS total_lost_value
            ")
            ->groupByRaw($expr)
            ->orderByDesc('total_lost_value')
            ->orderBy('grp_label')
            ->limit(100)
            ->get();

        return $rows->map(fn (object $r): array => [
            $key => (string) $r->grp_label,
            'cancelled_orders' => (int) $r->cancelled_orders,
            'returned_orders' => (int) $r->returned_orders,
            'cancelled_value' => $mixedCurrency ? null : round((float) $r->cancelled_value, 2),
            'returned_value' => $mixedCurrency ? null : round((float) $r->returned_value, 2),
            'total_lost_value' => $mixedCurrency ? null : round((float) $r->total_lost_value, 2),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lostSplitByEmployee(string $fromTs, string $toTs, ?int $websiteId, bool $mixedCurrency): array
    {
        $q = DB::table('orders as o')
            ->leftJoin('order_assignments as oa', 'oa.order_id', '=', 'o.id')
            ->leftJoin('employees as e', function ($join): void {
                $join->on('e.id', '=', 'oa.employee_id')
                    ->whereColumn('e.tenant_id', 'o.tenant_id');
            })
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs)
            ->whereIn('o.status', ['cancelled', 'returned']);

        if ($websiteId !== null) {
            $q->where('o.website_id', $websiteId);
        }

        $rows = $q->selectRaw("
                COALESCE(e.name, 'Unassigned') AS emp_name,
                SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                SUM(CASE WHEN o.status = 'returned' THEN 1 ELSE 0 END) AS returned_orders,
                SUM(CASE WHEN o.status = 'cancelled' THEN o.total ELSE 0 END) AS cancelled_value,
                SUM(CASE WHEN o.status = 'returned' THEN o.total ELSE 0 END) AS returned_value,
                SUM(o.total) AS total_lost_value
            ")
            ->groupByRaw("COALESCE(e.name, 'Unassigned')")
            ->orderByDesc('total_lost_value')
            ->orderBy('emp_name')
            ->limit(100)
            ->get();

        return $rows->map(fn (object $r): array => [
            'label' => (string) $r->emp_name,
            'cancelled_orders' => (int) $r->cancelled_orders,
            'returned_orders' => (int) $r->returned_orders,
            'cancelled_value' => $mixedCurrency ? null : round((float) $r->cancelled_value, 2),
            'returned_value' => $mixedCurrency ? null : round((float) $r->returned_value, 2),
            'total_lost_value' => $mixedCurrency ? null : round((float) $r->total_lost_value, 2),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lostSplitByProduct(string $fromTs, string $toTs, ?int $websiteId, bool $mixedCurrency): array
    {
        $q = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs)
            ->whereIn('o.status', ['cancelled', 'returned']);

        if ($websiteId !== null) {
            $q->where('o.website_id', $websiteId);
        }

        $rows = $q->selectRaw("
                oi.product_name,
                oi.sku,
                SUM(CASE WHEN o.status = 'cancelled' THEN oi.quantity ELSE 0 END) AS cancelled_units,
                SUM(CASE WHEN o.status = 'returned' THEN oi.quantity ELSE 0 END) AS returned_units,
                SUM(CASE WHEN o.status = 'cancelled' THEN oi.line_total ELSE 0 END) AS cancelled_value,
                SUM(CASE WHEN o.status = 'returned' THEN oi.line_total ELSE 0 END) AS returned_value,
                SUM(oi.line_total) AS total_lost_value
            ")
            ->groupBy('oi.product_name', 'oi.sku')
            ->orderByDesc('total_lost_value')
            ->orderBy('oi.product_name')
            ->limit(100)
            ->get();

        return $rows->map(fn (object $r): array => [
            'name' => (string) $r->product_name,
            'sku' => $r->sku !== null ? (string) $r->sku : null,
            'cancelled_units' => (int) $r->cancelled_units,
            'returned_units' => (int) $r->returned_units,
            'cancelled_value' => $mixedCurrency ? null : round((float) $r->cancelled_value, 2),
            'returned_value' => $mixedCurrency ? null : round((float) $r->returned_value, 2),
            'total_lost_value' => $mixedCurrency ? null : round((float) $r->total_lost_value, 2),
        ])->all();
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $baseOrders
     * @return list<array<string, mixed>>
     */
    private function lostSplitByCancelReason($baseOrders, bool $mixedCurrency): array
    {
        $rows = $baseOrders
            ->where('o.status', 'cancelled')
            ->selectRaw("
                COALESCE(NULLIF(o.cancel_reason, ''), 'Unspecified') AS reason,
                COUNT(*) AS orders_count,
                SUM(o.total) AS cancelled_value
            ")
            ->groupByRaw("COALESCE(NULLIF(o.cancel_reason, ''), 'Unspecified')")
            ->orderByDesc('cancelled_value')
            ->orderByDesc('orders_count')
            ->limit(100)
            ->get();

        return $rows->map(fn (object $r): array => [
            'reason' => (string) $r->reason,
            'orders_count' => (int) $r->orders_count,
            'cancelled_value' => $mixedCurrency ? null : round((float) $r->cancelled_value, 2),
        ])->all();
    }

    /**
     * @param  list<object>  $shipmentRows
     * @param  callable(object): string  $groupKeyFn
     * @return list<array<string, mixed>>
     */
    private function buildScorecardFromShipments(array $shipmentRows, callable $groupKeyFn, bool $mixedCurrency, string $tz): array
    {
        $groups = [];

        foreach ($shipmentRows as $row) {
            $key = $groupKeyFn($row);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $key,
                    'shipments' => 0,
                    'delivered' => 0,
                    'returned' => 0,
                    'in_transit' => 0,
                    'cod_expected_sum' => 0.0,
                    'cod_expected_n' => 0,
                    'cod_collected_sum' => 0.0,
                    'cod_collected_n' => 0,
                    'delivery_hours_sum' => 0.0,
                    'delivery_hours_n' => 0,
                ];
            }

            $g = &$groups[$key];
            $g['shipments']++;

            if ($row->status === 'delivered') {
                $g['delivered']++;
            } elseif ($row->status === 'returned') {
                $g['returned']++;
            } elseif ($row->status === 'shipped') {
                $g['in_transit']++;
            }

            if ($row->cod_amount !== null) {
                $g['cod_expected_sum'] += (float) $row->cod_amount;
                $g['cod_expected_n']++;
            }
            if ($row->collected_amount !== null) {
                $g['cod_collected_sum'] += (float) $row->collected_amount;
                $g['cod_collected_n']++;
            }

            if ($row->shipped_at !== null && $row->delivered_at !== null) {
                try {
                    $sAt = Carbon::parse((string) $row->shipped_at, $tz);
                    $dAt = Carbon::parse((string) $row->delivered_at, $tz);
                    if ($dAt->gte($sAt)) {
                        $g['delivery_hours_sum'] += ($dAt->getTimestamp() - $sAt->getTimestamp()) / 3600.0;
                        $g['delivery_hours_n']++;
                    }
                } catch (Throwable) {
                    // Ignore unparseable timestamps
                }
            }
        }
        unset($g);

        $normalized = [];
        foreach ($groups as $g) {
            $codExp = (! $mixedCurrency && $g['cod_expected_n'] > 0) ? round($g['cod_expected_sum'], 2) : null;
            $codCol = (! $mixedCurrency && $g['cod_collected_n'] > 0) ? round($g['cod_collected_sum'], 2) : null;
            $avgHrs = $g['delivery_hours_n'] > 0 ? round($g['delivery_hours_sum'] / $g['delivery_hours_n'], 1) : null;

            $normalized[] = [
                'name' => $g['name'],
                'shipments' => $g['shipments'],
                'delivered' => $g['delivered'],
                'returned' => $g['returned'],
                'in_transit' => $g['in_transit'],
                'cod_expected' => $codExp,
                'cod_collected' => $codCol,
                'cod_gap' => ($codExp !== null && $codCol !== null) ? round($codExp - $codCol, 2) : null,
                'avg_delivery_hours' => $avgHrs,
            ];
        }

        return $this->finalizeScorecard($normalized);
    }

    /**
     * @param  list<object>  $orderRows
     * @param  callable(object): string  $groupKeyFn
     * @return list<array<string, mixed>>
     */
    private function buildScorecardFromOrders(array $orderRows, callable $groupKeyFn): array
    {
        $groups = [];
        foreach ($orderRows as $row) {
            $key = $groupKeyFn($row);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $key,
                    'shipments' => 0,
                    'delivered' => 0,
                    'returned' => 0,
                    'in_transit' => 0,
                    'cod_expected' => null,
                    'cod_collected' => null,
                    'cod_gap' => null,
                    'avg_delivery_hours' => null,
                ];
            }
            $groups[$key]['shipments']++;
            if ($row->status === 'delivered') {
                $groups[$key]['delivered']++;
            } elseif ($row->status === 'returned') {
                $groups[$key]['returned']++;
            } elseif ($row->status === 'shipped') {
                $groups[$key]['in_transit']++;
            }
        }

        return $this->finalizeScorecard(array_values($groups));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function finalizeScorecard(array $rows): array
    {
        $totalDeliveredAll = 0;
        $totalCompletedAll = 0;
        foreach ($rows as $r) {
            $totalDeliveredAll += $r['delivered'];
            $totalCompletedAll += ($r['delivered'] + $r['returned']);
        }

        foreach ($rows as &$r) {
            $completedN = $r['delivered'] + $r['returned'];
            $insufficient = $r['shipments'] < self::COURIER_MIN_SAMPLE || $completedN === 0;
            $r['completed_n'] = $completedN;
            $r['insufficient_data'] = $insufficient;
            $r['success_rate'] = $insufficient ? null : round(($r['delivered'] / $completedN) * 100, 2);

            $otherDel = $totalDeliveredAll - $r['delivered'];
            $otherComp = $totalCompletedAll - $completedN;

            $r['statistically_unusual'] = false;
            $r['outlier_label'] = null;
            $r['outlier_direction'] = null;
            $r['z_score'] = null;
            $r['p_value'] = null;
            $r['other_avg_rate'] = $otherComp > 0 ? round(($otherDel / $otherComp) * 100, 2) : null;

            if ($completedN >= self::ZTEST_MIN_SAMPLE && $otherComp > 0) {
                $p1 = $r['delivered'] / $completedN;
                $p2 = $otherDel / $otherComp;
                $pPool = ($r['delivered'] + $otherDel) / ($completedN + $otherComp);

                if ($pPool > 0.0 && $pPool < 1.0) {
                    $se = sqrt($pPool * (1.0 - $pPool) * ((1.0 / $completedN) + (1.0 / $otherComp)));
                    if ($se > 0.0) {
                        $z = ($p1 - $p2) / $se;
                        $pVal = $this->twoTailedNormalPValue(abs($z));
                        $r['z_score'] = round($z, 2);
                        $r['p_value'] = round($pVal, 4);

                        if ($pVal < self::ZTEST_ALPHA) {
                            $r['statistically_unusual'] = true;
                            $r['outlier_label'] = 'Statistically unusual';
                            $r['outlier_direction'] = $z < 0 ? 'lower' : 'higher';
                        }
                    }
                }
            }
        }
        unset($r);

        usort($rows, static function (array $a, array $b): int {
            if ($a['insufficient_data'] !== $b['insufficient_data']) {
                return $a['insufficient_data'] ? 1 : -1;
            }
            if (! $a['insufficient_data']) {
                $rateCmp = ($b['success_rate'] ?? 0) <=> ($a['success_rate'] ?? 0);
                if ($rateCmp !== 0) {
                    return $rateCmp;
                }
            }

            $shipCmp = $b['shipments'] <=> $a['shipments'];

            return $shipCmp !== 0 ? $shipCmp : strcmp($a['name'], $b['name']);
        });

        $rank = 1;
        foreach ($rows as &$r) {
            if (! $r['insufficient_data']) {
                $r['rank'] = $rank++;
            } else {
                $r['rank'] = null;
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Two-tailed p-value from standard normal |z|: erfc(|z| / sqrt(2)).
     * Abramowitz & Stegun formula 7.1.26 (max error 1.5e-7).
     */
    private function twoTailedNormalPValue(float $absZ): float
    {
        if ($absZ <= 0.0) {
            return 1.0;
        }

        $x = $absZ / M_SQRT2;
        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $poly = $t * (
            0.254829592 + $t * (
                -0.284496736 + $t * (
                    1.421413741 + $t * (
                        -1.453152027 + $t * 1.061405429
                    )
                )
            )
        );
        $erfc = $poly * exp(-$x * $x);

        return max(0.0, min(1.0, $erfc));
    }
}
