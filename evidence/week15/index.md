# Evidence index – Week 15 (IA6): Integration Testing and Security Hardening

Produced by `bash scripts/run-week15.sh <before|after>` against the live system (Caddy + API + worker + generator).
The **expected** results are the collection's assertions, written before the baseline run. Failures are kept, not deleted.

## Lab log

| Item | Value |
| --- | --- |
| Student | Jewel Julian, BSIT 3A · Project 21 Certificate Generation Queue |
| Branch | `week15` |
| Baseline run | 2026-11-17 23:30 +0800, commit `254290a` (end of Week 14) → `before/` |
| Regression run | 2026-11-17 23:46 +0800, commit `883df35` (after the hardening sprint) → `after/` |
| Environment | macOS · PHP 8.5.9 · Laravel 13.39.0 → 13.43.0 · Caddy 2.11.7 · SQLite + database queue · `PHP_CLI_SERVER_WORKERS=4` · Node 24 + Newman 6.2.1 · Flutter 3.47.8 |
| Approved scope | The bounded certificate workflow in `docs/week15/01-test-strategy.md` §1.1; local test API only; fictional seed accounts |
| Test data | `juan`, `maria`, `approver`, `custodian` `@example.test` (fictional); requests created by the collection at run time |
| Secrets | None in evidence: tokens live in a temp file deleted by the script; step 15.9 scans each folder |

## Files

| File (in `before/` and `after/`) | What it proves | Expected | Before | After |
| --- | --- | --- | --- | --- |
| `00-run-info.txt` | Build, environment and time of the run | — | `254290a` | `883df35` |
| `01-composer-audit.txt` | Dependencies have no known advisories | 0 advisories | ❌ 7 advisories, 4 packages | ✅ 0 |
| `02-newman-main.txt` / `.junit.xml` | Folders 00–07: normal path, invalid + boundary, 401, 403, 404/405, 409, hardening | 0 failed | ❌ 14 of 159 failed (all hardening checks) | ✅ 0 of 160 |
| `03-newman-dependency-503.txt` | Folder 08: generator 503 → `needs_review` after exactly 3 attempts; safe requester message | 0 failed | ✅ 0 of 36 | ✅ 0 of 37 |
| `04-newman-timeout.txt` | Folder 09: generator slower than the timeout | 0 failed | ❌ 2 failed: **test defect T1** (wrong prediction) | ✅ 0 of 24 (generated on attempt 2, one PDF) |
| `05-newman-recovery.txt` | Folder 10: generator restored, staff re-queue, completes once | 0 failed | ❌ 1 failed: **test defect T2** (wrong action name) | ✅ 0 of 10 |
| `06-automation-log-*.jsonl` | Worker log lines (correlation ID, attempts, review, notification) for the 503 and timeout cases | 16 and 8 lines; no personal data | ✅ | ✅ |
| `07-idempotency-race.txt` | 5 rounds × 12 parallel POSTs with one key | 1×201 + 11×200, 1 row per key | ✅ (race not reproduced) | ✅ |
| `08-backend-tests.txt` | PHPUnit suite | all pass | 59 passed | ✅ 71 passed |
| `09-flutter-tests.txt` | Flutter tests | all pass | 12 passed | ✅ 14 passed |
| `10-secret-scan.txt` | No tokens, keys or passwords in this folder | clean | ✅ | ✅ |

| Other file | What it proves |
| --- | --- |
| `hardening-tests-on-old-code.txt` | The 12 new `HardeningTest` tests run against the code **before** the fixes: 7 failures + 1 error (every W2–W6 test), 4 regression guards pass |
| `t1-timeout-investigation.txt` | Audit history + generator log showing why the timeout case recovered on attempt 2 (generator replayed the stored PDF) |

## Flutter Web evidence (to add: screenshots by the student)

Put browser screenshots in `evidence/week15/flutter/` and tick them off here:

- [ ] Desktop width: staff request list loads (pagination is invisible to the user: all requests appear)
- [ ] Narrow width (~390 px): the same list and a request detail are readable and usable
- [ ] Validation agreement: the "New request" form refuses a 4-character purpose in the browser, **and** the server refuses it too (shown in `02-newman-main.txt`, folder 02)
- [ ] Error state: a 403 or 404 shows a safe message with a reference (correlation ID), no technical detail
- [ ] Review state: a `needs_review` item seen as the requester ("staff have been notified") and as staff (reason shown)
- [ ] Postman app (optional): the collection runner result for folders 00–07
