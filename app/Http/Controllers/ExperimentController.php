<?php

namespace App\Http\Controllers;

use App\Models\Experiment;
use App\Models\ResearchFinding;
use App\Models\User;
use App\Models\Website;
use App\Services\Rnd\ExperimentService;
use App\Services\Rnd\FindingTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class ExperimentController extends Controller
{
    public function index(Request $request): View
    {
        $statusFilter = (string) $request->query('status', 'all');
        if ($statusFilter !== 'all' && ! in_array($statusFilter, Experiment::STATUSES, true)) {
            $statusFilter = 'all';
        }

        $websiteIdParam = $request->query('website_id');
        $websiteId = null;
        if ($websiteIdParam !== null && $websiteIdParam !== '') {
            if (! ctype_digit((string) $websiteIdParam)) {
                abort(404);
            }
            $websiteId = (int) $websiteIdParam;
            if (! Website::query()->where('id', $websiteId)->exists()) {
                abort(404);
            }
        }

        $query = Experiment::query()
            ->with([
                'website:id,name,currency',
                'finding:id,rule_code,title,status',
                'owner:id,name',
                'approver:id,name',
                'results',
            ])
            ->orderByDesc('id');

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        if ($websiteId !== null) {
            $query->where('website_id', $websiteId);
        }

        $experiments = $query->paginate(20)->withQueryString();
        $websites = Website::query()->orderBy('name')->get(['id', 'name', 'currency']);

        $statusCounts = Experiment::query()
            ->selectRaw('status, COUNT(*) AS cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        return view('experiments.index', [
            'user' => $request->user(),
            'experiments' => $experiments,
            'websites' => $websites,
            'statusCounts' => $statusCounts,
            'filters' => [
                'status' => $statusFilter,
                'website_id' => $websiteId,
            ],
        ]);
    }

    public function create(Request $request, ExperimentService $service): View
    {
        $user = $request->user();
        $findingParam = $request->query('finding_id');
        $finding = null;
        $prefill = null;

        if ($findingParam !== null && $findingParam !== '') {
            if (! ctype_digit((string) $findingParam)) {
                abort(404);
            }
            $finding = ResearchFinding::query()
                ->with('website:id,name,currency')
                ->findOrFail((int) $findingParam);

            $locale = FindingTemplates::normalizeLocale((string) session('locale', 'en'));
            $bnDigits = (bool) session('bn_digits', false);
            $prefill = $service->prefillFromFinding($finding, $locale, $bnDigits);
        }

        $websites = Website::query()->orderBy('name')->get(['id', 'name', 'currency']);
        $tenantUsers = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return view('experiments.create', [
            'user' => $user,
            'finding' => $finding,
            'prefill' => $prefill,
            'websites' => $websites,
            'tenantUsers' => $tenantUsers,
        ]);
    }

    public function store(Request $request, ExperimentService $service): RedirectResponse
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $data = $request->validate([
            'finding_id' => ['nullable', 'integer'],
            'website_id' => ['nullable', 'integer', Rule::exists('websites', 'id')->where('tenant_id', $tenantId)],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'hypothesis' => ['required', 'string', 'min:5', 'max:2000'],
            'design' => ['required', 'string', 'min:5', 'max:4000'],
            'metric_name' => ['required', 'string', 'min:2', 'max:255'],
            'baseline_value' => ['nullable', 'string', 'max:255'],
            'baseline_sample' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'target_value' => ['required', 'string', 'min:1', 'max:255'],
            'min_sample' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'planned_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $finding = null;
        if (isset($data['finding_id']) && $data['finding_id'] !== null) {
            $finding = ResearchFinding::query()->findOrFail((int) $data['finding_id']);
        }

        $experiment = $service->create($user, $data, $finding);

        return redirect()
            ->route('experiments.show', $experiment)
            ->with('ok', 'Draft experiment created with immutable baseline snapshot. Owner or admin approval is required before starting.');
    }

    public function show(Request $request, Experiment $experiment): View
    {
        $experiment->load([
            'website:id,name,currency',
            'finding:id,rule_code,title,status,outcome_note,severity,confidence',
            'owner:id,name,role',
            'approver:id,name',
            'results.recorder:id,name',
        ]);

        return view('experiments.show', [
            'user' => $request->user(),
            'experiment' => $experiment,
        ]);
    }

    public function approve(Request $request, Experiment $experiment, ExperimentService $service): RedirectResponse
    {
        try {
            $service->approve($experiment, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('ok', "Experiment #{$experiment->id} approved.");
    }

    public function start(Request $request, Experiment $experiment, ExperimentService $service): RedirectResponse
    {
        try {
            $service->start($experiment, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('ok', "Experiment #{$experiment->id} started.");
    }

    public function complete(Request $request, Experiment $experiment, ExperimentService $service): RedirectResponse
    {
        $data = $request->validate([
            'measured_value' => ['required', 'string', 'min:1', 'max:255'],
            'measured_summary' => ['nullable', 'string', 'max:1000'],
            'outcome' => ['required', 'in:validated,rejected,inconclusive'],
            'lesson' => ['required', 'string', 'min:5', 'max:2000'],
            'control_success' => ['nullable', 'integer', 'min:0', 'required_with:control_n,variant_success,variant_n'],
            'control_n' => ['nullable', 'integer', 'min:1', 'gte:control_success', 'required_with:control_success,variant_success,variant_n'],
            'variant_success' => ['nullable', 'integer', 'min:0', 'required_with:control_success,control_n,variant_n'],
            'variant_n' => ['nullable', 'integer', 'min:1', 'gte:variant_success', 'required_with:control_success,control_n,variant_success'],
        ]);

        try {
            $result = $service->complete($experiment, $request->user(), $data);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('ok', "Experiment #{$experiment->id} completed with outcome '{$result->outcome}'. Linked finding updated.");
    }

    public function cancel(Request $request, Experiment $experiment, ExperimentService $service): RedirectResponse
    {
        $data = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $service->cancel($experiment, $request->user(), $data['cancel_reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('ok', "Experiment #{$experiment->id} cancelled.");
    }
}
