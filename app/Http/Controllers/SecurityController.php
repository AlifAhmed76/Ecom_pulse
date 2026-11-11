<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\SecurityRule;
use App\Models\User;
use App\Services\Security\SecurityEventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function index(Request $request, SecurityEventService $security): View
    {
        /** @var User $user */
        $user = Auth::user();
        $tenantId = (int) $user->tenant_id;

        $rules = $security->ensureDefaultsForTenant($tenantId);

        $typeFilter = trim((string) $request->query('type', ''));
        $statusFilter = trim((string) $request->query('review_status', ''));

        $events = SecurityEvent::query()
            ->when($typeFilter !== '', fn ($q) => $q->where('type', $typeFilter))
            ->when($statusFilter !== '', fn ($q) => $q->where('review_status', $statusFilter))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('security.index', compact('rules', 'events', 'typeFilter', 'statusFilter'));
    }

    public function reviewEvent(Request $request, SecurityEvent $event): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'review_status' => ['required', 'in:open,false_positive,confirmed'],
        ]);

        $event->forceFill([
            'review_status' => $validated['review_status'],
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ])->save();

        AuditLog::record(
            'security.event_reviewed',
            "Marked security event #{$event->id} ({$event->type}) as {$validated['review_status']}"
        );

        return redirect()
            ->route('security.index')
            ->with('ok', "Security event #{$event->id} marked {$validated['review_status']}.");
    }

    public function updateRule(Request $request, SecurityRule $rule): RedirectResponse
    {
        $validated = $request->validate([
            'threshold' => ['required', 'integer', 'min:1', 'max:1000'],
            'mode' => ['required', 'in:log_only,throttle'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $rule->forceFill([
            'threshold' => (int) $validated['threshold'],
            'mode' => $validated['mode'],
            'enabled' => $request->boolean('enabled'),
        ])->save();

        AuditLog::record(
            'security.rule_updated',
            "Updated rule {$rule->type}: mode={$rule->mode}, threshold={$rule->threshold}"
        );

        return redirect()
            ->route('security.index')
            ->with('ok', "Security rule '{$rule->type}' updated.");
    }
}
