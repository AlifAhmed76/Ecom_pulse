# T16: Weekly report, demo mode, export and delete

**Depends on:** T15

## Goal
Presentation-ready extras: Bangla weekly summary, one-click demo data, tenant data export and deletion.

## Read first
AGENT_RULES.md, docs/DEMO_PLAN.md, docs/UI_GUIDE.md, docs/SECURITY_RULES.md

## Deliverables
- weekly_reports build job (Saturday 08:00) + page with a print stylesheet; Bangla and English; top findings, money lost, courier highlights, experiment updates.
- Demo mode: deterministic seeder (as in DEMO_PLAN) marked source=demo; 'Load demo data' and 'Remove demo data' (tenant-scoped, owner only).
- Tenant export (zip of CSVs, CSV-injection-safe) and tenant data deletion (owner only, typed confirmation, audited). Queued.

## Done when (acceptance criteria)
- [ ] Demo data can be added and fully removed; export contains only the tenant's data.

## Required tests
- demo seed deterministic and removable; removal leaves non-demo data intact; export excludes other tenants; CSV injection neutralised; delete requires owner + confirmation; weekly report in both languages without placeholders.

## Do NOT
- ['No email/SMS sending (needs internet) - preview and print only.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Load demo, run a scan, print the weekly report, remove demo.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
