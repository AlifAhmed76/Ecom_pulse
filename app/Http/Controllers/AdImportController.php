<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Website;
use App\Services\Import\RowReader;
use App\Services\Marketing\AdImporter;
use App\Services\Marketing\AdMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AdImportController extends Controller
{
    public function index(): View
    {
        $batches = ImportBatch::query()
            ->where('kind', 'ads')
            ->with(['website:id,name', 'user:id,name'])
            ->orderByDesc('id')
            ->paginate(15);

        $websites = Website::query()->orderBy('name')->get(['id', 'name', 'platform']);
        $platforms = AdMapper::SUPPORTED_PLATFORMS;

        return view('marketing.imports.index', compact('batches', 'websites', 'platforms'));
    }

    public function upload(Request $request, RowReader $reader): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'platform' => ['required', 'in:'.implode(',', AdMapper::SUPPORTED_PLATFORMS)],
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
                ->withErrors(['file' => 'Legacy .xls files are not supported. Save your ad report as .xlsx or .csv and upload again.'])
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
                ->withErrors(['file' => 'The uploaded ad report appears empty or has no recognizable header row.'])
                ->withInput();
        }

        $mapping = array_merge(
            ['_platform' => (string) $data['platform']],
            AdMapper::emptyMapping()
        );

        $batch = new ImportBatch();
        $batch->forceFill([
            'tenant_id' => $tenantId,
            'website_id' => ! empty($data['website_id']) ? (int) $data['website_id'] : null,
            'user_id' => $request->user()->id,
            'kind' => 'ads',
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'stored_path' => $storedPath,
            'format' => $ext,
            'status' => 'uploaded',
            'header' => $preview['header'],
            'mapping' => $mapping,
        ])->save();

        return redirect()->route('marketing-imports.map', $batch);
    }

    public function map(ImportBatch $batch, RowReader $reader): View|RedirectResponse
    {
        abort_unless($batch->kind === 'ads', 404);

        if (in_array($batch->status, ['queued', 'processing', 'done', 'failed'], true)
            || $batch->stored_path === null
            || ! Storage::disk('local')->exists($batch->stored_path)) {
            return redirect()->route('marketing-imports.show', $batch);
        }

        $preview = $reader->preview(
            Storage::disk('local')->path($batch->stored_path),
            $batch->format,
            5
        );

        return view('marketing.imports.map', [
            'batch' => $batch,
            'header' => $batch->header ?? $preview['header'],
            'sample' => $preview['sample'],
            'mapping' => $batch->mapping ?? [],
            'platform' => (string) (($batch->mapping ?? [])['_platform'] ?? 'meta'),
            'platforms' => AdMapper::SUPPORTED_PLATFORMS,
            'fields' => AdMapper::FIELD_LABELS,
        ]);
    }

    public function confirm(Request $request, ImportBatch $batch, AdImporter $importer): RedirectResponse
    {
        abort_unless($batch->kind === 'ads', 404);

        if (in_array($batch->status, ['queued', 'processing', 'done', 'failed'], true)
            || $batch->stored_path === null
            || ! Storage::disk('local')->exists($batch->stored_path)) {
            return redirect()->route('marketing-imports.show', $batch);
        }

        $platform = strtolower(trim((string) $request->input('platform', ($batch->mapping ?? [])['_platform'] ?? '')));
        if (! in_array($platform, AdMapper::SUPPORTED_PLATFORMS, true)) {
            return back()->withErrors(['platform' => 'Choose a supported platform (meta, google, tiktok, other).'])->withInput();
        }

        $allowedHeaders = $batch->header ?? [];
        $submitted = $request->input('map', []);
        $cleanMapping = ['_platform' => $platform];

        foreach (array_keys(AdMapper::FIELD_LABELS) as $field) {
            $chosen = is_array($submitted) ? ($submitted[$field] ?? null) : null;
            $chosenStr = is_string($chosen) ? trim($chosen) : '';
            $cleanMapping[$field] = ($chosenStr !== '' && in_array($chosenStr, $allowedHeaders, true))
                ? $chosenStr
                : null;
        }

        if ($cleanMapping['date'] === null || $cleanMapping['campaign'] === null || $cleanMapping['spend'] === null) {
            return back()
                ->withErrors(['map' => 'Please map Date *, Campaign Name *, and Ad Spend * columns explicitly before importing.'])
                ->withInput();
        }

        $batch->forceFill(['mapping' => $cleanMapping])->save();

        if (Cache::get('sys:worker_beat') !== null) {
            $batch->forceFill(['status' => 'queued'])->save();
            ProcessImportBatch::dispatch($batch->id)->onQueue('default');

            return redirect()
                ->route('marketing-imports.show', $batch)
                ->with('ok', 'Ad report queued for background processing.');
        }

        $importer->processBatch($batch);

        return redirect()
            ->route('marketing-imports.show', $batch)
            ->with('ok', 'Ad report imported synchronously (no worker heartbeat detected).');
    }

    public function show(ImportBatch $batch): View
    {
        abort_unless($batch->kind === 'ads', 404);
        $batch->load(['website:id,name', 'user:id,name']);

        return view('marketing.imports.show', compact('batch'));
    }

    public function discard(ImportBatch $batch): RedirectResponse
    {
        abort_unless($batch->kind === 'ads', 404);

        if ($batch->stored_path !== null && Storage::disk('local')->exists($batch->stored_path)) {
            Storage::disk('local')->delete($batch->stored_path);
        }

        $batch->delete();

        return redirect()
            ->route('marketing-imports.index')
            ->with('ok', 'Ad report upload discarded and temporary file deleted.');
    }
}
