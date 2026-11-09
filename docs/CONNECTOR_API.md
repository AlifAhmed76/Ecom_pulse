# CONNECTOR API v1 (stateless JSON, no cookies, HTTPS in production)

Base: `/api/v1/connector`. Rate limit: `120/min` per key + `300/min` per IP (`429`).

## Credentials
Created in the dashboard under Websites. Public `key_id` (`lyp_...`) + secret (`lys_...` shown ONCE). Server stores secret encrypted (`Crypt`) because HMAC needs it. Scopes: `heartbeat`, `events:write`, `products:write`, `orders:write`. Optional expiry. Rotate = new credential, old expires in 1 h. Revoke = immediate.

## Signing every request
Headers:
- `X-LYP-Key`: key ID (`lyp_...`)
- `X-LYP-Timestamp`: unix seconds (`|skew| <= 300 s`)
- `X-LYP-Nonce`: `16-64` chars `[A-Za-z0-9_-]`, new per request incl. retries
- `X-LYP-Signature`: lowercase hex `HMAC-SHA256(secret, canonical)`

Canonical string:
```
METHOD "\n" PATH "\n" TIMESTAMP "\n" NONCE "\n" sha256hex(raw_body)
```
(`PATH` has no query string, e.g. `/api/v1/connector/heartbeat`.)

Verification order:
1. Headers present -> `401 missing_headers`
2. Body `<= 1 MB` -> `413 payload_too_large`
3. Timestamp within `+/- 300 s` -> `401 stale_timestamp`
4. Nonce format `[A-Za-z0-9_-]{16,64}` -> `401 bad_nonce`
5. Credential usable & website not disabled (unknown/revoked/expired/disabled site = same generic `401 invalid_credentials`)
6. `hash_equals` signature check -> `401 invalid_credentials`
7. Scope check -> `403 insufficient_scope`
8. Atomic nonce insert -> `401 replayed_request`

Never reveal which check failed beyond: `missing_headers`, `stale_timestamp`, `bad_nonce`, `invalid_credentials`, `insufficient_scope`, `replayed_request`, `payload_too_large`.

## Endpoints
- `POST /api/v1/connector/heartbeat` (scope: `heartbeat`)
  Body: `{"connector_version":"1.0.0"}` -> `200 {"ok":true,"api_version":"v1","server_time":"..."}`
- `POST /api/v1/connector/events` (scope: `events:write`)
  Body: `{"events":[{"id":"...","type":"...","occurred_at":"...","payload":{}}]}` (`1-100` events, payload `<= 64 KB`) -> `202 {"ok":true,"accepted":...,"duplicates":...}`. Idempotent on `(website_id, source_event_id)`.

## Sample client
Run the standalone CLI client against a local server:
```bash
php connector-sample/lyp_send.php http://127.0.0.1:8000 lyp_your_key lys_your_secret
```

## Errors & Retries
Errors return `{"ok":false,"error":"<code>"}` (validation errors return `422` with `errors`). Clients should retry `429`/`5xx` with exponential backoff, a **NEW** nonce/timestamp, and the **SAME** event/order IDs.

## Versioning
Breaking changes => `/api/v2`. Additive fields allowed in `v1`.
