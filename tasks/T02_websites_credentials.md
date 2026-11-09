# T02: Websites and connector credentials

**Depends on:** T01

## Goal
Register a store, normalise its domain, and manage machine credentials safely.

## Read first
AGENT_RULES.md, docs/DATA_MODEL.md (websites, api_credentials), docs/API_CONTRACT.md (Credentials), docs/SECURITY_RULES.md

## Deliverables
- Migrations: websites (platform laravel|file, domain nullable unique, currency, status, verification_token, verified_at, last_heartbeat_at, connector_version), api_credentials.
- App\Support\Domain::normalize (scheme/www/port/path stripped, lowercase, validates host, rejects user:pass@, accepts localhost) with unit tests.
- Website CRUD (index/create/show/destroy): create requires owner/admin; delete owner only; platform `file` needs no domain; domain unique across tenants; ownership shown as 'not verified'.
- CredentialService: key_id lyp_<20 hex>, secret lys_<64 hex>, secret stored with Crypt::encryptString, scopes allow-list, optional expiry.
- Credential UI: create (secret displayed ONCE in a response with Cache-Control no-store, not flashed to session), rotate (old expires in 1 hour), revoke (immediate). Checkbox scopes.
- Policy WebsitePolicy (view/create/manage/delete).

## Done when (acceptance criteria)
- [ ] Secret is never retrievable after creation, never in logs, never in the DB in plain text.
- [ ] Credentials of website A cannot be managed through website B's URL.

## Required tests
- Domain normaliser table tests; same-tenant/cross-tenant; analyst cannot create; only owner deletes; duplicate domain rejected; tenant_id in request ignored; secret shown once and not present in db/audit/log; rotation creates 2nd credential and shortens the first; revoke blocks immediately (verified again in T03); credential belonging to another website returns 404.

## Do NOT
- ['No ownership verification, no WooCommerce/WordPress platform, no API calls yet.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Create a credential, copy the secret, reload the page: the secret must be gone.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
