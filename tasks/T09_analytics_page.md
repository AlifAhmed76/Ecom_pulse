# T09: Analytics page

**Depends on:** T08

## Goal
Separate Analytics page implementing METRICS_DEFINITIONS exactly, with transparency about freshness and data quality.

## Read first
AGENT_RULES.md, docs/METRICS_DEFINITIONS.md, docs/UI_GUIDE.md, docs/ARCHITECTURE.md

## Deliverables
- App\Services\AnalyticsService (ONLY place with analytics SQL; explicit tenant filters; cached per filter set through TenantCache for 30 min; payload includes computed_at, filters, currency, definitions).
- Page: filter bar (7/30/90 days or custom, website), KPI cards, daily trend, status funnel, top products (revenue/units/return/cancel units), courier table, employee table (assigned/confirmed/cancelled/delivered/returned/response time), stock alerts with cover days, estimated contribution with coverage, data-quality panel + score, definitions panel.
- Currency rule (warn on mixed currencies). Portable SQL (DATE(), CASE WHEN, NOT EXISTS) - no MySQL-only functions in logic.

## Done when (acceptance criteria)
- [ ] Every KPI equals a hand-calculated value on a golden dataset.
- [ ] Page shows range, currency, computed_at, definitions.

## Required tests
- golden dataset exact values for every metric; repeat rate; response time only from non-import history; minimum-sample guards; mixed-currency warning; website filter belonging to another tenant rejected; tenant isolation; second load 0 queries; data-quality score arithmetic.

## Do NOT
- ['No R&D findings yet. No LLM.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Compare 5 numbers with a manual spreadsheet calculation of your own file.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
