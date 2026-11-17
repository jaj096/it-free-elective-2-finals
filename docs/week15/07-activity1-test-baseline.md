# 7. Activity 1 – Defensible test baseline (decision, matrix, interpretation)

**Scenario:** a multi-client university API passes a basic demonstration but has not been tested for malformed requests,
authorization boundaries, duplicate submissions, dependency timeouts or information leakage.

**Task:** turn a short set of integration requirements into a traceability matrix with positive, negative, boundary,
authorization and recovery tests and unambiguous expected results.

| Output | Where |
| --- | --- |
| Decision record (how to test) | [01-test-strategy.md §1.7](01-test-strategy.md) – Postman/Newman vs bash/curl vs PHPUnit only |
| Diagram | [01-test-strategy.md §1.2](01-test-strategy.md) – sequence diagram with trust boundary, failure paths and approval points |
| Matrix | [02-traceability-matrix.md](02-traceability-matrix.md) – 17 requirements → 37 rows of tests, before/after |
| Source/config changes | `tests/postman/*`, `scripts/run-week15.sh`, `backend/tests/Feature/HardeningTest.php` |
| Repeatable evidence | `bash scripts/run-week15.sh before|after` → `evidence/week15/<label>/` |
| Adverse cases | 401/403/404/405/409/422/400, generator 503, generator timeout, parallel duplicates |

**Assumptions:** fictional seed data; generator failure modes are a stand-in for a real outage; one queue worker;
SQLite. **Constraints:** no production endpoints or real records; tests must run on a student laptop in under 10 minutes.
**Role authorized to accept:** the instructor as service owner (in a real office: Registrar + IT security).

**Coverage by type:**

| Type | Matrix rows (a row can have two types) | Examples |
| --- | --- | --- |
| Positive | 6 | submit, approve, generate, verify/release, download hash, acknowledge |
| Negative | 11 | missing/unknown fields, malformed JSON, bad key, no/bad token, wrong password, unknown route/method, repeated actions |
| Boundary | 4 | purpose 4 / 5 / 255 / 256 chars; `per_page` 0, 2, 1000 |
| Authorization | 6 | 6 role (403) cases, 3 ownership (404) cases, requester sees no staff fields |
| Recovery/reliability | 7 | 503 → review, timeout → retry, re-queue → exactly once, sequential + parallel duplicates |
| Security hardening | 5 | headers, timing, dependencies, pagination, secret scan |

## One-page interpretation

**Observed facts.**
- The baseline (before any fix) passed every positive, authorization, duplicate and dependency-failure test: 0 failures in
  folders 01, 04, 05, 06 and 08.
- It failed 14 assertions in folders 02, 03 and 07: malformed JSON (422 instead of 400), login timing (unknown email
  10 ms vs 227 ms), missing hardening headers, the `X-Powered-By` version header, and no pagination.
- Two of my predictions were wrong. The timeout case recovered on attempt 2 instead of ending in review, because the
  generator kept working and replayed its stored PDF. The recovery test used the wrong audit action name.
- 60 parallel duplicate submits (5 keys × 12) created exactly 5 rows, one per key, and no 500s.
- `composer audit` listed 7 advisories.

**Reasoned conclusions.**
- The workflow's **authorization and reliability** behavior is defensible: every role and ownership boundary held, and
  every injected failure ended in a defined, visible, bounded state without duplicates.
- The weak spots were where a demo never looks: **error semantics** (malformed JSON), **side channels** (timing),
  **response metadata** (headers), **completeness** (a silent row limit) and **supply chain** (dependencies). Only
  reading code and running adverse tests revealed them.
- Wrong predictions are themselves results: T1 showed the system is safer than I assumed, so I changed the test, not the
  code. The original failing run is kept.
- "Not reproduced" (parallel race on SQLite) is reported as such, not as "passed".

**Recommendations.**
1. Keep the collection as the acceptance baseline. Any API change must keep folders 00–10 green.
2. Run it in CI with `composer audit` (dependency advisories appeared within days of Week 14).
3. Repeat the parallel-duplicate and timing tests under Week 16 load on a server database.
4. Limitation: one queue worker, SQLite and a mock generator. Timing results depend on the machine. This is a prototype,
   not a production-ready service.
