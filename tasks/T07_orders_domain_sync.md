# T07: Orders domain and sync API

**Depends on:** T06

## Goal
Normalised commerce data (products, customers, orders, items, status history, employees) with an idempotent sync path used by the connector and by file import.

## Read first
AGENT_RULES.md, docs/DATA_MODEL.md (Orders domain), docs/API_CONTRACT.md (products, orders), docs/STATUS_MAPPING.md, docs/SECURITY_RULES.md

## Deliverables
- Migrations for products, customers, orders, order_items, order_status_history, employees, order_assignments (indexes as listed).
- App\Support\StatusMapper (+ unit tests for every mapping word), App\Support\Parse (num/date/datetime).
- Models with BelongsToTenant where tenant-owned.
- App\Services\Sync\SyncService: upsertProducts, upsertOrder (transaction, lockForUpdate, stale guard, history only on status change, customer_key hashing, employee assignment, item snapshot + cost snapshot, merge-within-batch option for imports). Explicit tenant filters (no signed-in user in API/jobs).
- POST /products and /orders endpoints with validation per API_CONTRACT.
- Orders list (filters: status, website, search order id; paginated) and order detail (items, status history, employee) + Products list (search, stock, low-stock badge). Read-only.

## Done when (acceptance criteria)
- [ ] Sending the same order twice creates no duplicates and no extra history row.
- [ ] Phone/email are discarded after hashing: no such value in any table or log.

## Required tests
- idempotent upsert; history appended only on change; stale update ignored; item replacement; total rules (given vs computed vs no items); customer hash stable and contains no plaintext; same phone in two tenants gives different keys; tenant/website from credential not body; scopes enforced; validation errors; tenant isolation on lists and detail; N+1 check on list (query count).

## Do NOT
- ['No analytics, no courier tables, no import UI yet.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Post a sample order batch with the sample client; open Orders and the detail page; post again and confirm nothing duplicates.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
