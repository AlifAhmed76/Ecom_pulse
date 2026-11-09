# T11: R&D rule engine and Action Center

**Depends on:** T10

## Goal
Findings with the 10 mandatory fields, produced by rules and statistics only, in Bangla and English.

## Read first
AGENT_RULES.md, docs/RND_LAB_SPEC.md, docs/METRICS_DEFINITIONS.md

## Deliverables
- research_findings table; App\Support\Stats (two-proportion z-test, z-score, Wilson, pct-change guard) with known-value unit tests.
- App\Services\Rnd\RuleEngine + Rules R01-R12, thresholds in config/lyp.php, severity and confidence rubrics, fingerprint dedupe, 'insufficient data' handling.
- Bilingual templates (full Bangla text written by hand, not machine-translated at runtime). Language toggle.
- RunRndScan job: nightly per tenant (scheduler) + 'Run scan' button; uses AnalyticsService numbers only.
- Action Center page, finding detail page (all 10 fields, evidence link to Analytics with the same filters), actions Review / Dismiss with reason.

## Done when (acceptance criteria)
- [ ] Every finding shows all 10 fields; causes are labelled hypotheses; nothing claims certainty.
- [ ] LLM is not required.

## Required tests
- each rule: triggers on a synthetic dataset, does NOT trigger below thresholds/sample sizes, emits the right severity and confidence, emits 'insufficient data' when too few rows; stats helpers vs known values; dedupe updates not duplicates; templates contain no unfilled placeholders in either language; tenant isolation; analyst can view but not dismiss.

## Do NOT
- ['No experiments yet, no auto-actions, no LLM calls.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Run a scan on demo data and read three findings aloud: do they make sense to a shop owner?']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
