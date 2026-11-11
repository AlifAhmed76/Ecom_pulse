<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Website;
use App\Services\Courier\CourierImporter;
use App\Services\Courier\CourierMapper;
use App\Services\Import\RowReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CourierImportController extends Controller
{
    public function index(): View
    {
        $batches = ImportBatch::query()
            ->where('kind', 'courier')
            ->with(['website:id,name', 'user:id,name'])
            ->orderByDesc('id')
            ->paginate(15);

        $websites = Website::query()->orderBy('name')->get(['id', 'name', 'platform']);

        return view('courier.imports.index', compact('batches', 'websites'));
    }

    public function upload(Request $request, RowReader $reader): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'courier_name' => ['required', 'string', 'max:80'],
            'website_id' => [
                'nullable',
                'integer',
                Rule::exists('websites', 'id')->where('tenant_id', $tenantId),
            ],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xls') {
            return back()
                ->withErrors(['file' => 'Legacy .xls files are not supported. Save your courier report as .xlsx or .csv and upload again.'])
                ->withInput();
        }

        if (! in_array($ext, RowReader::ALLOWED_FORMATS, true)) {
            return back()
                ->withErrors(['file' => 'Only .csv, .xlsx, and .xml files up to 20 MB are accepted.'])
                ->withInput();
        }

        $disk = Storage::disk('local');
        $storedPath = $file->store("imports/t{$tenantId}", 'local');
        $fullPath = $disk->path($storedPath);

        try {
            $preview = $reader->preview($fullPath, $ext, 5);
        } catch (Throwable $e) {
            $disk->delete($storedPath);

            return back()
                ->withErrors(['file' => $e->getMessage()])
                ->withInput();
        }

        if ($preview['header'] === []) {
            $disk->delete($storedPath);

            return back()
                ->withErrors(['file' => 'The uploaded courier file appears empty or has no recognizable header row.'])
                ->withInput();
        }

        $mapping = array_merge(
            ['_courier' => trim((string) $data['courier_name'])],
            CourierMapper::emptyMapping()
        );

        $batch = new ImportBatch();
        $batch->forceFill([
            'tenant_id' => $tenantId,
            'website_id' => ! empty($data['website_id']) ? (int) $data['website_id'] : null,
            'user_id' => $request->user()->id,
            'kind' => 'courier',
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'stored_path' => $storedPath,
            'format' => $ext,
            'status' => 'uploaded',
            'header' => $preview['header'],
            'mapping' => $mapping,
        ])->save();

        return redirect()->route('courier-imports.map', $batch);
    }

    public function map(ImportBatch $batch, RowReader $reader): View|RedirectResponse
    {
        abort_unless($batch->kind === 'courier', 404);

        if (in_array($batch->status, ['queued', 'processing', 'done', 'failed'], true)
            || $batch->stored_path === null
            || ! Storage::disk('local')->exists($batch->stored_path)) {
            return redirect()->route('courier-imports.show', $batch);
        }

        $preview = $reader->preview(
            Storage::disk('local')->path($batch->stored_path),
            $batch->format,
            5
        );

        return view('courier.imports.map', [
            'batch' => $batch,
            'header' => $batch->header ?? $preview['header'],
            'sample' => $preview['sample'],
            'mapping' => $batch->mapping ?? [],
            'courierName' => (string) (($batch->mapping ?? [])['_courier'] ?? ''),
            'fields' => CourierMapper::FIELD_LABELS,
        ]);
    }

    public function confirm(Request $request, ImportBatch $batch, CourierImporter $importer): RedirectResponse
    {
        abort_unless($batch->kind === 'courier', 404);

        if (in_array($batch->status, ['queued', 'processing', 'done', 'failed'], true)
            || $batch->stored_path === null
            || ! Storage::disk('local')->exists($batch->stored_path)) {
            return redirect()->route('courier-imports.show', $batch);
        }

        $courierName = trim((string) $request->input('courier_name', ($batch->mapping ?? [])['_courier'] ?? ''));
        if ($courierName === '' || mb_strlen($courierName) > 80) {
            return back()->withErrors(['courier_name' => 'Courier name is required (max 80 chars).'])->withInput();
        }

        $allowedHeaders = $batch->header ?? [];
        $submitted = $request->input('map', []);
        $cleanMapping = ['_courier' => $courierName];

        foreach (array_keys(CourierMapper::FIELD_LABELS) as $field) {
            $chosen = is_array($submitted) ? ($submitted[$field] ?? null) : null;
            $chosenStr = is_string($chosen) ? trim($chosen) : '';
            $cleanMapping[$field] = ($chosenStr !== '' && in_array($chosenStr, $allowedHeaders, true))
                ? $chosenStr
                : null;
        }

        if ($cleanMapping['status'] === null) {
            return back()->withErrors(['map' => 'Please map a column for Courier Delivery Status before importing.'])->withInput();
        }

        if ($cleanMapping['consignment_id'] === null
            && $cleanMapping['order_ref'] === null
            && $cleanMapping['customer_contact'] === null) {
            return back()->withErrors([
                'map' => 'Please map at least one order matching column: Consignment ID, Order Reference, or Customer Contact.',
            ])->withInput();
        }

        $batch->forceFill(['mapping' => $cleanMapping])->save();

        if (Cache::get('sys:worker_beat') !== null) {
            $batch->forceFill(['status' => 'queued'])->save();
            ProcessImportBatch::dispatch($batch->id)->onQueue('default');

            return redirect()
                ->route('courier-imports.show', $batch)
                ->with('ok', 'Courier report queued for background processing.');
        }

        $importer->processBatch($batch);

        return redirect()
            ->route('courier-imports.show', $batch)
            ->with('ok', 'Courier report processed synchronously (no worker heartbeat detected).');
    }

    public function show(ImportBatch $batch): View
    {
        abort_unless($batch->kind === 'courier', 404);
        $batch->load(['website:id,name', 'user:id,name']);

        return view('courier.imports.show', compact('batch'));
    }

    public function discard(ImportBatch $batch): RedirectResponse
    {
        abort_unless($batch->kind === 'courier', 404);

        if ($batch->stored_path !== null && Storage::disk('local')->exists($batch->stored_path)) {
            Storage::disk('local')->delete($batch->stored_path);
        }

        $batch->delete();

        return redirect()
            ->route('courier-imports.index')
            ->with('ok', 'Courier import batch discarded and temporary file removed.');
    }
}
