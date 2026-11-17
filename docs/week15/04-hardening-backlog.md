# 4. Hardening backlog and implemented controls

How the weaknesses were found: reading the code path of every endpoint in the collection, `composer audit`,
inspecting live response headers with `curl -D -`, and the baseline collection run. Each is scored
**Likelihood × Impact × Exposure** (1–3 each, max 27). Exposure: 3 = reachable without logging in,
2 = any signed-in user, 1 = staff only or needs local access.

## 4.1 Ranked backlog

| Rank | ID | Weakness | L | I | E | Score | Decision |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | **W1** | 7 known advisories in `laravel/framework`, `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing` (path-based access-control bypass, trusted-proxy spoofing, host injection, HEAD bypass) | 2 | 3 | 3 | **18** | ✅ Fixed this sprint |
| 2 | **W2** | Login answers an unknown email in ~10 ms but a real one in ~227 ms, so anyone can find out which accounts exist (account enumeration) | 3 | 2 | 3 | **18** | ✅ Fixed |
| 3 | **W4** | No `Cache-Control: no-store` on responses with personal data and PDFs; no CSP / `X-Frame-Options` / `Referrer-Policy`; `X-Powered-By: PHP/8.5.9` shows the exact version | 2 | 2 | 3 | **12** | ✅ Fixed |
| 4 | **W5** | `GET /requests` stopped at 200 rows **without saying so**. Staff could miss requests: "missing output", which this project exists to prevent | 2 | 3 | 1 | **6** → raised to sprint (core business rule) | ✅ Fixed |
| 5 | **W3** | Malformed JSON was read as "no fields", giving 422 "Choose a certificate type" for a request that contained one. Blames the user for a client bug; hides defects | 2 | 1 | 2 | **4** | ✅ Fixed (cheap, improves diagnosability) |
| 6 | **W6** | Two submits with the same `Idempotency-Key` at the same moment: the loser hit the UNIQUE index and got **500**. No duplicate is created (the index holds), but the client sees a server error | 1 | 2 | 2 | **4** | ✅ Fixed (latent: not reproduced live on SQLite, reproduced in a test) |
| 7 | B1 | Login rate limit is per email+IP, so many emails from one IP aren't limited together (password spraying) | 2 | 2 | 3 | 12 | ⏭ Next sprint: add a per-IP limit (e.g. 30/min) next to the per-email one |
| 8 | B2 | Flutter keeps the token in `sessionStorage` (readable by injected script) | 1 | 3 | 2 | 6 | ⏭ Accepted for prototype (Week 14 R8); CSP on the Flutter host page is the next step |
| 9 | B3 | Review queue and notifications lists are not paginated | 1 | 1 | 1 | 1 | ⏭ Low: review queue is small by design; notifications capped at 50 with newest first |
| 10 | B4 | Certificate PDFs are not encrypted on disk | 1 | 3 | 1 | 3 | ⏭ Week 14 residual risk R2 |
| 11 | B5 | `CORS_ALLOWED_ORIGINS` in the local `.env` still lists `http://localhost:5000` (plain HTTP origin) | 1 | 1 | 2 | 2 | ⏭ Remove after the Flutter dev server is always run with TLS |

The order follows the score, with one exception: W5 was moved into the sprint because silently missing records break
the project's main requirement ("no duplicate or missing output"), even though its exposure is low.

## 4.2 Implemented controls (what changed)

| ID | Change | Files | Proof |
| --- | --- | --- | --- |
| W1 | `composer update laravel/framework "symfony/*" --with-dependencies`: Laravel 13.39.0 → 13.43.0, Symfony 8.1.10 → 8.1.11 (+ minor updates). Version ranges in `composer.json` unchanged | `backend/composer.lock` | `evidence/week15/{before,after}/01-composer-audit.txt`: 7 → 0 advisories; 71/71 tests still pass |
| W2 | Always run one bcrypt check. Unknown emails are checked against a fixed dummy hash with the same cost (12) | `AuthController.php` | Collection timing check; `HardeningTest::test_w2_*` |
| W3 | `RejectMalformedJson` middleware in the `api` group → 400 `MALFORMED_JSON` | `app/Http/Middleware/RejectMalformedJson.php`, `bootstrap/app.php` | 02 / Malformed JSON body; `test_w3_*` |
| W4 | `SecurityHeaders` global middleware (API paths only): `Cache-Control: no-store, private`, `Pragma: no-cache`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, removes `X-Powered-By` | `app/Http/Middleware/SecurityHeaders.php`, `bootstrap/app.php` | 07 / [H] headers; 01 / download `no-store`; `test_w4_*` |
| W5 | `paginate(per_page 1–100, default 50)` with `meta`/`links`; Flutter `listRequests()` follows every page (max 20 × 100) | `CertificateRequestController@index`, `frontend_flutter/lib/api/api_client.dart` | 07 / [H] paginated; `test_w5_*` (230 rows all reachable); `pagination_test.dart` |
| W6 | Catch `UniqueConstraintViolationException` on insert, look the key up again, and answer like a normal retry (200 same request, or 409 different data) | `CertificateRequestController@store` | `test_w6_*`; step 15.7 (60 parallel submits, 5 rows) |

## 4.3 What the sprint did not change (on purpose)

- No change to the Week 13/14 controls (retries, signing, encryption, roles). The full regression set proves they still hold.
- The JSON shape of `GET /requests` changed only by **adding** `meta` and `links`; `data` is unchanged, so an old client
  still works (it just sees page 1). The Flutter client was updated in the same commit.
- CSP on the API doesn't affect the Flutter app, which is served from its own origin. CORS still allows the app's origin.
