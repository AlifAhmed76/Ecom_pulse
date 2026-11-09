# T00: Environment and repository

**Depends on:** -

## Goal
A clean Laravel project that runs on the owner's Windows XAMPP PC, with this documentation inside the repo and a green first test.

## Read first
AGENT_RULES.md, docs/DECISIONS.md, docs/ARCHITECTURE.md

## Deliverables
- Verify with the owner: `php -v` >= 8.2, `composer -V`, `git --version`, MySQL running in XAMPP, PHP extensions mbstring pdo_mysql pdo_sqlite zip xmlreader dom fileinfo openssl curl. If anything is missing, give the owner step-by-step Windows instructions and WAIT.
- `composer create-project laravel/laravel lastyear`; record the exact Laravel and PHP versions in docs/PROGRESS.md.
- Configure .env.example and .env: MySQL database `lastyear`, CACHE_STORE=file, SESSION_DRIVER=file, QUEUE_CONNECTION=database, APP_TIMEZONE=Asia/Dhaka, SESSION_SAME_SITE=strict. Create the database.
- Replace phpunit.xml so tests use sqlite :memory:, CACHE_STORE=array, SESSION_DRIVER=array, QUEUE_CONNECTION=sync, BCRYPT_ROUNDS=4.
- Copy this package's docs/ into the project, keep AGENT_RULES.md and GEMINI.md at the root, keep reference/ OUT of the Laravel app folders. Ensure .gitignore ignores .env and vendor.
- Install `openspout/openspout:^4.0`.
- Add config/lyp.php with cache_ttl=1800 (env LYP_CACHE_TTL).

## Done when (acceptance criteria)
- [ ] `php artisan serve` shows the Laravel welcome page.
- [ ] `php artisan migrate` succeeds on MySQL.
- [ ] `php artisan test` passes (default example tests are fine).
- [ ] `.env` is not committed; repository has an initial commit.
- [ ] PROGRESS.md contains real version numbers.

## Required tests
- One smoke test: the home route returns 200 on SQLite.

## Do NOT
- ['Do not build any feature. Do not install Livewire, Redis, Docker, Node tooling, or Sanctum.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Run the three commands above on your PC and send the output to the reviewer.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
