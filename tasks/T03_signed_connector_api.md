# T03: Signed Connector API (heartbeat, events)

**Depends on:** T02

## Goal
Machine-to-machine API that rejects everything except correctly signed, fresh, non-replayed, in-scope requests.

## Read first
AGENT_RULES.md, docs/API_CONTRACT.md, docs/SECURITY_RULES.md

## Deliverables
- routes/api.php prefix v1/connector with throttle limiter `connector` (per key and per IP).
- VerifyConnectorSignature middleware exactly as in API_CONTRACT (order of checks, generic errors, hash_equals, atomic nonce insert via unique index).
- request_nonces and integration_events migrations; POST /heartbeat and POST /events (validation, 100 events max, 64 KB payload, insertOrIgnore idempotency, website id from the credential only).
- JSON error rendering for api/* routes.
- connector-sample/lyp_send.php (reference client) and docs/CONNECTOR_API.md copy kept in sync with API_CONTRACT.md.
- Scheduled/CLI command `lyp:prune` removing nonces older than 15 minutes (NOT in the request path).

## Done when (acceptance criteria)
- [ ] Every error case in the contract returns the specified code without leaking extra detail.
- [ ] A signature computed independently (e.g. with a Python script) matches the server's expectation.

## Required tests
- valid signature OK; missing headers; wrong signature; wrong secret; tampered body; stale and future timestamps; replayed nonce; revoked; expired; unknown key identical to bad signature; missing scope = 403; disabled website; events idempotent; same event id on two websites stored separately; website_id in body ignored; invalid batches 422; payload too large; rate limit returns 429.

## Do NOT
- ['No products/orders endpoints yet. No cookies/sessions on the API.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Run the sample client against your running app: heartbeat 200, events 202, repeat events => duplicates.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
