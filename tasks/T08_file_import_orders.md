# T08: File import: orders (CSV / XLSX / XML)

**Depends on:** T07

## Goal
Any shop's export can be uploaded, mapped and imported in about a minute.

## Read first
AGENT_RULES.md, docs/IMPORT_SPEC.md, docs/STATUS_MAPPING.md, docs/SECURITY_RULES.md

## Deliverables
- App\Services\Import\RowReader (csv/xlsx/xml streaming, preview), ColumnMatcher, OrderMapper (aliases from IMPORT_SPEC), OrderImporter (adjacent grouping, merge-in-batch, stats, status mapping report, skipped reasons).
- import_batches table; upload (private storage, extension allow-list, 20 MB), mapping screen with auto-detect + dropdowns + sample rows, confirm, result page, discard.
- ProcessImportBatch job (queue default; synchronous fallback when no worker beat); deletes the stored file afterwards; clears tenant cache and queues warm-up.
- Quick 'File Analysis' page (upload -> instant KPI/chart summary without saving orders) reusing RowReader.

## Done when (acceptance criteria)
- [ ] CSV with BOM + semicolons, XLSX with date cells, nested XML all import correctly.
- [ ] XML containing a DOCTYPE is rejected.
- [ ] Re-importing the same file creates no duplicates.

## Required tests
- reader tests for the three formats incl. quirks; matcher tests; importer with a hand-made file asserting exact orders/items/totals; status report counts; skipped-row reasons; cross-tenant batch 404; analyst forbidden; non-allowed extension rejected; file removed after processing; adjacency + non-adjacent repeat rule; idempotent re-import.

## Do NOT
- ['Do not guess courier/ad columns. No .xls support. No public file serving.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['Import YOUR real export from another site; read the mapping screen; check totals against the source.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
