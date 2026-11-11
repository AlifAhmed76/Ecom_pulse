<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Experiment;
use App\Models\ExperimentResult;
use App\Models\ResearchFinding;
use App\Models\Website;
use App\Services\Rnd\ExperimentService;
use App\Services\Rnd\FindingTemplates;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ExperimentTrackerTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createSite(int $tenantId, string $name = 'Dhaka Fashion', string $currency = 'BDT'): Website
    {
        $site = new Website([
            'name' => $name,
            'platform' => 'file',
            'currency' => $currency,
        ]);
        $site->forceFill([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();

        return $site;
    }

    private function makeFinding(int $tenantId, Website $site, string $subject = 'global', array $overrides = []): ResearchFinding
    {
        $params = [
            'website' => $site->name,
            'currency' => $site->currency,
            'days' => 7,
            'n' => 140,
            'base_n' => 220,
            'n_c' => 35,
            'base' => '6.00',
            'now' => '25.00',
            'rise_pp' => '19.00',
            'target' => '12.00',
            'missing_reason_pct' => '35.0',
            'dq_score' => 92,
            'p_value' => '0.0002',
            'confidence_reason_key' => 'high_stat',
        ];
        $rendered = FindingTemplates::render('R02.trigger', $params, 'en', false);

        $finding = new ResearchFinding();
        $finding->forceFill(array_merge([
            'tenant_id' => $tenantId,
            'website_id' => $site->id,
            'rule_code' => 'R02',
            'subject_key' => $subject,
            'week_key' => '2026-W46',
            'fingerprint' => hash('sha256', "R02|{$site->id}|{$subject}|2026-W46"),
            'is_insufficient_data' => false,
            'template_key' => 'R02.trigger',
            'params' => $params,
            'title' => $rendered['title'],
            'summary' => $rendered['summary'],
            'evidence' => [
                'window_from' => '2026-11-05',
                'window_to' => '2026-11-11',
                'comparison_baseline' => 'Trailing 28d rate 6.00% (n=220)',
                'sample_size' => 140,
                'p_value' => 0.0002,
                'z_score' => 4.21,
                'data_quality_score' => 92,
                'source_tables' => ['orders', 'order_status_history'],
                'metrics' => [
                    ['label' => 'Last 7d cancel rate', 'value' => '25.00%'],
                    ['label' => 'Trailing 28d cancel rate', 'value' => '6.00%'],
                ],
            ],
            'severity' => 'critical',
            'money_at_stake' => '42000.00',
            'currency' => $site->currency,
            'confidence' => 'high',
            'confidence_reason' => $rendered['confidence_reason'],
            'hypotheses' => $rendered['hypotheses'],
            'missing_data' => $rendered['missing_data'],
            'recommended_action' => $rendered['action'],
            'experiment_plan' => $rendered['experiment'],
            'success_metric' => $rendered['success_metric'],
            'status' => 'new',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ], $overrides));
        $finding->save();

        return $finding;
    }

    public function test_create_prefills_from_finding_and_enforces_immutable_baseline_snapshot(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Baseline Store');
        $site = $this->createSite($t['tenant']->id, 'Dhaka Fashion');
        $finding = $this->makeFinding($t['tenant']->id, $site);

        // Action Center links to experiment creation
        $this->actingAs($t['analyst'])
            ->get('/action-center')
            ->assertOk()
            ->assertSee('Create experiment');

        $this->actingAs($t['analyst'])
            ->get("/action-center/findings/{$finding->id}")
            ->assertOk()
            ->assertSee('Create experiment');

        // Prefilled form displays baseline snapshot copied from finding
        $this->actingAs($t['analyst'])
            ->get("/experiments/create?finding_id={$finding->id}")
            ->assertOk()
            ->assertSee('Immutable Baseline Snapshot')
            ->assertSee('[R02]')
            ->assertSee('25.00%')
            ->assertSee('42,000.00');

        // POST even with spoofed status/tenant_id/approved_by/baseline must force draft + actor's tenant + frozen finding baseline
        $this->actingAs($t['analyst'])
            ->post('/experiments', [
                'finding_id' => $finding->id,
                'title' => '[R02] Confirm COD orders within 2 hours',
                'hypothesis' => 'Unverified hypothesis: faster confirmation reduces fake/impulse cancellations.',
                'design' => 'Call odd-numbered COD orders within 2h; leave even orders on standard queue for 14 days.',
                'metric_name' => 'Cancellation rate',
                'baseline_value' => '99.99% SPOOFED',
                'target_value' => '<= 12.00%',
                'min_sample' => 50,
                'planned_days' => 14,
                'status' => 'running',
                'approved_by' => $t['owner']->id,
                'tenant_id' => $t['tenant']->id + 99,
            ])
            ->assertRedirect();

        $exp = Experiment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame((int) $t['tenant']->id, (int) $exp->tenant_id);
        $this->assertSame('draft', $exp->status);
        $this->assertNull($exp->approved_by);
        $this->assertNull($exp->approved_at);
        $this->assertSame('finding', $exp->baseline['source']);
        $this->assertSame('25.00%', $exp->baseline['value']);
        $this->assertSame(140, $exp->baseline['sample_size']);
        $this->assertSame('42000.00', $exp->baseline['money_at_stake']);

        // Analyst creating a draft must NOT advance the linked finding status
        $finding->refresh();
        $this->assertSame('new', $finding->status);

        // Baseline snapshot is strictly immutable once persisted
        $this->expectException(LogicException::class);
        $exp->forceFill(['baseline' => ['source' => 'tampered']])->save();
    }

    public function test_state_machine_rejects_illegal_transitions_and_blocks_start_without_approval(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 14, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('State Machine Store');
        $site = $this->createSite($t['tenant']->id);
        $finding = $this->makeFinding($t['tenant']->id, $site);

        /** @var ExperimentService $svc */
        $svc = app(ExperimentService::class);

        $exp = $svc->create($t['owner'], [
            'title' => '2h COD confirmation call',
            'hypothesis' => 'Confirmation call cuts impulse cancellations.',
            'design' => 'Test on 100 new COD orders over 14 days.',
            'metric_name' => 'Cancel rate',
            'target_value' => '<= 12%',
            'min_sample' => 40,
            'planned_days' => 14,
        ], $finding);

        // Cannot start a draft without approval (both HTTP & Service level)
        $this->actingAs($t['owner'])
            ->post("/experiments/{$exp->id}/start")
            ->assertSessionHasErrors('status');

        $exp->refresh();
        $this->assertSame('draft', $exp->status);
        $this->assertNull($exp->started_at);

        // Cannot complete a draft or approved experiment before running
        $this->actingAs($t['owner'])
            ->post("/experiments/{$exp->id}/complete", [
                'measured_value' => '11%',
                'outcome' => 'validated',
                'lesson' => 'Attempted premature completion.',
            ])
            ->assertSessionHasErrors('status');
        $this->assertSame('draft', $exp->fresh()->status);

        // Approve -> status approved, finding moves to approved
        $this->actingAs($t['admin'])
            ->post("/experiments/{$exp->id}/approve")
            ->assertRedirect();
        $exp->refresh();
        $this->assertSame('approved', $exp->status);
        $this->assertSame($t['admin']->id, $exp->approved_by);
        $this->assertNotNull($exp->approved_at);
        $this->assertSame('approved', $finding->fresh()->status);

        // Cannot approve an already approved experiment
        $this->actingAs($t['owner'])
            ->post("/experiments/{$exp->id}/approve")
            ->assertSessionHasErrors('status');

        // Still cannot complete while only approved (must start running first)
        try {
            $svc->complete($exp, $t['owner'], [
                'measured_value' => '11%',
                'outcome' => 'validated',
                'lesson' => 'Still not running.',
            ]);
            $this->fail('Expected InvalidArgumentException when completing an experiment before it runs.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('running', $e->getMessage());
        }

        // Start running -> status running, finding moves to testing
        $this->actingAs($t['owner'])
            ->post("/experiments/{$exp->id}/start")
            ->assertRedirect();
        $exp->refresh();
        $this->assertSame('running', $exp->status);
        $this->assertNotNull($exp->started_at);
        $this->assertSame('testing', $finding->fresh()->status);

        // Complete -> status completed, cannot transition further
        $this->actingAs($t['admin'])
            ->post("/experiments/{$exp->id}/complete", [
                'measured_value' => '11.40%',
                'measured_summary' => 'Observed 11.4% cancel rate across 120 confirmed calls.',
                'outcome' => 'validated',
                'lesson' => '2h WhatsApp + call confirmation cut cancel rate in half without hurting fulfillment.',
            ])
            ->assertRedirect();

        $exp->refresh();
        $this->assertSame('completed', $exp->status);
        $this->assertNotNull($exp->ended_at);

        // Terminal state rejects approve, start, complete, cancel
        $this->actingAs($t['owner'])->post("/experiments/{$exp->id}/approve")->assertSessionHasErrors('status');
        $this->actingAs($t['owner'])->post("/experiments/{$exp->id}/start")->assertSessionHasErrors('status');
        $this->actingAs($t['owner'])->post("/experiments/{$exp->id}/cancel")->assertSessionHasErrors('status');
        $this->actingAs($t['owner'])->post("/experiments/{$exp->id}/complete", [
            'measured_value' => '9%',
            'outcome' => 'rejected',
            'lesson' => 'Duplicate complete should fail cleanly.',
        ])->assertSessionHasErrors('status');

        // Also test cancellation workflow reverts finding from approved/testing back to reviewing
        $finding2 = $this->makeFinding($t['tenant']->id, $site, 'dhaka-cancel');
        $expCancel = $svc->create($t['owner'], [
            'title' => 'Test SMS nudge',
            'hypothesis' => 'SMS confirmation saves call time.',
            'design' => 'Send SMS 15m after COD order.',
            'metric_name' => 'Cancel rate',
            'target_value' => '<= 15%',
        ], $finding2);
        $svc->approve($expCancel, $t['owner']);
        $this->assertSame('approved', $finding2->fresh()->status);

        $this->actingAs($t['owner'])
            ->post("/experiments/{$expCancel->id}/cancel", ['cancel_reason' => 'SMS gateway maintenance'])
            ->assertRedirect();
        $this->assertSame('cancelled', $expCancel->fresh()->status);
        $this->assertSame('reviewing', $finding2->fresh()->status);
        $this->actingAs($t['owner'])->post("/experiments/{$expCancel->id}/start")->assertSessionHasErrors('status');
    }

    public function test_analyst_cannot_approve_start_complete_or_cancel_experiments(): void
    {
        $t = $this->makeTenantWithUsers('RBAC Store');
        $site = $this->createSite($t['tenant']->id);
        $finding = $this->makeFinding($t['tenant']->id, $site);

        /** @var ExperimentService $svc */
        $svc = app(ExperimentService::class);
        $exp = $svc->create($t['analyst'], [
            'title' => 'Courier switch pilot',
            'hypothesis' => 'Moving Dhaka Express share cuts returns.',
            'design' => 'Send 50 orders via Courier B.',
            'metric_name' => 'Return rate',
            'target_value' => '<= 8%',
        ], $finding);

        // Analyst receives HTTP 403 on all state transitions
        $this->actingAs($t['analyst'])->post("/experiments/{$exp->id}/approve")->assertForbidden();
        $this->actingAs($t['analyst'])->post("/experiments/{$exp->id}/start")->assertForbidden();
        $this->actingAs($t['analyst'])->post("/experiments/{$exp->id}/cancel")->assertForbidden();
        $this->actingAs($t['analyst'])->post("/experiments/{$exp->id}/complete", [
            'measured_value' => '7%',
            'outcome' => 'validated',
            'lesson' => 'Analyst should never complete.',
        ])->assertForbidden();

        // Analyst can still view experiment detail safely (with read-only note)
        $this->actingAs($t['analyst'])
            ->get("/experiments/{$exp->id}")
            ->assertOk()
            ->assertSee('View only: Only owner or admin users may approve');

        // Direct service invocation also rejects analyst via AuthorizationException
        $this->expectException(AuthorizationException::class);
        $svc->approve($exp, $t['analyst']);
    }

    public function test_results_sync_to_finding_and_write_verified_audit_entries(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 16, 0, 0, 'Asia/Dhaka'));

        $t = $this->makeTenantWithUsers('Result Sync Store');
        $site = $this->createSite($t['tenant']->id);
        $fVal = $this->makeFinding($t['tenant']->id, $site, 'subj-val');
        $fRej = $this->makeFinding($t['tenant']->id, $site, 'subj-rej');
        $fInc = $this->makeFinding($t['tenant']->id, $site, 'subj-inc');

        /** @var ExperimentService $svc */
        $svc = app(ExperimentService::class);

        // 1) Validated outcome -> finding validated + visible on finding detail + audit entries written
        $eVal = $svc->create($t['owner'], [
            'title' => 'Call queue SLA test',
            'hypothesis' => 'Fast confirmation reduces cancellations.',
            'design' => 'Call within 2h for 14 days.',
            'metric_name' => 'Cancel rate',
            'target_value' => '<= 12%',
            'min_sample' => 50,
            'planned_days' => 14,
        ], $fVal);
        $svc->approve($eVal, $t['admin']);
        $svc->start($eVal, $t['owner']);

        // Record below-min-sample z-test metrics cleanly while human records validated
        $this->actingAs($t['owner'])
            ->post("/experiments/{$eVal->id}/complete", [
                'measured_value' => '10.50%',
                'measured_summary' => '42% lower cancel rate in pilot week',
                'outcome' => 'validated',
                'lesson' => 'Dedicated morning confirmation slot keeps cancel rate under 12%.',
                'control_success' => 10,
                'control_n' => 40,
                'variant_success' => 2,
                'variant_n' => 40,
            ])
            ->assertRedirect();

        $fVal->refresh();
        $this->assertSame('validated', $fVal->status);
        $this->assertStringContainsString('[VALIDATED]', (string) $fVal->outcome_note);
        $this->assertStringContainsString('10.50%', (string) $fVal->outcome_note);
        $this->assertStringContainsString('Dedicated morning confirmation slot keeps cancel rate under 12%.', (string) $fVal->outcome_note);

        // Visible directly on Finding detail page Section 10
        $this->actingAs($t['analyst'])
            ->get("/action-center/findings/{$fVal->id}")
            ->assertOk()
            ->assertSee('Linked experiments')
            ->assertSee('Validated')
            ->assertSee('10.50%')
            ->assertSee('Dedicated morning confirmation slot keeps cancel rate under 12%.');

        // Visible on Experiment detail page (with guarded significance below min_sample=50!)
        $resVal = ExperimentResult::withoutGlobalScopes()->where('experiment_id', $eVal->id)->firstOrFail();
        $this->assertFalse($resVal->measured['significance']['sufficient_sample']);
        $this->assertFalse($resVal->measured['significance']['statistically_significant']);
        $this->actingAs($t['owner'])
            ->get("/experiments/{$eVal->id}")
            ->assertOk()
            ->assertSee('Below minimum sample (50 per group)');

        // 2) Rejected outcome -> finding rejected
        $eRej = $svc->create($t['owner'], [
            'title' => 'Pre-paid badge test',
            'hypothesis' => 'Badge increases prepaid.',
            'design' => 'Show COD notice.',
            'metric_name' => 'Cancel rate',
            'target_value' => '<= 10%',
        ], $fRej);
        $svc->approve($eRej, $t['owner']);
        $svc->start($eRej, $t['owner']);
        $svc->complete($eRej, $t['owner'], [
            'measured_value' => '26.00%',
            'outcome' => 'rejected',
            'lesson' => 'Checkout badge added friction without helping confirmation rate.',
        ]);
        $this->assertSame('rejected', $fRej->fresh()->status);

        // 3) Inconclusive outcome -> finding stays open in reviewing
        $eInc = $svc->create($t['owner'], [
            'title' => 'Evening follow-up test',
            'hypothesis' => 'Second retry rescues unreachable numbers.',
            'design' => 'Retry at 7pm.',
            'metric_name' => 'Cancel rate',
            'target_value' => '<= 15%',
        ], $fInc);
        $svc->approve($eInc, $t['owner']);
        $svc->start($eInc, $t['owner']);
        $svc->complete($eInc, $t['owner'], [
            'measured_value' => '21.00%',
            'outcome' => 'inconclusive',
            'lesson' => 'Traffic paused mid-week; repeat test next normal week.',
        ]);
        $fInc->refresh();
        $this->assertSame('reviewing', $fInc->status);
        $this->assertTrue($fInc->isOpen());

        // 4) Audit logs recorded for create/approve/start/complete and hash chain remains intact
        $actions = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $t['tenant']->id)
            ->pluck('action')
            ->all();

        $this->assertContains('rnd.experiment.create', $actions);
        $this->assertContains('rnd.experiment.approve', $actions);
        $this->assertContains('rnd.experiment.start', $actions);
        $this->assertContains('rnd.experiment.complete', $actions);

        $chain = AuditLog::verifyChain($t['tenant']->id);
        $this->assertTrue($chain['valid'], 'Audit hash chain must remain unbroken after experiment lifecycle.');
    }

    public function test_significance_helper_never_auto_declares_success_below_min_sample(): void
    {
        /** @var ExperimentService $svc */
        $svc = app(ExperimentService::class);

        // 1) Extreme separation (25/25 vs 0/25 => p < 0.0001) STILL blocked because n=25 < min_sample=30
        $below = $svc->evaluateRateSignificance(25, 25, 0, 25, 30);
        $this->assertFalse($below['sufficient_sample']);
        $this->assertFalse($below['statistically_significant']);
        $this->assertSame('insufficient_sample', $below['suggested_signal']);
        $this->assertLessThan(0.01, $below['p_value']);

        // 2) One arm below minimum sample (50 vs 29 < 30) -> still never auto-declares success
        $oneArmShort = $svc->evaluateRateSignificance(30, 50, 1, 29, 30);
        $this->assertFalse($oneArmShort['sufficient_sample']);
        $this->assertFalse($oneArmShort['statistically_significant']);
        $this->assertSame('insufficient_sample', $oneArmShort['suggested_signal']);

        // 3) Invalid counts (success > n or zero n)
        $invalid = $svc->evaluateRateSignificance(35, 30, 10, 40, 30);
        $this->assertFalse($invalid['sufficient_sample']);
        $this->assertFalse($invalid['statistically_significant']);
        $this->assertSame('invalid_inputs', $invalid['suggested_signal']);

        // 4) Sufficient sample (>= 30 each) with large difference -> statistically_significant true (still advisory)
        $sig = $svc->evaluateRateSignificance(30, 100, 12, 100, 30);
        $this->assertTrue($sig['sufficient_sample']);
        $this->assertTrue($sig['statistically_significant']);
        $this->assertSame('significant_difference', $sig['suggested_signal']);
        $this->assertLessThan(0.05, $sig['p_value']);

        // 5) Sufficient sample with noisy small difference -> not significant
        $noSig = $svc->evaluateRateSignificance(10, 40, 9, 40, 30);
        $this->assertTrue($noSig['sufficient_sample']);
        $this->assertFalse($noSig['statistically_significant']);
        $this->assertSame('no_significant_difference', $noSig['suggested_signal']);
        $this->assertGreaterThanOrEqual(0.05, $noSig['p_value']);
    }

    public function test_tenant_isolation_prevents_cross_tenant_view_create_filter_or_transitions(): void
    {
        $a = $this->makeTenantWithUsers('Tenant Alpha');
        $b = $this->makeTenantWithUsers('Tenant Beta');

        $siteA = $this->createSite($a['tenant']->id, 'Alpha Shop');
        $siteB = $this->createSite($b['tenant']->id, 'Beta Shop');

        $findingA = $this->makeFinding($a['tenant']->id, $siteA, 'alpha-only');

        /** @var ExperimentService $svc */
        $svc = app(ExperimentService::class);
        $expA = $svc->create($a['owner'], [
            'title' => 'Alpha Secret Experiment',
            'hypothesis' => 'Alpha hypothesis details.',
            'design' => 'Alpha private test design.',
            'metric_name' => 'Cancel rate',
            'target_value' => '<= 10%',
        ], $findingA);

        $expB = $svc->create($b['owner'], [
            'title' => 'Beta Visible Experiment',
            'hypothesis' => 'Beta hypothesis details.',
            'design' => 'Beta manual design.',
            'metric_name' => 'Return rate',
            'baseline_value' => '15%',
            'target_value' => '<= 8%',
            'website_id' => $siteB->id,
        ], null);

        // Tenant B index lists only Beta experiments
        $this->actingAs($b['owner'])
            ->get('/experiments')
            ->assertOk()
            ->assertSee('Beta Visible Experiment')
            ->assertDontSee('Alpha Secret Experiment');

        // Cross-tenant website filter -> 404
        $this->actingAs($b['owner'])
            ->get('/experiments?website_id='.$siteA->id)
            ->assertNotFound();

        // Cross-tenant experiment view & state endpoints -> 404
        $this->actingAs($b['owner'])->get("/experiments/{$expA->id}")->assertNotFound();
        $this->actingAs($b['owner'])->post("/experiments/{$expA->id}/approve")->assertNotFound();
        $this->actingAs($b['owner'])->post("/experiments/{$expA->id}/start")->assertNotFound();
        $this->actingAs($b['owner'])->post("/experiments/{$expA->id}/cancel")->assertNotFound();
        $this->actingAs($b['owner'])->post("/experiments/{$expA->id}/complete", [
            'measured_value' => '1%',
            'outcome' => 'validated',
            'lesson' => 'Cross tenant attempt.',
        ])->assertNotFound();

        // Cross-tenant finding prefill & POST creation -> 404
        $this->actingAs($b['owner'])
            ->get("/experiments/create?finding_id={$findingA->id}")
            ->assertNotFound();

        $this->actingAs($b['owner'])
            ->post('/experiments', [
                'finding_id' => $findingA->id,
                'title' => 'Hijack finding A',
                'hypothesis' => 'Cross tenant finding link test.',
                'design' => 'Should be 404.',
                'metric_name' => 'Cancel rate',
                'target_value' => '<= 5%',
            ])
            ->assertNotFound();

        // Cross-tenant website_id & owner_user_id on manual creation -> rejected via validation
        $this->actingAs($b['owner'])
            ->post('/experiments', [
                'website_id' => $siteA->id,
                'owner_user_id' => $a['owner']->id,
                'title' => 'Cross tenant site bind',
                'hypothesis' => 'Should fail validation cleanly.',
                'design' => 'Invalid FK scope.',
                'metric_name' => 'Cancel rate',
                'target_value' => '<= 5%',
            ])
            ->assertSessionHasErrors(['website_id', 'owner_user_id']);

        // Confirm Alpha's experiment wasn't modified
        $this->assertSame('draft', $expA->fresh()->status);
        $this->assertSame('Beta Visible Experiment', $expB->fresh()->title);
    }
}
