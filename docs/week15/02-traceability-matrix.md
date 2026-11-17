# 2. Requirements-to-test traceability matrix (Activity 1)

Each requirement is linked to its control, the tests that prove it (live collection folder and/or PHPUnit), the test
type, the expected result, and the evidence. "Before" is the baseline run on commit `254290a`; "After" is the
regression run after the hardening sprint (commit `883df35`). Collection folders are in `tests/postman/cert-queue.postman_collection.json`.

**Test types:** P = positive · N = negative · B = boundary · Z = authorization · R = recovery/reliability · S = security hardening

## 2.1 Requirements

| ID | Requirement | Control (where) |
| --- | --- | --- |
| R1 | A submitted request is stored once, as `submitted` | `CertificateRequestController@store`, DB transaction + audit row |
| R2 | Approval queues exactly one generation job | `approve()` → `dispatch()->afterCommit()`, `GenerateCertificate` idempotency |
| R3 | The worker produces one PDF whose hash is recorded and re-checked at verify and download | `GenerateCertificate`, `CertificateController@verify/download`, `X-Content-SHA256` |
| R4 | Only released certificates reach the requester; acknowledgement closes the flow | `download()` status check, `RequestStatus` transitions |
| R5 | Server-side validation is authoritative; invalid input is refused safely | Laravel validation, `RejectMalformedJson` (Week 15) |
| R6 | Missing, invalid or expired credentials → 401; accounts can't be discovered | Sanctum, `INVALID_CREDENTIALS`, constant-work login (Week 15) |
| R7 | Each action is limited to its role (function-level authorization) | `role:` middleware |
| R8 | Users can't read or act on other people's records (object-level authorization) | Ownership checks returning 404 |
| R9 | Duplicate submits and repeated actions never create duplicates or skip states | `Idempotency-Key` + UNIQUE index (+ race handling, Week 15), state machine → 409 |
| R10 | A failing dependency ends in human review after a bounded number of attempts; requesters see a safe message | Retry policy (3 attempts), `needs_review`, `statusMessage()` |
| R11 | A timeout is retried, bounded, and never produces a second PDF | 10 s client timeout, generator `job_key` idempotency |
| R12 | After the cause is fixed, staff can re-queue and the item completes exactly once | `ReviewQueueController@requeue` |
| R13 | Every response is traceable and errors never leak internals | `AssignCorrelationId`, `ApiError`, exception renderer |
| R14 | Responses can't be cached, framed or used to fingerprint the server | `SecurityHeaders` middleware (Week 15) |
| R15 | No dependency with a known vulnerability | `composer.lock` (updated Week 15), `composer audit` |
| R16 | List endpoints never silently drop records | Pagination with `meta.total` (Week 15); Flutter follows all pages |
| R17 | Evidence and repository contain no secrets or real personal data | `.gitignore`, temp env file, step 15.9 scan |

## 2.2 Matrix

| Req | Test (collection folder / request, or PHPUnit) | Type | Expected | Before | After |
| --- | --- | --- | --- | --- | --- |
| R1 | 01 / Requester submits (with Idempotency-Key) | P | 201, `submitted` | ✅ | ✅ |
| R2 | 01 / Approver approves | P | 202, `queued` | ✅ | ✅ |
| R3 | 01 / Poll until generated | P | `generated`, attempt 1 | ✅ | ✅ |
| R3 | 01 / Custodian verifies | P | 200 `verified` | ✅ | ✅ |
| R3 | 01 / Requester downloads PDF | P | 200 PDF; `X-Content-SHA256` = recorded hash | ✅ | ✅ |
| R4 | 01 / Custodian releases; Requester acknowledges | P | 200 `released`; 200 `acknowledged` | ✅ | ✅ |
| R4 | 01 / Requester lists own requests | P/Z | contains own request; no staff-only fields | ✅ | ✅ |
| R5 | 02 / Missing certificate_type; Unknown certificate_type | N | 422 `VALIDATION_FAILED`, names field | ✅ | ✅ |
| R5 | 02 / Purpose 4 chars | B | 422 | ✅ | ✅ |
| R5 | 02 / Purpose exactly 5 chars; exactly 255 chars | B | 201 (accepted at the boundary) | ✅ | ✅ |
| R5 | 02 / Purpose 256 chars | B | 422 | ✅ | ✅ |
| R5 | 02 / **Malformed JSON body** · `HardeningTest::test_w3_*` | N | 400 `MALFORMED_JSON`, no `fields` | ❌ 422 "Choose a certificate type" (**D3**) | ✅ |
| R5 | 02 / Invalid Idempotency-Key; Unknown status filter; Reject without reason; Wrong certificate number | N | 400; 422; 422; 422 | ✅ | ✅ |
| R6 | 03 / No token; Made-up token | N | 401 `UNAUTHENTICATED` | ✅ | ✅ |
| R6 | 03 / Wrong password ×2; Unknown email ×2 | N | 401 `INVALID_CREDENTIALS`, same message | ✅ | ✅ |
| R6 | 03 / **[H] Timing check** · `HardeningTest::test_w2_*` | S | unknown email ≥ 50 % of wrong-password time | ❌ 10 ms vs 227 ms (**D2**) | ✅ 226 vs 227 ms |
| R6 | `ApiSecurityTest` (Week 14): expired token | N | 401 `TOKEN_EXPIRED` | ✅ | ✅ |
| R7 | 04 / Requester approves; opens review queue; opens metrics | Z | 403 `FORBIDDEN` | ✅ | ✅ |
| R7 | 04 / Approver releases; Custodian submits; Approver acknowledges | Z | 403 `FORBIDDEN` | ✅ | ✅ |
| R8 | 05 / Another requester's request; download; acknowledge | Z | 404 `NOT_FOUND` (not 403) | ✅ | ✅ |
| R8 | 05 / Request that does not exist; Unknown route; Wrong method | N | 404; 404; 405 | ✅ | ✅ |
| R9 | 06 / Same key, same body | N | 200, **same** request ID | ✅ | ✅ |
| R9 | 06 / Same key, different body | N | 409 `DUPLICATE_REQUEST` | ✅ | ✅ |
| R9 | 06 / Approve again; Release twice; Requeue not in review | N | 409 `INVALID_STATE` | ✅ | ✅ |
| R9 | Step 15.7: 5 rounds × 12 parallel POSTs, one key each | R | 1×201 + 11×200 per round; 1 row per key | ✅ (not reproduced live) | ✅ |
| R9 | `HardeningTest::test_w6_*` (forced race) | R | 200 original / 409; never 500 | ❌ 500 (**D6**, on old code) | ✅ |
| R10 | 08 / Poll until needs_review (mode `always_503`) | R | `needs_review`, attempts = 3 | ✅ | ✅ |
| R10 | 08 / Requester sees a safe message only; Item in review queue | Z/R | no `review_reason`; safe text; listed | ✅ | ✅ |
| R11 | 09 / Poll until generated (mode `slow`) | R | retried; `generated` on attempt 2–3; one certificate | ❌ test expected `needs_review` (**T1**, test defect) | ✅ attempt 2 |
| R11 | 09 / History shows retry after timeout | R | `generation_retry_scheduled` "did not respond in time"; 1 × `generation_succeeded` | — (added after T1) | ✅ |
| R12 | 10 / Re-queue; Poll until generated; History | R | 202 attempts reset; `generated`; review → requeue → success order | ❌ wrong action name in test (**T2**) | ✅ |
| R13 | Collection-level test on **every** response | S | `X-Correlation-ID` present; errors: code, message, matching correlation ID, no stack trace | ✅ | ✅ |
| R14 | 07 / [H] Security headers (success + error) · 01 / download · `HardeningTest::test_w4_*` | S | `no-store`, CSP, `X-Frame-Options`, `Referrer-Policy`, no `X-Powered-By` | ❌ (**D4**) | ✅ |
| R15 | Step 15.1 `composer audit` | S | 0 advisories | ❌ 7 advisories, 4 packages (**D1**) | ✅ 0 |
| R16 | 07 / [H] Paginated list; Oversized page size · `HardeningTest::test_w5_*` · Flutter `pagination_test.dart` | S/B | `meta.total`, `last_page`; ≤ per_page items; 422 for 1000 | ❌ 15 items, no meta; 200 (**D5**) | ✅ |
| R17 | Step 15.9 evidence secret scan | S | no tokens, keys or passwords | ✅ | ✅ |

**Counts (live collection):** 66 requests in 11 folders; 231 assertions in the after run (main 160, dependency 37,
timeout 24, recovery 10). Baseline: 331 assertions, **17 failed** (14 hardening checks that were expected to fail,
2 + 1 from test defects T1/T2). After: **0 failed**. Assertion totals change between runs because the poll requests
repeat until the status changes and each repeat runs the collection-level checks; the timeout folder dropped from
126 to 24 assertions because the corrected test stops polling once the item is `generated` (13 polls instead of 120).

## 2.3 Ownership and acceptance

- **Owner of the tests and fixes:** the student developer (Jewel Julian).
- **Authorized to accept the result:** the course instructor, acting as the service owner for this prototype. In a
  real office this would be the Registrar (workflow) and the IT security officer (hardening).
- **Assumptions:** fictional data; one office; one queue worker; SQLite; local HTTPS with a development CA.
