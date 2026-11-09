# DECISIONS (locked unless the owner changes them in writing)

## Technology
- PHP 8.2+, Laravel (latest stable at project creation; record exact version in PROGRESS.md), MySQL via XAMPP, Blade + small vanilla JS, Chart.js served LOCALLY (no CDN), OpenSpout ^4 for XLSX reading, PHPUnit with SQLite in-memory.
- NO Livewire, NO Node build step, NO Redis/Docker/PostgreSQL requirement for the demo. Keep code portable to PostgreSQL (avoid MySQL-only SQL in app logic; if unavoidable, isolate and comment).
- Cache: file store. Queue: database. Sessions: file. Redis is an optional .env switch later.
- Auth: Laravel session auth + CSRF for the dashboard; connector API is stateless (no cookies), HMAC-signed.
- Primary keys: auto-increment bigints (decision made for simplicity/speed). Connector credentials use public key_id.
- Timezone Asia/Dhaka. Default currency BDT (stored per website).

## Product
- Canonical order statuses: pending, confirmed, cancelled, shipped, delivered, returned, other (raw source status always kept). See STATUS_MAPPING.md.
- Customer privacy: raw phone/email are NEVER stored; only customer_key = SHA-256(tenant_id|normalised contact). Name optional.
- Dashboard is PLAIN: <= 6 counters + links, served from cache. All analysis lives on separate pages.
- Cache TTL 30 minutes (config lyp.cache_ttl). Scheduler re-warms every 15 min. Clear-cache = bump the tenant's cache version (NEVER Cache::flush()).
- R&D Lab is HYBRID: rule/statistics engine produces every finding (works with LLM OFF, templates in Bangla + English). LLM (Ollama offline / OpenAI-compatible / Anthropic) only adds a labelled "AI explanation - hypothesis, not proof". LLM never computes numbers or triggers actions.
- Audit log: SHA-256 hash chain (tamper-evident). No "Web layer 3" / blockchain network.
- Languages: UI English + Bangla toggle (R&D finding templates and weekly report in both).
- Courier: CSV report import now; API adapters later via an adapter interface. Ads: CSV import now.
- Order creation by chatbot: DEFERRED (v1 chatbot = answers, order-status lookup by verified order number + contact hash, human handoff).
- Website ownership verification: DEFERRED (store verification_token; show "not verified").
- Fraud/suspicious-order output is ADVISORY (review queue). No auto-cancel, no auto-block.

## Out of scope (do NOT build)
Website/landing-page builder, hosted pages, template marketplace, new commerce engine, autonomous price/budget/order actions, mobile app, real-time websockets, WooCommerce/WordPress connector, courier live APIs, ad-platform live APIs, multi-store agency workspaces, billing/subscriptions.

## Owner inputs still pending (agent must ASK, never guess)
1. A real courier report CSV (needed before T10 mapping).
2. A real ad report CSV (needed before T15).
3. Live store's Laravel/PHP version (before T03 sample connector).
4. A real order export from any shop (CSV/XLSX/XML) for T08 verification.
