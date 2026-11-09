# T12: Experiment tracker

**Depends on:** T11

## Goal
Close the loop: finding -> hypothesis -> approved experiment -> measured result -> lesson.

## Read first
AGENT_RULES.md, docs/RND_LAB_SPEC.md (Experiments), docs/DATA_MODEL.md

## Deliverables
- experiments + experiment_results tables; workflow draft -> approved -> running -> completed|cancelled; approval only by owner/admin (analyst cannot); baseline snapshot of the metric at creation.
- Create from a finding (prefilled); result entry with measured values, outcome (validated/rejected/inconclusive) and lesson; closing updates the finding status/outcome.
- Optional rate-metric significance helper (two-proportion test) that never auto-declares success below the minimum sample.
- Audit entries for approve/start/complete.

## Done when (acceptance criteria)
- [ ] An experiment cannot start without approval.
- [ ] Outcome and lesson are stored and visible on the finding.

## Required tests
- state machine transitions (illegal ones rejected); analyst cannot approve; baseline immutable; result updates finding; audit rows written; tenant isolation; significance helper boundaries.

## Do NOT
- ['No automatic price/budget changes. No LLM.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Run one full experiment on demo data from finding to lesson.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
