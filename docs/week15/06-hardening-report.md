# 6. Regression result, hardening report and executive summary

## 6.1 Executive summary (for the service owner)

The Certificate Generation Queue was tested end to end against the live system (HTTPS proxy, API, queue worker and
generator) with a 66-request Postman collection covering normal use, invalid input, sign-in failures, wrong roles,
other users' records, duplicates, a failing generator, a slow generator and recovery. Before any change, the core
workflow and every Week 13–14 control behaved as required. **Six weaknesses** were found at the API boundary and in
third-party packages. All six were fixed in one bounded sprint, each with a test that failed before and passes after:

1. outdated framework packages with 7 published security advisories → updated, 0 advisories;
2. the login told attackers which email addresses have accounts (by answering faster for unknown ones) → fixed;
3. broken requests got a misleading "field missing" message → now a clear 400;
4. responses could be cached by the browser and showed the PHP version → hardening headers added;
5. staff lists stopped at 200 items without warning → paginated, and the app loads every page;
6. two identical submits at the same instant could show a server error → now handled like a normal retry.

After the sprint **all 231 live assertions, 71 backend tests and 14 Flutter tests pass**. No secrets or personal data
appear in the evidence. **Remaining risk is low to moderate for a one-office prototype with fictional data**; the system is
**not production ready** (see 6.4).

## 6.2 Regression result

| Suite | Before (`254290a`, 2026-11-17 23:30) | After (`883df35`, 2026-11-17 23:46) |
| --- | --- | --- |
| Collection 00–07 (normal, invalid, 401, 403, 404, 409, hardening) | 54 requests, 159 assertions, **14 failed** (all `[H]` + malformed JSON + timing) | 54 requests, 160 assertions, **0 failed** |
| Collection 08 dependency failure (503) | 36 assertions, 0 failed | 37, 0 failed |
| Collection 09 timeout | 126 assertions, **2 failed** (test defect T1) | 24, 0 failed |
| Collection 10 recovery | 10 assertions, **1 failed** (test defect T2) | 10, 0 failed |
| Parallel duplicate submit (60 requests) | 5 × (1×201 + 11×200), 1 row per key | same |
| `composer audit` | **7 advisories** | 0 |
| PHPUnit | 59 passed | **71 passed** (342 assertions) |
| Flutter | 12 passed | **14 passed**, `flutter analyze` clean |
| Evidence secret scan | clean | clean |

**Regression safety:** every previously passing collection request and PHPUnit test still passes after the dependency
update and the code changes. Environment: macOS, PHP 8.5.9, Laravel 13.39.0 → 13.43.0, Caddy 2.11.7, Flutter 3.47.8,
Node 24 + Newman 6.2.1, SQLite, database queue, 4 PHP server workers.

**Tested vs not tested:** the race in W6 was **not reproduced live** (only in a forced PHPUnit test). Performance under
load, MySQL and multiple queue workers were **not tested** (Week 16). Browser checks of the Flutter screens at desktop and
narrow widths are recorded by screenshots in `evidence/week15/flutter/` (added by the student).

## 6.3 Hardening changes

See [04-hardening-backlog.md](04-hardening-backlog.md) §4.2 for files and proof, and [05-defect-records.md](05-defect-records.md)
for each defect's reproduction and retest.

## 6.4 Residual-risk register (after Week 15)

L = likelihood, I = impact (1–3). Week 14 risks R1–R14 still apply unless noted here.

| ID | Risk | L | I | Treatment / next step |
| --- | --- | --- | --- | --- |
| Q1 | New advisories in dependencies after this date (D1 showed they appear within days) | 3 | 2 | **Reduce:** run `composer audit` weekly and in CI; Dependabot/Renovate on the repository |
| Q2 | Password spraying across many emails from one IP (limit is per email+IP) | 2 | 2 | **Reduce next:** add a per-IP login limit (backlog B1) |
| Q3 | Small timing differences remain at login (a few ms) | 1 | 1 | **Accept:** below network noise; rate limit slows enumeration |
| Q4 | Idempotency race untested on a server database under real concurrency | 2 | 1 | **Verify in Week 16** load test on MySQL; no duplicate possible thanks to the UNIQUE index |
| Q5 | Flutter caps list loading at 20 pages × 100 (2,000 requests) | 1 | 2 | **Accept** for one office; add server-side filters/search before that size |
| Q6 | Token in `sessionStorage` (Week 14 R8): no CSP on the Flutter host page yet | 1 | 3 | **Reduce next:** serve the built Flutter app with a CSP; API responses now have CSP |
| Q7 | Collection timing check could give false results on a very busy machine | 2 | 1 | **Accept:** 50 % margin; repeat the run before concluding |
| Q8 | Review queue / notifications not paginated (B3) | 1 | 1 | **Accept:** bounded by design (needs_review only; 50 notifications) |
| Q9 | Certificate PDFs unencrypted on disk (Week 14 R2) | 1 | 3 | **Unchanged**; next improvement after Week 16 |

**Realistic next improvement:** put Newman + `composer audit` + PHPUnit into a CI pipeline on every push. That would have
caught D1 the day the advisories were published, and it protects the hardening work from regressions.
