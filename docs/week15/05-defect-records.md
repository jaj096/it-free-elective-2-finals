# 5. Defect records

Every defect found in Week 15, including the two mistakes in my own tests. Each record says how to reproduce it,
what was expected and observed, the cause, the fix, and the retest. **Passed** means a test was run and passed;
**not reproduced** means it was tried and didn't happen, which is not the same as proven absent.

Baseline: commit `254290a`, 2026-11-17 23:30 (`evidence/week15/before/`).
Retest: commit `883df35` (`evidence/week15/after/`) and `evidence/week15/hardening-tests-on-old-code.txt`.

---

### D1 – Dependencies with known vulnerabilities (W1)
- **Found by:** `composer audit` (step 15.1)
- **Reproduce:** `cd backend && composer audit` on commit `254290a`
- **Expected:** "No security vulnerability advisories found."
- **Actual:** 7 advisories in 4 packages: `laravel/framework` 13.39.0 (low: task output injection), `symfony/http-foundation`
  8.1.10 (×4: trusted-proxy `private_ranges` spoofing, HEAD bypass of access rules, path-rule bypass, client-chosen host in
  generated URLs), `symfony/http-kernel` 8.1.10 (forwarded headers in inline fragments), `symfony/routing` 8.1.10
  (CVE-2026-118516, host placeholders bypassing path-based access control)
- **Cause:** no dependency check in the workflow; the advisories were published after the Week 14 lock file was made (Nov 4–13)
- **Fix:** `composer update laravel/framework "symfony/*" --with-dependencies` (patch/minor versions inside the existing constraints)
- **Retest:** `after/01-composer-audit.txt`: 0 advisories, exit 0 · **Regression:** 71/71 PHPUnit, all collection folders pass
- **Note:** our app does not use the affected `private_ranges` shortcut or inline fragments, so real exposure was lower than
  the advisory titles suggest. We patched anyway because the fix was cheap and in-range, and "not used today" is easy to get wrong.

### D2 – Login timing reveals which accounts exist (W2)
- **Found by:** reading `AuthController@login`, confirmed by the collection timing check
- **Reproduce:** time `POST /auth/login` with `juan@example.test` + wrong password vs. a made-up email
- **Expected:** both take about the same time (both return the same 401 `INVALID_CREDENTIALS` message)
- **Actual:** wrong password avg **227 ms**, unknown email avg **10 ms** (`before/02-newman-main.txt`)
- **Cause:** `if (! $user || ! Hash::check(...))`: for an unknown email the `||` stops early and bcrypt never runs
- **Fix:** always call `Hash::check`, against a fixed dummy bcrypt hash (cost 12) when the user doesn't exist
- **Retest:** after: wrong password ~227 ms, unknown email ~226 ms (see `after/02-newman-main.txt`; the timing line
  has the exact values) · `HardeningTest::test_w2_unknown_email_still_runs_a_password_hash_check`
  (on the old code: *"check() should be called at least 1 times but called 0 times"*)
- **Limit:** response time still varies by a few ms; the check uses a 50 % margin and catches large differences only.
  Rate limiting (5/min per email+IP) slows a slow enumeration further.

### D3 – Malformed JSON gives a misleading validation error (W3)
- **Found by:** baseline probe, then collection test 02 / Malformed JSON body
- **Reproduce:** `POST /requests` with `Content-Type: application/json` and body `{"certificate_type":"enrollment","purpose":`
- **Expected:** 400 saying the body can't be read
- **Actual:** 422 `VALIDATION_FAILED`, *"Choose a certificate type."* and *"The purpose field is required."*, for a request that did contain a type
- **Cause:** Laravel reads an unparsable JSON body as an empty input array, so validation runs on nothing
- **Fix:** `RejectMalformedJson` middleware → 400 `MALFORMED_JSON` "The request body is not valid JSON." (empty bodies still pass to validation)
- **Retest:** collection ✅; `test_w3_*` (old code: 422 instead of 400)
- **Classification:** client/integration defect made hard to diagnose by the provider. It's not a security hole, but the
  wrong message would send a user or developer looking in the wrong place.

### D4 – Missing hardening headers; PHP version disclosed (W4)
- **Found by:** `curl -D -` on `/api/v1/health`; collection folder 07
- **Expected:** `Cache-Control: no-store`, CSP, `X-Frame-Options`, `Referrer-Policy`; no `X-Powered-By`
- **Actual:** `cache-control: no-cache, private` (browsers may still store it), no CSP / frame / referrer headers, `x-powered-by: PHP/8.5.9`
- **Cause:** Week 14 hardened transport (HSTS, nosniff in Caddy) but not response handling
- **Fix:** `SecurityHeaders` middleware for `/api/*` (global, so unknown-URL 404s and PDF downloads are covered)
- **Retest:** 07 / [H] headers on success and error, 01 / download `no-store` ✅; `test_w4_*` (old code: `no-cache, private` lacks `no-store`)

### D5 – Request list silently cut off at 200 (W5)
- **Found by:** reading `CertificateRequestController@index` (`limit(200)`)
- **Reproduce:** with more than 200 requests, `GET /requests` as staff returns 200 items and no sign that more exist
- **Expected:** the client can tell how many exist and reach all of them
- **Actual:** 200 items, no `meta`; `per_page` ignored (`before`: 15 items for `per_page=2`; `per_page=1000` → 200)
- **Cause:** a fixed limit added in Week 13 to bound response size, without pagination
- **Fix:** `paginate()` with `per_page` 1–100 (default 50) and `meta.total/last_page`; Flutter follows all pages
- **Retest:** collection 07 ✅; `test_w5_more_than_200_requests_are_all_reachable` (230 rows, 3 pages, 230 unique IDs);
  Flutter `pagination_test.dart` ✅. On the old code `meta.total` was `null`.

### D6 – Parallel duplicate submit → 500 (W6, latent)
- **Found by:** code review of the check-then-insert in `store()`
- **Reproduce (live):** step 15.7, 12 parallel POSTs with one key × 5 rounds. **Not reproduced** on SQLite with 4 PHP workers,
  before or after (1×201 + 11×200 each round), because SQLite serializes writes and the window is tiny
- **Reproduce (deterministic):** `HardeningTest::test_w6_*` inserts the competing row right after the lookup misses
- **Expected:** the loser gets 200 with the original request (or 409 if the data differs)
- **Actual (old code):** 500 `SERVER_ERROR` (UNIQUE constraint violation). No duplicate row: the database index held
- **Fix:** catch `UniqueConstraintViolationException`, look the key up again, answer like a retry
- **Retest:** `test_w6_*` ✅ (old code: 500 in both tests); live race still 1 row per key ✅
- **Why fix something not seen live:** with MySQL/PostgreSQL and real concurrency (Week 16 load tests), the window is
  real. A 500 makes the Flutter client show "Something went wrong" for a request that actually succeeded.

---

## Test-design defects (my own tests were wrong)

### T1 – Wrong prediction for the timeout case
- **Observed in:** `before/04-newman-timeout.txt`: expected `needs_review` after 3 attempts, got `generated` on **attempt 2**
- **Investigation:** `before/06-automation-log-slowReqId-17.jsonl` and the audit history: attempt 1 →
  `generation_retry_scheduled` "Generator did not respond in time"; attempt 2 → `generation_succeeded`
- **Cause:** the backend gives up after 10 s, but the generator keeps working, finishes at 15 s and saves the PDF under
  the request's `job_key`. The retry gets that saved PDF back at once (`generate.replayed`). That is the **correct, safe**
  behavior (no duplicate PDF, no wasted retries). My prediction ignored the generator's idempotency.
- **Fix:** the test now expects a retry, `generated` within 2–3 attempts, exactly one `generation_succeeded`, and a
  retry reason mentioning the timeout
- **Lesson:** a timeout on the client side does not mean the work failed on the server side

### T2 – Wrong audit action name in the recovery test
- **Observed in:** `before/05-newman-recovery.txt`: `expected 9 to be below -1`
- **Cause:** the test looked for an action called `generated`; the audit action is `generation_succeeded`
- **Fix:** test checks `sent_to_review` → `requeued` → `generation_succeeded` in order · **Retest:** ✅

Both baseline failures stay in `evidence/week15/before/` unchanged.
