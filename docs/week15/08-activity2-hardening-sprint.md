# 8. Activity 2 – Weak configuration review and bounded hardening sprint

**Task:** review an intentionally weak service configuration, rank its weaknesses, implement a bounded hardening sprint,
and present before-and-after evidence without revealing operational secrets.

**Inputs:** (a) the intentionally weak sample [`weak-service-config.example`](weak-service-config.example) (fake values),
and (b) the real cert-queue service as it was at the end of Week 14 (commit `254290a`), which is what the sprint changed.

**Assumptions:** fictional data; one office; attackers can reach the API over the network but not the server's shell;
the instructor (as service owner) accepts the result. **Constraint:** bounded sprint, about one day; no change to
approved workflows; every change needs a test.

## 8.1 Review of the weak sample (ranked)

Score = Likelihood × Impact × Exposure (1–3 each).

| Rank | Item | Weakness | Score | Safe value | cert-queue today |
| --- | --- | --- | --- | --- | --- |
| 1 | C1 | `APP_DEBUG=true` in production: stack traces, paths and env values in error pages | 27 | `false` | ✅ `false`; collection checks no stack trace on every error |
| 2 | C10 | Generator over plain HTTP, TLS verification off | 27 | `https://`, verify on, CA bundle | ✅ HTTPS required, verified (Week 14) |
| 3 | C5 | DB listening on all interfaces as `root` with no password | 18 | localhost/private network, least-privilege user, strong password | ✅ SQLite file, local only (MySQL is optional and would need its own least-privilege user; not configured in this prototype) |
| 4 | C8 | `CORS *` lets any website call the API with a stolen token | 18 | explicit origin list | ✅ explicit list (residual: plain-HTTP dev origin, backlog B5) |
| 5 | C9 | Login limit 1000/min, so guessing passwords is easy | 18 | 5/min per account | ✅ 5/min per email+IP (residual: no per-IP total, backlog B1) |
| 6 | C11 | One key for both encryption and signing | 12 | separate keys + key ID | ✅ separate keys, refused if equal (Week 14) |
| 7 | C4 | Debug logging of request bodies: passwords and tokens end up in logs | 12 | `info`/`notice`, no bodies | ✅ structured logs with IDs only; secret scan clean |
| 8 | C7 | Tokens never expire | 12 | short expiry | ✅ 8 h, `TOKEN_EXPIRED` (Week 14) |
| 9 | C14 | No `no-store` / CSP / frame headers | 12 | set them | ❌ → **fixed this sprint (W4)** |
| 10 | C13 | `expose_php=On` → `X-Powered-By: PHP/x.y.z` | 8 | Off / header removed | ❌ → **fixed this sprint (W4)** |
| 11 | C12 | Timeout 0 (wait forever), attempts 0 (no bound) | 8 | 10 s, 3 attempts | ✅ 10 s, 3 attempts, then review |
| 12 | C6 | bcrypt cost 4 (fast to crack) | 6 | 12 | ✅ 12 (4 only in `phpunit.xml` for test speed) |
| 13 | C2 | A real-looking key committed in a config file | 6 | only in `.env`, git-ignored | ✅ only fake values in examples; scan in Week 14 + 15 |
| 14 | C3 | `APP_URL` on `http://` | 4 | `https://` | ✅ local HTTPS via Caddy |

**What this shows:** the sample's worst items were already covered by Weeks 13–14. The cert-queue gaps were the
less obvious ones: response headers, version disclosure, plus four found only by testing and code review (dependency
advisories, login timing, silent list cut-off, malformed JSON) and one by reasoning about concurrency (idempotency race).

## 8.2 Comparing approaches for the sprint

| Option | For | Against |
| --- | --- | --- |
| **A. Fix in the application, with a test for each** (chosen) | Works with or without the proxy; each fix is proven by PHPUnit and the live collection; travels with the code | Some duplication with Caddy (e.g. `nosniff` in both) |
| B. Fix at the proxy (Caddy headers) and the platform (`expose_php`) only | Fewer code changes; one place for headers | Can't be tested by PHPUnit; lost if the app is deployed behind another proxy; can't fix timing, pagination, JSON or idempotency |
| C. Add a WAF / API gateway | Central rules, rate limiting | Out of scope for one office and one sprint; new service to own; doesn't fix app logic |

**Risks of A:** a header middleware could break the Laravel welcome page (handled: API paths only, tested);
pagination changes the list response (handled: additive `meta`/`links`, Flutter updated in the same commit);
a dependency update can break things (handled: full regression, 71 + 14 tests and the full collection).

## 8.3 Sprint: before → after (no secrets shown)

| Check | Before (`254290a`) | After (`883df35`) | Evidence |
| --- | --- | --- | --- |
| `composer audit` | 7 advisories, 4 packages | **0** | `{before,after}/01-composer-audit.txt` |
| Login timing, unknown vs real email | 10 ms vs 227 ms | ~226 vs ~227 ms | `02-newman-main.txt` (timing line) |
| Malformed JSON | 422 "Choose a certificate type" | **400 `MALFORMED_JSON`** | `02-newman-main.txt` |
| `Cache-Control` | `no-cache, private` | **`no-store, private`** | `02-newman-main.txt` (07 folder) |
| CSP / X-Frame-Options / Referrer-Policy | missing | **present** | same |
| `X-Powered-By` | `PHP/8.5.9` | **absent** | same |
| `GET /requests?per_page=2` | 15 items, no `meta` | **2 items, `meta.total`, `last_page`** | same |
| `per_page=1000` | 200 OK | **422** | same |
| Forced idempotency race (PHPUnit) | 500 | **200 / 409** | `hardening-tests-on-old-code.txt`, `after/08-backend-tests.txt` |
| Collection assertions failed | 17 of 331 | **0 of all** | `02`–`05` in each folder |
| PHPUnit / Flutter tests | 59 / 12 pass | **71 / 14 pass** | `08-`, `09-` |

## 8.4 One-page interpretation

**Observed facts.** The baseline collection run failed exactly the 14 hardening checks written to expose W2–W5 (plus 3
failures from two mistakes in my own tests). `composer audit` reported 7 advisories. The new PHPUnit tests failed on
the old code (7 failures + 1 error) and pass on the new code. After the sprint, every collection folder passes, the
audit is clean, and all earlier tests still pass. A 60-request parallel submit test produced one row per key both before
and after; the race (W6) was not reproduced live.

**Reasoned conclusions.** The service's main reliability and crypto controls (Weeks 13–14) were sound; its weaknesses
were at the HTTP boundary and in supply-chain hygiene. Dependency advisories appeared *after* Week 14 was finished, so a
one-off check is not enough. The highest-value fixes were cheap: a dependency update and a one-line timing fix. W5
mattered more for the business ("no missing output") than for security. W6 is a correctness fix for a race the SQLite
setup hides.

**Recommendations.** (1) Run `composer audit` and the collection on every change (CI) and weekly for new advisories.
(2) Add a per-IP login limit (B1). (3) Re-run F5 and the timing check under Week 16 load on MySQL. (4) Before wider use,
an authorized maintainer should review the remaining risks in [06](06-hardening-report.md); this prototype is **not production ready**.
