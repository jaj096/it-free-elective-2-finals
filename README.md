# Certificate Generation Queue (Project 21)

PFEBSIT 002 Integrative Programming and Technologies 2 · Weeks 13–18 individual project
Jewel Julian · BSIT 3A · University of La Salette, Inc.

Approved certificates must be generated and released **without duplicate or missing output**.
Staff approve a request; a background queue job generates the PDF exactly once, retries temporary
failures a limited number of times, and sends permanent failures to a human review queue.
Approval, verification and release remain human decisions.

> Class prototype with **fictional data only**. Not production ready.

## What's in this repository

| Folder | What it is |
| --- | --- |
| `backend/` | Laravel 13 REST API (`/api/v1`), Sanctum token auth, database queue, automation job, tests |
| `generator-service/` | Mock Certificate Generator Service (plain PHP, no dependencies) with switchable failure modes |
| `frontend_flutter/` | Flutter Web client (browser only) |
| `scripts/run-evidence.sh` | Runs the Week 13 and Week 14 evidence scenarios against the live system and saves the results |
| `scripts/dev-certs.sh`, `Caddyfile` | Week 14: local dev CA + HTTPS reverse proxy |
| `evidence/` | Evidence index and lab log template (fill this from your own runs); `evidence/week14/` |
| `docs/week14/` | Week 14 security documents (data flow, requirements, ADR, key lifecycle, tests, activities) |

```
Flutter Web ──HTTPS──▶ Caddy ──▶ Laravel API ──▶ database queue ──▶ queue worker ──HTTPS + signed + encrypted──▶ Caddy ──▶ Generator
   (browser)  Bearer token          │                                 │   (HMAC-SHA256, XChaCha20-Poly1305, key ID)
                                    └── audit_logs + automation.log ◀─┘   (correlation ID links both)
```

## Requirements

- PHP 8.3+ (tested design on 8.5) with `pdo_sqlite` (or `pdo_mysql`), `mbstring`, `openssl`, `curl`
- Composer 2
- Flutter 3.x with web support (`flutter config --enable-web`), and Chrome
- Bash for the evidence script (Git Bash works on Windows)

## Setup (first time)

Week 14 runs everything over HTTPS. Full details and the reasons behind each step: [`docs/week14/https-setup.md`](docs/week14/https-setup.md).

### 0. Certificates and keys

```bash
brew install caddy                     # Windows: winget install CaddyServer.Caddy
bash scripts/dev-certs.sh              # local dev CA + localhost certificate in certs/ (git-ignored)
security add-trusted-cert -r trustRoot -k ~/Library/Keychains/login.keychain-db certs/dev-ca.pem   # so Chrome trusts it
```

### 1. Generator service

```bash
cd generator-service
cp .env.example .env            # Windows: copy .env.example .env
# Paste the generator block printed by `php artisan generator:keys` (step 2) into .env
php -S 127.0.0.1:8081 -t public public/index.php
```

### 2. Backend API (new terminal)

```bash
cd backend
composer install
cp .env.example .env            # Windows: copy .env.example .env
php artisan key:generate
php artisan generator:keys      # paste the backend block here and the generator block into generator-service/.env
# Set GENERATOR_CA_BUNDLE to the full path of certs/dev-ca.pem
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

To use MySQL instead of SQLite, set `DB_CONNECTION=mysql` and the `DB_*` values in `.env`.

### 3. Queue worker (new terminal) – **required**, or nothing gets generated

```bash
cd backend
php artisan queue:work          # after any .env change: php artisan queue:restart, then start it again
```

Optional, for the stuck-item sweeper (every 10 minutes): `php artisan schedule:work` in another terminal.

### 4. HTTPS proxy (new terminal, repository root)

```bash
caddy run                       # https://localhost:8443 → API, https://localhost:8444 → generator
```

### 5. Flutter Web client (new terminal)

```bash
cd frontend_flutter
flutter pub get
flutter run -d chrome --web-port=5000 \
  --web-tls-cert-path ../certs/localhost.pem --web-tls-cert-key-path ../certs/localhost.key \
  --dart-define=API_BASE_URL=https://localhost:8443/api/v1
```

If Flutter complains about missing web files, run `flutter create . --platforms web` once inside
`frontend_flutter` (it only adds missing files) and run again.

Check: `curl --cacert certs/dev-ca.pem https://localhost:8443/api/v1/health` should show `"database":"ok","generator":"ok"`.
Plain `http://127.0.0.1:8000/...` answers `403 HTTPS_REQUIRED` on purpose.

## Demo accounts (fictional)

All use the password `Password123!`

| Email | Role | Notes |
| --- | --- | --- |
| juan@example.test | Requester | Juan Dela Cruz, ID 2026-00001 |
| maria@example.test | Requester | Maria Santos, ID 2026-00002 |
| pedro@example.test | Requester | Pedro Reyes, **no ID number** (business-rule failure demo) |
| approver@example.test | Approving Staff | Ana Lim |
| custodian@example.test | Document Custodian | Carlo Garcia |

The seeder creates six submitted requests (#1–#6) ready for approval.

## Failure injection (Week 13 demo)

Change `GENERATOR_MODE` in `generator-service/.env`. The service re-reads it on every request, so
no restart is needed.

| Mode | Behaviour | Expected result in the app |
| --- | --- | --- |
| `success` | Returns a PDF | `generated` on attempt 1 |
| `fail_twice_then_succeed` | 503 twice per job, then a PDF | Retries, then `generated` on attempt 3 |
| `always_422` | Rejects the record | `needs_review` after 1 attempt (no retry) |
| `always_503` | Always unavailable | `needs_review` after exactly 3 attempts |
| `slow` | Waits 15 s (backend timeout is 10 s) | Treated as a timeout → retried |

Duplicate-delivery test: `php artisan certificates:dispatch-duplicate 1 --times=3` → still one certificate.

## Security failure injection (Week 14 demo)

```bash
cd backend && php artisan generator:security-test          # or --case=replayed-nonce, --json=file
```

| Case | Expected |
| --- | --- |
| `valid` (control) | 201 |
| `altered-ciphertext` | 400 `DECRYPTION_FAILED` |
| `bad-signature` | 401 `BAD_SIGNATURE` |
| `expired-timestamp` | 401 `REQUEST_EXPIRED` |
| `replayed-nonce` | 409 `REPLAY_DETECTED` |
| `wrong-key-id` | 401 `UNKNOWN_KEY_ID` |
| `plain-http` | sender refuses; generator 403 `HTTPS_REQUIRED` |

Each rejection writes one safe line to `generator-service/storage/generator.log` (reason, key ID, correlation ID).

**Run everything at once:** with the generator, API, worker and Caddy running on a freshly seeded database:

```bash
bash scripts/run-evidence.sh          # both weeks;  or: week13 | week14
```

It fills `evidence/01-…` to `evidence/07-…` (Week 13) and `evidence/week14/` (Week 14) with API responses and the matching log lines.
Add your own Flutter screenshots to `evidence/08-flutter-responsive/`.

## Reliability rules (where to find them in code)

| Control | Where |
| --- | --- |
| Allowed status transitions | `backend/app/Enums/RequestStatus.php` |
| Every status change + audit row + log line | `backend/app/Services/CertificateWorkflow.php` |
| Retry, backoff + jitter, transient vs permanent, idempotency | `backend/app/Jobs/GenerateCertificate.php` |
| Transient/permanent classification of generator responses | `backend/app/Services/GeneratorClient.php` |
| One certificate per request (DB-level guard) | `certificates.certificate_request_id` UNIQUE (migration) |
| Duplicate submit protection | `Idempotency-Key` header in `CertificateRequestController@store` |
| Stuck-item safety net | `backend/app/Console/Commands/SweepStuckCertificates.php` |
| Correlation ID across API and worker | `backend/app/Http/Middleware/AssignCorrelationId.php` (Laravel Context) |
| Structured JSON log format | `backend/app/Logging/FlatJsonFormatter.php` → `storage/logs/automation.log` |
| Retry settings | `backend/config/certificates.php` (read from `.env`) |

## Security controls (Week 14)

| Control | Where |
| --- | --- |
| Payload minimisation + encryption of name/ID (XChaCha20-Poly1305) | `backend/app/Security/SecureRequestBuilder.php`, `PayloadEncryptor.php` |
| HMAC-SHA256 request signing (method, path, timestamp, nonce, key ID, body hash) | `backend/app/Security/RequestSigner.php` |
| Keys from `.env`, separate encrypt/sign keys, key ID | `backend/app/Security/GeneratorKeyring.php`, `php artisan generator:keys` |
| Receiver checks: key ID → signature → 5-min window → nonce → decrypt | `generator-service/src/SecureReceiver.php` |
| HTTPS only + certificate verification | `Caddyfile`, `GeneratorClient::http()`, `RequireHttps` middleware |
| ID number encrypted at rest; masked in API | `User` casts, migration `2026_11_04_000100`, `security:reencrypt-id-numbers` |
| Expired token → `TOKEN_EXPIRED` | `bootstrap/app.php` |
| Download integrity (`INTEGRITY_FAILED`, `X-Content-SHA256`) | `CertificateController@download`, Flutter `ApiClient.verifyDownload` |
| Failure injection | `php artisan generator:security-test` |

## Tests

```bash
cd backend
php artisan test        # or: vendor/bin/phpunit
```

59 tests. Week 13 (23): the real queue job through a database queue worker with the generator faked:
normal success, transient recovery, permanent failure, retry limit, missing ID number, duplicate jobs,
sweeper, login/rate limit, validation, 401/403/404/409, idempotency key, full lifecycle, tamper detection
and insert-only audit log.

Week 14 (36): `SecureExchangeTest` and `SecurityTestCommandTest` start the **real** generator service (`php -S`)
and test every attack end to end; `AtRestEncryptionTest` (ciphertext in DB, key rotation); `ApiSecurityTest`
(expired token, HTTPS only, proxy trust, roles, download integrity).

Flutter: `cd frontend_flutter && flutter test` (12 tests, including safe security messages).

## Security notes

- Secrets live only in `.env` files, which are git-ignored. Only `.env.example` files with fake values are committed.
  The evidence script scans logs, tracked files and keys against Git history.
- Exchange keys and `APP_KEY` never reach Flutter. The old static `X-Service-Key` (Week 13) is replaced by signed,
  encrypted, single-use requests.
- All role and ownership checks happen on the server (`role:` middleware and controller checks).
  Requesters get 404 for other people's requests, so IDs can't be probed.
- Error responses use one safe format with a correlation ID; stack traces never reach the client. `APP_DEBUG=false`.
- Login is limited to 5 attempts per minute; tokens expire after 8 hours (`TOKEN_EXPIRED`) and are pruned daily.
- Logs record IDs, statuses and reason codes, not names, ID numbers, passwords, tokens or keys.
- Limitation: the Flutter app keeps its token in `sessionStorage` (cleared when the tab closes);
  a script injected into the page could read it. See residual risks in `docs/week14/06-security-test-record.md`.

## Known limitations

- The generator is a mock; its timing does not represent a real PDF service.
- Database queue driver and a single worker: fine for one office, not load-tested (Week 16).
- Notifications are in-app only.
- Certificate PDFs are not yet encrypted on disk (backend and generator) and carry no digital signature.
- Local HTTPS uses a self-made development CA; production needs a public certificate.

## AI assistance

Parts of this project were drafted with help from Claude (Anthropic), an AI assistant, and then
reviewed, run and tested by the student, as required by the course policy on AI use.
