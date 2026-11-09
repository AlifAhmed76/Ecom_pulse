# T01: Authentication, tenancy and roles

**Depends on:** T00

## Goal
Companies (tenants), users with roles, secure login, and ironclad tenant isolation proven by tests BEFORE any business feature exists.

## Read first
AGENT_RULES.md, docs/SECURITY_RULES.md, docs/DATA_MODEL.md (Foundation), docs/ARCHITECTURE.md (Tenancy)

## Deliverables
- Migrations: tenants; users gets tenant_id and role (owner|admin|analyst).
- Register page: creates tenant + owner in a transaction, logs in, regenerates session. Password rule min 10, letters+numbers. Throttle register and login.
- Login/logout with CSRF, session regenerate, generic error message.
- App\Concerns\BelongsToTenant trait (global scope by signed-in user's tenant; sets tenant_id on create ONLY when a user is signed in).
- User::canManage() (owner|admin), isOwner(). Role middleware `role:owner,admin`.
- Layout with navigation (only built pages), security headers middleware, plain login/register views.
- Seeder: tenant 'Demo Store' (owner, analyst) and tenant 'Other Company' (owner), passwords documented in README only for demo.

## Done when (acceptance criteria)
- [ ] Cross-tenant access returns 404; wrong role returns 403; guests are redirected to login.
- [ ] tenant_id and role cannot be set through registration or any form field.
- [ ] Security headers present on responses.

## Required tests
- Guests redirected; same-tenant allowed; other tenant gets 404 for a tenant-owned test model; analyst forbidden on a manager route; registration ignores injected role/tenant_id; weak password rejected; login throttled; session id changes on login; CSRF required.

## Do NOT
- ['No business models yet. Do not read tenant_id or role from request input anywhere.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ["Log in as both demo companies; confirm neither can open the other's URLs."]
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
