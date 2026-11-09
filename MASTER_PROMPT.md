# MASTER BUILD PROMPT - Last Year Project (execute the whole plan, autonomously)

## 1. Mission
You are the sole developer. Build the COMPLETE product described in docs/ (tasks T00 to T17) from an empty folder to a finished, tested, offline-demo-ready Laravel application, then hand it over for review. Work continuously through all tasks in order WITHOUT waiting for approval between tasks. The product owner is not a programmer and will run your final result on a Windows PC with XAMPP.

## 2. Read first (in this order, fully)
1. AGENT_RULES.md
2. docs/PROJECT_BRIEF.md, docs/DECISIONS.md, docs/ARCHITECTURE.md, docs/DATA_MODEL.md
3. docs/API_CONTRACT.md, docs/STATUS_MAPPING.md, docs/METRICS_DEFINITIONS.md, docs/IMPORT_SPEC.md
4. docs/COURIER_AND_LOSS_SPEC.md, docs/RND_LAB_SPEC.md, docs/SECURITY_RULES.md
5. docs/TEST_PLAN.md, docs/UI_GUIDE.md, docs/DEMO_PLAN.md
6. The task files in tasks/ (T00 ... T17). Read each one right before you start it.
docs/original_brief.pdf is the source of truth. The folder reference/ is an UNVERIFIED earlier draft: read-only inspiration, never copy blindly.
If two documents conflict, the order of authority is: original_brief.pdf, DECISIONS.md, AGENT_RULES.md, then the rest. Record every conflict you resolved in docs/DECISIONS_LOG.md.

## 3. The loop you repeat for EVERY task (this is your quality gate)
1. Write a 5-line plan for the task into docs/BUILD_LOG.md (do not wait for approval).
2. Implement exactly what the task file lists. Nothing more.
3. Write the tests the task file requires, together with the code.
4. Run the FULL test suite: php artisan test. Every earlier task's tests must still pass.
5. Run php -l on every changed PHP file. Remove any dd(), dump(), var_dump().
6. If anything is red: fix the CODE (never edit, skip, delete or weaken a test to get green). You get 3 fix attempts per failure. If still red, write BLOCKED with details into docs/BUILD_LOG.md, stop building, and go to section 8 (partial hand-over). Never stack new work on top of a red state.
7. Commit: git commit -m "Txx: <title>". Tick the task in docs/PROGRESS.md.
8. Append to docs/BUILD_LOG.md: task id, files created, commands run, REAL output tail of the test run, anything NOT run or UNVERIFIED.
9. Print ONE status line in the chat: "Txx DONE - tests: N passed, 0 failed" and continue to the next task.

## 4. When you may stop and ask the owner (ONLY these cases)
a) T00: the PC lacks PHP 8.2+, Composer, Git, MySQL or a required extension. Give exact Windows install steps, then wait.
b) A task is BLOCKED after 3 fix attempts.
c) A requirement contradicts docs/original_brief.pdf and you cannot resolve it with section 2's authority order.
d) You would need a real secret or real account (LLM API key, courier token). Do not ask: skip it, keep the feature behind config, mark it UNVERIFIED.
Everything else (naming, layout, small design choices) you decide yourself and note in docs/DECISIONS_LOG.md. Do not ask for permission between tasks.

## 5. Missing real files from the owner (T08, T10, T15)
Real courier / ad / order export files may not exist yet. Do NOT stop and do NOT guess vendor column names as if they were facts.
- Build the generic, alias-based column matcher plus the mapping screen exactly as docs/IMPORT_SPEC.md says.
- Create clearly synthetic fixtures under tests/Fixtures/ (name them synthetic_*) and test against them.
- Show a visible note on those mapping screens: "Columns are matched by common names. Check the mapping before importing."
- In BUILD_LOG.md and FINAL_REPORT.md mark these as UNVERIFIED_AGAINST_REAL_DATA.
LLM providers (ollama, openai-compatible, anthropic): verify request/response formats against the vendor's official documentation if you can reach it, and cite the page in BUILD_LOG.md. If you cannot, implement behind config, mark UNVERIFIED, default LLM_DRIVER=none, and test with Http::fake. Tests must never make real network calls.

## 6. Non-negotiable constraints (summary; AGENT_RULES.md has the full text)
- Stack: PHP 8.2+, Laravel latest stable, MySQL (XAMPP), Blade + small vanilla JS, local Chart.js, OpenSpout ^4. NO Livewire, NO Node build, NO Redis/Docker requirement, NO CDN.
- Scope: build only what the tasks list. Out of scope: website/landing builders, autonomous price/budget/order actions, live courier or ad APIs, WooCommerce connector, mobile app, billing, chatbot order creation.
- Security: tenant_id/website_id/role/price/status never from request input. Code without a signed-in user (jobs, scheduler, API, console) must filter by tenant explicitly. Secrets only in .env or encrypted columns, never logged, shown once. Escape all untrusted output. Raw customer phone/email are never stored (SHA-256 customer_key only). Uploads are private, allow-listed, deleted after processing; XML with DOCTYPE rejected.
- Speed: dashboard and analytics read through MetricsService / AnalyticsService + TenantCache (30 min). No N+1, paginate lists, index filter columns, slow work in queued jobs.
- AI: code computes every number and finding; the LLM only adds an optional, clearly labelled explanation; it never changes numbers, statuses or data and never acts.
- Honesty: separate DESIGNED / IMPLEMENTED / TESTED / NOT RUN. Never write "done" or "all tests pass" unless you ran it and pasted the output.

## 7. Task order and one-line goals
T00 Environment and repository: clean Laravel project, MySQL, test config, docs in repo, green first test.
T01 Auth, tenancy, roles: tenants, users, roles, tenant isolation proven by tests first.
T02 Websites and credentials: domain normaliser, encrypted secrets shown once, rotate, revoke.
T03 Signed Connector API: HMAC, timestamp, nonce replay table, scopes, rate limits, heartbeat, idempotent events.
T04 Audit hash-chain log: tamper-evident, per tenant, verify button.
T05 Cache layer and plain dashboard: TenantCache (30 min), MetricsService, ETag JSON route, System page, clear-own-cache.
T06 Scheduler and workers: beats, warm-up, prune, queues high/default/ai, Windows .bat scripts.
T07 Orders domain and sync API: products, customers (hash only), orders, items, status history, employees, idempotent SyncService, /products and /orders endpoints, Orders and Products pages.
T08 File import: CSV, XLSX, XML reader, column matcher, mapping screen, background import, quick File Analysis page.
T09 Analytics page: every metric exactly as docs/METRICS_DEFINITIONS.md, freshness, definitions, data-quality score.
T10 Courier and loss: courier report import, "money lost" report, courier scorecard, advisory suspicious-order flags.
T11 R&D engine: rules R01-R12, statistics helpers, 10-field findings in Bangla and English, Action Center.
T12 Experiment tracker: approval, baseline, results, lessons, finding outcome.
T13 Optional LLM explanation: client with budget and ledger, ai queue, labelled button, works fully with LLM off.
T14 Chatbot and security events: knowledge-grounded answers, handoff, widget token, lead consent, log-only security events.
T15 Marketing: ad report import, platform ROAS vs real ROAS with coverage, rule R13.
T16 Weekly report, demo mode, export and delete: Bangla/English printable summary, removable demo data, tenant export and deletion.
T17 Packaging and final docs: setup.bat, run.bat, run-tests.bat, README, smoke test of every page as owner and analyst, final zip.

## 8. Finishing (also use this if you must stop early)
Create the folder REVIEW_BUNDLE/ in the project root containing:
1. FINAL_REPORT.md: a table of every task T00-T17 with status (NOT STARTED / IMPLEMENTED / TESTED / BLOCKED), test counts, the list of UNVERIFIED items, known limitations, install and run steps, demo logins, and an explicit "NOT RUN" list.
2. BUILD_LOG.md and DECISIONS_LOG.md (copies).
3. tests_output.txt: the COMPLETE output of php artisan test.
4. routes.txt: php artisan route:list. migrations.txt: list of files in database/migrations. git_log.txt: git log --stat --oneline. env_info.txt: php -v, composer -V, the Laravel version.
5. lastyear-final.zip: git archive --format=zip -o REVIEW_BUNDLE/lastyear-final.zip HEAD (this excludes vendor and .env).
Add a smoke test (tests/Feature/SmokeTest.php) that requests every GET page as owner and as analyst and asserts no 500 and correct 403s. Then tell the owner: "Hand-over ready: send REVIEW_BUNDLE/ to the reviewer, and run run-tests.bat and the DEMO_PLAN checklist on the XAMPP PC."

## 9. Context and session limits
Your session may end before the project does. Keep docs/BUILD_LOG.md and docs/PROGRESS.md current after every task. If you sense you are running out of context: finish and commit the current task if you can, otherwise commit work-in-progress on a branch named wip; write "RESUME FROM Txx, step N" at the top of docs/BUILD_LOG.md; stop. The owner will paste prompts/NEW_CHAT_RESUME_PROMPT.md to continue.

## 10. Definition of done for the whole project
- All 18 tasks implemented, php artisan test green, smoke test green, no dd()/dump(), no secrets committed.
- Two companies cannot see each other's data (tests prove it, including inside jobs).
- Dashboard is plain and instant from cache; Analytics, Courier and Loss, R&D Lab pages exist.
- CSV, XLSX and XML imports work end to end; demo mode loads and removes data; the whole docs/DEMO_PLAN.md script can be performed offline.
- LLM off by default and nothing breaks without it.
- REVIEW_BUNDLE/ exists and is complete.

## 10b. Start now
Begin with T00. Verify the environment first. Then run the loop of section 3 for T00, T01, ... T17 without further instructions from the owner.
