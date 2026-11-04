# Flutter Web client – Certificate Generation Queue

Week 14: the app only talks to the API over **HTTPS**. Create the dev certificates first
(`bash scripts/dev-certs.sh` in the repository root, then trust `certs/dev-ca.pem`; see
`docs/week14/https-setup.md`).

```bash
flutter pub get
flutter run -d chrome --web-port=5000 \
  --web-tls-cert-path ../certs/localhost.pem --web-tls-cert-key-path ../certs/localhost.key \
  --dart-define=API_BASE_URL=https://localhost:8443/api/v1
```

Build for deployment: `flutter build web --dart-define=API_BASE_URL=https://your-api/api/v1`

- No secrets are stored in this app. The API URL is the only build-time setting.
- An `http://` API address is refused before anything is sent (only `--dart-define=ALLOW_INSECURE_API=true`
  overrides this, for a throwaway test server).
- Forms collect only what the server needs: certificate type + purpose (requester), a reason (staff),
  and the requester ID number only when staff must fix a missing one. Staff see a masked ID (`2026-***01`).
- Safe messages: invalid (400/422), altered (unreadable body, `INTEGRITY_FAILED`, PDF hash mismatch),
  expired (`TOKEN_EXPIRED`), unauthorized (401/403), `HTTPS_REQUIRED`. Server internals are never shown.
- Downloaded PDFs are checked against the `X-Content-SHA256` header before they are saved.

Tests: `flutter test` (see `test/security_messages_test.dart`). See the main README for full setup.
