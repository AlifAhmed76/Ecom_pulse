# File Import Guide (Orders, Courier Reports & Ad Performance)

All file uploads follow [`docs/IMPORT_SPEC.md`](IMPORT_SPEC.md) and [`docs/SECURITY_RULES.md`](SECURITY_RULES.md):
- **Access:** Owner & Admin only (`403` for Analyst; cross-tenant batches return `404`).
- **Storage & Deletion:** Uploaded files live only inside private `storage/app/private/imports/t{tenantId}` during parsing and are **deleted immediately after processing or discard**. Nothing is publicly served or executed.
- **Limits & Safety:** Max **20 MB**. Supported formats: `.csv`, `.xlsx` (first sheet via OpenSpout v4), `.xml` (parsed with `LIBXML_NONET`; any XML containing `<!DOCTYPE` or external entity declarations is rejected with validation error). `.xls` binary spreadsheets are intentionally rejected — re-save as `.xlsx` or `.csv`.

---

## 1. Order File Import (`/imports`) & Quick File Analysis (`/file-analysis`)

Supported quirks:
- **CSV:** UTF-8 BOM stripped automatically; delimiter auto-detected among `,`, `;`, `\t`, `|`.
- **XLSX:** First worksheet streamed cleanly; Excel date serials and `DateTimeInterface` cells converted cleanly.
- **XML:** Repeating record node flattened cleanly (`<customer><name/></customer>` -> `customer_name`; nested `<items><item/></items>` expands per item repeating order header columns).
- **Dates & Numbers:** Recognizes ISO dates, Excel serials, Unix timestamps, `YYYYMMDD`, and ambiguous `a/b/yyyy` (documented on the mapping screen as month/day `m/d/yyyy`). Currency symbols/commas stripped; accounting negatives `(100.00)` become `-100.00`.
- **Grouping & Idempotency:** Adjacent rows with the same Order ID combine into one multi-item order; non-adjacent repeats within the same batch append items; re-importing the same file in a later batch updates idempotently without creating duplicate orders.

Steps:
1. Go to **Orders Import (`/imports`)**, select target Website, upload `.csv` / `.xlsx` / `.xml`.
2. Review **Auto-detected Mapping** + 5 sample rows and adjust any column dropdowns if needed.
3. Click **Confirm Import**. If a queue worker heartbeat is active (`sys:worker_beat`), the job queues on `default`; otherwise it runs synchronously so offline demos work without needing extra worker steps.
4. Review summary counts, the **`raw status -> canonical -> count`** table (see [`docs/STATUS_MAPPING.md`](STATUS_MAPPING.md)), skipped reasons, and data-quality hints.

*(Need instant KPI/chart inspection without writing into `orders`? Upload the same file in **Quick File Analysis (`/file-analysis`)**.)*

---

## 2. Courier Settlement Report Import (`/courier-imports`)

> **Important (`UNVERIFIED_AGAINST_REAL_DATA`):** No real Pathao/Steadfast/RedX settlement CSV was supplied during development. To obey `AGENT_RULES.md`, **no vendor column aliases are guessed** — all mapping dropdowns start unselected on the Courier mapping screen.

Mapping fields:
- `courier` (or fixed fallback courier name on upload), `consignment_id`, `order_ref`, `status`, `cod_amount`, `collected_amount`, `delivery_fee`, `return_fee`, `area`, `shipped_at`, `delivered_at`, `returned_at`, `customer_contact` (hashed into `customer_key` and discarded immediately).

Matching order priority (per shipment row):
1. `consignment_id` == `orders.consignment_id`
2. `order_ref` == `orders.external_id`
3. Unique match on `(customer_key + cod_amount == orders.total)` within a ±1 day window.

Unmatched rows are preserved and reflected in matching coverage % on **Courier & Loss (`/courier-loss`)**. Orders' canonical status is **never** overwritten silently; courier status updates append to `order_status_history` (`source=courier`).

---

## 3. Ad Performance CSV Import (`/marketing/imports`)

> **Important (`UNVERIFIED_AGAINST_REAL_DATA`):** No real Meta/Google/TikTok Ads CSV was provided yet; `AdMapper` defaults every mapping field to empty so owners pick exact headers manually.

Steps:
1. Open **Marketing Imports (`/marketing/imports`)**, pick Platform (`meta`, `google`, `tiktok`, or `other`), optional Website, and upload CSV/XLSX/XML.
2. Map: `date*`, `campaign*`, `spend*`, `impressions`, `clicks`, `platform_conversions`, `platform_revenue`.
3. Rows upsert idempotently on `(tenant_id, platform, campaign, date)`.
4. View **Marketing (`/marketing`)** to compare Platform-Reported Metrics vs Real Delivered-Order Metrics (`orders.utm_campaign`, `status = 'delivered'`) alongside UTM coverage % (never claiming ROAS equals net profit).

---

## 4. Workspace CSV Export Safety (`/workspace`)

When the Workspace Owner exports full data via **Workspace Settings -> Queue workspace export**, all exported CSV cells neutralize OWASP CSV formula injection (`=`, `+`, `-`, `@`, `\t`, `\r`, or whitespace before formula triggers) by prefixing `'`, and omit all secrets (`password`, `remember_token`, `verification_token`, `secret_encrypted`, encrypted lead contact plaintext).
