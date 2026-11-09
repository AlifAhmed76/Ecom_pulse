# T06: Scheduler and workers

**Depends on:** T05

## Goal
Background processing never delays a page; owner can see it running.

## Read first
AGENT_RULES.md, docs/ARCHITECTURE.md (Queues, Scheduler)

## Deliverables
- routes/console.php: scheduler-beat (every min), WorkerBeat job (every min, queue high), RefreshAllTenants (every 15 min, withoutOverlapping), lyp:prune (every 5 min). Nightly R&D scan and weekly report entries are added by T11/T16.
- Jobs: WarmTenantCache (ShouldBeUnique per tenant, tries 2, backoff), RefreshAllTenants, WorkerBeat.
- Windows scripts: scheduler.bat (schedule:work loop), worker.bat (queues high,default), worker-ai.bat (queue ai); run.bat starts web + all three.
- System page shows scheduler/worker status from beats (no cross-tenant counts).

## Done when (acceptance criteria)
- [ ] With workers stopped the app still works (cache miss recomputes inline).
- [ ] With workers running, System page shows 'running' within 2 minutes.

## Required tests
- RefreshAllTenants dispatches one job per tenant; WarmTenantCache fills cache; prune removes only old nonces; unique job does not pile up.

## Do NOT
- ['No supervisor/Docker setup yet (documentation only).']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ["Start run.bat; after 2 minutes the System page shows scheduler and worker running; close the worker window and see it turn 'not running'."]
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
