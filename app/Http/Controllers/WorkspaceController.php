<?php

namespace App\Http\Controllers;

use App\Jobs\DeleteTenantData;
use App\Jobs\ExportTenantData;
use App\Models\AuditLog;
use App\Models\TenantExport;
use App\Models\User;
use App\Services\Demo\DemoSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $members = User::where('tenant_id', $user->tenant_id)
            ->orderBy('id')
            ->paginate(20);

        $exports = TenantExport::query()
            ->with('requester:id,name')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('workspace', [
            'user' => $user,
            'tenant' => $tenant,
            'members' => $members,
            'demoSummary' => DemoSeeder::summary((int) $user->tenant_id),
            'exports' => $exports,
        ]);
    }

    public function loadDemo(Request $request): RedirectResponse
    {
        $user = $request->user();
        $result = DemoSeeder::seed((int) $user->tenant_id, (int) $user->id);

        if ($result['already_seeded']) {
            return redirect()
                ->route('workspace.show')
                ->with('ok', 'Demo dataset is already loaded for this workspace.');
        }

        return redirect()
            ->route('workspace.show')
            ->with('ok', sprintf(
                'Demo dataset loaded: %d products, %d orders, %d courier shipments, and %d ad rows (marked source=demo). Go to Action Center and click Run scan to inspect R&D findings.',
                $result['products'],
                $result['orders'],
                $result['shipments'],
                $result['ad_rows']
            ));
    }

    public function removeDemo(Request $request): RedirectResponse
    {
        $user = $request->user();
        $removed = DemoSeeder::remove((int) $user->tenant_id, (int) $user->id);

        return redirect()
            ->route('workspace.show')
            ->with('ok', sprintf(
                'Demo dataset removed (%d demo orders, %d demo products, %d demo shipments deleted; non-demo data left intact).',
                $removed['orders_removed'],
                $removed['products_removed'],
                $removed['shipments_removed']
            ));
    }

    public function exportTenant(Request $request): RedirectResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $tz = config('app.timezone', 'Asia/Dhaka');

        $filename = sprintf('workspace-export-%s-%s.zip', $tenant->slug, now($tz)->format('Ymd-His'));

        $export = new TenantExport();
        $export->forceFill([
            'tenant_id' => (int) $user->tenant_id,
            'requested_by' => (int) $user->id,
            'status' => 'queued',
            'filename' => $filename,
        ])->save();

        AuditLog::record(
            'tenant.export.requested',
            "Queued tenant CSV-ZIP export #{$export->id}",
            (int) $user->tenant_id,
            (int) $user->id
        );

        ExportTenantData::dispatch((int) $user->tenant_id, (int) $export->id)->onQueue('default');

        return redirect()
            ->route('workspace.show')
            ->with('ok', 'Workspace ZIP export queued. Refresh or download from Recent Exports below.');
    }

    public function downloadExport(TenantExport $tenantExport): StreamedResponse
    {
        $disk = Storage::disk('local');
        if (! $tenantExport->isReady() || ! $disk->exists((string) $tenantExport->stored_path)) {
            abort(404);
        }

        return $disk->download((string) $tenantExport->stored_path, (string) $tenantExport->filename, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyTenant(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isOwner()) {
            abort(403, 'Only the workspace owner may delete all workspace data.');
        }

        $tenant = $user->tenant;
        $data = $request->validate([
            'confirm_slug' => ['required', 'string'],
        ]);

        if (trim((string) $data['confirm_slug']) !== (string) $tenant->slug) {
            return redirect()
                ->route('workspace.show')
                ->withErrors([
                    'confirm_slug' => "Confirmation did not match. Type '{$tenant->slug}' exactly to permanently delete this workspace.",
                ]);
        }

        $tenantId = (int) $tenant->id;
        $tenantSlug = (string) $tenant->slug;
        $userId = (int) $user->id;

        AuditLog::record(
            'tenant.delete.requested',
            "Owner #{$userId} confirmed permanent deletion of workspace '{$tenantSlug}' (#{$tenantId})",
            $tenantId,
            $userId
        );

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        DeleteTenantData::dispatch($tenantId, $userId, $tenantSlug)->onQueue('default');

        return redirect()
            ->route('login')
            ->with('ok', "Workspace '{$tenantSlug}' deletion has been queued/processed and recorded in the audit log.");
    }
}
