# 3. Control-selection rationale, with misuse warnings

## The four tools, in one sentence each

| Tool | Answers the question | Reversible? | Needs a key? | Used in this project for |
| --- | --- | --- | --- | --- |
| **Encryption** (AEAD) | "Can anyone *read* this?" (+ AEAD: "was it changed?") | Yes, with the key | Yes (secret) | ID number at rest; name + ID in the generator request |
| **Hashing** | "Is this the *same* data as before?" | No | No | PDF fingerprint (`certificates.sha256`), body hash in the signature, Sanctum token storage |
| **Password hashing** (bcrypt) | "Is this the right password?" (and slow on purpose) | No | No (uses a salt) | User passwords |
| **HMAC** | "Did someone *with our secret key* send this, unchanged?" | No | Yes (shared secret) | Signing every request to the generator |
| **Digital signature** (e.g. Ed25519) | "Did *the holder of this private key* send this?" (anyone can check with the public key) | No | Private key signs, public key verifies | Not used yet; recommended for released certificates (R4) |

## Why each control was chosen

### 1. TLS (HTTPS) for every hop
*Problem:* passwords, tokens and personal data cross the network.
*Control:* Caddy terminates TLS 1.2/1.3. Laravel's HTTP client **verifies** the generator certificate against our dev CA.
The API and generator refuse plain HTTP with `403 HTTPS_REQUIRED`, and the sender refuses to send to an `http://` URL.
*Why refuse instead of redirect:* by the time a redirect happens, the token has already been sent in clear.

### 2. AEAD encryption of the personal fields (XChaCha20-Poly1305)
*Problem:* TLS protects only the connection. A proxy, a debug tool or a mis-configured load balancer could see plaintext.
*Control:* `requester_name` and `requester_id_number` are encrypted inside the message, so they are protected end to end.
*Why AEAD:* it gives confidentiality **and** integrity in one step. The associated data (job key, type, purpose) ties
the ciphertext to this one job, so it can't be cut out and pasted into another request.

### 3. HMAC-SHA256 request signature + timestamp + nonce
*Problem:* encryption alone doesn't prove *who* sent the request, and a recorded valid request could be sent again.
*Control:* the HMAC covers method, path, timestamp, nonce, key ID and body hash. The receiver checks it with
`hash_equals` (constant time), rejects anything older than **5 minutes**, and remembers each nonce for 10 minutes so
it can't be used twice.
*Why separate keys:* one key, one purpose. If the encryption key leaks, an attacker still can't sign. If the
signing key leaks, they still can't read the ID numbers.

### 4. Encryption at rest for the ID number (Laravel `encrypted` cast)
*Problem:* database files and backups get copied, lost or stolen.
*Control:* AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC) with `APP_KEY`, built into Laravel. The ID must be
**readable** later (it's printed), so it's encrypted, not hashed.

### 5. bcrypt for passwords
*Problem:* if the users table leaks, attackers try billions of password guesses offline.
*Control:* bcrypt with a random salt per password and cost 12 (≈ 2¹² rounds), so each guess is deliberately slow.

### 6. SHA-256 for file integrity, token storage and the body hash
These hash **high-entropy or non-secret data**, so a fast hash is the right choice (see the warnings below).

### 7. Data minimisation and masking
Only the five printed fields are sent to the generator. Staff screens show `2026-***01`. `/auth/me` no longer returns the
ID number. The queue payload holds only the request ID. Data we don't send or store can't leak.

## Misuse warnings (common mistakes and why they're wrong)

| ⚠ Don't | Why it's wrong | Do instead |
| --- | --- | --- |
| Hash passwords with SHA-256 (even with a salt) | SHA-256 is built to be **fast**: a GPU tries billions per second, so common passwords fall in minutes | bcrypt (cost ≥ 12) or Argon2id: slow, salted, tunable |
| Hash the ID number to "protect" it | A hash can't be reversed, so it can't be printed. And there are only ~100,000 IDs per year, so every hash can be brute-forced in milliseconds | Encrypt it; for look-ups use an HMAC "blind index" with a separate key |
| Use plain encryption (AES-CBC/CTR without a MAC) | An attacker can flip bits in ciphertext and change the plaintext; padding-oracle attacks | AEAD (XChaCha20-Poly1305, AES-GCM) or Laravel's encrypt-then-MAC |
| Think encryption proves who sent something | Anyone with the shared key can encrypt | Sign with HMAC (shared key) or a digital signature |
| Reuse a nonce with the same key | With ChaCha20/AES-GCM this reveals plaintext relationships and can expose the authentication key | Fresh random 24-byte nonce every message (XChaCha makes random nonces safe) |
| Compare MACs with `==` | Timing differences can leak how many bytes matched | `hash_equals()` |
| Use one key for encryption and signing | A weakness or leak in one use breaks both | Separate keys; the code refuses identical keys |
| Base64 "to hide" data | Base64 is encoding, not encryption. Anyone can decode it | Encrypt |
| Turn off TLS verification (`verify=false`, `curl -k`) "to make it work" | Accepts **any** certificate, including an attacker's | Trust a specific CA (`GENERATOR_CA_BUNDLE`) |
| Write your own crypto (XOR, custom ciphers, home-made MAC formats) | Subtle mistakes are invisible until exploited | libsodium, OpenSSL, Laravel encrypter, bcrypt |
| Put keys in code, Git, logs or the Flutter app | Anyone with the repo or the app has the key | `.env` (git-ignored), secret scans, keys only on the server |
| Sign only the body | An attacker could replay it to another path, or with an old timestamp | Sign method + path + timestamp + nonce + key ID + body hash |
