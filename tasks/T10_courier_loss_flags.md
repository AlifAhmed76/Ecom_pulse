# T10: Courier report import, loss report, scorecard, suspicious-order flags

**Depends on:** T09 + owner's real courier CSV

## Goal
The investor headline: how much money is lost to cancellation/return, which courier/area is unusual, which orders need human review.

## Read first
AGENT_RULES.md, docs/COURIER_AND_LOSS_SPEC.md, docs/IMPORT_SPEC.md, docs/METRICS_DEFINITIONS.md

## Deliverables
- FIRST: ask the owner for a real courier report CSV. Do not start the mapping code without it.
- courier_shipments + risk_flags migrations; CourierImporter with mapping screen and matching (consignment id -> order ref -> hashed contact + amount), matching coverage report.
- Loss report page; courier scorecard by courier and by area with minimum-sample and z-test labelling; suspicious-order rules S1-S3 as a scheduled job; Review queue (open / reviewed_ok / confirmed) - advisory only.
- Courier/status updates may append order_status_history with source=courier.

## Done when (acceptance criteria)
- [ ] Loss numbers match a hand calculation.
- [ ] No flag ever changes an order.

## Required tests
- golden dataset for loss totals; sample-size guard; z-test outlier boundaries; S1/S2/S3 boundary cases (2 vs 3 failures, 23h vs 25h); review does not alter orders; matching coverage math; tenant isolation.

## Do NOT
- ['No courier live API. No automatic cancel/block.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Import your real courier report and verify coverage % and 3 shipments by hand.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
