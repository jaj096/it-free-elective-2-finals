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
| `scripts/run-evidence.sh` | Runs the Week 13 evidence scenarios against the live system and saves the results |
| `evidence/` | Evidence index and lab log template (fill this from your own runs) |

```
Flutter Web ──HTTPS(wk14)/JSON──▶ Laravel API ──▶ database queue ──▶ queue worker ──X-Service-Key──▶ Generator Service
   (browser)        Bearer token        │                                │
                                        └── audit_logs + automation.log ◀┘   (correlation ID links both)
```

## Requirements

- PHP 8.3+ (tested design on 8.5) with `pdo_sqlite` (or `pdo_mysql`), `mbstring`, `openssl`, `curl`
- Composer 2
- Flutter 3.x with web support (`flutter config --enable-web`), and Chrome
- Bash for the evidence script (Git Bash works on Windows)

## Setup (first time)

### 1. Generator service

```bash
cd generator-service
cp .env.example .env            # Windows: copy .env.example .env
# Edit .env: set GENERATOR_SERVICE_KEY to a long random string
php -S 127.0.0.1:8081 -t public public/index.php
```

Check: open http://127.0.0.1:8081/health → `{"status":"ok","mode":"success"}`

### 2. Backend API (new terminal)

```bash
cd backend
composer install
cp .env.example .env            # Windows: copy .env.example .env
# Edit .env: GENERATOR_SERVICE_KEY must be the SAME value as in generator-service/.env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate:fresh --seed
php artisan serve --port=8000
```

To use MySQL instead of SQLite, set `DB_CONNECTION=mysql` and the `DB_*` values in `.env`.

### 3. Queue worker (new terminal) – **required**, or nothing gets generated

```bash
cd backend
php artisan queue:work
```

Optional, for the stuck-item sweeper (every 10 minutes): `php artisan schedule:work` in another terminal.

### 4. Flutter Web client (new terminal)

```bash
cd frontend_flutter
flutter pub get
flutter run -d chrome --web-port=5000 --dart-define=API_BASE_URL=http://localhost:8000/api/v1
```

If Flutter complains about missing web files, run `flutter create . --platforms web` once inside
`frontend_flutter` (it only adds missing files) and run again.

Check: `http://localhost:8000/api/v1/health` should show `"database":"ok","generator":"ok"`.

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

**Run everything at once:** with the generator, API and worker running on a freshly seeded database:

```bash
bash scripts/run-evidence.sh
```

It fills `evidence/01-…` to `evidence/07-…` with API responses and the matching log lines.
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

## Tests

```bash
cd backend
php artisan test        # or: vendor/bin/phpunit
```

19 feature tests run the real queue job through a database queue worker with the generator faked:
normal success, transient recovery, permanent failure, retry limit, missing ID number, duplicate jobs,
sweeper, login/rate limit, validation, 401/403/404/409, idempotency key, full lifecycle, tamper detection
and insert-only audit log.

Flutter starter widget tests: `cd frontend_flutter && flutter test`.

## Security notes (Week 13 level)

- Secrets live only in `.env` files, which are git-ignored. Only `.env.example` files with fake values are committed.
- The generator service key is sent server-to-server; it never reaches Flutter.
- All role and ownership checks happen on the server (`role:` middleware and controller checks).
  Requesters get 404 for other people's requests, so IDs can't be probed.
- Error responses use one safe format with a correlation ID; stack traces never reach the client.
  Keep `APP_DEBUG=false` when collecting evidence.
- Login is limited to 5 attempts per minute; tokens expire after 8 hours.
- Logs record IDs and statuses, not names, passwords or tokens.
- Limitation: the Flutter app keeps its token in `sessionStorage` (cleared when the tab closes);
  a script injected into the page could read it. HTTPS and signed service calls come in Week 14.

## Known limitations

- The generator is a mock; its timing does not represent a real PDF service.
- Database queue driver and a single worker: fine for one office, not load-tested (Week 16).
- Notifications are in-app only.
- Plain HTTP until Week 14.

## AI assistance

Parts of this project were drafted with help from Claude (Anthropic), an AI assistant, and then
reviewed, run and tested by the student, as required by the course policy on AI use.
