<?php

namespace App\Http\Controllers;

use App\Models\Analysis;
use App\Services\Import\OrderMapper;
use App\Services\Import\RowReader;
use App\Support\StatusMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class FileAnalysisController extends Controller
{
    public function index(): View
    {
        $analyses = Analysis::query()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate(15);

        return view('analysis.index', compact('analyses'));
    }

    public function analyze(Request $request, RowReader $reader, OrderMapper $mapper): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xls') {
            return back()->withErrors(['file' => 'Legacy .xls files are not supported. Save as .xlsx or .csv instead.']);
        }
        if (! in_array($ext, RowReader::ALLOWED_FORMATS, true)) {
            return back()->withErrors(['file' => 'Only .csv, .xlsx, and .xml files up to 20 MB are accepted.']);
        }

        $tenantId = $request->user()->tenant_id;
        $storedPath = $file->store("analysis/t{$tenantId}", 'local');
        $disk = Storage::disk('local');
        $fullPath = $disk->path($storedPath);

        try {
            $preview = $reader->preview($fullPath, $ext, 1);
            if ($preview['header'] === []) {
                return back()->withErrors(['file' => 'The uploaded file has no recognizable header row.']);
            }

            $mapping = OrderMapper::autoDetect($preview['header']);
            if ($mapping['order_id'] === null || ($mapping['line_total'] === null && $mapping['unit_price'] === null)) {
                return back()->withErrors(['file' => 'Could not detect an Order ID and an amount column (Line Total or Unit Price) for instant analysis. Try the full Import mapping wizard instead.']);
            }

            $rowsRead = 0;
            $skipped = 0;
            $orders = [];
            $statusCounts = array_fill_keys(StatusMapper::CANONICAL, 0);
            $productRev = [];

            foreach ($reader->iterate($fullPath, $ext) as $row) {
                $rowsRead++;
                $mapped = $mapper->mapRow($row, $mapping);
                if (! $mapped['ok']) {
                    $skipped++;
                    continue;
                }

                $d = $mapped['data'];
                $oid = $d['id'];
                $canonical = StatusMapper::map($d['status']);

                $lineRev = 0.0;
                if (! empty($d['items'])) {
                    foreach ($d['items'] as $it) {
                        $rev = round($it['quantity'] * $it['unit_price'], 2);
                        $lineRev += $rev;
                        $pName = $it['name'];
                        $productRev[$pName] = ($productRev[$pName] ?? 0.0) + $rev;
                    }
                }

                if (! isset($orders[$oid])) {
                    $orders[$oid] = [
                        'status' => $canonical,
                        'date' => substr((string) $d['ordered_at'], 0, 10),
                        'total' => $d['total'] !== null ? (float) $d['total'] : round($lineRev - $d['discount'] + $d['shipping'], 2),
                    ];
                } elseif ($d['total'] === null) {
                    $orders[$oid]['total'] += $lineRev;
                }
            }

            $grossSales = 0.0;
            $deliveredRev = 0.0;
            $cancelled = 0;
            $daily = [];

            foreach ($orders as $o) {
                $statusCounts[$o['status']] = ($statusCounts[$o['status']] ?? 0) + 1;
                $day = $o['date'] ?: 'Unknown';
                if (! isset($daily[$day])) {
                    $daily[$day] = ['orders' => 0, 'revenue' => 0.0];
                }
                $daily[$day]['orders']++;

                if ($o['status'] === 'cancelled') {
                    $cancelled++;
                } else {
                    $grossSales += $o['total'];
                    $daily[$day]['revenue'] = round($daily[$day]['revenue'] + $o['total'], 2);
                }

                if ($o['status'] === 'delivered') {
                    $deliveredRev += $o['total'];
                }
            }

            ksort($daily);
            arsort($productRev);
            $topProducts = array_slice($productRev, 0, 10, true);

            $orderCount = count($orders);
            $nonCancelled = max(0, $orderCount - $cancelled);

            $analysis = new Analysis();
            $analysis->forceFill([
                'tenant_id' => $tenantId,
                'user_id' => $request->user()->id,
                'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'rows_count' => $rowsRead,
                'result' => [
                    'rows_read' => $rowsRead,
                    'skipped_rows' => $skipped,
                    'orders_count' => $orderCount,
                    'gross_sales' => round($grossSales, 2),
                    'delivered_revenue' => round($deliveredRev, 2),
                    'aov' => $nonCancelled > 0 ? round($grossSales / $nonCancelled, 2) : 0.0,
                    'cancellation_rate' => $orderCount > 0 ? round(($cancelled / $orderCount) * 100, 1) : 0.0,
                    'status_counts' => $statusCounts,
                    'daily_trend' => $daily,
                    'top_products' => $topProducts,
                    'mapping' => $mapping,
                ],
            ])->save();

            return redirect()->route('analysis.show', $analysis);
        } catch (Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        } finally {
            if ($disk->exists($storedPath)) {
                $disk->delete($storedPath);
            }
        }
    }

    public function show(Analysis $analysis): View
    {
        return view('analysis.show', compact('analysis'));
    }
}
