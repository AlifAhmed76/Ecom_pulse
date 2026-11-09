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
