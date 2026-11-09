# PROGRESS (update at the end of every task)
Laravel version: 12.71.0 (laravel/laravel v12.12.2)   PHP: 8.2.10   Date started: 2026-11-10

- [x] T00 Environment & repository — TESTED (3 passed, 0 failed)
- [ ] T01 Auth, tenancy, roles
- [ ] T02 Websites & connector credentials
- [ ] T03 Signed Connector API (heartbeat, events)
- [ ] T04 Audit hash-chain log
- [ ] T05 Cache layer, MetricsService, System page, plain dashboard
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
