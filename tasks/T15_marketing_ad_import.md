# T15: Marketing: ad report import and real ROAS

**Depends on:** T09 + owner's real ad CSV

## Goal
Show platform-reported results next to real order results, with honesty about matching coverage.

## Read first
AGENT_RULES.md, docs/IMPORT_SPEC.md (Ad report), docs/METRICS_DEFINITIONS.md

## Deliverables
- FIRST: ask the owner for a real ad report CSV; map fields via the mapping screen.
- ad_performance_daily + AdImporter (idempotent on tenant/platform/campaign/date).
- Marketing page: spend, impressions, clicks, platform conversions, CPA, platform ROAS, real ROAS from orders.utm_campaign matches, matching coverage %, attribution notes, data freshness. No profit claim.
- R&D rule R13 platform-vs-real ROAS gap (only when coverage is shown and >= a threshold).

## Done when (acceptance criteria)
- [ ] Platform numbers and real-order numbers are always shown separately and labelled.

## Required tests
- golden dataset ROAS/CPA; coverage math; idempotent re-import; no division by zero; tenant isolation; R13 respects thresholds.

## Do NOT
- ['No ad-platform APIs, no auto budget changes.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Import a real ad report and compare two campaigns manually.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
