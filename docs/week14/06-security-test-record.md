# 6. Security test record and residual-risk register

Run on 2026-11-04 (commit `3b998d3` + docs) on macOS, PHP 8.5.9, Laravel 13.39, Caddy 2.11.7, Flutter 3.47.8.
**Automated:** `php artisan test` → **59 passed**; `flutter test` → **12 passed**.
**Live:** `bash scripts/run-evidence.sh week14` → files in `evidence/week14/`.

## Test record (expected vs actual)

| ID | Test | Expected | Actual | Evidence |
| --- | --- | --- | --- | --- |
| T01 | Valid signed + encrypted request | 201, PDF | 201, PDF ✅ | `03-security-test.txt`; `SecureExchangeTest::test_valid_request_is_accepted…` |
| T02 | Name/ID on the wire | Only ciphertext | No plaintext; fields `v, job_key, certificate_type, purpose, protected` ✅ | `02-wire-sample.json` |
| T03 | Altered ciphertext (1 bit flipped, re-signed) | 400 `DECRYPTION_FAILED` | 400 `DECRYPTION_FAILED` ✅ | `03-…`; `test_altered_ciphertext_is_rejected` |
| T04 | Bad signature (wrong key) | 401 `BAD_SIGNATURE` | 401 `BAD_SIGNATURE` ✅ | `03-…`; `test_bad_signature_is_rejected` |
| T05 | Body changed after signing | 401 `BAD_SIGNATURE` | 401 ✅ | `test_body_changed_after_signing_is_rejected` |
| T06 | Timestamp 10 min old | 401 `REQUEST_EXPIRED` | 401 `REQUEST_EXPIRED` ✅ | `03-…`; `test_expired_timestamp_is_rejected` |
| T07 | Same request sent twice | 1st 201, 2nd 409 `REPLAY_DETECTED` | 201 then 409 ✅ | `03-…`; `test_replayed_nonce_is_rejected` |
| T08 | Unknown/revoked key ID | 401 `UNKNOWN_KEY_ID` | 401 `UNKNOWN_KEY_ID` ✅ | `03-…`; `test_wrong_key_id_is_rejected` |
| T09 | Unsigned request | 401 `MISSING_SIGNATURE` | 401 ✅ | `test_unsigned_request_is_rejected` |
| T10 | Ciphertext moved to another job (re-signed by an insider) | 400 `DECRYPTION_FAILED` (AAD binding) | 400 ✅ | `test_ciphertext_moved_to_another_job_fails_decryption` |
| T11 | Plain HTTP to generator | Sender refuses; receiver 403 `HTTPS_REQUIRED` | Sender blocked before sending; 403 ✅ | `03-…`, `01-transport.txt`; `test_plain_http_is_refused_by_sender_and_by_receiver` |
| T12 | Rejection logs | Reason code, key ID, correlation ID only | 6 lines, no keys, signatures, ciphertext, name or ID ✅ | `04-generator-rejection-log.jsonl`; `test_rejection_logs_contain_no_keys…` |
| T13 | Key rotation: overlap, switch, revoke | 201, 201, then 401 | ✅ | `test_key_rotation_…` |
| T14 | Missing/identical keys | Permanent error, generator not called, no key in message | ✅ | `test_missing_or_reused_keys_fail_safely…` |
| T15 | Security rejection inside the real job | `needs_review` after 1 attempt, no retry | ✅ | `test_security_rejection_sends_job_to_review_without_retry` |
| T16 | TLS on API and generator | TLS 1.2/1.3, `verify ok`, HSTS | TLSv1.3, verify ok, HSTS ✅ | `01-transport.txt` |
| T17 | Untrusted certificate | Client refuses (curl exit 60) | exit 60 ✅ | `01-transport.txt` |
| T18 | API over plain HTTP (health, login) | 403 `HTTPS_REQUIRED` | 403, 403 ✅ | `01-…`; `ApiSecurityTest::test_plain_http_is_refused…` |
| T19 | Spoofed `X-Forwarded-Proto` from a non-local IP | 403 | 403 ✅ | `test_forwarded_proto_from_an_untrusted_address_is_ignored` |
| T20 | ID number at rest | Ciphertext; 0 plaintext rows; different ciphertext for equal values | ✅ | `05-db-at-rest.txt`; `AtRestEncryptionTest` |
| T21 | Tampered DB ciphertext | `DecryptException` | ✅ | `test_altered_ciphertext_in_database_is_detected` |
| T22 | Migration of old plaintext rows | Encrypted once, idempotent | ✅ | `test_migration_encrypts_existing_plaintext_rows_once` |
| T23 | APP_KEY rotation | Old key alone fails; after re-encrypt, new key alone works | ✅ | `test_app_key_rotation_reencrypts_with_the_new_key` |
| T24 | Passwords | bcrypt `$2y$12$` | ✅ | `05-…`; `test_password_is_a_bcrypt_hash…` |
| T25 | Expired Sanctum token | 401 `TOKEN_EXPIRED` | 401 `TOKEN_EXPIRED` ✅ | `06-api-controls.txt`; `test_expired_sanctum_token_is_rejected…` |
| T26 | Token after 8 h 1 min | 401 `TOKEN_EXPIRED` | ✅ | `test_token_works_until_it_expires` |
| T27 | Logout revokes token | 401 afterwards | ✅ | `test_logout_revokes_the_token` |
| T28 | Role check, ownership, validation, safe errors | 403, 404, 422 without stack traces | ✅ | `06-…`; `test_role_checks_validation_and_safe_errors_still_apply` + Week 13 tests |
| T29 | Rate limit | 6th bad login in a minute → 429 | 429 ✅ | `06-…`; `test_login_is_rate_limited` |
| T30 | Full ID never returned by API | Masked `2026-***01`, not in `/auth/me` | ✅ | `06-…`; `test_api_never_returns_the_full_id_number` |
| T31 | Altered certificate file | Download refused 409 `INTEGRITY_FAILED`; header `X-Content-SHA256` | ✅ | `test_download_sends_hash_and_refuses_an_altered_file` |
| T32 | Flutter safe messages: invalid, altered, expired, unauthorized, HTTPS | Specific safe text, no internals | ✅ 9 tests | `frontend_flutter/test/security_messages_test.dart` |
| T33 | Flutter refuses `http://` API | Nothing sent | ✅ | same file |
| T34 | Secret scan: logs, tracked files, full Git history | No keys, tokens, names, IDs | None found ✅ | `07-secret-scan.txt`; history check in Activity 2 |
| T35 | Week 13 regression | All Week 13 tests still pass | 23/23 ✅ (one assertion updated: it checked the removed `X-Service-Key` header, now checks `X-Key-Id` + `X-Signature`) | `08-backend-tests.txt` |

**Failures seen during the run (kept, as required):** the first "untrusted CA" check used `--cacert /dev/null` and gave
curl exit **77** (empty CA file) instead of **60**. That tested the wrong thing. It was changed to use the normal system
trust store, and gave exit 60.

## Residual-risk register

Likelihood (L) and Impact (I) on a 1–3 scale; score = L × I.

| ID | Risk that remains | L | I | Score | Treatment / owner |
| --- | --- | --- | --- | --- | --- |
| R1 | A process on the same machine can call the loopback servers directly and set `X-Forwarded-Proto: https` | 1 | 2 | 2 | **Accept** for a class prototype. Prod: Unix socket or mTLS between Caddy and app; separate hosts |
| R2 | Generator keeps generated PDFs (name + ID) in plaintext in `storage/pdf/` for idempotent replay | 2 | 2 | 4 | **Reduce:** delete after the backend confirms, or encrypt with a storage key. Mock service only |
| R3 | Backend stores certificate PDFs in plaintext in `storage/app/private/certificates` | 2 | 2 | 4 | **Reduce:** full-disk encryption (FileVault/BitLocker) now; encrypt files with a separate key later |
| R4 | HMAC is symmetric: the generator could forge a request; PDFs carry no digital signature, so outsiders can't verify a certificate | 1 | 2 | 2 | **Accept** now; Ed25519-signed certificates with a public verify page (future week) |
| R5 | Keys live in `.env` on the same host as the database; a full host compromise gets both | 1 | 3 | 3 | **Reduce** in prod: secrets manager / KMS; least-privilege OS users |
| R6 | Nonce store is one JSON file: fine for one instance, not for several, and a bottleneck under load | 1 | 2 | 2 | **Accept**; Redis `SET NX EX 600` when scaling (Week 16 load test) |
| R7 | Clock skew > 5 min rejects all requests (availability) | 1 | 2 | 2 | **Reduce:** NTP on both hosts; jobs go to review, not lost |
| R8 | Token in `sessionStorage` can be stolen by injected script (XSS) | 1 | 3 | 3 | **Reduce:** Content-Security-Policy header in Caddy, no `innerHTML`; short 8-h expiry already |
| R9 | `purpose` is not encrypted (only authenticated) and may contain personal hints | 2 | 1 | 2 | **Accept**: it's inside TLS and printed anyway; can move it into `protected` if needed |
| R10 | Dev CA private key on the laptop could mint certificates this machine trusts | 1 | 2 | 2 | **Reduce:** `chmod 600`; remove the CA from the trust store after the course |
| R11 | Requester name is still plaintext in the DB | 2 | 1 | 2 | **Accept**: needed for search and display; ID number (the identifier) is encrypted |
| R12 | Backups made before Week 14 (e.g. `backend/database/database.sqlite.week13.bak`) contain plaintext ID numbers | 2 | 1 | 2 | **Remove:** delete old backups once Week 14 is verified (fictional data, so low impact) |
| R13 | Generator responses are protected only by TLS (not signed) | 1 | 2 | 2 | **Accept**; the backend checks the response is a valid PDF and records its SHA-256; sign responses later |
| R14 | Losing `APP_KEY` makes all ID numbers unreadable | 1 | 3 | 3 | **Reduce:** offline backup of `.env` secrets (password manager) |
