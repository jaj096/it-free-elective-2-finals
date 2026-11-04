# 5. Key lifecycle plan

## Inventory

| Key | Purpose | Where it lives | Who needs it | Rotate every | If lost |
| --- | --- | --- | --- | --- | --- |
| `GENERATOR_ENCRYPTION_KEY` (+ `_<kid>` on receiver) | XChaCha20-Poly1305 on name + ID in transit | `backend/.env`, `generator-service/.env` | Worker, generator | 90 days, on staff change, or on suspicion | Generation stops until a new key is set (old messages are not stored, so nothing to recover) |
| `GENERATOR_SIGNING_KEY` (+ `_<kid>`) | HMAC-SHA256 request signatures | same | Worker, generator | together with the encryption key (same key ID) | same |
| `APP_KEY` | Laravel encryption of `users.id_number` (and cookies/sessions) | `backend/.env` | API, worker | yearly or on suspicion | **Encrypted ID numbers are unreadable**, so an offline backup is required |
| TLS key `certs/localhost.key` | HTTPS for Caddy + Flutter dev server | `certs/` (git-ignored, `chmod 600`) | Caddy | 90 days (the certificate expires) | Re-issue with `scripts/dev-certs.sh` |
| Dev CA key `certs/dev-ca.key` | Signs local TLS certificates | `certs/` | Only `dev-certs.sh` | 2 years; delete after the course | Create a new CA and re-trust it |
| Sanctum tokens | User sessions | DB (SHA-256 hash) | Users | Expire after 8 h; pruned daily | User signs in again |
| Passwords | Login | DB (bcrypt) | Users | User's choice | Password reset (not built in the prototype) |

## 1. Generate

- **Exchange keys:** `php artisan generator:keys --kid=k202611`. This prints 32 random bytes per key from
  `random_bytes()` (the OS CSPRNG), base64-encoded, plus the blocks to paste into both `.env` files.
  It writes nothing to disk and never generates the same value for both keys.
- **APP_KEY:** `php artisan key:generate`.
- **TLS:** `bash scripts/dev-certs.sh` (RSA-3072 CA, RSA-2048 server key, SAN `localhost`, `127.0.0.1`, `::1`).
- Key IDs follow `k<YYYYMM>` so anyone can see how old a key is.

## 2. Store

- Only in `.env` files, which are git-ignored. `.env.example` holds visibly fake values (`FAKE-…-DO-NOT-USE`).
- Unix permissions: `chmod 600 backend/.env generator-service/.env certs/*.key`.
- Never in code, Git, logs, screenshots, chat or the Flutter app. `GeneratorKeyring::__debugInfo()` hides keys from
  `dd()`/`print_r`, error messages never include key material (tested), and the evidence script scans logs, tracked
  files and all Git history for the real values.
- Production: environment variables from a secrets manager (Vault, AWS/GCP Secret Manager), not files on disk.

## 3. Distribute

- Copy the receiver block to the generator host over a secure channel (in person, SSH, or a password manager share).
  Never by email or chat.
- The Flutter client never receives any key. It only ever holds the user's own token.

## 4. Use

- One key, one purpose (encrypt **or** sign). The code refuses identical keys.
- Every request carries `X-Key-Id`, so logs show which key was used without revealing it.

## 5. Rotate (no downtime)

**Exchange keys** (the order matters):

```bash
php artisan generator:keys --kid=k202702                 # 1. new pair
# generator-service/.env: GENERATOR_ACCEPTED_KEY_IDS=k202611,k202702  + the two new _k202702 lines
#                                                        # 2. receiver accepts old AND new
# backend/.env: GENERATOR_KEY_ID=k202702 + new keys        # 3. switch the sender
php artisan queue:restart && php artisan queue:work      #    workers load the new config
php artisan generator:security-test --case=valid         # 4. check
# generator-service/.env: GENERATOR_ACCEPTED_KEY_IDS=k202702  and delete the k202611 lines
#                                                        # 5. old key retired
```

This exact sequence is covered by `SecureExchangeTest::test_key_rotation_old_key_works_while_listed_and_fails_after_removal`.

**APP_KEY:**

```bash
# 1. backend/.env: APP_PREVIOUS_KEYS=<old APP_KEY>   then   php artisan key:generate
# 2. Laravel now encrypts with the new key and still decrypts old rows with the previous one.
php artisan security:reencrypt-id-numbers     # 3. rewrite every ID number with the new key
# 4. remove APP_PREVIOUS_KEYS; all users must sign in again if sessions were encrypted.
```

Covered by `AtRestEncryptionTest::test_app_key_rotation_reencrypts_with_the_new_key` (including a check that the new
key alone cannot read old rows before re-encryption).

**TLS:** re-run `scripts/dev-certs.sh` before day 90 and restart Caddy.

## 6. Revoke

| What | How | Effect |
| --- | --- | --- |
| Exchange key | Remove its ID from `GENERATOR_ACCEPTED_KEY_IDS` | Immediately `401 UNKNOWN_KEY_ID` (no restart; the generator re-reads `.env` on every request) |
| One user's session | Logout, or delete their rows in `personal_access_tokens` | Next call gets `401` |
| All sessions | `DELETE FROM personal_access_tokens;` | Everyone signs in again |
| Dev CA | Delete it from Keychain / Windows cert store; delete `certs/` | Browser stops trusting our certificates |

## 7. Compromise response (suspected key leak)

1. **Detect.** Signals: a spike of `security.rejected` with `BAD_SIGNATURE`/`REPLAY_DETECTED` in `generator.log`, a key
   value found by the secret scan, a lost laptop, an `.env` pasted somewhere public.
2. **Contain (minutes).** Revoke first: remove the key ID from the receiver (exchange key), or delete tokens. Stop the
   queue worker if forged requests are suspected.
3. **Replace.** Generate and roll out a new key pair (steps above, but skip the overlap: the old ID is removed at once).
   For `APP_KEY`, rotate and run `security:reencrypt-id-numbers`.
4. **Investigate.** Use correlation IDs to follow the trail: `automation.log` → `audit_logs` → `generator.log`. Which
   certificates were produced while the key was exposed? Compare `certificates.sha256` with the files on disk.
5. **Recover.** Regenerate any suspicious certificates through the review queue; staff verify before release.
6. **Notify.** Tell the instructor or the data protection officer. With real personal data, the Philippine Data
   Privacy Act and NPC breach-notification rules (notify within 72 hours when sensitive personal information is
   involved) would apply.
7. **Learn.** Record the incident and the fix; add a test or scan rule that would have caught it.

## 8. Destroy

After rotation and re-encryption, delete old keys from `.env` and from any backups. After the course, delete
`certs/`, remove the dev CA from the trust store, and delete local `.env.backup` files.
