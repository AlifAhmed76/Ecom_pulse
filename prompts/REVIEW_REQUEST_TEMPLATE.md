(What to paste into your chat with Claude, the reviewer, after each task.)

```
TASK: Txx
OWNER-RUN RESULTS (run these yourself, do not trust the agent):
  php artisan test  ->  (paste the last 15-20 lines)
  Owner verification steps from the task file -> what you saw (worked / failed / what error)
AGENT'S FINAL REPORT: (paste fully)
git diff --stat: (paste)
ANYTHING THAT SURPRISED YOU:
```
If the reviewer asks for specific files, paste or upload them. For big tasks send the migration files, the main service class and the test files first - that is where mistakes hide.

The reviewer replies with one of: PASS - next task | FIX LIST (copy it straight to the agent) | STOP - decision needed from the owner.
