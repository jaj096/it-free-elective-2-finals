# 3. Failure-injection log with recovery findings

Each failure was **predicted before the run** (the prediction is the collection's assertion, written first), then run
against the live system. Times are from the baseline run on 2026-11-17 (commit `254290a`); the after run repeated every
case with the same outcome unless noted. Failure modes are switched by `scripts/run-week15.sh` through
`GENERATOR_MODE` in `generator-service/.env` (re-read on every request, so no restart is needed).

| # | Injected failure | How | Prediction | Actual | Safe? | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| F1 | Dependency unavailable | `GENERATOR_MODE=always_503` | 3 attempts (backoff ~10 s, ~30 s), then `needs_review`; staff see the reason, requester sees "staff have been notified"; listed in review queue | Exactly that: attempts at 23:30:14, :29, :59 → `sent_to_review` "Generator still unavailable after 3 attempts" (45 s total) | ✅ bounded, visible, no data change | `before/03-newman-dependency-503.txt`, `06-automation-log-failReqId-16.jsonl` |
| F2 | Dependency timeout | `GENERATOR_MODE=slow` (15 s, client timeout 10 s) | 3 timeouts → `needs_review` | ❌ **prediction wrong**: attempt 1 timed out, attempt 2 → `generated` (one PDF). The generator finished attempt 1 after the backend gave up and replayed the stored PDF (**T1**) | ✅ (better than predicted: no duplicate, faster recovery) | `before/04-newman-timeout.txt`, `t1-timeout-investigation.txt` |
| F3 | Recovery after F1 | `GENERATOR_MODE=success`, staff `POST /requeue` | 202, attempts reset to 0, `generated` once; history review → requeue → success | 202; `generated` on attempt 1 one second later; history in order (assertion corrected for **T2**) | ✅ exactly once | `before/05-newman-recovery.txt`, `after/05-…` |
| F4 | Duplicate delivery (same key, sequential) | 06 / same key + same body | 200 with the original ID | ✅ | ✅ | `02-newman-main.txt` |
| F5 | Duplicate delivery (same key, **parallel**) | 5 × 12 concurrent POSTs | one 201 + eleven 200, one row per key, **never 500** | ✅ every round, before and after. The race that would give a 500 (**D6**) was **not reproduced** live; it was forced in `HardeningTest` | ✅ | `07-idempotency-race.txt`, `hardening-tests-on-old-code.txt` |
| F6 | Broken client payload | 02 / malformed JSON | 400 "body is not valid JSON" | ❌ before: 422 "Choose a certificate type" (**D3**) · ✅ after | ⚠️ before: safe but misleading | `02-newman-main.txt` |
| F7 | Hostile/malformed credentials | 03 / made-up token, wrong password, unknown email | 401 with the same message; no timing difference | ❌ before: unknown email 10 ms vs 227 ms (**D2**) · ✅ after | ⚠️ before: leaked account existence | `02-newman-main.txt` |
| F8 | Repeated state change | 06 / approve again, release twice, requeue not in review | 409 `INVALID_STATE`, state unchanged | ✅ | ✅ | `02-newman-main.txt` |
| F9 | Week 13/14 regression | Duplicate job dispatch, tampered ciphertext, replayed nonce, expired token (PHPUnit) | All still pass after the dependency update | 71/71 ✅ | ✅ | `after/08-backend-tests.txt` |

## Observations (facts)

1. Every failure ended in a defined state (`generated`, `needs_review`, or a 4xx with a reason code). No request was left
   in `generating`/`retrying`, and no second PDF was created in any case (one `generation_succeeded` per request).
2. Each failure produced an actionable signal: a staff-visible `review_reason`, an audit row, JSON log lines with the
   correlation ID (`06-automation-log-*.jsonl`: `generation.attempt_failed` ×3, `request.sent_to_review`, `notification.sent`),
   and the review-queue entry. Requesters only ever saw "Needs review: staff have been notified".
3. No error response contained a stack trace, file path or exception name (collection-level assertion on every 4xx/5xx, 0 failures).
4. Processing was bounded: max 3 attempts; total time to `needs_review` was about 45 s with the configured backoff.

## Interpretation (reasoned conclusions)

- The retry + idempotency design from Week 13 holds under live dependency failure, timeout and duplicate delivery.
  The timeout case (F2) showed that two separate idempotency layers work together: the backend's `generation_key`
  and the generator's stored PDF per `job_key`.
- The two real defects found here (D2, D3) were at the **API boundary**, not in the queue. That is where Week 15's
  hardening effort went.
- "Not reproduced" for F5 doesn't prove the race can't happen: SQLite serializes writes. The fix is justified by code
  review and the forced test; Week 16 load testing on a server database should re-check it.

## Recovery instructions (for an operator)

1. `GET /api/v1/health`: `checks.generator` should be `ok` again.
2. Open the review queue (Flutter "Review" tab, or `GET /review-queue`). Read `review_reason`; for permanent errors fix the
   data first (e.g. add a missing ID number in the re-queue form).
3. Re-queue (`POST /requests/{id}/requeue`). The `generation_key` is kept, so the generator can't make a second PDF.
4. Confirm `generated` and one `generation_succeeded` in the history; use the correlation ID to follow the logs.
