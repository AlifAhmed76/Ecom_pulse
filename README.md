# Last Year Project (EcomPulse)

Multi-tenant Bangladesh COD e-commerce analytics, courier loss measurement, statistical R&D partner, knowledge-grounded chatbot, and presentation platform built on **Laravel 12.71.0** (`PHP 8.2.10+`, MySQL/MariaDB via XAMPP, SQLite `:memory:` for PHPUnit).

Runs **100% offline** on a Windows + XAMPP PC with zero Node build step and zero external API keys required (`LLM_DRIVER=none` works out of the box), and can switch to Redis/Apache/OPcache via `.env`.

---

## 1. Fresh Windows PC Setup (From Release ZIP or Repo)

### Prerequisites
- Windows 10/11 with **XAMPP** (PHP 8.2+ & MySQL/MariaDB) or standalone PHP 8.2+ + MySQL/MariaDB 10.4+.
- **Composer** installed in `PATH` (not needed if `vendor/` is already present).
- Required PHP extensions: `mbstring`, `pdo_mysql`, `pdo_sqlite`, `zip`, `xmlreader`, `dom`, `fileinfo`, `openssl`, `curl`, `zlib`.

### Step-by-step Installation
1. Extract the release zip (e.g. to `C:\projects\EcomPulse`) and open a terminal inside the extracted folder.
2. Open **XAMPP Control Panel** and click **Start** on **MySQL**.
3. Run the automated setup script:
   ```bat
   setup.bat
   ```
   This verifies PHP 8.2+ & extensions, ensures `storage/` directories exist, runs `composer install`, creates `.env` from `.env.example` with a generated `APP_KEY`, creates the `lastyear` MySQL database, runs all 34 migrations (`migrate --force`), seeds demo accounts (`db:seed --force`), and caches Blade views.
4. Run the full automated test suite (runs strictly on in-memory SQLite per `phpunit.xml`; never touches MySQL):
   ```bat
   run-tests.bat
   ```
5. Start the web server, scheduler (`schedule:work`), and queue workers (`high,default` + `ai`):
   ```bat
   run.bat
   ```
6. Open **<http://127.0.0.1:8000>** in your browser.

---

## 2. Seeded Login Credentials (`DatabaseSeeder`)

| Workspace | Role | Email | Password |
|---|---|---|---|
| **Demo Store** (`demo-store`) | Owner | `owner@demostore.test` | `DemoStore123` |
| **Demo Store** (`demo-store`) | Analyst (read-only on admin tools) | `analyst@demostore.test` | `DemoStore123` |
| **Other Company** (`other-company`) | Owner (isolated 2nd tenant) | `owner@othercompany.test` | `OtherCompany123` |

---

## 3. 10-Minute Offline Presentation Script (Per `docs/DEMO_PLAN.md`)

Preparation: MySQL started, `run.bat` running (`scheduler.bat`, `worker.bat`, `worker-ai.bat`, web server), **System (`/system`)** showing scheduler & worker alive. Optionally pre-load demo data or load it live on `/workspace`.

1. **Tenant Isolation:** Log in as `owner@demostore.test` and `owner@othercompany.test` in separate browser profiles — neither company can access the other's websites, orders, findings, experiments, exports, or weekly reports (`404` across tenants).
2. **Plain Dashboard & Cache:** Open `/dashboard` (`<= 6` KPI cards served instantly from `TenantCache` 30-min cache + ETag JSON endpoint `/metrics/dashboard.json`). Show `/system` live scheduler/worker heartbeats.
3. **One-Click Deterministic Demo Data:** Go to **Workspace (`/workspace`)** as Owner and click **Load demo data**. This deterministically seeds 1 `source=demo` website, 40 products with costs, 2,500 orders across 120 days, 3 couriers (`Pathao`, `Steadfast`, `RedX` return outlier), 4 employees (`Rafiq Hasan` cancel outlier), shipments, history rows, and 135 ad rows. *(Reversible in one click without touching real data.)*
4. **Live File Import (`< 1 min`):** Visit **Imports (`/imports`)**, upload any sample CSV/XLSX/XML, review column auto-detect + `raw -> canonical` status mapping, confirm import, and verify idempotency.
5. **Analytics (`/analytics`):** Show filterable KPIs, Charts (local `chart.umd.js`), 7-indicator 0–100 Data-Quality score, minimum-sample (`< 20`) & cost-coverage (`< 60%`) guards, mixed-currency protection, and metric definitions.
6. **Money Lost & Courier Scorecard (`/courier-loss`):** Highlight COD value lost to cancellations and returns, top areas/reasons, courier scorecard with two-proportion z-test outliers (`n >= 30, p < 0.05`), and advisory risk flags (`S1`/`S2`/`S3`).
7. **R&D Action Center (`/action-center`):** Click **Run scan**. Open a finding (e.g., `R04` or `R01`) showing all 10 mandatory fields, explicit unverified hypotheses, missing data, confidence rationale, and filter links into `/analytics`.
8. **Experiment Tracker (`/experiments`):** Create an experiment from the finding, note immutable baseline snapshot, demonstrate role-guarded approval (`owner|admin`), start, and record outcome (`validated`/`rejected`/`inconclusive`) syncing back to the finding.
9. **Tamper-Evident Audit Log (`/audit`):** Click **Verify Integrity** (valid). Optionally demonstrate tamper detection if editing a row directly in MySQL, then restore.
10. **Marketing (`/marketing`), Chatbot (`/chatbots`), Security (`/security`) & Weekly Summary (`/weekly-reports`):**
    - Show separated Platform ROAS vs Real Delivered-Order ROAS + UTM coverage % on `/marketing`.
    - Ask grounded EN/Bangla questions + ungrounded handoff on a Chatbot **Test Chat** page.
    - *(Optional if Ollama running locally with `LLM_DRIVER=ollama`)* Click **AI explanation** on a finding (`AI-generated hypothesis - not proof`); with `LLM_DRIVER=none`, point out graceful offline fallback.
    - Open **Weekly Reports (`/weekly-reports`)**, build current week, toggle **বাংলা (`bn_digits=1`)**, and click **Print / Save PDF**.
11. *(Optional Cleanup)* On **Workspace (`/workspace`)**, click **Remove demo data** — non-demo records remain completely untouched.

---

## 4. Reference Guides

- **Connector Integration:** [`docs/CONNECTOR_GUIDE.md`](docs/CONNECTOR_GUIDE.md) & [`docs/CONNECTOR_API.md`](docs/CONNECTOR_API.md)
- **File Imports (Order/Courier/Ad):** [`docs/IMPORT_GUIDE.md`](docs/IMPORT_GUIDE.md) & [`docs/IMPORT_SPEC.md`](docs/IMPORT_SPEC.md)
- **Redis / Apache / OPcache Setup:** [`docs/DEPLOYMENT_REDIS_APACHE_OPCACHE.md`](docs/DEPLOYMENT_REDIS_APACHE_OPCACHE.md)
- **Optional Local LLM (Ollama) & Cloud AI:** [`docs/LLM_SETUP.md`](docs/LLM_SETUP.md)
- **Cloudflare / WAF Rollout Notes:** [`docs/CLOUDFLARE_WAF.md`](docs/CLOUDFLARE_WAF.md)
- **Security Rules & Data Model:** [`docs/SECURITY_RULES.md`](docs/SECURITY_RULES.md) & [`docs/DATA_MODEL.md`](docs/DATA_MODEL.md)

---

## 5. Security Notes & Known Limitations

### Security Highlights
- **Strict Tenant Isolation:** All tenant-owned Eloquent models enforce `BelongsToTenant`; background jobs, scheduler, console commands, and API endpoints filter explicitly by verified `tenant_id` (`404` on cross-tenant route binding).
- **Zero Plaintext Customer PII:** Customer phone/email are hashed via `SHA-256(tenant_id|normalized_contact)`; raw contact strings are discarded before DB writes (`chatbot_leads.contact_enc` stores only consented leads encrypted via Laravel `Crypt` and is excluded from CSV ZIP exports).
- **Strict CSP & Output Escaping:** Security headers (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `CSP` without inline scripts on app pages); all user/import/AI output uses Blade `{{ }}` escaping and `@json`.
- **Tamper-Evident Audit Chain:** SHA-256 chained `audit_logs` with secret redaction, preserved cleanly even across tenant deletion.
- **CSV Formula Injection Defense:** Exported ZIP CSVs prefix `'` on leading `=`, `+`, `-`, `@`, `\t`, `\r` and whitespace-prefixed formulas.

### Known Limitations
- **`UNVERIFIED_AGAINST_REAL_DATA`:** Real client Order export (T08), Courier settlement CSV (T10), and Ad platform CSV (T15) were not supplied; Courier and Ad mappers intentionally avoid guessing headers and require manual column selection on upload.
- **Offline Preview/Print Only:** Weekly reports do not send emails or SMS; `.xls` binary files are not supported (save as `.xlsx`/`.csv`).
- **Advisory Safeguards Only:** No autonomous order cancellation, price adjustment, budget changes, IP blocking, or WAF manipulation; security rules default to `log_only`.
- **Single-Currency Sums:** Mixing order currencies (e.g. `BDT` + `USD`) triggers a banner and suppresses summed revenue cards rather than silently converting exchange rates.
- **Windows PHP `extension=zip`:** Local PHP CLI installs without `extension=zip` enabled in `php.ini` must use `run-tests.bat` / `run.bat` (`php -d extension=zip ...`) or uncomment `extension=zip` in `php.ini`.

---

## 6. Project Status Table (`DESIGNED / IMPLEMENTED / TESTED / NOT RUN`)

| Task | Scope | Status | Test Evidence & Unverified / Pending Items |
|---|---|---|---|
| **T00** | Repo & environment | `TESTED` | Passed in PHPUnit suite; live `php artisan serve` tested via owner PC |
| **T01** | Auth, tenancy, roles | `TESTED` | Passed (`AuthAndRolesTest`, `TenantIsolationTest`); MySQL `migrate` + `db:seed` succeeded on T01 |
| **T02** | Websites & HMAC credentials | `TESTED` | Passed (`DomainTest`, `WebsiteCredentialTest`); automated website ownership DNS verify deferred |
| **T03** | Signed Connector API v1 | `TESTED` | Passed (`ConnectorApiTest` — HMAC, skew, nonce replay, scopes, `429`); live store Laravel check deferred |
| **T04** | Audit hash-chain log | `TESTED` | Passed (`AuditLogTest` — tamper/middle-delete detect, secret redaction) |
| **T05** | TenantCache, MetricsService, System | `TESTED` | Passed (`CacheAndMetricsTest` — 1800s TTL, 0-query hit, ETag/304) |
| **T06** | Scheduler & queue workers | `TESTED` | Passed (`SchedulerAndWorkersTest`, `schedule:list`) |
| **T07** | Orders domain & sync API | `TESTED` | Passed (`StatusMapperAndParseTest`, `OrdersSyncAndUiTest`) |
| **T08** | Order file import (CSV/XLSX/XML) + Quick Analysis | `TESTED` | Passed (`ImportReaderAndMapperTest`, `OrderImportTest`); **`UNVERIFIED_AGAINST_REAL_DATA`** |
| **T09** | Analytics page + Data Quality score | `TESTED` | Passed (`AnalyticsTest` — golden math, `<20` & `<60%` guards, 0-query cache) |
| **T10** | Courier CSV import, loss report, risk flags | `TESTED` | Passed (`CourierAndLossTest` — manual mapping, scorecard z-test, S1–S3); **`UNVERIFIED_AGAINST_REAL_DATA`** |
| **T11** | R&D rule engine (`R01`–`R12`) & Action Center | `TESTED` | Passed (`StatsTest`, `RndTemplatesTest`, `RndEngineAndActionCenterTest`) |
| **T12** | Experiment tracker & outcome sync | `TESTED` | Passed (`ExperimentTrackerTest`) |
| **T13** | Optional LLM explanation (`Ollama/OpenAI/Anthropic`) | `TESTED` | Passed (`LlmExplanationTest` via `Http::fake()`); live Ollama binary run on XAMPP PC **`NOT RUN`** |
| **T14** | Grounded chatbot & security events | `TESTED` | Passed (`ChatbotAndSecurityTest`); live Cloudflare WAF perimeter rules **`NOT RUN`** (`CLOUDFLARE_WAF.md` docs-only) |
| **T15** | Ad CSV import, Real ROAS, `R13` | `TESTED` | Passed (`MarketingAndAdImportTest`); **`UNVERIFIED_AGAINST_REAL_DATA`** |
| **T16** | Weekly reports (EN/BN), Demo mode, Export & Delete | `TESTED` | Passed (`WeeklyReportDemoExportDeleteTest`) |
| **T17** | Packaging, guides, smoke test, release zip | `TESTED` | Full suite `173 passed (3370 assertions)` via `run-tests.bat` + `RouteSmokeTest`; `setup.bat` + all 34 migrations + seeder + live `artisan serve` HTTP flow verified on local XAMPP MariaDB (`3306`); release ZIP created at `dist/ecompulse-release.zip`. Remaining `NOT RUN` / `UNVERIFIED`: 2nd-PC zip extract, real Order/Courier/Ad CSVs (`UNVERIFIED_AGAINST_REAL_DATA`), live Ollama binary, live Cloudflare WAF |
