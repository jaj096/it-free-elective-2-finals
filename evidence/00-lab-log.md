# Lab log – Week 13

| Item | Value |
| --- | --- |
| Date and time of runs | October 30, 2026 11:00pm |
| Student | Jewel Julian, BSIT 3A |
| Project | No. 21 – Certificate Generation Queue |
| Branch / commit | main, b1d4f9d |
| OS | MacOS Tahoe Version 26.6.2 |
| PHP | 8.5.9 |
| Laravel | 13.39.0 |
| Flutter | 3.47.8 |
| Database | SQLite (local) / MySQL version: |
| Browser used for Flutter Web | Chrome | Version 157.0.8103.38 |
| Test data | Fictional seed data (`php artisan migrate:fresh --seed`) |
| Generator modes used | success, fail_twice_then_succeed, always_422, always_503 |
| Retry settings | CERT_MAX_ATTEMPTS=3, CERT_RETRY_BACKOFF=10,30, CERT_RETRY_JITTER=5 |

## Notes

- Generator, backend API and queue worker were run in separate terminals; Flutter ran on port 5050 because macOS AirPlay uses port 5000.
- All 7 evidence runs matched the expected results.
