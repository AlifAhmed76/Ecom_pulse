# T04: Audit hash-chain log

**Depends on:** T01 (can be done after T03)

## Goal
Tamper-evident audit trail visible per tenant.

## Read first
AGENT_RULES.md, docs/SECURITY_RULES.md, docs/DATA_MODEL.md (audit_logs)

## Deliverables
- audit_logs table; AuditLog::record(action, details, ?tenantId) inside a transaction with lockForUpdate on the last row; hash = sha256(prev|tenant|user|action|details|created_at).
- verifyChain() streaming with cursor; tenants see ONLY their rows; verify button shows pass/fail without leaking other tenants' counts.
- Record: register, login, login_failed, logout, website create/delete, credential issue/rotate/revoke, cache clear, imports, experiment approvals, etc. as those features arrive. Details are short, never secrets or payloads.
- Audit page for owner/admin with pagination.

## Done when (acceptance criteria)
- [ ] Editing or deleting any row in the DB makes Verify fail.
- [ ] Analyst cannot open the page.

## Required tests
- chain valid after N records; tampering with details detected; deletion of a middle row detected; tenant A cannot see tenant B rows; secret strings never appear in details; analyst 403.

## Do NOT
- ['Do not add blockchain/web3 anything. No logging of request bodies.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Change one row directly in MySQL, click Verify, confirm failure.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
