# T14: Chatbot and security events

**Depends on:** T13

## Goal
Grounded website chatbot with human handoff, plus app-level security signals.

## Read first
AGENT_RULES.md, docs/SECURITY_RULES.md (Chatbot, security events), docs/DATA_MODEL.md (Chatbot & security), docs/DECISIONS.md

## Deliverables
- Knowledge documents (manual FAQ/policy entries + text upload), keyword retrieval (no vectors, no PII), answer ONLY from retrieved text; if nothing matches or the question is sensitive -> handoff message.
- Short-lived, domain-restricted widget token; session token hashed; rate limits; message size limits; conversation + message storage; lead capture requires explicit consent timestamp.
- Order-status lookup only after verifying order number + contact hash; NO order creation (deferred). Bangla/Banglish/English keyword handling basic.
- security_events (failed logins, rate-limit hits, signature failures, suspicious request patterns) log-only by default; rules table; false-positive review; output always escaped. Cloudflare/WAF = documentation only.

## Done when (acceptance criteria)
- [ ] Chatbot never answers outside the tenant's knowledge; unknown => handoff.
- [ ] Widget cannot read other tenants' data.

## Required tests
- grounded vs ungrounded; prompt-injection text in a question does not change behaviour; cross-tenant retrieval impossible; token expiry/domain mismatch rejected; rate limit; lead without consent rejected; order lookup requires both factors; security events recorded without secrets; XSS payloads rendered inert.

## Do NOT
- ['No order creation, no auto-block, no live WAF calls, no vector DB.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Ask 5 questions: 3 in the knowledge base, 1 not, 1 sensitive; see grounded answers and handoffs.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
