# Cloudflare & WAF Staged Rollout Guide (Documentation Only)

> **Scope Notice:** This repository runs offline / on local XAMPP without live Cloudflare API calls or automated IP blocking. No Cloudflare rule is exercised automatically in tests.
>
> **Architecture Boundary:** A token-only integration (such as the Last Year Project connector or widget key) **cannot protect or inspect all traffic of an external store website**. Full-site WAF, DDoS mitigation, and TLS edge filtering require routing the external storefront's authoritative DNS through a reverse proxy / edge WAF such as Cloudflare.

---

## 1. Staged Edge Rollout Checklist (External Storefront)

1. **Stage 0 — DNS Only (`DNS Only` / Grey Cloud) + Baseline App Signals**
   - Verify origin SSL certificates (`Full (Strict)` target).
   - Enable Last Year Project app-level `security_events` in default `log_only` mode (`Security -> Rules`).
   - Confirm legitimate courier callbacks, connector heartbeats (`/api/v1/connector/*`), and checkout routes do not trigger false positives.

2. **Stage 1 — Edge Proxy (`Proxied` / Orange Cloud) in Log / Simulate Mode**
   - Point storefront DNS through Cloudflare with `SSL/TLS = Full (Strict)`.
   - Enable Cloudflare Managed Rules in **Log** (Simulate) mode for 7 days.
   - Preserve true client IP headers cleanly (`CF-Connecting-IP` if origin trusts Cloudflare CIDR ranges only; never trust arbitrary forwarded headers on direct internet ports).

3. **Stage 2 — Targeted Rate Limiting (Checkout, Login, Widget)**
   - Configure edge rate limits on high-cost POST routes:
     - `/login` and `/register`
     - Storefront checkout & OTP routes
     - `/api/v1/widget/{publicKey}/token` and `/api/v1/widget/order-status`
   - Start with `Managed Challenge` or `Log` before `Block`, verifying real mobile-carrier NAT traffic in Bangladesh does not suffer false blocks (many mobile users share carrier CGNAT IPs).

4. **Stage 3 — False-Positive Review & Gradual Tightening**
   - Review `/wp-admin`, `*.php`, or VPS/emulator traffic cautiously: **probe paths or CGNAT/VPS traffic alone are NOT proof of malicious intent**.
   - Allowlist connector origin IPs or signed `X-LYP-Signature` webhook paths before tightening managed rules from `Simulate/Log` to `Managed Challenge`.
   - Maintain a documented rollback toggle back to `Log` if conversion rate drops unexpectedly.

---

## 2. What Remains Inside Last Year Project (App-Level)

- Hashed IP telemetry (`ip_hash` HMAC-SHA256) with zero secrets, passwords, cookies, or bearer/widget tokens in `security_events.meta`.
- Default `log_only` recording for `login_failed`, `rate_limit_hit`, `signature_failed`, `widget_auth_failed`, and `suspicious_request`.
- Explicit owner opt-in `throttle` mode per signal type + manual `false_positive` / `confirmed` triage inside `/security`.
