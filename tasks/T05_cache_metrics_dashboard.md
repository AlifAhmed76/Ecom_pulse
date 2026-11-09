# T05: Cache layer, MetricsService, plain dashboard, System page

**Depends on:** T04

## Goal
Dashboard loads instantly from a 30-minute shared cache; one calculation is reused everywhere; tenant can clear only its own cache.

## Read first
AGENT_RULES.md, docs/ARCHITECTURE.md (Caching), docs/UI_GUIDE.md, docs/DECISIONS.md

## Deliverables
- App\Support\TenantCache (versioned keys, remember with stampede lock, put, clear = version bump) - never Cache::flush().
- App\Services\MetricsService::for($tenantId)->dashboard() / rebuildDashboard(): websites, connected (heartbeat < 10 min), active credentials, events 24h (more counters are added by later tasks). Uses explicit tenant filters (works inside jobs).
- Plain dashboard from the service; shows 'Updated HH:MM'; 'Refresh data' button (owner/admin, throttled) = clear tenant cache + queue WarmTenantCache on `high`.
- GET /metrics/dashboard.json with ETag, Cache-Control private,max-age=60,stale-while-revalidate=120, 304 support; dashboard polls it every 60 s.
- System page (owner/admin): cache store/TTL/computed_at, scheduler+worker alive booleans, clear-cache button; LLM status placeholder (real in T13).
- Static asset URLs get ?v=filemtime cache-busting.

## Done when (acceptance criteria)
- [ ] Second dashboard read performs 0 SQL queries.
- [ ] Clearing tenant A leaves tenant B's cache intact.
- [ ] Metrics computed without a signed-in user count only that tenant.

## Required tests
- ttl is 1800; cached until cleared; clear isolates tenants; zero-query second read; JSON route ETag + 304; login required; tenant-specific; analyst cannot clear; clear is audited; job-context tenant correctness; stampede lock does not break when lock times out.

## Do NOT
- ['No heavy analytics on the dashboard. No Redis.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ["Open the dashboard twice (second is instant); click Refresh; confirm the other company's numbers are unaffected."]
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
