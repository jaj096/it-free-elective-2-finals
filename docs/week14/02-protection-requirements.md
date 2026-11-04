# 2. Protection-requirements matrix

How much each data item needs each security property, and the control that provides it.
**H** = high, **M** = medium, **L** = low.

| Data item | Confidentiality | Integrity | Authenticity | Availability | Accountability |
| --- | --- | --- | --- | --- | --- |
| **Requester ID number** | **H**. Encrypted at rest (`encrypted` cast); TLS + XChaCha20-Poly1305 to the generator; masked in staff API; never logged | **H**. AEAD tag (in transit), MAC in Laravel ciphertext (at rest); regex validation on edit | **H**. Only staff (role `approver`) can change it; request to generator is HMAC-signed | M. Needed to generate; a missing ID sends the job to human review instead of failing silently | **H**. Every change is written to the insert-only `audit_logs` with actor and correlation ID |
| **Requester name** | **M–H**. TLS everywhere; AEAD to the generator; not logged | **H**. AEAD tag; associated data binds the ciphertext to its job | **H**. HMAC signature on the request | M | M. Account changes are audited |
| **Purpose / certificate type** | L–M. TLS; sent as AEAD *associated data* (authenticated, not encrypted) | **H**. Changing it breaks both the HMAC and the AEAD tag | **H**. HMAC | M | M. Stored with the request; audited |
| **Password** | **H**. bcrypt (cost 12), never stored or logged in plaintext; TLS for the one login request | **H**. bcrypt check | **H**. Rate limit 5/min per email+IP | M | M. Failed logins logged (IP only) |
| **Bearer token** | **H**. TLS; stored as SHA-256 hash; `sessionStorage` (cleared when the tab closes) | M | **H**. Sanctum check; **expires after 8 h** (`TOKEN_EXPIRED`); revoked on logout; expired tokens pruned daily | M | M. Login and logout logged with user ID |
| **Certificate PDF** | **M–H**. Download only by owner after release, or custodian; ⚠ plaintext on disk (R3) | **H**. SHA-256 recorded at generation; checked before verify **and** before download; client re-checks `X-Content-SHA256` | M. Only the signed request can produce it; no digital signature on the PDF yet (R4) | **H**. Retry + review queue (Week 13) so none are lost; idempotent job key | **H**. Audit: generated, verified, released, downloaded, acknowledged |
| **Exchange encryption key** | **H**. `.env` only, git-ignored, hidden from debug output | **H** | — | **H**. If lost, no certificates can be generated → keep a secure backup | M. Key ID in every log line shows which key was used |
| **Exchange signing key** | **H**. Separate from the encryption key | **H** | **H**. It *is* the authenticity of the backend | **H** | M. Key ID logged |
| **APP_KEY** | **H**. `.env` only | **H** | — | **H**. If lost, encrypted ID numbers can't be read → backup required | L |
| **TLS private key / dev CA key** | **H**. `certs/` is git-ignored, files `chmod 600` | **H** | **H**. Proves the server is ours | M. Re-issue with `scripts/dev-certs.sh` | L |
| **Logs** (`automation.log`, `generator.log`) | M. Contain no personal data or secrets by design (secret scan) | M. Append-only files | — | M | **H**. Correlation ID links API → worker → generator |
| **Audit log** | M | **H**. Model blocks update/delete (insert-only, tested) | M | M | **H** |

## How to read it

- **Confidentiality**: who may *read* it. **Integrity**: nobody can *change* it unnoticed. **Authenticity**: we know
  *who sent* it. **Availability**: it's there when needed. **Accountability**: we can prove *who did what*.
- The highest needs are confidentiality and integrity for the **ID number** and integrity and authenticity for the
  **generator request**. That's why the request gets three layers: TLS, AEAD on the personal fields, and HMAC
  over the whole request.
- Keys have **high availability** needs too. Losing a key is also an incident: certificates stop, and encrypted data
  can't be read. The key lifecycle plan (document 5) covers backups.
