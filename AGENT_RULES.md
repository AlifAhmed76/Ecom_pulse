# AGENT RULES (mandatory for every task)

You are the developer on "Last Year Project". The product owner decides scope. A reviewer (Claude) checks your work after each task.

## Reading order before ANY work
1. docs/PROJECT_BRIEF.md  2. docs/DECISIONS.md  3. docs/PROGRESS.md  4. the task file in tasks/  5. only the docs that the task file lists.
`docs/original_brief.pdf` is the source of truth. If anything contradicts it, STOP and ask.

## How you work
1. ONE task at a time, in order. Never start the next task without the owner's go-ahead.
2. Before coding, write 5-10 lines: what you understood, what files you will create/change, what could go wrong. Ask if anything is unclear.
3. Small commits. Never rewrite unrelated files. No "while I'm here" improvements.
4. Do NOT add features, packages, services, or UI that are not in the task. If you think something is needed, ASK first.
5. Never invent an API, library function, courier endpoint, column name or CLI flag. If you are not sure, read the official documentation or the vendor's installed source code and say where you read it. If you cannot verify, say "UNVERIFIED" and ask the owner.
6. Real files from the owner (courier CSV, ad CSV) define the column names. Do not guess them.

## Tests (non-negotiable)
- Write tests with the code. Tenant isolation, authorization, credentials, signatures, replay, idempotency, and AI-guardrail tests are required where the task says so.
- NEVER edit, delete, skip or weaken an existing test to make it pass. Fix the code. If you believe a test is wrong, explain and wait for approval. Tenant-isolation and security tests may only be changed with explicit owner approval.
- Tests run on in-memory SQLite (phpunit.xml) and must never touch the MySQL dev database.
- Run `php artisan test` before reporting. Paste the real output tail.

## Security (always)
- tenant_id, website_id, role, price, status NEVER come from request input. Derive from the authenticated user or the verified credential.
- Code that runs without a signed-in user (jobs, scheduler, API middleware, console) does NOT get the tenant global scope: filter by tenant explicitly.
- Secrets only in .env or encrypted columns. Never hardcode, log, return again, or put in browser JS. Never log passwords, tokens, cookies, Authorization headers, or raw customer payloads.
- Escape all untrusted text in Blade ({{ }} only; no {!! !!} on user data). Never render raw payloads as HTML.
- Validate every request. Parameterised queries only.
- Customer phone/email are never stored; store only the SHA-256 key described in docs/DATA_MODEL.md.
- AI/LLM never computes money or metrics, never takes actions, and never sees secrets or other tenants' data.

## Performance (always)
- No query in a dashboard page except via MetricsService + TenantCache (30 min). No N+1: eager load. Paginate lists. Index columns used in filters.
- Anything slow (sync, import, analytics rebuild, LLM) runs in a queued job, never in a web request.

## Honesty in reports
Separate clearly: DESIGNED / IMPLEMENTED / TESTED (command + result) / NOT RUN. Do not write "done" or "all tests pass" unless you ran them and pasted the output. List anything skipped or uncertain.

## Final report format (end of every task)
```
TASK: Txx
WHAT I UNDERSTOOD:
FILES CREATED/CHANGED:
COMMANDS RUN + RESULT (paste real output tail):
TESTS ADDED (names):
ACCEPTANCE CRITERIA: [ ] each item from the task file, with evidence
NOT RUN / UNVERIFIED / DEVIATIONS:
QUESTIONS FOR OWNER:
```
Update docs/PROGRESS.md (tick items) as the last step.
