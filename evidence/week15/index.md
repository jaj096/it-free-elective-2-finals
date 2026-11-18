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

## Flutter Web and runner screenshots (`flutter/`)

Taken by the student on 2026-11-18 against the live system (Chrome, `https://localhost:5000`, API through Caddy).

| Check | Screenshot(s) | Result |
| --- | --- | --- |
| Desktop width: staff list loads every request (pagination invisible to the user) | `W15_E01a_Desktop_Staff_List.png`, `W15_E01b_Desktop_Staff_List.png` | ✅ requests #1 to #52 all listed (more than one API page) |
| Narrow width (iPhone, 393 px): list and detail readable and usable | `W15_E02a_Narrow_List.png`, `W15_E02b_Narrow_Detail.png` | ✅ single column, Approve/Reject reachable, masked ID shown |
| Validation agreement: 4-character purpose | `W15_E03a_Browser_Refuses.png` (browser), `W15_E03b_Server_Refuses.png` (server 422, `after/02-newman-main.txt` lines 112–117) | ✅ both refuse it |
| Safe error with a reference | `W15_E06_Newman_Terminal.png` (404 → "error uses the safe format (code, message, correlation_id; no stack trace)") | ✅ at the API. In the browser the app shows a plain message for 403/404 ("You don't have access to this.") and adds the reference only for server errors (500); no technical detail is ever shown |
| Review state: same item as staff and as requester | `W15_E05a_Staff_Review_Reason.png` (staff: "Requester ID number is missing…"), `W15_E05b_Requester_Review_Reason.png` (requester: "Needs review: staff have been notified") | ✅ reason visible to staff only |
| Collection runner, folders 00–07 | `W15_E06_Newman_Terminal.png` (Newman, Postman's command-line runner) | ✅ 54 requests, 160 assertions, 0 failed |
