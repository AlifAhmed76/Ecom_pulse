# T17: Packaging and final documentation

**Depends on:** T16

## Goal
A fresh PC can install and demo the project from the zip in minutes.

## Read first
AGENT_RULES.md, docs/DEMO_PLAN.md, docs/ARCHITECTURE.md

## Deliverables
- setup.bat (checks PHP/extensions/Composer/MySQL, creates DB, installs dependencies, migrates, seeds, optimises), run.bat, run-tests.bat, optional docker-compose.redis.yml and docs for Redis/Apache/OPcache.
- README (install, demo logins, demo script), CONNECTOR guide, IMPORT guide, security notes, known limitations, honest status table (designed/implemented/tested/not run).
- Final full test run output; final zip.

## Done when (acceptance criteria)
- [ ] A second PC (or clean folder) can set up from scratch following only the README.

## Required tests
- Full suite green; smoke script hits every page as owner and as analyst with no 500.

## Do NOT
- ['No new features.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Install on a clean folder and run the full DEMO_PLAN script.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
