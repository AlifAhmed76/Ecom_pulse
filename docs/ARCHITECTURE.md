# ARCHITECTURE

Modular monolith (Laravel). No microservices. Conventional Laravel layout with these namespaces:

```
app/
  Concerns/BelongsToTenant.php        global scope + auto tenant_id on create (only when a user is signed in)
  Support/   Domain, Parse, ColumnMatcher, StatusMapper, TenantCache, Money, Stats (z-test, z-score)
  Services/
    MetricsService         dashboard counters (ONLY place with dashboard SQL)
    AnalyticsService       analytics page numbers (ONLY place with analytics SQL)
    Sync/SyncService       idempotent product/order upsert (connector + import use it)
    Import/                RowReader (csv/xlsx/xml), OrderMapper, OrderImporter, CourierImporter, AdImporter
    Rnd/                   RuleEngine, Rules/R01..R12, Templates (bn/en), Confidence, Severity
    Llm/                   LlmClient, Policy, drivers (optional)
    Chat/                  Retriever, Responder (T14)
  Jobs/  WarmTenantCache, RefreshAllTenants, ProcessImportBatch, RunRndScan, RunLlmExplanation, WorkerBeat
  Http/Controllers, Http/Middleware (SecurityHeaders, RoleMiddleware, VerifyConnectorSignature)
  Policies/
routes/ web.php  api.php (stateless connector API)  console.php (scheduler)
```

## Tenancy
- Every tenant-owned table has tenant_id. Models use BelongsToTenant (global scope by the signed-in user's tenant).
- PITFALL: jobs, scheduler, console and API middleware have NO signed-in user, so the scope is NOT applied. Services called from those contexts MUST filter by tenant explicitly (DB::table(...)->where('tenant_id', $t) or ->withoutGlobalScopes()->where(...)). Every such service needs a test that proves it only sees its own tenant when no user is signed in.
- Route-model binding under the scope returns 404 for other tenants' records (desired).

## Caching ("one calculation, used everywhere")
Pages / JSON route / jobs -> MetricsService or AnalyticsService -> TenantCache -> DB.
- TenantCache key = t{tenantId}:v{version}:{name}. Clear = increment version. Stampede lock on rebuild. TTL 1800 s.
- Every analytics payload includes computed_at, filters, currency, definitions.
- GET /metrics/dashboard.json returns the same cached payload with ETag + Cache-Control private,max-age=60,stale-while-revalidate=120.

## Queues
Names: `high` (user-triggered refresh, heartbeat), `default` (warm/import/sync), `ai` (LLM only, separate worker, timeout 180 s). Imports fall back to synchronous execution only when no worker heartbeat exists (offline demo safety).

## Scheduler (php artisan schedule:work)
every minute: scheduler-beat, worker-beat | every 5 min: prune nonces | every 15 min: warm all tenants | nightly 02:00: R&D scan for all tenants | weekly Sat 08:00: weekly report build.

## Observability
System page shows scheduler/worker alive (booleans only), cache TTL, last computed time, LLM status/usage. No cross-tenant counts.

## Config files
config/lyp.php (cache_ttl, thresholds for rules, low_stock threshold), config/llm.php (driver none|ollama|openai|anthropic, budgets).
