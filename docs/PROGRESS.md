# PROGRESS (update at the end of every task)
Laravel version: 12.71.0 (laravel/laravel v12.12.2)   PHP: 8.2.10   Date started: 2026-11-10

- [x] T00 Environment & repository — TESTED (3 passed, 0 failed)
- [x] T01 Auth, tenancy, roles — TESTED (14 passed, 0 failed)
- [x] T02 Websites & connector credentials — TESTED (31 passed, 0 failed)
- [x] T03 Signed Connector API (heartbeat, events) — TESTED (38 passed, 0 failed)
- [x] T04 Audit hash-chain log — TESTED (41 passed, 0 failed)
- [x] T05 Cache layer, MetricsService, System page, plain dashboard — TESTED (46 passed, 0 failed)
- [ ] T06 Scheduler & workers
- [ ] T07 Orders domain + sync API
- [ ] T08 File import (CSV/XLSX/XML)
- [ ] T09 Analytics page
- [ ] T10 Courier & loss report
- [ ] T11 R&D rule engine + Action Center
- [ ] T12 Experiment tracker
- [ ] T13 Optional LLM explanation
- [ ] T14 Chatbot & security events
- [ ] T15 Marketing (ad CSV)
- [ ] T16 Weekly report, demo mode, export/delete
- [ ] T17 Packaging & final docs

## Status legend: DESIGNED / IMPLEMENTED / TESTED (command + result) / NOT RUN
- T00: TESTED (`php -d extension=zip artisan test` -> Tests: 3 passed (5 assertions); `php -d extension=zip artisan migrate --force` on MySQL `lastyear` succeeded; `php artisan serve` HTTP check NOT RUN directly, deferred to T17/owner PC verification).
- T01: TESTED (`php -d extension=zip artisan test` -> Tests: 14 passed (88 assertions); `migrate --force` + `db:seed --force` on MySQL succeeded).
- T02: TESTED (`php -d extension=zip artisan test` -> Tests: 31 passed (156 assertions), incl. 13 `DomainTest` data cases & 4 `WebsiteCredentialTest` tests; MySQL `migrate --force` succeeded).
- T03: TESTED (`php -d extension=zip artisan test` -> Tests: 38 passed (336 assertions), incl. all 7 `ConnectorApiTest` suites covering HMAC, skew, replay, scopes, rate limit 429, idempotency, 1MB/64KB caps, `lyp:prune`; MySQL `migrate --force` succeeded).
- T04: TESTED (`php -d extension=zip artisan test` -> Tests: 41 passed (359 assertions), incl. `AuditLogTest` chain validation, tamper/middle-delete detection, secret redaction, tenant isolation, analyst 403; MySQL `migrate --force` succeeded).
- T05: TESTED (`php -d extension=zip artisan test` -> Tests: 46 passed (407 assertions), incl. `CacheAndMetricsTest`: 1800s TTL, 0-query cached hit, tenant-isolated version bump clear, ETag/304, auth/role guards, audited clear, non-auth job context, lock timeout fallback).
