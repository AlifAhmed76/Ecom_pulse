<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AuditLog::query()
            ->with('user')
            ->forTenant($request->user()->tenant_id)
            ->orderByDesc('id')
            ->paginate(25);

        return view('audit.index', [
            'logs' => $logs,
            'verifyResult' => session('audit_verify'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $result = AuditLog::verifyChain($request->user()->tenant_id);

        return redirect()
            ->route('audit.index')
            ->with('audit_verify', $result);
    }
}
