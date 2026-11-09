# PROJECT BRIEF (summary of docs/original_brief.pdf + owner decisions)

## What it is
"Last Year Project" is a multi-tenant SaaS for e-commerce businesses (primary market: Bangladesh, cash-on-delivery heavy, couriers like Pathao / Steadfast / RedX). It connects to EXISTING websites (not a site builder), unifies products/orders/couriers/ads, explains what happened and what might be wrong, and acts as an AI-assisted R&D partner: findings with evidence, experiments, and measured outcomes.

Core promise: "Show where money is being lost (cancellations, returns), why it MAY be happening, and what to test next. Numbers come from code; AI only explains."

## Users
SME merchants without analysts; growing brands with staff/couriers/ads; later agencies (out of scope now).

## Data paths (all land in the SAME tables)
1. Live Laravel store -> signed Connector API v1 (heartbeat, events, products, orders).
2. File import: CSV, XLSX, XML (any shop's export) with column auto-detect + mapping screen.
3. Courier report CSV (courier APIs are a LATER adapter; reports are imported by CSV now).
4. Ad report CSV (ad-platform APIs are later).

## Modules (final scope)
A. Foundation: tenants, roles (owner/admin/analyst), policies, websites, credentials, audit hash-chain.
B. Speed/operations: 30-min cache layer, scheduler, queue workers, System page, cache clear.
C. Orders & analytics (separate Analytics page; Dashboard stays PLAIN).
D. Courier & loss: "money lost to cancel/return" report, courier+area scorecard, suspicious-order flags (advisory only).
E. Data-quality score on every analytics view.
F. R&D Lab (signature): rule/statistics engine -> findings with 10 mandatory fields, Action Center ("what to do today"), experiment tracker, optional LLM explanation.
G. Chatbot (knowledge-grounded, human handoff) + security events (app-level).
H. Marketing: ad CSV -> platform ROAS vs real-order ROAS with coverage.
I. Reports/presentation: weekly summary (Bangla + English, printable), demo mode, data export/delete.

## Hard rules from the original brief
- Laravel is the system of record. Never trust tenant/website IDs, roles, prices, statuses from the browser.
- Deterministic code computes money and metrics. LLM only explains; it must separate verified facts / computed metrics / hypotheses / recommendations / forecasts and show confidence + missing data.
- Never claim attribution is perfect, ROAS = profit, or that AI guarantees growth.
- High-impact actions (price change, budget change, order cancel, export, security block) require human approval. This version performs NONE of them automatically.
- Do not treat /wp-admin, a .php URL, or VPS traffic alone as malicious.
- No website builder, landing-page builder, template marketplace, or new commerce engine.
- Distinguish: proposed design / implemented / tested / deployed. Never claim unimplemented work is complete.

## Delivery target
Runs OFFLINE on a Windows PC with XAMPP for a teacher/investor/client demo, and can later move to Docker + PostgreSQL + Redis without code changes (only .env).
