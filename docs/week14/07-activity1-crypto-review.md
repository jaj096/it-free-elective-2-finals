# 7. Activity 1 – Review of five proposed uses of hashing and encryption

**Task:** review five proposals for protecting data in the Certificate Generation Queue, say whether each is correct,
fix the bad ones, and state the assumptions.

## Assumptions

- A1. Data is fictional now, but the design must hold for real student data (Data Privacy Act).
- A2. Attackers we consider: someone who gets a **copy of the database or a backup**, someone **on the network**
  between services, and someone who **replays or edits** a captured request. A full root compromise of the server
  is out of scope (it would expose `.env` keys anyway; see R5).
- A3. Backend and generator are run by the same organisation (so a shared symmetric key is acceptable).
- A4. ID numbers follow `YYYY-NNNNN`, so there are about 100,000 possible values per year.
- A5. Sanctum tokens are 40 random characters from a CSPRNG (`Str::random` → `random_bytes`).
- A6. Only established libraries are allowed: PHP sodium, OpenSSL, Laravel's encrypter and hasher.

## Review

### Proposal 1: "Store requester ID numbers as SHA-256 hashes so they're protected."
**Verdict: ❌ wrong.**
- A hash is one-way, but the certificate must **print** the ID number. A hash can't be turned back into it.
- With only ~100,000 possible IDs (A4), an attacker hashes all of them in well under a second and reverses every row.
  Adding a salt doesn't help much at this tiny input space.

**Correction (implemented):** reversible, authenticated encryption at rest with the Laravel `encrypted` cast
(AES-256-CBC + HMAC-SHA256, random IV, `APP_KEY`). See `User::casts()` and migration `2026_11_04_000100`. If we
ever need to *search* by ID, add a separate column holding `HMAC-SHA256(id, separate_key)` (a "blind index"), never a
plain hash.

### Proposal 2: "Hash passwords with SHA-256 plus a salt, because SHA-256 is secure."
**Verdict: ❌ wrong.**
- SHA-256 is secure as a *fingerprint*, but it's **fast**. One GPU computes billions per second, so a leaked table of
  salted SHA-256 passwords still loses most real passwords to dictionary attacks.

**Correction (already in place, confirmed):** bcrypt via Laravel's `hashed` cast, cost 12 (`BCRYPT_ROUNDS=12`). Each
hash has its own salt and is deliberately slow; the cost can be raised later. Argon2id is an equally good alternative.
Evidence: `05-db-at-rest.txt` shows `$2y$12$…`; test `test_password_is_a_bcrypt_hash_not_reversible_ciphertext`.

### Proposal 3: "Encrypt the generator request with AES-256-CBC using the existing `X-Service-Key`. If the generator can decrypt it, it must have come from us."
**Verdict: ❌ wrong (three problems).**
1. **CBC without a MAC is malleable.** Flipping bits in one ciphertext block changes the next plaintext block in a
   predictable way, and error messages can become a padding oracle.
2. **Decrypting is not proof of origin.** It proves only that the sender had the key. It doesn't stop a captured
   request from being **replayed**, and the headers, path and time are not protected at all.
3. **Key reuse.** The same secret would act as both the authentication token and the encryption key.

**Correction (implemented):** XChaCha20-Poly1305 AEAD (libsodium) on the personal fields with a fresh random nonce and
associated data binding it to the job. A **separate** HMAC-SHA256 signing key signs method + path + timestamp + nonce
+ key ID + body hash. The receiver enforces a 5-minute window and single-use nonces, and the key ID allows rotation.
Tests T03–T10 in document 6.

### Proposal 4: "Store the SHA-256 of each generated PDF in the `certificates` table to detect tampering."
**Verdict: ⚠ partly correct.**
- ✅ It detects a file that was changed on disk without the database, accidentally or by someone with file access
  only. Verification **and** (since Week 14) download both re-check it (`INTEGRITY_FAILED`), and the Flutter client
  checks the bytes again with `X-Content-SHA256`.
- ❌ It's not **authenticity**. Someone who can write to both the file and the database can replace the PDF and its
  hash together. A plain hash has no secret.

**Improvement:** keep the hash (cheap, useful), and for real use sign released certificates with **Ed25519** (private key
outside the database) so anyone can verify them with the public key, or at least store `HMAC-SHA256(pdf, key)` with the
key outside the database. Recorded as residual risk R4.

### Proposal 5: "Store Sanctum API tokens as unsalted SHA-256 hashes."
**Verdict: ✅ correct.**
- Tokens are long random values (A5), roughly 238 bits of entropy, so guessing is impossible however fast the hash is.
  The bcrypt reasoning (slow down guessing of *human-chosen* secrets) doesn't apply.
- A leaked `personal_access_tokens` table doesn't give usable tokens. A fast hash also keeps every API request quick.

**Keep it**, together with 8-hour expiry (`TOKEN_EXPIRED`), revoke on logout, and daily `sanctum:prune-expired`.

## Summary table

| # | Proposal | Verdict | Final control |
| --- | --- | --- | --- |
| 1 | SHA-256 the ID number | ❌ | Laravel `encrypted` cast; HMAC blind index only if search is needed |
| 2 | Salted SHA-256 passwords | ❌ | bcrypt cost 12 (or Argon2id) |
| 3 | AES-CBC with the service key as "proof" | ❌ | XChaCha20-Poly1305 + separate HMAC-SHA256 signing, timestamp, nonce, key ID |
| 4 | SHA-256 of each PDF | ⚠ | Keep; add Ed25519 signature or keyed HMAC for authenticity |
| 5 | SHA-256 of API tokens | ✅ | Keep; plus expiry and revocation |

**Rule of thumb for the viva:** *hash* when you only need to compare, *password-hash* when the input is something
a human chose, *encrypt (AEAD)* when you must read it back, and *HMAC/sign* when you must prove who sent it.
