<?php

namespace App\Services;

use App\Support\StatusMapper;
use App\Support\TenantCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class AnalyticsService
{
    public const COURIER_MIN_SAMPLE = 20;

    public const COST_COVERAGE_MIN_PCT = 60.0;

    public const LOW_COVER_DAYS_THRESHOLD = 15.0;

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
    public function analytics(array $filters = []): array
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
            fn (): array => $this->computeAnalytics($resolved)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuildDefault(): array
    {
        $resolved = $this->resolveDateRange(['preset' => '30']);
        $cacheKey = $this->cacheKey($resolved);

        $data = $this->computeAnalytics($resolved);
        TenantCache::put($this->tenantId, $cacheKey, $data);

        return $data;
    }

    /**
     * @param  array{preset?: string, from?: ?string, to?: ?string, website_id?: int|string|null}  $filters
     * @return array{preset: string, from: string, to: string, from_ts: string, to_ts: string, website_id: ?int}
     */
    public function resolveFilters(array $filters): array
    {
        $resolved = $this->resolveDateRange($filters);

        if ($resolved['website_id'] !== null && ! $this->websiteBelongsToTenant($resolved['website_id'])) {
            throw new InvalidArgumentException('Selected website does not belong to this tenant.');
        }

        return $resolved;
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
        return 'analytics:'.md5((string) json_encode([
            'from' => $resolved['from'],
            'to' => $resolved['to'],
            'website_id' => $resolved['website_id'],
        ]));
    }

    /**
     * All analytics SQL lives here, explicitly scoped by $this->tenantId and portable across SQLite / MySQL / PostgreSQL.
     *
     * @param  array{preset: string, from: string, to: string, from_ts: string, to_ts: string, website_id: ?int}  $resolved
     * @return array<string, mixed>
     */
    private function computeAnalytics(array $resolved): array
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $now = now($tz);
        $fromTs = $resolved['from_ts'];
        $toTs = $resolved['to_ts'];
        $websiteId = $resolved['website_id'];

        $websites = DB::table('websites')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'platform', 'currency', 'status', 'last_heartbeat_at'])
            ->map(fn (object $w): array => [
                'id' => (int) $w->id,
                'name' => (string) $w->name,
                'platform' => (string) $w->platform,
                'currency' => (string) $w->currency,
            ])
            ->all();

        $websiteName = null;
        $fallbackCurrency = 'BDT';
        foreach ($websites as $w) {
            if ($websiteId !== null && $w['id'] === $websiteId) {
                $websiteName = $w['name'];
                $fallbackCurrency = $w['currency'];
                break;
            }
        }
        if ($websiteId === null && $websites !== []) {
            $fallbackCurrency = $websites[0]['currency'];
        }

        $baseOrders = DB::table('orders as o')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs);

        if ($websiteId !== null) {
            $baseOrders->where('o.website_id', $websiteId);
        }

        // 1. Currency check
        $currencies = (clone $baseOrders)
            ->select('o.currency')
            ->distinct()
            ->orderBy('o.currency')
            ->pluck('o.currency')
            ->map(fn ($c): string => strtoupper(trim((string) $c)))
            ->filter(fn (string $c): bool => $c !== '')
            ->values()
            ->all();

        $mixedCurrency = count($currencies) > 1;
        $currency = $mixedCurrency ? 'MIXED' : ($currencies[0] ?? $fallbackCurrency);
        $currencyWarning = $mixedCurrency
            ? 'Selected orders contain multiple currencies ('.implode(', ', $currencies).'). Summed monetary metrics are hidden because amounts across currencies are not comparable.'
            : null;

        // 2. Core order KPIs
        $summary = (clone $baseOrders)->selectRaw("
            COUNT(*) AS orders_count,
            SUM(CASE WHEN o.status <> 'cancelled' THEN 1 ELSE 0 END) AS non_cancelled_count,
            SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
            SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN o.status = 'returned' THEN 1 ELSE 0 END) AS returned_count,
            SUM(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE 0 END) AS gross_sales,
            SUM(CASE WHEN o.status = 'delivered' THEN o.total ELSE 0 END) AS delivered_revenue,
            SUM(CASE WHEN o.status = 'cancelled' THEN o.total ELSE 0 END) AS lost_to_cancel,
            SUM(CASE WHEN o.status = 'returned' THEN o.total ELSE 0 END) AS lost_to_return
        ")->first();

        $ordersCount = (int) ($summary->orders_count ?? 0);
        $nonCancelledCount = (int) ($summary->non_cancelled_count ?? 0);
        $deliveredCount = (int) ($summary->delivered_count ?? 0);
        $cancelledCount = (int) ($summary->cancelled_count ?? 0);
        $returnedCount = (int) ($summary->returned_count ?? 0);

        $grossSales = $mixedCurrency ? null : round((float) ($summary->gross_sales ?? 0), 2);
        $deliveredRevenue = $mixedCurrency ? null : round((float) ($summary->delivered_revenue ?? 0), 2);
        $aov = $mixedCurrency
            ? null
            : ($nonCancelledCount > 0 ? round($grossSales / $nonCancelledCount, 2) : 0.0);
        $lostToCancel = $mixedCurrency ? null : round((float) ($summary->lost_to_cancel ?? 0), 2);
        $lostToReturn = $mixedCurrency ? null : round((float) ($summary->lost_to_return ?? 0), 2);

        $cancellationRate = $ordersCount > 0 ? round(($cancelledCount / $ordersCount) * 100, 2) : 0.0;
        $returnDenom = $deliveredCount + $returnedCount;
        $returnRate = $returnDenom > 0 ? round(($returnedCount / $returnDenom) * 100, 2) : 0.0;
        $deliveryRate = $ordersCount > 0 ? round(($deliveredCount / $ordersCount) * 100, 2) : 0.0;

        // 3. Repeat purchase rate (buyers in range with >= 2 lifetime non-cancelled orders / buyers in range, identified by customer_key)
        $buyersInRangeSub = DB::table('orders as o')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('c.tenant_id', $this->tenantId)
            ->whereNotNull('c.customer_key')
            ->where('c.customer_key', '<>', '')
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs);
        if ($websiteId !== null) {
            $buyersInRangeSub->where('o.website_id', $websiteId);
        }

        $buyersInRange = (clone $buyersInRangeSub)
            ->distinct()
            ->count('c.customer_key');

        $repeatBuyers = 0;

        if ($buyersInRange > 0) {
            $repeatBuyers = DB::table('orders as lo')
                ->join('customers as lc', 'lc.id', '=', 'lo.customer_id')
                ->where('lo.tenant_id', $this->tenantId)
                ->where('lc.tenant_id', $this->tenantId)
                ->where('lo.status', '<>', 'cancelled')
                ->whereIn('lc.customer_key', (clone $buyersInRangeSub)->select('c.customer_key')->distinct())
                ->groupBy('lc.customer_key')
                ->havingRaw('COUNT(*) >= 2')
                ->select('lc.customer_key')
                ->get()
                ->count();
        }
        $repeatPurchaseRate = $buyersInRange > 0 ? round(($repeatBuyers / $buyersInRange) * 100, 2) : 0.0;

        // 4. Daily trend (portable DATE())
        $dailyRows = (clone $baseOrders)
            ->selectRaw("
                DATE(o.ordered_at) AS day,
                COUNT(*) AS orders_count,
                SUM(CASE WHEN o.status <> 'cancelled' THEN 1 ELSE 0 END) AS non_cancelled_count,
                SUM(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE 0 END) AS gross_sales,
                SUM(CASE WHEN o.status = 'delivered' THEN o.total ELSE 0 END) AS delivered_revenue
            ")
            ->groupByRaw('DATE(o.ordered_at)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $dailyTrend = [];
        $startDay = Carbon::createFromFormat('Y-m-d', $resolved['from'], $tz)->startOfDay();
        $endDay = Carbon::createFromFormat('Y-m-d', $resolved['to'], $tz)->startOfDay();
        $daySpan = $startDay->diffInDays($endDay) + 1;

        if ($daySpan <= 92) {
            for ($d = $startDay->copy(); $d->lte($endDay); $d->addDay()) {
                $key = $d->format('Y-m-d');
                $row = $dailyRows->get($key);
                $dailyTrend[] = [
                    'day' => $key,
                    'orders' => (int) ($row->orders_count ?? 0),
                    'non_cancelled' => (int) ($row->non_cancelled_count ?? 0),
                    'gross_sales' => $mixedCurrency ? null : round((float) ($row->gross_sales ?? 0), 2),
                    'delivered_revenue' => $mixedCurrency ? null : round((float) ($row->delivered_revenue ?? 0), 2),
                ];
            }
        } else {
            foreach ($dailyRows as $key => $row) {
                $dailyTrend[] = [
                    'day' => (string) $key,
                    'orders' => (int) $row->orders_count,
                    'non_cancelled' => (int) $row->non_cancelled_count,
                    'gross_sales' => $mixedCurrency ? null : round((float) $row->gross_sales, 2),
                    'delivered_revenue' => $mixedCurrency ? null : round((float) $row->delivered_revenue, 2),
                ];
            }
        }

        // 5. Status funnel
        $statusCountsRaw = (clone $baseOrders)
            ->selectRaw('o.status, COUNT(*) AS cnt')
            ->groupBy('o.status')
            ->pluck('cnt', 'status')
            ->all();

        $statusFunnel = [];
        foreach (StatusMapper::CANONICAL as $st) {
            $cnt = (int) ($statusCountsRaw[$st] ?? 0);
            $statusFunnel[] = [
                'status' => $st,
                'label' => ucfirst($st),
                'count' => $cnt,
                'pct' => $ordersCount > 0 ? round(($cnt / $ordersCount) * 100, 2) : 0.0,
            ];
        }

        // 6. Top products (revenue, units, return units, cancel units)
        $topProductsQuery = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs);

        if ($websiteId !== null) {
            $topProductsQuery->where('o.website_id', $websiteId);
        }

        $topProducts = $topProductsQuery
            ->selectRaw("
                oi.product_name,
                oi.sku,
                SUM(CASE WHEN o.status <> 'cancelled' THEN oi.quantity ELSE 0 END) AS units,
                SUM(CASE WHEN o.status <> 'cancelled' THEN oi.line_total ELSE 0 END) AS revenue,
                SUM(CASE WHEN o.status = 'returned' THEN oi.quantity ELSE 0 END) AS return_units,
                SUM(CASE WHEN o.status = 'cancelled' THEN oi.quantity ELSE 0 END) AS cancel_units
            ")
            ->groupBy('oi.product_name', 'oi.sku')
            ->orderByDesc('revenue')
            ->orderByDesc('units')
            ->limit(100)
            ->get()
            ->map(fn (object $r): array => [
                'name' => (string) $r->product_name,
                'sku' => $r->sku !== null ? (string) $r->sku : null,
                'units' => (int) $r->units,
                'revenue' => $mixedCurrency ? null : round((float) $r->revenue, 2),
                'return_units' => (int) $r->return_units,
                'cancel_units' => (int) $r->cancel_units,
            ])
            ->all();

        // 7. Courier table (shipped/delivered/returned only; insufficient data below 20 orders)
        $courierRows = (clone $baseOrders)
            ->whereNotNull('o.courier')
            ->where('o.courier', '<>', '')
            ->whereIn('o.status', ['shipped', 'delivered', 'returned'])
            ->selectRaw("
                o.courier,
                COUNT(*) AS total,
                SUM(CASE WHEN o.status = 'shipped' THEN 1 ELSE 0 END) AS shipped,
                SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN o.status = 'returned' THEN 1 ELSE 0 END) AS returned,
                SUM(CASE WHEN o.status = 'returned' THEN o.total ELSE 0 END) AS returned_value
            ")
            ->groupBy('o.courier')
            ->orderByDesc('total')
            ->orderBy('o.courier')
            ->get();

        $couriers = $courierRows->map(function (object $r) use ($mixedCurrency): array {
            $total = (int) $r->total;
            $delivered = (int) $r->delivered;
            $returned = (int) $r->returned;
            $insufficient = $total < self::COURIER_MIN_SAMPLE || ($delivered + $returned) === 0;

            return [
                'courier' => (string) $r->courier,
                'total' => $total,
                'shipped' => (int) $r->shipped,
                'delivered' => $delivered,
                'returned' => $returned,
                'returned_value' => $mixedCurrency ? null : round((float) $r->returned_value, 2),
                'success_rate' => $insufficient ? null : round(($delivered / ($delivered + $returned)) * 100, 2),
                'insufficient_data' => $insufficient,
            ];
        })->all();

        // 8. Employee table + response time strictly from non-import history
        $empQuery = DB::table('order_assignments as oa')
            ->join('orders as o', 'o.id', '=', 'oa.order_id')
            ->join('employees as e', 'e.id', '=', 'oa.employee_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('e.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs);

        if ($websiteId !== null) {
            $empQuery->where('o.website_id', $websiteId);
        }

        $empRows = $empQuery
            ->selectRaw("
                e.id AS employee_id,
                e.name AS employee_name,
                COUNT(*) AS assigned,
                SUM(CASE WHEN o.status IN ('confirmed', 'shipped', 'delivered', 'returned') THEN 1 ELSE 0 END) AS confirmed_count,
                SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN o.status = 'cancelled' THEN o.total ELSE 0 END) AS cancelled_value,
                SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN o.status = 'returned' THEN 1 ELSE 0 END) AS returned
            ")
            ->groupBy('e.id', 'e.name')
            ->orderByDesc('assigned')
            ->orderBy('e.name')
            ->get();

        // Portable non-import confirmation response time: fetch MIN(h.changed_at) per order & diff in PHP
        $respQuery = DB::table('order_assignments as oa')
            ->join('orders as o', 'o.id', '=', 'oa.order_id')
            ->join('order_status_history as h', 'h.order_id', '=', 'o.id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs)
            ->where('h.to_status', 'confirmed')
            ->where(function ($q): void {
                $q->whereNull('h.source')->orWhere('h.source', '<>', 'import');
            });

        if ($websiteId !== null) {
            $respQuery->where('o.website_id', $websiteId);
        }

        $respRows = $respQuery
            ->selectRaw('oa.employee_id, o.id AS order_id, o.ordered_at, MIN(h.changed_at) AS first_confirmed_at')
            ->groupBy('oa.employee_id', 'o.id', 'o.ordered_at')
            ->get();

        $respSamples = [];
        foreach ($respRows as $rr) {
            $empId = (int) $rr->employee_id;
            $orderedDt = Carbon::parse((string) $rr->ordered_at, $tz);
            $confirmedDt = Carbon::parse((string) $rr->first_confirmed_at, $tz);
            $mins = max(0.0, ($confirmedDt->getTimestamp() - $orderedDt->getTimestamp()) / 60.0);
            $respSamples[$empId][] = $mins;
        }

        $employees = $empRows->map(function (object $r) use ($respSamples, $mixedCurrency): array {
            $empId = (int) $r->employee_id;
            $assigned = (int) $r->assigned;
            $confirmedCount = (int) $r->confirmed_count;
            $cancelled = (int) $r->cancelled;
            $samples = $respSamples[$empId] ?? [];
            $avgResponse = $samples !== []
                ? round(array_sum($samples) / count($samples), 1)
                : null;

            return [
                'employee_id' => $empId,
                'name' => (string) $r->employee_name,
                'assigned' => $assigned,
                'confirmed_count' => $confirmedCount,
                'cancelled' => $cancelled,
                'cancelled_value' => $mixedCurrency ? null : round((float) $r->cancelled_value, 2),
                'delivered' => (int) $r->delivered,
                'returned' => (int) $r->returned,
                'confirmation_rate' => $assigned > 0 ? round(($confirmedCount / $assigned) * 100, 2) : 0.0,
                'cancel_rate' => $assigned > 0 ? round(($cancelled / $assigned) * 100, 2) : 0.0,
                'avg_response_minutes' => $avgResponse,
            ];
        })->all();

        // 9. Stock alerts with cover days (always based on last 30 days non-cancelled units)
        $cutoff30dStr = $now->copy()->subDays(30)->format('Y-m-d H:i:s');
        $nowStr = $now->format('Y-m-d H:i:s');

        $sales30dQuery = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.status', '<>', 'cancelled')
            ->where('o.ordered_at', '>=', $cutoff30dStr)
            ->where('o.ordered_at', '<=', $nowStr)
            ->whereNotNull('oi.product_id');

        if ($websiteId !== null) {
            $sales30dQuery->where('o.website_id', $websiteId);
        }

        $units30dByProduct = $sales30dQuery
            ->selectRaw('oi.product_id, SUM(oi.quantity) AS units_30d')
            ->groupBy('oi.product_id')
            ->pluck('units_30d', 'product_id')
            ->all();

        $lowStockThreshold = (int) config('lyp.low_stock_threshold', 10);

        $productsQuery = DB::table('products as p')
            ->where('p.tenant_id', $this->tenantId)
            ->where('p.active', true)
            ->whereNotNull('p.stock');

        if ($websiteId !== null) {
            $productsQuery->where('p.website_id', $websiteId);
        }

        $stockAlerts = [];
        foreach ($productsQuery->orderBy('p.stock')->orderBy('p.name')->get(['p.id', 'p.name', 'p.sku', 'p.stock']) as $prod) {
            $pid = (int) $prod->id;
            $stock = (int) $prod->stock;
            $units30d = (int) ($units30dByProduct[$pid] ?? 0);
            $coverDays = $units30d > 0
                ? round($stock / ($units30d / 30.0), 1)
                : null;

            if ($stock <= $lowStockThreshold || ($coverDays !== null && $coverDays <= self::LOW_COVER_DAYS_THRESHOLD)) {
                $stockAlerts[] = [
                    'product_id' => $pid,
                    'name' => (string) $prod->name,
                    'sku' => $prod->sku !== null ? (string) $prod->sku : null,
                    'stock' => $stock,
                    'units_30d' => $units30d,
                    'cover_days' => $coverDays,
                ];
            }
        }

        // 10. Estimated contribution with cost coverage % (< 60% => "cost data insufficient", no margin %)
        $contribQuery = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs)
            ->where('o.status', 'delivered');

        if ($websiteId !== null) {
            $contribQuery->where('o.website_id', $websiteId);
        }

        $contribAgg = $contribQuery->selectRaw('
            SUM(oi.line_total) AS all_delivered_item_rev,
            SUM(CASE WHEN oi.unit_cost IS NOT NULL THEN oi.line_total ELSE 0 END) AS costed_rev,
            SUM(CASE WHEN oi.unit_cost IS NOT NULL THEN oi.quantity * oi.unit_cost ELSE 0 END) AS costed_cost
        ')->first();

        $allDeliveredItemRev = (float) ($contribAgg->all_delivered_item_rev ?? 0);
        $costedRev = (float) ($contribAgg->costed_rev ?? 0);
        $costedCost = (float) ($contribAgg->costed_cost ?? 0);

        $costCoveragePct = $allDeliveredItemRev > 0
            ? round(($costedRev / $allDeliveredItemRev) * 100, 2)
            : 0.0;
        $sufficientCoverage = $allDeliveredItemRev > 0 && $costCoveragePct >= self::COST_COVERAGE_MIN_PCT;
        $estContribution = $mixedCurrency ? null : round($costedRev - $costedCost, 2);
        $marginPct = (! $mixedCurrency && $sufficientCoverage && $costedRev > 0)
            ? round((($costedRev - $costedCost) / $costedRev) * 100, 2)
            : null;

        $contribution = [
            'estimated_contribution' => $estContribution,
            'costed_revenue' => $mixedCurrency ? null : round($costedRev, 2),
            'costed_cost' => $mixedCurrency ? null : round($costedCost, 2),
            'all_delivered_item_revenue' => $mixedCurrency ? null : round($allDeliveredItemRev, 2),
            'cost_coverage_pct' => $costCoveragePct,
            'sufficient_coverage' => $sufficientCoverage,
            'margin_pct' => $marginPct,
            'status_label' => $sufficientCoverage ? 'ok' : 'cost data insufficient',
        ];

        // 11. Data-quality score (0-100) + 7 indicators using portable SQL (NOT EXISTS, CASE WHEN)
        $dataQuality = $this->computeDataQuality($fromTs, $toTs, $websiteId, $now);

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
            'currencies' => $currencies,
            'mixed_currency' => $mixedCurrency,
            'currency_warning' => $currencyWarning,
            'kpis' => [
                'orders' => $ordersCount,
                'non_cancelled_orders' => $nonCancelledCount,
                'delivered_orders' => $deliveredCount,
                'cancelled_orders' => $cancelledCount,
                'returned_orders' => $returnedCount,
                'gross_sales' => $grossSales,
                'delivered_revenue' => $deliveredRevenue,
                'aov' => $aov,
                'cancellation_rate' => $cancellationRate,
                'return_rate' => $returnRate,
                'delivery_rate' => $deliveryRate,
                'repeat_purchase_rate' => $repeatPurchaseRate,
                'buyers_in_range' => $buyersInRange,
                'repeat_buyers' => $repeatBuyers,
                'lost_to_cancel' => $lostToCancel,
                'lost_to_return' => $lostToReturn,
            ],
            'contribution' => $contribution,
            'daily_trend' => $dailyTrend,
            'status_funnel' => $statusFunnel,
            'top_products' => $topProducts,
            'couriers' => $couriers,
            'employees' => $employees,
            'stock_alerts' => $stockAlerts,
            'data_quality' => $dataQuality,
            'definitions' => $this->definitions(),
        ];
    }

    /**
     * @return array{score: int, indicators: list<array{key: string, label: string, weight: int, numerator: int, denominator: int, pct: float, penalty: float, level: string, message: string}>}
     */
    private function computeDataQuality(string $fromTs, string $toTs, ?int $websiteId, Carbon $now): array
    {
        $orderAggQuery = DB::table('orders as o')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs);

        if ($websiteId !== null) {
            $orderAggQuery->where('o.website_id', $websiteId);
        }

        $dq = $orderAggQuery->selectRaw("
            COUNT(*) AS total_orders,
            SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id) THEN 1 ELSE 0 END) AS no_items_orders,
            SUM(CASE WHEN o.status = 'other' THEN 1 ELSE 0 END) AS other_status_orders,
            SUM(CASE WHEN o.customer_id IS NULL OR NOT EXISTS (SELECT 1 FROM customers c WHERE c.id = o.customer_id AND c.customer_key IS NOT NULL AND c.customer_key <> '') THEN 1 ELSE 0 END) AS no_customer_orders,
            SUM(CASE WHEN o.status IN ('shipped', 'delivered', 'returned') THEN 1 ELSE 0 END) AS courier_eligible_orders,
            SUM(CASE WHEN o.status IN ('shipped', 'delivered', 'returned') AND (o.courier IS NULL OR o.courier = '') THEN 1 ELSE 0 END) AS missing_courier_orders,
            SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM order_assignments oa WHERE oa.order_id = o.id) THEN 1 ELSE 0 END) AS no_employee_orders
        ")->first();

        $totalOrders = (int) ($dq->total_orders ?? 0);
        $noItems = (int) ($dq->no_items_orders ?? 0);
        $otherStatus = (int) ($dq->other_status_orders ?? 0);
        $noCustomer = (int) ($dq->no_customer_orders ?? 0);
        $courierEligible = (int) ($dq->courier_eligible_orders ?? 0);
        $missingCourier = (int) ($dq->missing_courier_orders ?? 0);
        $noEmployee = (int) ($dq->no_employee_orders ?? 0);

        $itemAggQuery = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.ordered_at', '>=', $fromTs)
            ->where('o.ordered_at', '<=', $toTs);

        if ($websiteId !== null) {
            $itemAggQuery->where('o.website_id', $websiteId);
        }

        $itemAgg = $itemAggQuery->selectRaw('
            COUNT(*) AS total_items,
            SUM(CASE WHEN oi.unit_cost IS NULL THEN 1 ELSE 0 END) AS missing_cost_items
        ')->first();

        $totalItems = (int) ($itemAgg->total_items ?? 0);
        $missingCostItems = (int) ($itemAgg->missing_cost_items ?? 0);

        // Connected sites whose latest order update is older than 48 h
        $cutoff10mStr = $now->copy()->subMinutes(10)->format('Y-m-d H:i:s');
        $cutoff48h = $now->copy()->subHours(48);

        $connectedSitesQuery = DB::table('websites')
            ->where('tenant_id', $this->tenantId)
            ->where('status', '!=', 'disabled')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', $cutoff10mStr);

        if ($websiteId !== null) {
            $connectedSitesQuery->where('id', $websiteId);
        }

        $connectedSiteIds = $connectedSitesQuery->pluck('id')->all();
        $connectedCount = count($connectedSiteIds);
        $staleSites = 0;

        if ($connectedCount > 0) {
            $latestBySite = DB::table('orders')
                ->where('tenant_id', $this->tenantId)
                ->whereIn('website_id', $connectedSiteIds)
                ->selectRaw('website_id, MAX(COALESCE(source_updated_at, updated_at)) AS last_update')
                ->groupBy('website_id')
                ->pluck('last_update', 'website_id')
                ->all();

            foreach ($connectedSiteIds as $siteId) {
                $lastUpd = $latestBySite[$siteId] ?? null;
                if ($lastUpd === null || Carbon::parse((string) $lastUpd, $now->getTimezone())->lt($cutoff48h)) {
                    $staleSites++;
                }
            }
        }

        $buildIndicator = static function (
            string $key,
            string $label,
            int $weight,
            int $numerator,
            int $denominator,
            string $message
        ): array {
            $pct = $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0.0;
            $penalty = round($weight * ($pct / 100.0), 2);
            $level = $pct <= 5.0 ? 'ok' : ($pct <= 20.0 ? 'warn' : 'bad');

            return [
                'key' => $key,
                'label' => $label,
                'weight' => $weight,
                'numerator' => $numerator,
                'denominator' => $denominator,
                'pct' => $pct,
                'penalty' => $penalty,
                'level' => $level,
                'message' => $message,
            ];
        };

        $indicators = [
            $buildIndicator(
                'no_items',
                'Orders with no line items',
                15,
                $noItems,
                $totalOrders,
                'Orders missing line items: product breakdown, stock velocity, and contribution coverage are incomplete.'
            ),
            $buildIndicator(
                'status_other',
                'Orders with unmapped status (other)',
                20,
                $otherStatus,
                $totalOrders,
                'Unmapped order statuses: funnel, confirmation, cancellation, and delivery rates are distorted.'
            ),
            $buildIndicator(
                'no_customer_key',
                'Orders without customer contact key',
                10,
                $noCustomer,
                $totalOrders,
                'Missing customer hash key: repeat purchase rate and buyer cohorts are undercounted.'
            ),
            $buildIndicator(
                'missing_courier',
                'Shipped/delivered/returned without courier',
                20,
                $missingCourier,
                $courierEligible,
                'Courier missing: courier scorecard and delivery comparison are unreliable.'
            ),
            $buildIndicator(
                'no_employee',
                'Orders without assigned employee',
                10,
                $noEmployee,
                $totalOrders,
                'Employee missing: staff confirmation and cancel scorecards are incomplete.'
            ),
            $buildIndicator(
                'missing_item_cost',
                'Order items missing unit cost',
                15,
                $missingCostItems,
                $totalItems,
                'Item unit cost missing: estimated contribution and margin coverage are unreliable.'
            ),
            $buildIndicator(
                'stale_connected_site',
                'Connected sites stale (> 48h without order update)',
                10,
                $staleSites,
                $connectedCount,
                'Stale order sync on connected site: live connector sync may be paused or failing.'
            ),
        ];

        $totalPenalty = 0.0;
        foreach ($indicators as $ind) {
            $totalPenalty += $ind['penalty'];
        }

        $score = (int) round(max(0.0, min(100.0, 100.0 - $totalPenalty)));

        return [
            'score' => $score,
            'indicators' => $indicators,
        ];
    }

    /**
     * Canonical supplementary aggregates for the R&D rule engine so rules use AnalyticsService numbers only.
     *
     * @return array<string, mixed>
     */
    public function rndSupplement(int $websiteId): array
    {
        if (! $this->websiteBelongsToTenant($websiteId)) {
            throw new InvalidArgumentException('Selected website does not belong to this tenant.');
        }

        $tz = config('app.timezone', 'Asia/Dhaka');
        $today = now($tz)->format('Y-m-d');
        $cacheKey = 'rnd_sup:'.$websiteId.':'.$today;

        return TenantCache::remember(
            $this->tenantId,
            $cacheKey,
            fn (): array => $this->computeRndSupplement($websiteId, $tz)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeRndSupplement(int $websiteId, string $tz): array
    {
        $now = now($tz);
        $nowEndStr = $now->copy()->endOfDay()->format('Y-m-d H:i:s');
        $from30dStr = $now->copy()->subDays(29)->startOfDay()->format('Y-m-d H:i:s');
        $from60dStr = $now->copy()->subDays(59)->startOfDay()->format('Y-m-d H:i:s');

        $totalSiteOrders = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->where('website_id', $websiteId)
            ->count();

        $siteOrders60d = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->where('website_id', $websiteId)
            ->where('ordered_at', '>=', $from60dStr)
            ->where('ordered_at', '<=', $nowEndStr)
            ->count();

        // Trailing 8 prior 7-day blocks before the current 7-day window ([today-6d .. today])
        $trailingWeeksCount = (int) config('lyp.rnd.r01_trailing_weeks', 8);
        $weeklySeries = [];
        $earliestFrom = $now->copy()->subDays(6 + ($trailingWeeksCount * 7))->startOfDay();
        $priorEnd = $now->copy()->subDays(7)->endOfDay();

        $priorCurrencies = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->where('website_id', $websiteId)
            ->where('ordered_at', '>=', $earliestFrom->format('Y-m-d H:i:s'))
            ->where('ordered_at', '<=', $priorEnd->format('Y-m-d H:i:s'))
            ->distinct()
            ->pluck('currency')
            ->map(fn ($c): string => strtoupper(trim((string) $c)))
            ->filter(fn (string $c): bool => $c !== '')
            ->all();
        $weeklyMixed = count($priorCurrencies) > 1;

        for ($w = $trailingWeeksCount; $w >= 1; $w--) {
            $wEnd = $now->copy()->subDays($w * 7)->endOfDay();
            $wStart = $wEnd->copy()->subDays(6)->startOfDay();

            $agg = DB::table('orders')
                ->where('tenant_id', $this->tenantId)
                ->where('website_id', $websiteId)
                ->where('ordered_at', '>=', $wStart->format('Y-m-d H:i:s'))
                ->where('ordered_at', '<=', $wEnd->format('Y-m-d H:i:s'))
                ->selectRaw("
                    COUNT(*) AS orders_count,
                    SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END) AS gross_sales
                ")
                ->first();

            $weeklySeries[] = [
                'from' => $wStart->format('Y-m-d'),
                'to' => $wEnd->format('Y-m-d'),
                'orders' => (int) ($agg->orders_count ?? 0),
                'gross_sales' => $weeklyMixed ? null : round((float) ($agg->gross_sales ?? 0), 2),
            ];
        }

        // Top 30-day revenue product stock cover (for R06)
        $salesByProd30d = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.website_id', $websiteId)
            ->where('o.status', '<>', 'cancelled')
            ->where('o.ordered_at', '>=', $from30dStr)
            ->where('o.ordered_at', '<=', $nowEndStr)
            ->whereNotNull('oi.product_id')
            ->selectRaw('oi.product_id, SUM(oi.quantity) AS units_30d, SUM(oi.line_total) AS revenue_30d')
            ->groupBy('oi.product_id')
            ->get()
            ->keyBy('product_id');

        $activeProducts = DB::table('products')
            ->where('tenant_id', $this->tenantId)
            ->where('website_id', $websiteId)
            ->where('active', true)
            ->get(['id', 'name', 'sku', 'stock', 'price', 'cost']);

        $productStockCover = [];
        foreach ($activeProducts as $prod) {
            $pid = (int) $prod->id;
            $sales = $salesByProd30d->get($pid);
            $units30d = (int) ($sales->units_30d ?? 0);
            $rev30d = round((float) ($sales->revenue_30d ?? 0.0), 2);
            if ($units30d <= 0 && $rev30d <= 0.0) {
                continue;
            }
            $stock = $prod->stock !== null ? (int) $prod->stock : null;
            $coverDays = ($stock !== null && $units30d > 0)
                ? round($stock / ($units30d / 30.0), 1)
                : null;

            $productStockCover[] = [
                'product_id' => $pid,
                'name' => (string) $prod->name,
                'sku' => $prod->sku !== null ? (string) $prod->sku : null,
                'stock' => $stock,
                'units_30d' => $units30d,
                'revenue_30d' => $rev30d,
                'cover_days' => $coverDays,
            ];
        }
        usort($productStockCover, static function (array $a, array $b): int {
            $cmp = $b['revenue_30d'] <=> $a['revenue_30d'];

            return $cmp !== 0 ? $cmp : ($b['units_30d'] <=> $a['units_30d']);
        });

        // Dead stock: active products with stock > 0 and 0 non-cancelled units in 60 days (for R07)
        $deadDays = (int) config('lyp.rnd.r07_dead_days', 60);
        $cutoffDeadStr = $now->copy()->subDays($deadDays - 1)->startOfDay()->format('Y-m-d H:i:s');
        $soldProductIds60d = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.tenant_id', $this->tenantId)
            ->where('o.website_id', $websiteId)
            ->where('o.status', '<>', 'cancelled')
            ->where('o.ordered_at', '>=', $cutoffDeadStr)
            ->where('o.ordered_at', '<=', $nowEndStr)
            ->whereNotNull('oi.product_id')
            ->distinct()
            ->pluck('oi.product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $soldSet = array_flip($soldProductIds60d);

        $deadItems = [];
        $deadMissingValueCount = 0;
        foreach ($activeProducts as $prod) {
            $pid = (int) $prod->id;
            $stock = $prod->stock !== null ? (int) $prod->stock : 0;
            if ($stock <= 0 || isset($soldSet[$pid])) {
                continue;
            }
            $cost = $prod->cost !== null ? (float) $prod->cost : null;
            $price = $prod->price !== null ? (float) $prod->price : null;
            $unitVal = ($cost !== null && $cost > 0) ? $cost : (($price !== null && $price > 0) ? $price : null);

            if ($unitVal === null) {
                $deadMissingValueCount++;
                continue;
            }

            $deadItems[] = [
                'product_id' => $pid,
                'name' => (string) $prod->name,
                'sku' => $prod->sku !== null ? (string) $prod->sku : null,
                'stock' => $stock,
                'unit_value' => round($unitVal, 2),
                'value_source' => ($cost !== null && $cost > 0) ? 'cost' : 'price',
                'stock_value' => round($stock * $unitVal, 2),
            ];
        }
        usort($deadItems, static fn (array $a, array $b): int => $b['stock_value'] <=> $a['stock_value']);

        // Discounted vs undiscounted 30d cohort metrics (for R10)
        $discCurrencies = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->where('website_id', $websiteId)
            ->where('ordered_at', '>=', $from30dStr)
            ->where('ordered_at', '<=', $nowEndStr)
            ->distinct()
            ->pluck('currency')
            ->map(fn ($c): string => strtoupper(trim((string) $c)))
            ->filter(fn (string $c): bool => $c !== '')
            ->all();
        $discMixed = count($discCurrencies) > 1;

        $cohortQuery = function (bool $discounted) use ($websiteId, $from30dStr, $nowEndStr, $discMixed): array {
            $q = DB::table('orders')
                ->where('tenant_id', $this->tenantId)
                ->where('website_id', $websiteId)
                ->where('ordered_at', '>=', $from30dStr)
                ->where('ordered_at', '<=', $nowEndStr);

            if ($discounted) {
                $q->where('discount', '>', 0);
            } else {
                $q->where('discount', '<=', 0);
            }

            $agg = $q->selectRaw("
                COUNT(*) AS orders_count,
                SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END) AS non_cancelled_count,
                SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END) AS gross_sales,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned_count,
                SUM(CASE WHEN status = 'returned' THEN total ELSE 0 END) AS returned_value
            ")->first();

            $orders = (int) ($agg->orders_count ?? 0);
            $nonCancelled = (int) ($agg->non_cancelled_count ?? 0);
            $delivered = (int) ($agg->delivered_count ?? 0);
            $returned = (int) ($agg->returned_count ?? 0);
            $completed = $delivered + $returned;
            $gross = $discMixed ? null : round((float) ($agg->gross_sales ?? 0), 2);
            $aov = ($discMixed || $nonCancelled <= 0) ? null : round($gross / $nonCancelled, 2);

            return [
                'orders' => $orders,
                'non_cancelled' => $nonCancelled,
                'gross_sales' => $gross,
                'aov' => $aov,
                'delivered' => $delivered,
                'returned' => $returned,
                'completed' => $completed,
                'return_rate' => $completed > 0 ? round(($returned / $completed) * 100, 2) : 0.0,
                'returned_value' => $discMixed ? null : round((float) ($agg->returned_value ?? 0), 2),
            ];
        };

        $from7dStr = $now->copy()->subDays(6)->startOfDay()->format('Y-m-d H:i:s');
        $cancelReason7dAgg = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->where('website_id', $websiteId)
            ->where('status', 'cancelled')
            ->where('ordered_at', '>=', $from7dStr)
            ->where('ordered_at', '<=', $nowEndStr)
            ->selectRaw("
                COUNT(*) AS cancelled_total,
                SUM(CASE WHEN cancel_reason IS NULL OR cancel_reason = '' THEN 1 ELSE 0 END) AS missing_reason
            ")
            ->first();
        $canTotal7d = (int) ($cancelReason7dAgg->cancelled_total ?? 0);
        $canMiss7d = (int) ($cancelReason7dAgg->missing_reason ?? 0);
        $cancelMissingReasonPct7d = $canTotal7d > 0 ? round(($canMiss7d / $canTotal7d) * 100, 1) : 0.0;

        return [
            'website_id' => $websiteId,
            'total_site_orders' => $totalSiteOrders,
            'site_orders_60d' => $siteOrders60d,
            'cancel_missing_reason_pct_7d' => $cancelMissingReasonPct7d,
            'weekly_mixed_currency' => $weeklyMixed,
            'weekly_gross_sales' => $weeklySeries,
            'product_stock_cover' => $productStockCover,
            'dead_stock' => [
                'items' => $deadItems,
                'missing_value_count' => $deadMissingValueCount,
                'dead_days' => $deadDays,
            ],
            'discount_cohorts_30d' => [
                'mixed_currency' => $discMixed,
                'discounted' => $cohortQuery(true),
                'undiscounted' => $cohortQuery(false),
            ],
        ];
    }

    /**
     * @return list<array{metric: string, formula: string}>
     */
    public function definitions(): array
    {
        return [
            ['metric' => 'Orders', 'formula' => 'Count of orders in selected range (inclusive, Asia/Dhaka on orders.ordered_at).'],
            ['metric' => 'Gross sales', 'formula' => 'SUM(total) of orders where status <> cancelled.'],
            ['metric' => 'Delivered revenue', 'formula' => 'SUM(total) of orders where status = delivered.'],
            ['metric' => 'AOV', 'formula' => 'Gross sales / count of orders where status <> cancelled.'],
            ['metric' => 'Cancellation rate', 'formula' => 'Cancelled orders / all orders in range.'],
            ['metric' => 'Return rate', 'formula' => 'Returned orders / (delivered + returned).'],
            ['metric' => 'Delivery rate', 'formula' => 'Delivered orders / all orders in range.'],
            ['metric' => 'Repeat purchase rate', 'formula' => 'Buyers in range with >= 2 lifetime non-cancelled orders / buyers in range (by customer_key).'],
            ['metric' => 'Revenue lost to cancel', 'formula' => 'SUM(total) of orders where status = cancelled.'],
            ['metric' => 'Revenue lost to return', 'formula' => 'SUM(total) of orders where status = returned.'],
            ['metric' => 'Courier success rate', 'formula' => 'Delivered / (delivered + returned) across shipped/delivered/returned orders; shows "insufficient data" below 20 orders.'],
            ['metric' => 'Employee confirmation rate', 'formula' => 'Orders in (confirmed, shipped, delivered, returned) / assigned orders.'],
            ['metric' => 'Employee cancel rate', 'formula' => 'Cancelled orders / assigned orders.'],
            ['metric' => 'Response time', 'formula' => 'Minutes from ordered_at to first order_status_history row with to_status = confirmed and source <> import ("n/a" if none).'],
            ['metric' => 'Stock cover (days)', 'formula' => 'Current product stock / (non-cancelled units sold in last 30 days / 30); null if no 30d sales.'],
            ['metric' => 'Estimated contribution', 'formula' => 'Delivered item revenue with known cost - SUM(quantity * unit_cost). Shows cost coverage %; below 60% shows "cost data insufficient" and hides margin %. Before ads, shipping, returns, and overhead — not profit.'],
            ['metric' => 'Data-quality score', 'formula' => '100 minus weighted penalties across 7 completeness/freshness indicators (ok <= 5%, warn <= 20%, bad > 20%).'],
        ];
    }
}
