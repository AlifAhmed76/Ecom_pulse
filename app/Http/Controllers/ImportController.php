<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Website;
use App\Services\Import\OrderImporter;
use App\Services\Import\OrderMapper;
use App\Services\Import\RowReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ImportController extends Controller
{
    public function index(): View
    {
        $batches = ImportBatch::query()
            ->where('kind', 'orders')
            ->with(['website:id,name', 'user:id,name'])
            ->orderByDesc('id')
            ->paginate(15);

        $websites = Website::query()->orderBy('name')->get(['id', 'name', 'platform']);

        return view('imports.index', compact('batches', 'websites'));
    }

    public function upload(Request $request, RowReader $reader): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'website_id' => [
                'required',
                'integer',
                Rule::exists('websites', 'id')->where('tenant_id', $tenantId),
            ],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xls') {
            return back()
                ->withErrors(['file' => 'Legacy .xls files are not supported. Please save your spreadsheet as .xlsx or .csv and upload again.'])
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
                ->withErrors(['file' => 'The uploaded file appears empty or has no recognizable header row.'])
                ->withInput();
        }

        $autoMapping = OrderMapper::autoDetect($preview['header']);

        $batch = new ImportBatch();
        $batch->forceFill([
            'tenant_id' => $tenantId,
            'website_id' => (int) $data['website_id'],
            'user_id' => $request->user()->id,
            'kind' => 'orders',
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'stored_path' => $storedPath,
            'format' => $ext,
            'status' => 'uploaded',
            'header' => $preview['header'],
            'mapping' => $autoMapping,
        ])->save();

        return redirect()->route('imports.map', $batch);
    }

    public function map(ImportBatch $batch, RowReader $reader): View|RedirectResponse
    {
        abort_unless($batch->kind === 'orders', 404);

        if (in_array($batch->status, ['queued', 'processing', 'done', 'failed'], true)
            || $batch->stored_path === null
            || ! Storage::disk('local')->exists($batch->stored_path)) {
            return redirect()->route('imports.show', $batch);
        }

        $preview = $reader->preview(
            Storage::disk('local')->path($batch->stored_path),
            $batch->format,
            5
        );

        return view('imports.map', [
            'batch' => $batch,
            'header' => $batch->header ?? $preview['header'],
            'sample' => $preview['sample'],
            'mapping' => $batch->mapping ?? [],
            'fields' => OrderMapper::FIELD_LABELS,
        ]);
    }

    public function confirm(Request $request, ImportBatch $batch, OrderImporter $importer): RedirectResponse
    {
        abort_unless($batch->kind === 'orders', 404);

        if (in_array($batch->status, ['queued', 'processing', 'done', 'failed'], true)
            || $batch->stored_path === null
            || ! Storage::disk('local')->exists($batch->stored_path)) {
            return redirect()->route('imports.show', $batch);
        }

        $allowedHeaders = $batch->header ?? [];
        $submitted = $request->input('map', []);
        $cleanMapping = [];

        foreach (array_keys(OrderMapper::FIELD_ALIASES) as $field) {
            $chosen = is_array($submitted) ? ($submitted[$field] ?? null) : null;
            $chosenStr = is_string($chosen) ? trim($chosen) : '';
            $cleanMapping[$field] = ($chosenStr !== '' && in_array($chosenStr, $allowedHeaders, true))
                ? $chosenStr
                : null;
        }

        if ($cleanMapping['order_id'] === null) {
            return back()->withErrors(['map' => 'Please map a column for Order ID before importing.'])->withInput();
        }
        if ($cleanMapping['line_total'] === null && $cleanMapping['unit_price'] === null) {
            return back()->withErrors(['map' => 'Please map either Line / Order Total or Unit Price before importing.'])->withInput();
        }

        $batch->forceFill(['mapping' => $cleanMapping])->save();

        if (Cache::get('sys:worker_beat') !== null) {
            $batch->forceFill(['status' => 'queued'])->save();
            ProcessImportBatch::dispatch($batch->id)->onQueue('default');

            return redirect()
                ->route('imports.show', $batch)
                ->with('ok', 'Import queued for background processing.');
        }

        $importer->processBatch($batch);

        return redirect()
            ->route('imports.show', $batch)
            ->with('ok', 'Import processed synchronously (no worker heartbeat detected).');
    }

    public function show(ImportBatch $batch): View
    {
        abort_unless($batch->kind === 'orders', 404);
        $batch->load(['website:id,name', 'user:id,name']);

        return view('imports.show', compact('batch'));
    }

    public function discard(ImportBatch $batch): RedirectResponse
    {
        abort_unless($batch->kind === 'orders', 404);
        if ($batch->stored_path !== null && Storage::disk('local')->exists($batch->stored_path)) {
            Storage::disk('local')->delete($batch->stored_path);
        }

        $batch->delete();

        return redirect()
            ->route('imports.index')
            ->with('ok', 'Import batch discarded and temporary file removed.');
    }
}
