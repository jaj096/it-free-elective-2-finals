# Week 14 – Secure Data Exchange and Encryption (SA8 + IA5)

| # | Document | One-line purpose |
| --- | --- | --- |
| — | [https-setup.md](https-setup.md) | How to run everything over HTTPS locally (Caddy + dev CA) |
| 1 | [01-data-flow.md](01-data-flow.md) | Mermaid diagram: trust boundaries; sensitive data at rest, in transit, in logs |
| 2 | [02-protection-requirements.md](02-protection-requirements.md) | C / I / A / availability / accountability per data item |
| 3 | [03-control-selection.md](03-control-selection.md) | Encryption vs hashing vs HMAC vs signatures; misuse warnings |
| 4 | [04-algorithm-decision-record.md](04-algorithm-decision-record.md) | What we chose, what we rejected, why |
| 5 | [05-key-lifecycle.md](05-key-lifecycle.md) | Generate, store, rotate, revoke, compromise response |
| 6 | [06-security-test-record.md](06-security-test-record.md) | 35 tests expected vs actual; residual-risk register |
| 7 | [07-activity1-crypto-review.md](07-activity1-crypto-review.md) | Five proposed hashing/encryption uses reviewed and corrected |
| 8 | [08-activity2-secure-exchange-review.md](08-activity2-secure-exchange-review.md) | Source, config, transport, tamper, secret scan; one-page interpretation |

Evidence: [`evidence/week14/`](../../evidence/week14/index.md) · Run it: `bash scripts/run-evidence.sh week14`

## What was built (in plain words)

1. **The worker sends less.** Only the five things printed on the certificate leave the backend.
2. **The name and ID number are locked.** XChaCha20-Poly1305 from libsodium encrypts them, and the lock also detects
   any change. Associated data glues the ciphertext to its job, so it can't be reused elsewhere.
3. **Every request is signed.** HMAC-SHA256 over method, path, timestamp, nonce, key ID and body hash. The generator
   rejects a bad signature (401), anything older than 5 minutes (401), a repeated nonce (409), an unknown key ID (401),
   altered ciphertext (400) and plain HTTP (403).
4. **Keys come only from `.env`**: one for encrypting, one for signing, named by a key ID so they can be rotated.
5. **HTTPS everywhere**, through Caddy with our own dev CA. Laravel verifies the certificate; plain HTTP gets 403.
6. **ID numbers are encrypted in the database**, staff see `2026-***01`, and passwords stay bcrypt.
7. **Expired tokens say so** (`TOKEN_EXPIRED`), and Flutter shows safe messages for invalid, altered, expired and
   unauthorized responses.

## Viva cheat sheet: likely questions

**Why encrypt inside HTTPS? Isn't TLS enough?**
TLS protects the *connection*, and it ends wherever TLS is terminated (Caddy, a load balancer, a debugging proxy). Message
encryption protects the *data itself* from sender to receiver. It's defence in depth.

**What's the difference between encryption, hashing and HMAC?**
Encryption can be undone with the key (to read data back). Hashing can't be undone (to compare or fingerprint).
HMAC is a hash mixed with a secret key (to prove a key-holder sent it, unchanged).

**Why bcrypt and not SHA-256 for passwords?**
SHA-256 is fast, so attackers can guess billions of passwords per second. bcrypt is slow on purpose, salted, and tunable
(cost 12).

**If the signature already protects the body, why does altered ciphertext give 400 and not 401?**
Normally it *would* give 401, because the signature breaks first. The test simulates an attacker who has the
*signing* key but not the *encryption* key: they alter the ciphertext and re-sign it. The AEAD tag still catches it.
That's why the two keys are separate.

**How does replay protection work?**
Each request has a random nonce and a timestamp, both inside the signature. The generator rejects timestamps more than
5 minutes away, and remembers every nonce it accepted for 10 minutes. A copy within 5 minutes hits the nonce check;
an older copy hits the time check.

**Won't the queue's retries be rejected as replays?**
No. Each attempt builds a fresh request with a new nonce and timestamp. Only an exact copy is a replay.

**Why does the nonce get saved only after the signature check?**
Otherwise an attacker could send junk with guessed nonces and "use up" nonces, making real requests fail.

**How do you rotate a key without downtime?**
Add the new key ID to the receiver, switch the sender, then remove the old ID. The key ID header tells the receiver
which key to use. This is tested.

**What happens if a key leaks?**
Remove its ID from `GENERATOR_ACCEPTED_KEY_IDS`. It's rejected at once, with no restart. Then roll out a new pair,
and follow the trail with correlation IDs (document 5, section 7).

**Why XChaCha20-Poly1305 instead of AES-GCM?**
Its 24-byte nonce is safe to pick at random. It runs constant-time without special hardware, and libsodium makes
it hard to misuse. AES-GCM is also good, but its 12-byte nonce makes random-nonce collisions a real worry.

**Why is `hash_equals` used?**
A normal `==` can stop at the first differing byte, and that timing difference can leak the correct signature byte by
byte.

**What is still not protected?**
PDF files on disk, keys sharing a host with the database, and no digital signature on certificates
(residual risks R2–R5).
