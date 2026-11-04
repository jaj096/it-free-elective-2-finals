# 8. Activity 2 – Secure-exchange review (backend worker → generator service)

**Reviewer:** Jewel Julian · **Date:** 2026-11-04 · **Target:** `GeneratorClient` (sender) → Caddy → `generator-service` (receiver)

## 1. Source inspection

| File | What I checked | Finding |
| --- | --- | --- |
| `backend/app/Security/PayloadEncryptor.php` | Library, nonce source and size, AAD | libsodium XChaCha20-Poly1305 IETF; 24-byte nonce from `random_bytes`; AAD = `{v, job_key, certificate_type, purpose}` ✅ |
| `backend/app/Security/RequestSigner.php` | What the HMAC covers | Scheme tag + method + path + timestamp + nonce + key ID + SHA-256(body) ✅ |
| `backend/app/Security/GeneratorKeyring.php` | Key loading | Only from config/.env; length checks; refuses identical keys; `__debugInfo` hides keys; errors never contain key material ✅ |
| `backend/app/Security/SecureRequestBuilder.php` | Data minimisation | Exactly 5 printed fields; name + ID only inside `protected` ✅ |
| `backend/app/Services/GeneratorClient.php` | Transport and error handling | Refuses non-https URL; `verify` = dev CA or system store, never `false`; 401/403/409 → permanent → human review ✅ |
| `generator-service/src/SecureReceiver.php` | Order of checks, comparisons | key ID → HMAC (`hash_equals`) → time window → nonce (stored only after the HMAC passes) → AEAD decrypt ✅ |
| `generator-service/public/index.php` | HTTPS check, logging | `X-Forwarded-Proto` trusted only from loopback; rejection logs hold reason, status, key ID and a sanitised correlation ID ✅ |
| Whole codebase, pattern search | `verify => false`, `withoutVerifying`, `md5(`, `sha1(`, `rand(`, `mt_rand`, `uniqid(`, `==` on signatures, log calls with name/ID/password/token | **No matches** ✅ |

Security code reviewed: about 670 lines across 8 files.

## 2. Configuration review

| Setting | Value (example / local) | OK? |
| --- | --- | --- |
| `GENERATOR_URL` | `https://localhost:8444` | ✅ |
| `GENERATOR_REQUIRE_HTTPS` (backend + generator) | `true` | ✅ |
| `GENERATOR_CA_BUNDLE` | path to `certs/dev-ca.pem` (verification on) | ✅ |
| `GENERATOR_KEY_ID` / `GENERATOR_ACCEPTED_KEY_IDS` | `k202611` / `k202611` (one active key) | ✅ |
| Encryption ≠ signing key, both 32 bytes | enforced in code on both sides | ✅ |
| `GENERATOR_MAX_SKEW_SECONDS` | 300 | ✅ |
| `APP_DEBUG` | `false` in `.env.example` and local | ✅ |
| `APP_REQUIRE_HTTPS`, `trustProxies` | `true`; only `127.0.0.1`, `::1` | ✅ |
| `.env.example` files | only visibly fake keys (`FAKE-…-DO-NOT-USE`) | ✅ |
| `.gitignore` | `.env`, `.env.backup`, `certs/`, `*.sqlite*` | ✅ |
| `Caddyfile` | TLS 1.2/1.3 only, HSTS, nosniff, no `Server` header, no port-80 redirect | ✅ |
| CORS | `https://localhost:5000`; exposes only `X-Correlation-ID`, `Content-Disposition`, `X-Content-SHA256` | ✅ |
| Sanctum | tokens expire after 8 h; daily `sanctum:prune-expired` | ✅ |

## 3. Transport check (`evidence/week14/01-transport.txt`)

- API and generator: **TLSv1.3**, certificate `CN=localhost` issued by `Cert Queue Dev Root CA`, **"SSL certificate verify ok"**, HSTS present.
- Client that doesn't trust the dev CA: **curl exit 60**, so verification is really on.
- Plain HTTP: API health **403**, login **403**, generator `/generate` **403**, all `HTTPS_REQUIRED`.
- Sender guard: with `GENERATOR_REQUIRE_HTTPS=true` the worker refuses an `http://` URL **before sending** (test T11).

## 4. Tamper tests (`php artisan generator:security-test`, `evidence/week14/03-security-test.txt`)

| Attack | Expected | Actual |
| --- | --- | --- |
| Control (valid) | 201 | 201 ✅ |
| Altered ciphertext | 400 `DECRYPTION_FAILED` | ✅ |
| Bad signature | 401 `BAD_SIGNATURE` | ✅ |
| Expired timestamp (−10 min) | 401 `REQUEST_EXPIRED` | ✅ |
| Replayed nonce | 409 `REPLAY_DETECTED` | ✅ (first copy 201) |
| Wrong key ID | 401 `UNKNOWN_KEY_ID` | ✅ |
| Plain HTTP | 403 `HTTPS_REQUIRED` | ✅ (sender also blocked it) |

Extra automated cases: body edited after signing, missing signature, ciphertext moved to another job, key rotation and
revocation, security rejection during a real job → `needs_review` with no retry (tests T05, T09, T10, T13, T15).

## 5. Secret scan

- `evidence/week14/07-secret-scan.txt`: the real values of `APP_KEY` and both exchange keys (base64 and raw), the
  name `Juan Dela Cruz`, the ID `2026-00001`, `Bearer ` and `"password"` were searched in `automation.log`,
  `laravel.log`, `generator.log` and every git-tracked file. **No findings.**
- Git history: the 8 real secret values in `backend/.env`, `generator-service/.env` and their backups were checked
  against `git log --all -p` (every commit, ~590 KB). **0 found.**
- Test `test_rejection_logs_contain_no_keys_signatures_or_personal_data` also checks that the signature, nonce
  and ciphertext never appear in the receiver's log.

## 6. Residual risk

The main remaining risks (full register in document 6):

- **R2/R3:** PDFs, which contain the name and ID, sit unencrypted on both the backend and generator disks.
- **R4:** symmetric HMAC (no non-repudiation), and certificates carry no digital signature.
- **R5:** keys and database share one host.
- **R1:** a local process could bypass Caddy on the loopback interface.
- **R12:** pre-Week-14 backups still hold plaintext ID numbers.

---

## One-page interpretation

### Observed facts
1. Every request from the worker to the generator travels over TLS 1.3 with a verified certificate. Plain HTTP is
   refused by the sender **and** by both servers (403 `HTTPS_REQUIRED`).
2. On the wire, the requester's name and ID number appear only as XChaCha20-Poly1305 ciphertext. The request has exactly
   five content fields and carries a key ID, timestamp, nonce, body hash and HMAC-SHA256 signature.
3. All six attack types were rejected with the planned status codes, in the live HTTPS setup and in 16 automated tests
   against a real generator process. A valid control request succeeded in the same run.
4. Each rejection produced one log line with a reason code, key ID and correlation ID. No key, signature, ciphertext,
   name or ID number appeared in any log, tracked file or commit.
5. In the database, ID numbers are 200-character ciphertexts (0 plaintext rows) and passwords are bcrypt (`$2y$12$`).
   An expired Sanctum token gets `401 TOKEN_EXPIRED`; role checks, validation (422) and rate limiting (429) still work.
6. All 23 Week 13 tests still pass (59 backend tests in total), plus 12 Flutter tests.

### Conclusions
- **Confidentiality** of the personal fields in transit is met twice over (TLS + AEAD); at rest it is met for the
  ID number but **not yet for PDF files**.
- **Integrity and authenticity** of generator requests are met: any change to headers, body or ciphertext is detected,
  and only a holder of the current signing key can create a valid request.
- **Freshness** is met: old or repeated requests are rejected, and legitimate retries still work because each retry
  is signed again with a new nonce.
- **Accountability** is met: rejections are visible and traceable through the correlation ID without exposing personal data.
- The design depends on keeping `.env` secret and clocks in sync. Those are operational controls, not code.

### Recommendations (priority order)
1. Encrypt certificate files at rest (or at least enable full-disk encryption) and delete generator copies after
   delivery (R2, R3).
2. Delete pre-Week-14 database backups that contain plaintext IDs (R12).
3. Add a Content-Security-Policy header in Caddy to reduce the XSS risk to the browser token (R8).
4. Move keys to a secrets manager when deployed, and rotate exchange keys every 90 days (document 5) (R5).
5. Later: Ed25519-signed certificates with a public verification page (R4); Redis nonce store when running more
   than one generator (R6); mTLS or a Unix socket between Caddy and the PHP servers (R1).
