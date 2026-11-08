# Evidence index – Week 13 (SA7)

One line per file: what it proves. Run `bash scripts/run-evidence.sh` to fill folders 01–07, then add your screenshots.

| Folder / file | What it proves | Expected | Actual |
| --- | --- | --- | --- |
| `00-lab-log.md` | Date, environment, versions, approved scope | — |  See lab log |
| `01-normal-success/` | Approved request → one certificate on attempt 1 | `generated`, attempts 1 |  Matched: `generated`, attempts 1, ULS-2026-000001 |
| `02-transient-recovered/` | Two 503s, then success on attempt 3 | `generated`, attempts 3, 2 retries in log |  Matched: 503, 503, then `generated` on attempt 3 (waits 15 s, 33 s) |
| `03-permanent-to-review/` | 422 from generator → human review, no retry | `needs_review`, attempts 1 |  Matched: 422, `needs_review` after 1 attempt, no certificate |
| `04-retries-exhausted/` | 503 every time → stops at 3 attempts | `needs_review`, attempts 3, no 4th call |  Matched: 3 × 503, `needs_review`, no 4th attempt |
| `05-duplicate-dispatch/` | 3 duplicate jobs → still one certificate | 3 × `duplicate_skipped` |  Matched: 3 × `duplicate_skipped`, still 1 certificate |
| `06-recovery/` | Missing ID number fixed by staff, re-queued | `needs_review` → `requeued` → `generated` |  Matched: `needs_review` → `requeued` → `generated` (ULS-2026-000005) |
| `07-api-adverse/` | 401, 403, 404, 409, 422 responses | all match |  Matched: 401, 422, 403, 409, 404, 403 (6 of 6) |
| `08-flutter-responsive/` | Screenshots at desktop and ~375 px width | readable, usable |  2 screenshots added |
| `secret-scan.txt` | No passwords, tokens or keys in the automation log | no matches |  Matched: no secrets found |

Write the **expected** column before each run, and record failures as they happened. Do not delete them.
