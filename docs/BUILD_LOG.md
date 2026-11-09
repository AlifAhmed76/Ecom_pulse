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
