# BUILD LOG - Last Year Project

## T00: Environment and repository
**Plan:**
1. Verify PHP >= 8.2, Composer, Git, required extensions (`-d extension=zip`), and XAMPP MariaDB/MySQL (`10.4.32-MariaDB`).
2. Create a clean Laravel stable project via Composer in a temp staging folder, move into workspace root keeping `docs/`, `tasks/`, `AGENT_RULES.md`, `GEMINI.md`, and excluding `reference/`.
3. Install `openspout/openspout:^4.0`, configure `.env` & `.env.example` for MySQL `lastyear` (file cache/session, database queue, `Asia/Dhaka`, `SESSION_SAME_SITE=strict`), and create MySQL database `lastyear`.
4. Configure `phpunit.xml` for SQLite `:memory:` (`CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `BCRYPT_ROUNDS=4`), add `config/lyp.php` (`cache_ttl=1800`), and add a smoke test verifying `/` returns HTTP 200 on SQLite.
5. Run `php artisan migrate` on MySQL, `php artisan test`, and `php -l` on changed PHP files; update `docs/PROGRESS.md` and commit.

**Result (T00):**
- **Files created/changed:** Laravel 12.71.0 skeleton (`app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `artisan`, `composer.json`, `composer.lock`), `config/lyp.php`, `config/app.php` (timezone `env('APP_TIMEZONE', 'Asia/Dhaka')`), `.env.example`, `.env` (untracked), `phpunit.xml`, `tests/Feature/ExampleTest.php`, `docs/DECISIONS_LOG.md`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -v` -> PHP 8.2.10; `composer -V` -> 2.8.6; `git --version` -> 2.42.0.windows.1; `php -d extension=zip -m` -> all required extensions present (`mbstring`, `pdo_mysql`, `pdo_sqlite`, `zip`, `xmlreader`, `dom`, `fileinfo`, `openssl`, `curl`). Note: `php.ini` has `;extension=zip` commented out; `-d extension=zip` is used during build/tests and documented for the owner.
  - `composer create-project laravel/laravel` + `composer require openspout/openspout:^4.0` -> installed `laravel/framework v12.71.0` and `openspout/openspout v4.28.5`.
  - MySQL (`10.4.32-MariaDB`) database `lastyear` created; `php -d extension=zip artisan migrate --force` -> 3 migrations DONE.
  - `php -d extension=zip -l` -> No syntax errors detected.
  - `php -d extension=zip artisan test` -> `Tests: 3 passed (5 assertions), Duration: 0.37s`.
- **NOT RUN / UNVERIFIED:**
  - `php artisan serve` live browser check NOT RUN directly (welcome view HTTP 200 verified via Feature test; owner PC `php.ini` `extension=zip` permanent uncomment documented in setup).
- **Status:** `T00 DONE - tests: 3 passed, 0 failed`

## T01: Authentication, tenancy and roles
**Plan:**
1. Create migrations for `tenants` and add `tenant_id` FK + `role` (`owner|admin|analyst`) to `users`; create `Tenant` model and update `User` model (`isOwner()`, `canManage()`, `isAnalyst()`).
2. Implement `App\Concerns\BelongsToTenant` global scope + `creating` hook (strictly server-derived tenant_id), `RoleMiddleware`, and `SecurityHeaders` middleware (CSP without inline script/style, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy).
3. Build `AuthController` (login/logout with session regenerate & `throttle:6,1`), `RegisterController` (transactional tenant+owner creation ignoring injected `role`/`tenant_id`, 10+ char alphanumeric password rule), `/dashboard`, and a manager-only `/workspace` settings route (`role:owner,admin`).
4. Create `public/css/app.css` (enterprise light-mode SaaS design system with slate/teal palette, sidebar layout, print stylesheet), `public/js/app.js`, and Blade views (`layouts/app`, `welcome`, `auth/login`, `auth/register`, `dashboard`, `workspace`).
5. Seed `Demo Store` (owner + analyst) and `Other Company` (owner), write comprehensive isolation/auth/role/CSRF/throttle tests, run `php artisan test` + `php -l` + MySQL migrate/seed, and commit.

**Result (T01):**
- **Files created/changed:** `database/migrations/2026_11_10_000001_create_tenants_table.php`, `database/migrations/2026_11_10_000002_add_tenant_and_role_to_users_table.php`, `app/Models/Tenant.php`, `app/Models/User.php`, `app/Concerns/BelongsToTenant.php`, `app/Http/Middleware/RoleMiddleware.php`, `app/Http/Middleware/SecurityHeaders.php`, `app/Http/Controllers/AuthController.php`, `app/Http/Controllers/RegisterController.php`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/WorkspaceController.php`, `bootstrap/app.php`, `routes/web.php`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`, `public/css/app.css`, `public/js/app.js`, `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php`, `resources/views/welcome.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/auth/register.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/workspace.blade.php`, `tests/Concerns/CreatesTenants.php`, `tests/Feature/AuthAndRolesTest.php`, `tests/Feature/TenantIsolationTest.php`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -l` across all `app/`, `database/`, `routes/`, `tests/` PHP files -> 0 syntax errors.
  - `php -d extension=zip artisan test` -> `Tests: 14 passed (88 assertions), Duration: 2.03s`.
  - `php -d extension=zip artisan migrate --force; php -d extension=zip artisan db:seed --force` on MySQL `lastyear` -> succeeded.
- **NOT RUN / UNVERIFIED:** Browser manual login check on XAMPP PC deferred to owner verification / T17 live smoke check.
- **Status:** `T01 DONE - tests: 14 passed, 0 failed`

## T02: Websites and connector credentials
**Plan:**
1. Create migrations for `websites` (`platform` laravel|file, nullable unique `domain`, `currency`, `status`, `verification_token`, `verified_at`, `last_heartbeat_at`, `connector_version`) and `api_credentials` (`key_id` unique, `secret_encrypted`, `scopes`, `expires_at`, `revoked_at`, `last_used_at`, `created_by`).
2. Implement `App\Support\Domain::normalize` (strips scheme/www/port/path, lowercases, validates hostnames, allows `localhost`, rejects `user:pass@`) with full unit tests (`tests/Unit/DomainTest.php`).
3. Create `Website` and `ApiCredential` models (`BelongsToTenant` on `Website`), `WebsitePolicy` (view: same-tenant all roles; create/manage: owner|admin; delete: owner only), and `App\Services\CredentialService` (`lyp_<20 hex>`, `lys_<64 hex>`, `Crypt::encryptString`, rotate with 1h grace, immediate revoke).
4. Implement `WebsiteController` and `CredentialController` (one-time secret view with `Cache-Control: no-store`, never flashed to session; cross-website credential URL guard returning 404) + clean SaaS Blade views (`websites/index`, `create`, `show`, `secret`) and add Websites link to sidebar.
5. Write comprehensive unit and feature tests (`DomainTest`, `WebsiteCredentialTest`), run `php artisan test`, `php -l`, and MySQL migrate, update `PROGRESS.md`, and commit.

**Result (T02):**
- **Files created/changed:** `database/migrations/2026_11_10_000003_create_websites_table.php`, `database/migrations/2026_11_10_000004_create_api_credentials_table.php`, `app/Support/Domain.php`, `app/Models/Website.php`, `app/Models/ApiCredential.php`, `app/Policies/WebsitePolicy.php`, `app/Services/CredentialService.php`, `app/Http/Controllers/WebsiteController.php`, `app/Http/Controllers/CredentialController.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`, `public/css/app.css`, `public/js/app.js`, `resources/views/layouts/app.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/websites/{index,create,show,secret}.blade.php`, `tests/Unit/DomainTest.php`, `tests/Feature/WebsiteCredentialTest.php`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -l` across `app/`, `database/`, `routes/`, `tests/` -> 0 syntax errors.
  - `php -d extension=zip artisan test` -> `Tests: 31 passed (156 assertions), Duration: 2.70s`.
  - `php -d extension=zip artisan migrate --force` on MySQL `lastyear` -> `2026_11_10_000003_create_websites_table`, `2026_11_10_000004_create_api_credentials_table` DONE.
- **NOT RUN / UNVERIFIED:** Manual browser reload check on XAMPP PC deferred to owner verification / T17 live smoke check (HTTP `Cache-Control: no-store` and absent secret on GET reload verified via feature tests).
- **Status:** `T02 DONE - tests: 31 passed, 0 failed`

## T03: Signed Connector API (heartbeat, events)
**Plan:**
1. Create migrations & models for `request_nonces` and `integration_events` with unique constraints for atomic replay protection and per-website event idempotency.
2. Enable `routes/api.php` (`/api/v1/connector`) and JSON error rendering on `api/*` in `bootstrap/app.php`, and configure `connector` rate limiter (`120/min` per key, `300/min` per IP) in `AppServiceProvider`.
3. Implement `VerifyConnectorSignature` middleware enforcing the exact `API_CONTRACT.md` check order (headers, 1 MB cap, 300s timestamp skew, nonce regex, credential/website usability, constant-time HMAC-SHA256 signature, scope, atomic nonce insert).
4. Implement `ConnectorController` (`POST /heartbeat` updating heartbeat timestamp & version, `POST /events` enforcing 100-event batch & 64 KB payload caps with `insertOrIgnore` idempotency) plus `lyp:prune` command to delete nonces older than 15 minutes.
5. Create `connector-sample/lyp_send.php`, `docs/CONNECTOR_API.md`, write full `ConnectorApiTest` (plus Python/independent HMAC verification check), run `php artisan test`, `php -l`, MySQL migrate, update `PROGRESS.md`, and commit.

**Result (T03):**
- **Files created/changed:** `database/migrations/2026_11_10_000005_create_request_nonces_table.php`, `database/migrations/2026_11_10_000006_create_integration_events_table.php`, `app/Models/IntegrationEvent.php`, `app/Http/Middleware/VerifyConnectorSignature.php`, `app/Http/Controllers/Api/ConnectorController.php`, `app/Console/Commands/PruneCommand.php`, `routes/api.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `connector-sample/lyp_send.php`, `docs/CONNECTOR_API.md`, `tests/Feature/ConnectorApiTest.php`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -l` across `app/`, `bootstrap/`, `connector-sample/`, `database/`, `routes/`, `tests/` -> 0 syntax errors.
  - `php -d extension=zip artisan test` -> `Tests: 38 passed (336 assertions), Duration: 2.67s`.
  - `php -d extension=zip artisan migrate --force` on MySQL `lastyear` -> `2026_11_10_000005_create_request_nonces_table`, `2026_11_10_000006_create_integration_events_table` DONE.
- **NOT RUN / UNVERIFIED:** Live `connector-sample/lyp_send.php` CLI execution against `php artisan serve` deferred to owner PC / T17 end-to-end live check (standalone HMAC canonical vector & all connector API endpoints verified via `ConnectorApiTest`).
- **Status:** `T03 DONE - tests: 38 passed, 0 failed`

## T04: Audit hash-chain log
**Plan:**
1. Create migration for `audit_logs` (`tenant_id` nullable indexed, `user_id` nullable, `action`, `details(255)`, `prev_hash` nullable, `hash`, `created_at` dateTime).
2. Implement `App\Models\AuditLog` with `record(action, details, ?tenantId, ?userId)` using `DB::transaction` + `lockForUpdate()` on the latest row and `sha256(prev|tenant|user|action|details|created_at)`.
3. Implement `AuditLog::verifyChain(?int $tenantId)` streaming via `cursor()` that verifies `prev_hash` linkage and `hash` integrity across the chain while reporting only the current tenant's row count.
4. Instrument auth (`register`, `login`, `login_failed`, `logout`), `WebsiteController` (`create`, `delete`), and `CredentialController` (`issue`, `rotate`, `revoke`) with short non-sensitive audit entries.
5. Build `AuditController`, routes (`role:owner,admin` only), `audit/index.blade.php` + sidebar link, write `AuditLogTest`, run `php artisan test`, `php -l`, MySQL migrate, update `PROGRESS.md`, and commit.

**Result (T04):**
- **Files created/changed:** `database/migrations/2026_11_10_000007_create_audit_logs_table.php`, `app/Models/AuditLog.php`, `app/Http/Controllers/AuditController.php`, `app/Http/Controllers/AuthController.php`, `app/Http/Controllers/RegisterController.php`, `app/Http/Controllers/WebsiteController.php`, `app/Http/Controllers/CredentialController.php`, `routes/web.php`, `resources/views/layouts/app.blade.php`, `resources/views/audit/index.blade.php`, `tests/Feature/AuditLogTest.php`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -l` across `app/`, `bootstrap/`, `connector-sample/`, `database/`, `routes/`, `tests/` -> 0 syntax errors.
  - `php -d extension=zip artisan test` -> `Tests: 41 passed (359 assertions), Duration: 3.08s`.
  - `php -d extension=zip artisan migrate --force` on MySQL `lastyear` -> `2026_11_10_000007_create_audit_logs_table` DONE (switched `integration_events` and `audit_logs` to `dateTime` to prevent MariaDB 10.4 strict-mode timestamp errors).
- **NOT RUN / UNVERIFIED:** Manual browser verification of MySQL direct-row edit followed by UI Verify click deferred to owner PC check.
- **Status:** `T04 DONE - tests: 41 passed, 0 failed`

## T05: Cache layer, MetricsService, plain dashboard, System page
**Plan:**
1. Implement `App\Support\TenantCache` with per-tenant version keys (`t{id}:ver` -> `t{id}:v{ver}:{name}`), default 1800s TTL, stampede lock recovery on timeout, and isolated tenant `clear()` without ever calling `Cache::flush()`.
2. Implement `App\Services\MetricsService` with explicit tenant-filtered queries (`websites`, `connected`, `active_credentials`, `events_24h`, and future `orders_30d`/`products`/`open_findings` hooks) plus `WarmTenantCache` job targeting queue `high`.
3. Update `DashboardController` and plain `dashboard.blade.php` (max 6 KPI cards, `Updated HH:MM`, owner/admin throttled `Refresh data` button, audited cache clear) and add `GET /metrics/dashboard.json` with ETag, `Cache-Control: private, max-age=60, stale-while-revalidate=120`, and 304 responses.
4. Build `SystemController` and `system/index.blade.php` (`role:owner,admin`) showing cache store, TTL, last computed time, scheduler/worker booleans, LLM placeholder, and clear-cache button; add 60s background polling in `public/js/app.js` and ensure `?v=filemtime` on all asset URLs.
5. Write `CacheAndMetricsTest` covering TTL, zero-query cached read, tenant isolation, lock timeout fallback, ETag/304, auth/role guards, auditing, and job-context tenant filtering; run `php artisan test`, `php -l`, update `PROGRESS.md`, and commit.

**Result (T05):**
- **Files created/changed:** `app/Support/TenantCache.php`, `app/Services/MetricsService.php`, `app/Jobs/WarmTenantCache.php`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/SystemController.php`, `routes/web.php`, `public/js/app.js`, `resources/views/layouts/app.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/system/index.blade.php`, `tests/Feature/CacheAndMetricsTest.php`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -l` across `app/`, `bootstrap/`, `connector-sample/`, `database/`, `routes/`, `tests/` -> 0 syntax errors.
  - `php -d extension=zip artisan test` -> `Tests: 46 passed (407 assertions), Duration: 4.21s`.
- **NOT RUN / UNVERIFIED:** Manual browser verification of 60s dashboard JS polling deferred to owner PC check.
- **Status:** `T05 DONE - tests: 46 passed, 0 failed`

## T06: Scheduler and workers
**Plan:**
1. Update `WarmTenantCache` to implement `ShouldBeUnique` (`uniqueId`, `uniqueFor = 120`, `tries = 2`, `backoff = [15, 60]`) so duplicate warm requests coalesce per tenant.
2. Create `App\Jobs\WorkerBeat` (`sys:worker_beat`, 180s TTL) and `App\Jobs\RefreshAllTenants` (dispatches unique `WarmTenantCache` per tenant on queue `default`).
3. Configure `routes/console.php` schedules: `scheduler-beat` (every minute), `WorkerBeat` on `high` (every minute), `RefreshAllTenants` (every 15 minutes with `withoutOverlapping`), and `lyp:prune` (every 5 minutes).
4. Update `SystemController` / `system/index.blade.php` to display `"Running"` / `"Not running"` status badges strictly from beat cache keys, and create `scheduler.bat`, `worker.bat`, `worker-ai.bat`, and `run.bat`.
5. Write `SchedulerAndWorkersTest` verifying per-tenant dispatch, cache warming, pruning, and unique-job deduplication; run `php artisan test`, `php -l`, update `PROGRESS.md`, and commit.

**Result (T06):**
- **Files created/changed:** `app/Jobs/WarmTenantCache.php`, `app/Jobs/WorkerBeat.php`, `app/Jobs/RefreshAllTenants.php`, `routes/console.php`, `resources/views/system/index.blade.php`, `scheduler.bat`, `worker.bat`, `worker-ai.bat`, `run.bat`, `tests/Feature/SchedulerAndWorkersTest.php`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -l` across `app/`, `bootstrap/`, `connector-sample/`, `database/`, `routes/`, `tests/` -> 0 syntax errors.
  - `php -d extension=zip artisan test` -> `Tests: 50 passed (421 assertions), Duration: 4.21s`.
  - `php -d extension=zip artisan schedule:list` -> 4 schedules listed cleanly (`scheduler-beat`, `worker-beat`, `refresh-all-tenants`, `lyp:prune`).
- **NOT RUN / UNVERIFIED:** Interactive `run.bat` window launch over 2 minutes on XAMPP PC deferred to owner PC verification / T17.
- **Status:** `T06 DONE - tests: 50 passed, 0 failed`

## T07: Orders domain and sync API
**Plan:**
1. Create migrations & tenant-scoped models for `products`, `customers`, `orders`, `order_items`, `order_status_history`, `employees`, and `order_assignments`, and add `low_stock_threshold` to `config/lyp.php`.
2. Implement `App\Support\StatusMapper` (`STATUS_MAPPING.md` with EN & বাংলা synonyms + unit tests) and `App\Support\Parse` (numeric, Bangla digit, date, and timezone-aware datetime parsing).
3. Implement `App\Services\Sync\SyncService` with transactional `lockForUpdate()`, stale-update protection, append-only status history, tenant-salted SHA-256 `customer_key` hashing (discarding raw phone/email), employee assignment, item replacement, and product cost fallback.
4. Add signed connector endpoints `POST /api/v1/connector/products` (`products:write`) and `POST /api/v1/connector/orders` (`orders:write`) returning `{ok, created, updated[, stale]}` with strict validation (`no items => total required`, `website_id`/`tenant_id` from credential only).
5. Build read-only `OrderController` (`index` w/ filters, `show` w/ items & status history) and `ProductController` (`index` w/ search & low-stock badge), add nav links & Blade views, write unit & feature tests (incl. N+1 query assertion), run tests & MySQL migrate, update `PROGRESS.md`, and commit.

**Result (T07):**
- **Files created/changed:** `config/lyp.php`, `database/migrations/2026_11_10_000008..000014_*.php`, `app/Support/{StatusMapper,Parse}.php`, `app/Models/{Product,Customer,Order,OrderItem,OrderStatusHistory,Employee,OrderAssignment}.php`, `app/Services/Sync/SyncService.php`, `app/Http/Controllers/Api/ConnectorController.php`, `app/Http/Controllers/{OrderController,ProductController}.php`, `routes/{api,web}.php`, `public/css/app.css`, `resources/views/layouts/app.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/orders/{index,show}.blade.php`, `resources/views/products/index.blade.php`, `connector-sample/lyp_send.php`, `docs/CONNECTOR_API.md`, `tests/Unit/StatusMapperAndParseTest.php`, `tests/Feature/OrdersSyncAndUiTest.php`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -l` across `app/`, `bootstrap/`, `connector-sample/`, `database/`, `routes/`, `tests/` -> 0 syntax errors.
  - `php -d extension=zip artisan test` -> `Tests: 110 passed (541 assertions), Duration: 5.05s`.
  - `php -d extension=zip artisan migrate --force` on MySQL `lastyear` -> all 7 T07 migrations DONE.
- **NOT RUN / UNVERIFIED:** Live `connector-sample/lyp_send.php` end-to-end execution against `php artisan serve` deferred to owner PC / T17 final live verification.
- **Status:** `T07 DONE - tests: 110 passed, 0 failed`

## T08: File import: orders (CSV / XLSX / XML)
**Plan:**
1. Create migrations & tenant-scoped models for `import_batches` and `analyses`, plus copy local Chart.js bundle to `public/js/chart.umd.js`.
2. Implement `App\Services\Import\RowReader` (UTF-8 BOM & multi-delimiter CSV, OpenSpout ^4 XLSX with DateTime/serial dates, `LIBXML_NONET` XML with `<!DOCTYPE`/entity rejection and nested-item expansion) and `App\Support\ColumnMatcher` (exact alias then length >= 5 substring, single-use assignment).
3. Implement `OrderMapper` (exact `IMPORT_SPEC.md` aliases, `(100)` negatives, ambiguous date rule) and `OrderImporter` (adjacent grouping, within-batch merge vs cross-batch replace, product-free total mode, status mapping table, skipped reasons, private storage file cleanup, tenant cache clear + warm dispatch) plus `ProcessImportBatch` job with offline no-worker synchronous fallback.
4. Build `ImportController` (`role:owner,admin`, 20 MB cap, private storage, mapping UI with explicit common-name/date warnings, confirm, result, discard) and `FileAnalysisController` (reusing `RowReader` without writing to `orders`, saving summary to `analyses`, rendering local Chart.js bar/doughnut charts CSP-safely).
5. Create `tests/Fixtures/synthetic_orders.{csv,xlsx,xml}` and `synthetic_xxe.xml`, write unit/feature tests, run `php artisan test`, `php -l`, MySQL migrate, mark `UNVERIFIED_AGAINST_REAL_DATA` where owner files do not exist yet, update `PROGRESS.md`, and commit.

**Result (T08):**
- **Files created/changed:** `database/migrations/2026_11_10_000015_create_import_batches_table.php`, `database/migrations/2026_11_10_000016_create_analyses_table.php`, `app/Models/{ImportBatch,Analysis}.php`, `app/Support/ColumnMatcher.php` (longest-alias Pass 2 priority to prevent `customer` stealing `customer_phone`/`mobile_number`), `app/Services/Import/{RowReader,OrderMapper,OrderImporter}.php`, `app/Jobs/ProcessImportBatch.php`, `app/Http/Controllers/{ImportController,FileAnalysisController}.php`, `routes/web.php`, `public/js/{chart.umd.js,app.js}`, `resources/views/layouts/app.blade.php`, `resources/views/imports/{index,map,show}.blade.php`, `resources/views/analysis/{index,show}.blade.php`, `tests/Fixtures/synthetic_orders.{csv,xlsx,xml}`, `tests/Fixtures/synthetic_xxe.xml`, `tests/Unit/ImportReaderAndMapperTest.php`, `tests/Feature/OrderImportTest.php`, `docs/PROGRESS.md`.
- **Commands run + result:**
  - `php -l` across all `app/`, `database/`, `routes/`, `tests/` PHP files -> 0 syntax errors (`PHP lint passed on all files`).
  - `php -d extension=zip vendor/phpunit/phpunit/phpunit` -> `OK (121 tests, 743 assertions)`.
- **NOT RUN / UNVERIFIED:**
  - `php -d extension=zip artisan migrate --force` on MySQL `lastyear` failed to connect (`SQLSTATE[HY000] [2002]`, XAMPP MySQL not started on `127.0.0.1:3306`).
  - `php -d extension=zip artisan test` spawns a child PHP process without `-d extension=zip` (`ZipArchive` missing in child until `extension=zip` is uncommented in `C:\php-8.2.10\php.ini`); full suite verified directly via `php -d extension=zip vendor/phpunit/phpunit/phpunit`.
  - `UNVERIFIED_AGAINST_REAL_DATA`: no real shop order export (CSV/XLSX/XML) supplied yet by owner.
- **Status:** `T08 DONE - tests: 121 passed, 0 failed (UNVERIFIED_AGAINST_REAL_DATA)`
