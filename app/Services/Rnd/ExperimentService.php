<?php

namespace App\Services\Rnd;

use App\Models\AuditLog;
use App\Models\Experiment;
use App\Models\ExperimentResult;
use App\Models\ResearchFinding;
use App\Models\User;
use App\Models\Website;
use App\Services\MetricsService;
use App\Support\Stats;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExperimentService
{
    /**
     * Build prefill fields and immutable baseline candidate from a ResearchFinding.
     *
     * @return array<string, mixed>
     */
    public function prefillFromFinding(ResearchFinding $finding, string $locale = 'en', bool $bnDigits = false): array
    {
        $rendered = FindingTemplates::renderFinding($finding, $locale, $bnDigits);
        $ev = is_array($finding->evidence) ? $finding->evidence : [];
        $sm = is_array($rendered['success_metric'] ?? null) ? $rendered['success_metric'] : [];

        $plannedDays = 14;
        if (isset($sm['duration']) && preg_match('/(\d+)/', (string) $sm['duration'], $m) === 1) {
            $plannedDays = max(1, min(365, (int) $m[1]));
        }

        $hypotheses = $rendered['hypotheses'] ?? [];
        $defaultHypothesis = $hypotheses !== []
            ? implode("\n", array_slice($hypotheses, 0, 2))
            : 'Unverified hypothesis: address the root operational cause observed in evidence.';

        $sampleSize = isset($ev['sample_size']) && is_numeric($ev['sample_size'])
            ? (int) $ev['sample_size']
            : null;

        return [
            'finding' => $finding,
            'website_id' => $finding->website_id,
            'title' => mb_substr('['.$finding->rule_code.'] '.$rendered['title'], 0, 255),
            'hypothesis' => $defaultHypothesis,
            'design' => (string) ($rendered['experiment'] ?? $finding->experiment_plan ?? ''),
            'min_sample' => max(30, $sampleSize !== null && $sampleSize < 200 ? $sampleSize : 30),
            'planned_days' => $plannedDays,
            'success_metric' => [
                'metric' => (string) ($sm['metric'] ?? 'Target KPI'),
                'baseline' => (string) ($sm['baseline'] ?? 'n/a'),
                'target' => (string) ($sm['target'] ?? 'Improve vs baseline'),
                'duration' => (string) ($sm['duration'] ?? $plannedDays.' days'),
            ],
            'baseline' => $this->buildBaselineSnapshot($finding, $rendered),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input, ?ResearchFinding $finding = null): Experiment
    {
        $tenantId = (int) $actor->tenant_id;

        if ($finding !== null && (int) $finding->tenant_id !== $tenantId) {
            throw new InvalidArgumentException('Finding does not belong to this workspace.');
        }

        $websiteId = $finding?->website_id ?? (isset($input['website_id']) && $input['website_id'] !== '' ? (int) $input['website_id'] : null);
        if ($websiteId !== null) {
            $siteOk = Website::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $websiteId)
                ->exists();
            if (! $siteOk) {
                throw new InvalidArgumentException('Selected website does not belong to this workspace.');
            }
        }

        $ownerUserId = isset($input['owner_user_id']) && $input['owner_user_id'] !== ''
            ? (int) $input['owner_user_id']
            : (int) $actor->id;
        $ownerOk = User::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $ownerUserId)
            ->exists();
        if (! $ownerOk) {
            throw new InvalidArgumentException('Experiment owner must belong to this workspace.');
        }

        if ($finding !== null) {
            $rendered = FindingTemplates::renderFinding($finding, 'en', false);
            $baseline = $this->buildBaselineSnapshot($finding, $rendered);
            $defaultSm = $rendered['success_metric'];
        } else {
            $baseline = [
                'source' => 'manual',
                'metric' => trim((string) ($input['metric_name'] ?? 'Manual baseline metric')),
                'value' => trim((string) ($input['baseline_value'] ?? '')),
                'sample_size' => isset($input['baseline_sample']) && $input['baseline_sample'] !== '' ? (int) $input['baseline_sample'] : null,
                'window_from' => null,
                'window_to' => null,
                'currency' => 'BDT',
                'captured_at' => now()->format('Y-m-d H:i:s'),
            ];
            $defaultSm = [
                'metric' => trim((string) ($input['metric_name'] ?? 'Target KPI')),
                'baseline' => trim((string) ($input['baseline_value'] ?? 'n/a')),
                'target' => trim((string) ($input['target_value'] ?? 'Improved')),
                'duration' => ((int) ($input['planned_days'] ?? 14)).' days',
            ];
        }

        $successMetric = [
            'metric' => trim((string) ($input['metric_name'] ?? $defaultSm['metric'] ?? 'Target KPI')),
            'baseline' => (string) ($defaultSm['baseline'] ?? $baseline['value'] ?? 'n/a'),
            'target' => trim((string) ($input['target_value'] ?? $defaultSm['target'] ?? 'Improve vs baseline')),
            'duration' => trim((string) ($input['duration_label'] ?? (((int) ($input['planned_days'] ?? 14)).' days'))),
        ];

        return DB::transaction(function () use ($tenantId, $websiteId, $finding, $actor, $ownerUserId, $input, $baseline, $successMetric): Experiment {
            $experiment = new Experiment();
            $experiment->forceFill([
                'tenant_id' => $tenantId,
                'website_id' => $websiteId,
                'finding_id' => $finding?->id,
                'title' => mb_substr(trim((string) $input['title']), 0, 255),
                'hypothesis' => trim((string) $input['hypothesis']),
                'design' => trim((string) $input['design']),
                'baseline' => $baseline,
                'success_metric' => $successMetric,
                'min_sample' => isset($input['min_sample']) && $input['min_sample'] !== '' ? max(1, (int) $input['min_sample']) : 30,
                'planned_days' => isset($input['planned_days']) && $input['planned_days'] !== '' ? max(1, (int) $input['planned_days']) : 14,
                'owner_user_id' => $ownerUserId,
                'status' => Experiment::STATUS_DRAFT,
            ])->save();

            AuditLog::record(
                'rnd.experiment.create',
                "Created draft experiment #{$experiment->id}: {$experiment->title}",
                $tenantId,
                $actor->id
            );

            return $experiment;
        });
    }

    /**
     * Approve a draft experiment (owner or admin only).
     */
    public function approve(Experiment $experiment, User $actor): Experiment
    {
        $this->assertCanManage($actor, $experiment);

        if ($experiment->status !== Experiment::STATUS_DRAFT) {
            throw new InvalidArgumentException("Only draft experiments can be approved (current status: {$experiment->status}).");
        }

        return DB::transaction(function () use ($experiment, $actor): Experiment {
            $now = now();
            $experiment->forceFill([
                'status' => Experiment::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => $now,
            ])->save();

            $finding = $experiment->finding_id !== null
                ? ResearchFinding::withoutGlobalScopes()->where('tenant_id', $experiment->tenant_id)->find($experiment->finding_id)
                : null;

            if ($finding !== null && in_array($finding->status, ['new', 'reviewing'], true)) {
                $finding->forceFill([
                    'status' => 'approved',
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $now,
                ])->save();
            }

            MetricsService::for((int) $experiment->tenant_id)->rebuildDashboard();

            AuditLog::record(
                'rnd.experiment.approve',
                "Approved experiment #{$experiment->id} ({$experiment->title})",
                (int) $experiment->tenant_id,
                $actor->id
            );

            return $experiment;
        });
    }

    /**
     * Start an approved experiment (cannot start from draft or closed states).
     */
    public function start(Experiment $experiment, User $actor): Experiment
    {
        $this->assertCanManage($actor, $experiment);

        if ($experiment->status !== Experiment::STATUS_APPROVED) {
            throw new InvalidArgumentException("Experiment cannot start without prior approval (current status: {$experiment->status}).");
        }

        return DB::transaction(function () use ($experiment, $actor): Experiment {
            $now = now();
            $experiment->forceFill([
                'status' => Experiment::STATUS_RUNNING,
                'started_at' => $now,
            ])->save();

            $finding = $experiment->finding_id !== null
                ? ResearchFinding::withoutGlobalScopes()->where('tenant_id', $experiment->tenant_id)->find($experiment->finding_id)
                : null;

            if ($finding !== null && in_array($finding->status, ['new', 'reviewing', 'approved'], true)) {
                $finding->forceFill([
                    'status' => 'testing',
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $now,
                ])->save();
            }

            MetricsService::for((int) $experiment->tenant_id)->rebuildDashboard();

            AuditLog::record(
                'rnd.experiment.start',
                "Started experiment #{$experiment->id} ({$experiment->title})",
                (int) $experiment->tenant_id,
                $actor->id
            );

            return $experiment;
        });
    }

    /**
     * Record measured values, human-chosen outcome, and lesson; closes experiment and updates linked finding.
     *
     * @param  array<string, mixed>  $input
     */
    public function complete(Experiment $experiment, User $actor, array $input): ExperimentResult
    {
        $this->assertCanManage($actor, $experiment);

        if ($experiment->status !== Experiment::STATUS_RUNNING) {
            throw new InvalidArgumentException("Only running experiments can be completed (current status: {$experiment->status}).");
        }

        $outcome = (string) ($input['outcome'] ?? '');
        if (! in_array($outcome, ExperimentResult::OUTCOMES, true)) {
            throw new InvalidArgumentException("Invalid experiment outcome: {$outcome}.");
        }

        $lesson = trim((string) ($input['lesson'] ?? ''));
        if ($lesson === '') {
            throw new InvalidArgumentException('Lesson learned is required when completing an experiment.');
        }

        $significance = null;
        if (
            isset($input['control_success'], $input['control_n'], $input['variant_success'], $input['variant_n'])
            && $input['control_success'] !== ''
            && $input['control_n'] !== ''
            && $input['variant_success'] !== ''
            && $input['variant_n'] !== ''
        ) {
            $significance = $this->evaluateRateSignificance(
                (int) $input['control_success'],
                (int) $input['control_n'],
                (int) $input['variant_success'],
                (int) $input['variant_n'],
                max(1, (int) ($experiment->min_sample ?? 30))
            );
        }

        $measured = [
            'metric_value' => trim((string) ($input['measured_value'] ?? '')),
            'summary' => trim((string) ($input['measured_summary'] ?? '')),
            'significance' => $significance,
        ];

        return DB::transaction(function () use ($experiment, $actor, $outcome, $lesson, $measured): ExperimentResult {
            $now = now();
            $tenantId = (int) $experiment->tenant_id;

            $result = new ExperimentResult();
            $result->forceFill([
                'tenant_id' => $tenantId,
                'experiment_id' => $experiment->id,
                'measured' => $measured,
                'outcome' => $outcome,
                'lesson' => $lesson,
                'recorded_by' => $actor->id,
                'recorded_at' => $now,
            ])->save();

            $experiment->forceFill([
                'status' => Experiment::STATUS_COMPLETED,
                'ended_at' => $now,
            ])->save();

            $finding = $experiment->finding_id !== null
                ? ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($experiment->finding_id)
                : null;

            if ($finding !== null) {
                $newFindingStatus = match ($outcome) {
                    'validated' => 'validated',
                    'rejected' => 'rejected',
                    default => 'reviewing', // inconclusive returns to reviewing unless owner dismisses/re-tests
                };

                $noteParts = ['['.strtoupper($outcome).'] Experiment #'.$experiment->id];
                if ($measured['metric_value'] !== '') {
                    $noteParts[] = 'Measured: '.$measured['metric_value'];
                }
                $noteParts[] = 'Lesson: '.$lesson;

                $finding->forceFill([
                    'status' => $newFindingStatus,
                    'outcome_note' => implode(' — ', $noteParts),
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $now,
                ])->save();
            }

            MetricsService::for($tenantId)->rebuildDashboard();

            AuditLog::record(
                'rnd.experiment.complete',
                "Completed experiment #{$experiment->id} outcome={$outcome}",
                $tenantId,
                $actor->id
            );

            return $result;
        });
    }

    /**
     * Cancel an experiment before completion.
     */
    public function cancel(Experiment $experiment, User $actor, ?string $reason = null): Experiment
    {
        $this->assertCanManage($actor, $experiment);

        if (! $experiment->canTransitionTo(Experiment::STATUS_CANCELLED)) {
            throw new InvalidArgumentException("Cannot cancel experiment in status '{$experiment->status}'.");
        }

        return DB::transaction(function () use ($experiment, $actor, $reason): Experiment {
            $now = now();
            $tenantId = (int) $experiment->tenant_id;

            $experiment->forceFill([
                'status' => Experiment::STATUS_CANCELLED,
                'ended_at' => $now,
            ])->save();

            $finding = $experiment->finding_id !== null
                ? ResearchFinding::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($experiment->finding_id)
                : null;

            if ($finding !== null && in_array($finding->status, ['approved', 'testing'], true)) {
                $otherActive = Experiment::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('finding_id', $finding->id)
                    ->where('id', '<>', $experiment->id)
                    ->whereIn('status', [Experiment::STATUS_APPROVED, Experiment::STATUS_RUNNING])
                    ->exists();

                $cleanReason = trim((string) ($reason ?? ''));
                $note = $cleanReason !== ''
                    ? "[CANCELLED] Experiment #{$experiment->id}: {$cleanReason}"
                    : "[CANCELLED] Experiment #{$experiment->id}";

                $finding->forceFill([
                    'status' => $otherActive ? $finding->status : 'reviewing',
                    'outcome_note' => $note,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $now,
                ])->save();
            }

            MetricsService::for($tenantId)->rebuildDashboard();

            AuditLog::record(
                'rnd.experiment.cancel',
                "Cancelled experiment #{$experiment->id} ({$experiment->title})",
                $tenantId,
                $actor->id
            );

            return $experiment;
        });
    }

    /**
     * Optional two-proportion z-test helper for rate metrics.
     * NEVER claims statistical significance or auto-declares success below $minSample per group.
     *
     * @return array{
     *   sufficient_sample: bool,
     *   min_sample_required: int,
     *   control_success: int,
     *   control_n: int,
     *   variant_success: int,
     *   variant_n: int,
     *   control_rate_pct: ?float,
     *   variant_rate_pct: ?float,
     *   diff_pp: ?float,
     *   z_score: ?float,
     *   p_value: ?float,
     *   statistically_significant: bool,
     *   suggested_signal: string,
     *   note: string
     * }
     */
    public function evaluateRateSignificance(
        int $controlSuccess,
        int $controlN,
        int $variantSuccess,
        int $variantN,
        int $minSample = 30
    ): array {
        $req = max(1, $minSample);
        $sufficient = ($controlN >= $req && $variantN >= $req);
        $z = Stats::twoProportionZTest($variantSuccess, $variantN, $controlSuccess, $controlN);

        if ($z === null) {
            return [
                'sufficient_sample' => false,
                'min_sample_required' => $req,
                'control_success' => $controlSuccess,
                'control_n' => $controlN,
                'variant_success' => $variantSuccess,
                'variant_n' => $variantN,
                'control_rate_pct' => null,
                'variant_rate_pct' => null,
                'diff_pp' => null,
                'z_score' => null,
                'p_value' => null,
                'statistically_significant' => false,
                'suggested_signal' => 'invalid_inputs',
                'note' => 'Sample counts and successes are invalid (successes cannot exceed group sample and n must be > 0).',
            ];
        }

        $ctrlRate = round($z['p2'] * 100.0, 2);
        $varRate = round($z['p1'] * 100.0, 2);

        if (! $sufficient) {
            return [
                'sufficient_sample' => false,
                'min_sample_required' => $req,
                'control_success' => $controlSuccess,
                'control_n' => $controlN,
                'variant_success' => $variantSuccess,
                'variant_n' => $variantN,
                'control_rate_pct' => $ctrlRate,
                'variant_rate_pct' => $varRate,
                'diff_pp' => $z['diff_pp'],
                'z_score' => $z['z'],
                'p_value' => $z['p_value'],
                'statistically_significant' => false,
                'suggested_signal' => 'insufficient_sample',
                'note' => "Below minimum sample ({$req} per group: control={$controlN}, variant={$variantN}); system will not declare significance or success — human judgement required.",
            ];
        }

        $isSig = $z['p_value'] < 0.05;

        return [
            'sufficient_sample' => true,
            'min_sample_required' => $req,
            'control_success' => $controlSuccess,
            'control_n' => $controlN,
            'variant_success' => $variantSuccess,
            'variant_n' => $variantN,
            'control_rate_pct' => $ctrlRate,
            'variant_rate_pct' => $varRate,
            'diff_pp' => $z['diff_pp'],
            'z_score' => $z['z'],
            'p_value' => $z['p_value'],
            'statistically_significant' => $isSig,
            'suggested_signal' => $isSig ? 'significant_difference' : 'no_significant_difference',
            'note' => $isSig
                ? "Two-proportion test p={$z['p_value']} (< 0.05) across n=({$controlN}, {$variantN}); final outcome remains a human decision."
                : "Difference not statistically significant (p={$z['p_value']} >= 0.05) across n=({$controlN}, {$variantN}); final outcome remains a human decision.",
        ];
    }

    /**
     * @param  array<string, mixed>  $rendered
     * @return array<string, mixed>
     */
    private function buildBaselineSnapshot(ResearchFinding $finding, array $rendered): array
    {
        $ev = is_array($finding->evidence) ? $finding->evidence : [];
        $sm = is_array($rendered['success_metric'] ?? null) ? $rendered['success_metric'] : [];

        return [
            'source' => 'finding',
            'finding_id' => $finding->id,
            'rule_code' => $finding->rule_code,
            'metric' => (string) ($sm['metric'] ?? 'Finding KPI'),
            'value' => (string) ($sm['baseline'] ?? 'n/a'),
            'money_at_stake' => $finding->money_at_stake !== null ? (string) $finding->money_at_stake : null,
            'currency' => $finding->currency,
            'sample_size' => $ev['sample_size'] ?? null,
            'p_value' => $ev['p_value'] ?? null,
            'window_from' => $ev['window_from'] ?? null,
            'window_to' => $ev['window_to'] ?? null,
            'metrics' => is_array($ev['metrics'] ?? null) ? $ev['metrics'] : [],
            'captured_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function assertCanManage(User $actor, Experiment $experiment): void
    {
        if (! $actor->canManage()) {
            throw new AuthorizationException('Only owner or admin users may approve, start, complete, or cancel experiments.');
        }

        if ((int) $actor->tenant_id !== (int) $experiment->tenant_id) {
            throw new InvalidArgumentException('Experiment does not belong to this workspace.');
        }
    }
}
