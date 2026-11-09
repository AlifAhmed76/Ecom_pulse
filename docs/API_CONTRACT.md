# CONNECTOR API v1 (stateless JSON, no cookies, HTTPS in production)

Base: /api/v1/connector. Rate limit: 120/min per key + 300/min per IP (429).

## Credentials
Created in the dashboard. Public `key_id` (lyp_...) + secret (lys_... shown ONCE). Server stores secret encrypted (Crypt) because HMAC needs it. Scopes: heartbeat, events:write, products:write, orders:write. Optional expiry. Rotate = new credential, old expires in 1 h. Revoke = immediate.

## Signing every request
Headers: X-LYP-Key, X-LYP-Timestamp (unix s, |skew| <= 300 s), X-LYP-Nonce (16-64 chars [A-Za-z0-9_-], new per request incl. retries), X-LYP-Signature = lowercase hex HMAC-SHA256(secret, canonical)
canonical = METHOD "\n" PATH "\n" TIMESTAMP "\n" NONCE "\n" sha256hex(raw_body)   (PATH has no query string)
Verification order: headers present -> body <= 1 MB -> timestamp -> nonce format -> credential usable (unknown/revoked/expired/disabled site = same generic 401) -> hash_equals signature -> scope (403) -> atomic nonce insert (duplicate = 401 replayed_request).
Never reveal which check failed beyond: missing_headers, stale_timestamp, bad_nonce, invalid_credentials, insufficient_scope, replayed_request, payload_too_large.

## Endpoints
POST /heartbeat (heartbeat)   body {"connector_version":"1.0.0"} -> 200 {ok, api_version:"v1", server_time}
POST /events (events:write)   body {"events":[{id,type,occurred_at?,payload{}}]} 1-100 events, payload <= 64 KB -> 202 {accepted, duplicates}. Idempotent on (website, id).
POST /products (products:write) body {"products":[{id, name, sku?, category?, price?, cost?, stock?, active?}]} 1-200 -> 200 {ok, created, updated}. Absent optional field = keep existing value.
POST /orders (orders:write)   1-100 orders -> 200 {ok, created, updated, stale}
```
{"orders":[{
 "id":"1001", "status":"Delivered", "ordered_at":"2025-03-01T10:15:00+06:00",
 "updated_at":"...", "status_changed_at":"...", "currency":"BDT",
 "discount":0, "shipping":60, "total":null, "courier":"Pathao", "consignment_id":"...",
 "city":"Dhaka", "cancel_reason":null, "utm_campaign":null,
 "customer":{"id":"..", "name":"Rahim", "phone":"01711...", "email":null},
 "employee":{"name":"Karim"},
 "items":[{"product_id":"p1","sku":"S1","name":"Polo","quantity":2,"unit_price":500,"unit_cost":300}]
}]}
```
Order rules:
- Upsert by (website, id). website_id/tenant_id come from the verified credential, NEVER from the body.
- If `updated_at` is older than the stored source_updated_at -> ignore as "stale" (retry/ordering safety).
- Items are replaced on re-sync (snapshot). total: if provided it is authoritative; else subtotal - discount + shipping; no items => total required.
- Status canonicalised (STATUS_MAPPING.md); raw kept in source_status. A history row is appended ONLY when canonical status changes (first sight = initial row).
- customer phone/email are hashed into customer_key and DISCARDED; never stored or logged.
- employee name -> employees + order_assignments (one current assignment per order).
- unit_cost missing but product matched and has cost => snapshot product cost.

## Errors
{"ok":false,"error":"<code>"} ; validation => 422 with Laravel's errors object. Clients retry 429/5xx with exponential backoff, NEW nonce, SAME event/order ids.

## Versioning
Breaking change => /api/v2. Additive fields allowed in v1. Document every change in this file.
