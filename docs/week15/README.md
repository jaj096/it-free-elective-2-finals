# Week 15 – Integration Testing and Security Hardening (IA6)

| # | Document | One-line purpose |
| --- | --- | --- |
| 1 | [01-test-strategy.md](01-test-strategy.md) | Scope, sequence diagram with failure paths, acceptance criteria, test layers, decision record |
| 2 | [02-traceability-matrix.md](02-traceability-matrix.md) | 17 requirements → tests (positive, negative, boundary, authorization, recovery), before vs after |
| 3 | [03-failure-injection-log.md](03-failure-injection-log.md) | Predicted vs actual for 503, timeout, duplicates, bad input; recovery steps |
| 4 | [04-hardening-backlog.md](04-hardening-backlog.md) | Ranked weaknesses (L × I × E), what was fixed, what waits |
| 5 | [05-defect-records.md](05-defect-records.md) | D1–D6 (system) and T1–T2 (my own test mistakes): reproduce, cause, fix, retest |
| 6 | [06-hardening-report.md](06-hardening-report.md) | Executive summary, regression result, residual-risk register |
| 7 | [07-activity1-test-baseline.md](07-activity1-test-baseline.md) | Activity 1 + one-page interpretation |
| 8 | [08-activity2-hardening-sprint.md](08-activity2-hardening-sprint.md) | Activity 2: weak config review, sprint before/after, one-page interpretation |
| — | [weak-service-config.example](weak-service-config.example) | The intentionally weak configuration reviewed in Activity 2 (fake values) |

Evidence: [`evidence/week15/`](../../evidence/week15/index.md) · Collection: [`tests/postman/`](../../tests/postman/)

## Run it

With the generator, API (`PHP_CLI_SERVER_WORKERS=4 php artisan serve …`), worker and Caddy running (see the main README):

```bash
bash scripts/run-week15.sh after      # ~6 min; writes evidence/week15/after/
```

**In the Postman app (for a demo or screenshots):** Import → `tests/postman/cert-queue.postman_collection.json` and
`cert-queue.local.postman_environment.json` → select the environment → Settings → Certificates → CA certificate →
`certs/dev-ca.pem` → run folders 00–07 with the Collection Runner. Folders 08–10 need `GENERATOR_MODE` changed by hand
first (`always_503`, then `slow`, then `success`).

## What was done (in plain words)

1. **Wrote a test collection** for the live system: 66 requests in 11 folders. Every response is also checked for a
   correlation ID and a safe error format.
2. **Ran it before changing anything** and kept the failures (`evidence/week15/before/`).
3. **Fixed six weaknesses**, each with a test that fails on the old code:
   outdated packages (7 advisories), login timing leak, misleading error for broken JSON, missing security headers +
   PHP version leak, silent 200-row list limit, and a 500 when two identical submits race.
4. **Ran everything again**: 0 failures, clean audit, 71 + 14 tests pass (`evidence/week15/after/`).
5. **Was honest about my own mistakes:** two tests had wrong expectations (T1, T2). The timeout one taught me something
   about the system.

## Viva cheat sheet: likely questions

**Why Postman/Newman and not just PHPUnit?**
PHPUnit tests the app in memory with the generator faked. The collection tests the real thing: Caddy and TLS, the real
worker, the real generator in failure modes. It found things PHPUnit can't, like the `X-Powered-By` header that PHP's
web server adds and the timing difference over the network. PHPUnit stays as the fast safety net, and every fix got a
PHPUnit test too.

**How did you find the weaknesses?**
Four ways: `composer audit` (D1), reading each endpoint's code (D2, D5, D6), looking at the raw headers with
`curl -D -` (D4), and running adverse requests (D3). Then I wrote the test first, watched it fail, fixed it, and watched it pass.

**What's account enumeration and how did timing cause it?**
It means finding out which emails have accounts. Both cases said "Email or password is incorrect", but for an unknown email
the code skipped bcrypt, so it answered in 10 ms instead of 227 ms. Now it always runs one bcrypt check (against a dummy
hash), so both take about 227 ms.

**Why is a dummy hash safe to put in the code?**
It's a hash of a random value nobody knows, and it can't log anyone in, because it's only used when the user doesn't
exist (and then the login fails anyway). It just makes the work equal.

**Why does `no-store` matter for an API?**
Responses include names, request details and certificate PDFs. `no-cache` still lets the browser keep a copy on disk.
`no-store` means no copy at all, so it matters on shared lab computers.

**The race (W6) never happened live. Why fix it?**
SQLite runs writes one at a time, which hides it. On MySQL under load, two requests can both see "key not used" and
both insert. The UNIQUE index stops the duplicate, but the loser got a 500, and the user would think it failed. I forced
the race in a test (insert the competing row right after the lookup) to prove both the bug and the fix.

**Your timeout test failed. Was that a system bug?**
No, my prediction was wrong. The backend gives up after 10 s, but the generator finishes at 15 s and stores the PDF
under the job key. The retry gets that same PDF back, so there's one certificate, no duplicate, and faster recovery.
I kept the failed run as evidence and fixed the test (T1).

**What's the difference between 401, 403 and 404 in your API?**
401: we don't know who you are (no or bad token). 403: we know you, but your role can't do this. 404: it doesn't
exist, **or it's someone else's**. We say 404 for other people's records so IDs can't be probed.

**How do you know the fixes didn't break anything?**
The whole collection (normal path, roles, duplicates, failure modes) and all earlier tests ran again after the fixes:
0 failures. The dependency update was checked the same way.

**What would you do next?**
Put Newman + `composer audit` + PHPUnit in CI on every push, add a per-IP login limit, and re-test the race and
timing under Week 16 load.
