# DATA MODEL (final target; create tables in the task that first needs them)

Conventions: bigint auto-increment ids; `tenant_id` FK on every tenant-owned table; money = decimal(14,2); datetimes stored as server-local (Asia/Dhaka) 'Y-m-d H:i:s'; never store secrets in plain text.

## Foundation (T01-T04)
- tenants(id, name, slug unique, timestamps)
- users(id, tenant_id FK, name, email unique, password, role[owner|admin|analyst], timestamps)  -- tenant_id/role set by server only
- websites(id, tenant_id, name, platform[laravel|file], domain NULLABLE UNIQUE (normalised host), status[pending|active|disabled], currency char(3) default 'BDT', verification_token, verified_at NULL, last_heartbeat_at NULL, connector_version NULL, timestamps)
- api_credentials(id, website_id FK, key_id unique, secret_encrypted (Crypt), scopes json, expires_at NULL, revoked_at NULL, last_used_at NULL, created_by NULL, timestamps)
- request_nonces(id, credential_id FK, nonce, created_at; UNIQUE(credential_id, nonce))
- integration_events(id, website_id FK, source_event_id, type, occurred_at NULL, payload json, status, received_at; UNIQUE(website_id, source_event_id))
- audit_logs(id, tenant_id NULL idx, user_id NULL, action, details(255), prev_hash NULL, hash, created_at)

## Orders domain (T07)
- products(id, tenant_id, website_id FK, external_id, sku NULL, name, category NULL, price NULL, cost NULL, stock NULL, active bool, timestamps; UNIQUE(website_id, external_id); idx(tenant_id, sku))
- customers(id, tenant_id, customer_key (sha256 hex), name NULL, timestamps; UNIQUE(tenant_id, customer_key))   -- NO phone/email columns
- orders(id, tenant_id, website_id FK, external_id, customer_id NULL, status canonical, source_status NULL, ordered_at, subtotal, discount, shipping, total, currency, courier NULL, consignment_id NULL, city NULL, cancel_reason NULL, utm_campaign NULL, status_changed_at NULL, source_updated_at NULL, source[connector|import], import_batch_id NULL (plain bigint, no FK), timestamps;
  UNIQUE(website_id, external_id); idx(tenant_id, ordered_at); idx(tenant_id, status); idx(website_id, ordered_at); idx(tenant_id, courier))
- order_items(id, order_id FK cascade, product_id NULL FK nullOnDelete, product_name, sku NULL, quantity, unit_price, unit_cost NULL, line_total; idx(order_id), idx(product_id))
- order_status_history(id, order_id FK cascade, from_status NULL, to_status, changed_at, employee_id NULL, source[connector|import|courier]; idx(order_id), idx(to_status, changed_at))  -- append-only
- employees(id, tenant_id, name, timestamps; UNIQUE(tenant_id, name))
- order_assignments(id, order_id FK UNIQUE, employee_id FK, assigned_at)
- import_batches(id, tenant_id, website_id, user_id, kind[orders|courier|ads], filename, stored_path NULL, format, status[uploaded|queued|processing|done|failed], header json, mapping json, stats json, error NULL, timestamps)  -- stored file deleted after processing

## Courier & loss (T10)
- courier_shipments(id, tenant_id, website_id NULL, import_batch_id, courier, consignment_id NULL, order_ref NULL, order_id NULL FK, customer_key NULL, status canonical, cod_amount NULL, collected_amount NULL, delivery_fee NULL, return_fee NULL, area NULL, shipped_at NULL, delivered_at NULL, returned_at NULL, matched bool, timestamps; idx(tenant_id, courier), idx(order_id))
- risk_flags(id, tenant_id, order_id NULL, customer_id NULL, rule[S1|S2|S3], evidence json, status[open|reviewed_ok|confirmed], reviewed_by NULL, reviewed_at NULL, created_at)   -- ADVISORY only

## R&D Lab (T11-T13)
- research_findings(id, tenant_id, website_id NULL, rule_code, fingerprint (dedupe key), finding_en, finding_bn, evidence json, window_from, window_to, severity[critical|high|medium|low], confidence[high|medium|low], hypotheses json, missing_data json, recommended_action_en/_bn, experiment_plan json, success_metric json, status[new|reviewing|approved|testing|validated|rejected|dismissed], outcome_note NULL, ai_note NULL, ai_model NULL, first_seen_at, last_seen_at, timestamps; idx(tenant_id,status))
- experiments(id, tenant_id, finding_id NULL, title, hypothesis, design, baseline json, success_metric json, min_sample NULL, planned_days NULL, owner_user_id, status[draft|approved|running|completed|cancelled], approved_by NULL, approved_at NULL, started_at NULL, ended_at NULL, timestamps)
- experiment_results(id, experiment_id, measured json, outcome[validated|rejected|inconclusive], lesson, recorded_by, recorded_at)
- llm_calls(id, tenant_id, purpose, driver, model, input_tokens, output_tokens, latency_ms, status, error NULL, created_at)  -- no prompts/answers stored

## Chatbot & security (T14)
- chatbots(id, tenant_id, website_id, name, enabled, widget_domain, handoff_text, timestamps)
- knowledge_documents(id, tenant_id, chatbot_id, title, body, source[manual|file], updated_at)   -- no customer PII
- conversations(id, tenant_id, chatbot_id, session_token_hash, status[open|handoff|closed], started_at)
- chat_messages(id, conversation_id, role[user|bot|agent], body (size-limited), grounded bool, source_doc_id NULL, created_at)
- chatbot_leads(id, tenant_id, conversation_id, name NULL, contact_hash/contact_enc, consent_at NOT NULL, created_at)
- ai_action_logs(id, tenant_id, conversation_id NULL, action, outcome, created_at)
- security_events(id, tenant_id NULL, website_id NULL, type, severity, ip_hash, path (escaped on output), meta json, created_at)
- security_rules(id, tenant_id, type, threshold, mode[log_only|throttle], enabled, timestamps)

## Marketing (T15)
- ad_performance_daily(id, tenant_id, website_id NULL, import_batch_id, platform, campaign, date, spend, impressions, clicks, platform_conversions, platform_revenue NULL; UNIQUE(tenant_id, platform, campaign, date))

## Misc
- analyses(id, tenant_id, user_id, filename, rows_count, result json, timestamps)   -- quick file analysis feature
- weekly_reports(id, tenant_id, week_start, payload json, built_at; UNIQUE(tenant_id, week_start))

## Rules
- Preserve status history; never overwrite it. Retain order-time item/price snapshots.
- External IDs unique per website. Re-sending the same data must never duplicate rows.
- Deleting a tenant cascades its data. Provide per-tenant export + delete (T16).
