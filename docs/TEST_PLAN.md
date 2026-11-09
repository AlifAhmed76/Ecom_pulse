# TEST PLAN
Automated: PHPUnit (Feature + Unit), phpunit.xml uses sqlite :memory:, CACHE array, QUEUE sync. `php artisan test` must be green before every report. Name tests after behaviour (test_other_tenant_cannot_view_website).
Each task file lists its required tests. Global must-haves: tenant isolation for every new model/route/service, policy tests per role, idempotency for every ingest path, privacy test (no phone/email column or log), performance test (second read of cached metric issues 0 SQL queries).
Golden datasets: build small hand-calculated datasets in tests (e.g. 10 orders with known totals/statuses) and assert exact metric values; never assert values produced by the same code under test.
Manual verification checklist per milestone is in each task ("Owner verification"). The owner runs these on the XAMPP PC and replies with results.
Definition of done (every task): code + tests + docs updated + PROGRESS ticked + final report in the required format + `php artisan test` output pasted.
Static checks: `php -l` on changed files; no dd()/dump()/var_dump left; no secrets in repo; .env ignored.
