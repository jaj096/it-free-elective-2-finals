# 4. Algorithm decision record (ADR-14)

**Status:** accepted · **Date:** 2026-11-04 · **Decider:** Jewel Julian · **Scope:** Week 14, Project 21

## Context

The queue worker sends the requester's name and ID number to a separate generator service. We need confidentiality
(nobody reads the personal fields), integrity (nobody changes anything unnoticed), authenticity (only our backend can
order a certificate), freshness (no replays) and key rotation. Both ends are PHP 8.5 with libsodium and OpenSSL built
in. The course rules require an established library and no home-made crypto.

## Decisions

| # | Need | Chosen | Alternatives rejected | Why |
| --- | --- | --- | --- | --- |
| D1 | Encrypt name + ID in the request | **XChaCha20-Poly1305 (IETF), libsodium** `sodium_crypto_aead_xchacha20poly1305_ietf_*` | **AES-256-GCM** (OpenSSL or sodium) | GCM's 96-bit nonce makes random nonces risky over many messages, and a repeated nonce is catastrophic. XChaCha's 192-bit nonce is safe to pick at random. libsodium's AES-GCM needs AES-NI hardware (`sodium_crypto_aead_aes256gcm_is_available()` can be false). OpenSSL's GCM API requires handling the tag yourself, which is easy to get wrong. ChaCha20 is constant-time in software. |
|  |  |  | AES-CBC + HMAC built by hand | Composing encrypt-then-MAC by hand (IV, padding, MAC order) is exactly the home-made crypto we must avoid |
|  |  |  | JWE / JOSE library | Adds dependencies to a deliberately dependency-free mock; JOSE has a history of "alg" confusion bugs |
|  |  |  | RSA / public-key encryption | Both ends belong to the same organisation, so a shared symmetric key is simpler and faster. Public-key encryption is for when the sender must not be able to decrypt |
| D2 | Prove the request is from our backend, unchanged, fresh | **HMAC-SHA256** over `CERTQ-HMAC-SHA256 / method / path / timestamp / nonce / key ID / SHA-256(body)`, 5-minute window, single-use nonce | Static shared key header (Week 13 `X-Service-Key`) | Anyone who sees it once can reuse it forever; it doesn't protect the body |
|  |  |  | **Ed25519 digital signature** | Would give non-repudiation, and the generator would only need a public key. Rejected *for now*: both sides are ours, and HMAC is simpler with fewer keys to manage. **Revisit** if the generator is run by another party (see R4) |
|  |  |  | Mutual TLS only | Strong transport authentication, but no protection once TLS ends at a proxy and no per-message replay protection. Good *addition* in production |
| D3 | Separate keys + rotation | Two 256-bit keys per **key ID** (`X-Key-Id`); receiver accepts a list | One key for everything | One key, one purpose; the key ID allows overlap during rotation and instant revocation |
| D4 | ID number at rest | **Laravel `encrypted` cast**: AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC), random IV, `APP_KEY`, rotation via `APP_PREVIOUS_KEYS` | Hash (SHA-256) | The ID must be printed later, so it must be reversible. Only ~100k IDs per year, so a hash is trivially brute-forced |
|  |  |  | Database/disk-level encryption only | Protects a stolen disk, but not a leaked SQL dump or backup file; complementary, not a replacement |
| D5 | Passwords | **bcrypt**, cost 12 (Laravel `hashed` cast) | SHA-256 / MD5 | Too fast for passwords (see document 3) |
|  |  |  | Argon2id | Also excellent (memory-hard). Rejected only to keep the Laravel default that every PHP host supports; a one-line switch in `config/hashing.php` |
| D6 | Transport | **TLS 1.2/1.3** via Caddy, certificate verified against a pinned dev CA | `verify=false` / plain HTTP | No authenticity, so the connection could be intercepted |
| D7 | Request body / file fingerprints | **SHA-256** | MD5 / SHA-1 | Collisions are practical for MD5 and SHA-1 |
| D8 | Nonce/random values | `random_bytes()` (OS CSPRNG) | `rand()`, `mt_rand()`, `uniqid()` | Predictable |

## Consequences

- ✅ A modified ciphertext fails with `DECRYPTION_FAILED`. A modified header or body fails with `BAD_SIGNATURE`. Old
  requests get `REQUEST_EXPIRED`, repeats get `REPLAY_DETECTED`, and revoked keys get `UNKNOWN_KEY_ID`. All are tested
  automatically and live.
- ✅ Personal fields stay protected even if TLS is terminated by something we don't control.
- ⚠ Both sides hold the same signing key, so the generator could forge a request. Acceptable inside one organisation.
- ⚠ Clocks must be roughly in sync (NTP); more than 5 minutes of skew rejects every request.
- ⚠ The nonce store is a local file. It's fine for one generator instance; it needs a shared store (Redis `SET NX EX`)
  for several.

## Where in the code

`backend/app/Security/PayloadEncryptor.php` (D1), `RequestSigner.php` (D2), `GeneratorKeyring.php` (D3),
`backend/app/Models/User.php` casts (D4, D5), `Caddyfile` + `GeneratorClient::http()` (D6),
`generator-service/src/SecureReceiver.php` (receiving side of D1–D3).
