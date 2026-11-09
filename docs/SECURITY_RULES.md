# SECURITY RULES
## Dashboard
Session auth, CSRF on every POST, login throttle (6/min), session regenerate on login, logout invalidates, password >= 10 chars with letters+numbers, roles via policies for EVERY tenant-owned resource, 403 for wrong role, 404 for other tenant's records.
Security headers on web responses: X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy same-origin, Permissions-Policy, Content-Security-Policy (self; inline allowed only where unavoidable).
Cookies: HttpOnly, SameSite=strict (lax if a flow needs it), Secure when HTTPS.
## Connector API
See API_CONTRACT.md. HMAC-SHA256, hash_equals, 300 s skew, atomic nonce replay table, scopes, expiry, rotate, revoke, rate limits, generic 401s, 1 MB body cap. Secret encrypted at rest, shown once, never logged or returned again, never in browser JS.
## Data
Tenant isolation: global scope + explicit filters in non-user contexts + tests for both. No raw customer phone/email stored. Logs never contain passwords, secrets, tokens, cookies, Authorization headers, or raw customer payloads. Audit log is hash-chained; tenants see only their rows.
Uploads: extension allow-list, size cap, parsed from private storage, deleted after processing, never served, never executed; XML with DOCTYPE rejected. CSV cells beginning with = + - @ are treated as text and neutralised if ever exported (CSV injection).
Output: Blade {{ }} escaping only for user data; no raw HTML from imports, events, chat or findings; JSON embedded in <script> via @json.
## AI
Tenant-scoped inputs only; no secrets/PII in prompts; untrusted text fenced; LLM cannot call tools or change data; usage budget per tenant; failures never break pages.
## Chatbot / security events (T14)
Widget token: short-lived, domain-restricted, rate-limited, input size-limited; answers only from the tenant's knowledge documents; unknown/sensitive => human handoff; leads need explicit consent timestamp.
Security events: log-only by default; mitigation (throttle) opt-in per rule, staged, with review of false positives. /wp-admin, *.php or VPS/emulator traffic alone is NOT proof of malice.
Cloudflare/WAF: documentation + staged rollout guide only (cannot be exercised offline). State clearly that a token-only integration cannot protect all traffic of an external site.
## Required tests (from the brief)
authentication, authorization (same-tenant allowed, cross-tenant denied), tenant isolation incl. jobs, expired/revoked credentials, bad signature, stale timestamp, replay, rate limit, scopes, idempotent ingestion, sensitive-data-not-logged, XML DOCTYPE rejection, upload type enforcement, AI guardrails (fenced input, budget, no number changes), chatbot grounding/handoff.
