# Store Connector Integration Guide (v1)

**Last Year Project** connects to existing Laravel / PHP e-commerce storefronts via stateless HMAC-SHA256 signed JSON endpoints under `/api/v1/connector`. See [`docs/CONNECTOR_API.md`](CONNECTOR_API.md) and [`docs/API_CONTRACT.md`](API_CONTRACT.md) for the wire contract.

---

## 1. Issue Credentials in the Dashboard

1. Sign in as an **Owner** or **Admin** (`owner@demostore.test` in the seeded demo).
2. Open **Websites -> Register Website**, choose platform **`laravel`**, enter the store domain (e.g., `shop.example.com`) and 3-letter currency (`BDT`), and submit.
3. On the website page, issue a Connector Credential selecting only required scopes:
   - `heartbeat`
   - `events:write`
   - `products:write`
   - `orders:write`
4. Copy the public **`key_id`** (`lyp_...`) and one-time **`secret`** (`lys_...`).
   - Secrets are encrypted at rest with Laravel `Crypt` and **never displayed again**.
   - **Rotate** creates a replacement key while keeping the old key valid for 1 hour grace. **Revoke** invalidates immediately.

---

## 2. Canonical Request Signing

Every request MUST send HTTP headers:
- `X-LYP-Key`: `lyp_...`
- `X-LYP-Timestamp`: current UNIX epoch seconds (`|server_time - timestamp| <= 300s`)
- `X-LYP-Nonce`: unique random string (`16–64` chars `[A-Za-z0-9_-]`) — **new on every attempt including retries**
- `X-LYP-Signature`: lowercase hex `hash_hmac('sha256', $canonical, $secret)`

Canonical string format:
```text
METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256hex(raw_body)
```
where `PATH` is the URL path without query string (e.g., `/api/v1/connector/orders`).

Constraints & guarantees:
- Request body max size: **1 MB** (`413 payload_too_large`).
- Rate limits: `120/min` per credential + `300/min` per IP (`429`).
- Reused nonces within the window are rejected atomically (`401 replayed_request`).
- Order sync upserts idempotently by `(website_id, id)` and discards stale updates whose `updated_at` is older than stored `source_updated_at`.
- Customer `phone`/`email` are SHA-256 hashed (`SHA-256(tenant_id|normalized_contact)`) immediately on receipt; raw contact strings are never persisted.

---

## 3. Standalone Sample Client (`connector-sample/lyp_send.php`)

To test a local connector flow offline against `http://127.0.0.1:8000`:

1. Start the app with `run.bat`.
2. Create a website & credential in **Websites**.
3. Run:
   ```bat
   php connector-sample/lyp_send.php http://127.0.0.1:8000 lyp_YOUR_KEY lys_YOUR_SECRET
   ```
   The sample script signs and dispatches:
   1. `POST /api/v1/connector/heartbeat`
   2. `POST /api/v1/connector/products` (sample SKU upsert)
   3. `POST /api/v1/connector/orders` (sample order upsert)
   4. `POST /api/v1/connector/events` (sample custom integration event)

---

## 4. Compatibility Caveats

- WordPress/WooCommerce plugins and SaaS store adapters are out of scope for v1 (use **File Import** for non-Laravel exports).
- Website ownership `verification_token` is stored on registration, while automated DNS/HTML verification is deferred in v1 (`verified_at` remains unverified unless connected).
