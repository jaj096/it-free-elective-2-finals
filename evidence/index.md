# Evidence index – Week 13 (SA7)

One line per file: what it proves. Run `bash scripts/run-evidence.sh` to fill folders 01–07, then add your screenshots.

| Folder / file | What it proves | Expected | Actual |
| --- | --- | --- | --- |
| `00-lab-log.md` | Date, environment, versions, approved scope | — | |
| `01-normal-success/` | Approved request → one certificate on attempt 1 | `generated`, attempts 1 | |
| `02-transient-recovered/` | Two 503s, then success on attempt 3 | `generated`, attempts 3, 2 retries in log | |
| `03-permanent-to-review/` | 422 from generator → human review, no retry | `needs_review`, attempts 1 | |
| `04-retries-exhausted/` | 503 every time → stops at 3 attempts | `needs_review`, attempts 3, no 4th call | |
| `05-duplicate-dispatch/` | 3 duplicate jobs → still one certificate | 3 × `duplicate_skipped` | |
| `06-recovery/` | Missing ID number fixed by staff, re-queued | `needs_review` → `requeued` → `generated` | |
| `07-api-adverse/` | 401, 403, 404, 409, 422 responses | all match | |
| `08-flutter-responsive/` | Screenshots at desktop and ~375 px width | readable, usable | |
| `secret-scan.txt` | No passwords, tokens or keys in the automation log | no matches | |

Write the **expected** column before each run, and record failures as they happened. Do not delete them.
