# Local HTTPS setup (Week 14)

**Goal:** no personal data or token travels over plain HTTP, even on one laptop. Caddy terminates TLS in front of the
two PHP servers, and Laravel's HTTP client **verifies** the generator's certificate. Verification is never turned off.

```
Browser (Flutter Web)  ──https://localhost:5000──  flutter run (TLS)
        │
        └──https://localhost:8443──▶ Caddy ──http (loopback only)──▶ php artisan serve 127.0.0.1:8000
                                                                        │ queue worker
                                     Caddy ◀──https://localhost:8444────┘ (CA verified)
                                       └──http (loopback only)──▶ php -S 127.0.0.1:8081 (generator)
```

The PHP servers listen on `127.0.0.1` only, so other machines can't reach them. If something on this machine calls
them directly over plain HTTP, it gets **403 HTTPS_REQUIRED** (from both the API and the generator).

## One-time setup

```bash
# 1. Install Caddy (macOS; Windows: winget install CaddyServer.Caddy, or the zip from caddyserver.com)
brew install caddy

# 2. Create a local development CA + a certificate for localhost/127.0.0.1 (written to certs/, git-ignored)
bash scripts/dev-certs.sh

# 3. Let the browser trust the dev CA (needed for Flutter Web in Chrome). macOS:
security add-trusted-cert -r trustRoot -k ~/Library/Keychains/login.keychain-db certs/dev-ca.pem
#    Windows: certutil -user -addstore Root certs\dev-ca.pem
#    (Remove later in Keychain Access → login → "Cert Queue Dev Root CA".)
```

Why our own CA instead of `-k` / `verify=false`? Turning verification off would accept **any** certificate,
including an attacker's, so TLS would give no authenticity. With a private CA, only certificates we issued are trusted.

## `.env` values

`backend/.env`

```dotenv
APP_URL=https://localhost:8443
APP_REQUIRE_HTTPS=true                       # API answers 403 HTTPS_REQUIRED to plain HTTP
GENERATOR_URL=https://localhost:8444
GENERATOR_REQUIRE_HTTPS=true                 # sender refuses a non-https generator URL
GENERATOR_CA_BUNDLE=/full/path/to/cert-queue/certs/dev-ca.pem
CORS_ALLOWED_ORIGINS=https://localhost:5000
```

`generator-service/.env`

```dotenv
GENERATOR_REQUIRE_HTTPS=true
```

## Run (one terminal each)

```bash
cd generator-service && php -S 127.0.0.1:8081 -t public public/index.php
cd backend && php artisan serve --host=127.0.0.1 --port=8000
cd backend && php artisan queue:work
caddy run                                   # from the repository root (reads ./Caddyfile)
cd frontend_flutter && flutter run -d chrome --web-port=5000 \
  --web-tls-cert-path ../certs/localhost.pem --web-tls-cert-key-path ../certs/localhost.key \
  --dart-define=API_BASE_URL=https://localhost:8443/api/v1
```

After changing `backend/.env`, restart the queue worker (`php artisan queue:restart`, then `php artisan queue:work`).
A worker keeps its old configuration in memory until it restarts.

## Check it

```bash
export CURL_CA_BUNDLE=$PWD/certs/dev-ca.pem
curl https://localhost:8443/api/v1/health          # 200, "generator":"ok" (Laravel → generator over verified TLS)
curl http://127.0.0.1:8000/api/v1/health           # 403 HTTPS_REQUIRED
env -u CURL_CA_BUNDLE curl https://localhost:8443/  # exit 60: not trusted by the normal store (verification is on)
curl -sv https://localhost:8443/api/v1/health -o /dev/null 2>&1 | grep -E "SSL connection|issuer|verify|strict"
cd backend && php artisan generator:security-test  # all 7 cases PASS
```

## What Caddy adds

| Setting | Why |
| --- | --- |
| `tls certs/localhost.pem certs/localhost.key` + `protocols tls1.2 tls1.3` | Our dev certificate; old TLS versions are refused |
| `Strict-Transport-Security` | The browser remembers to use HTTPS only |
| `X-Content-Type-Options: nosniff`, no `Server` header | Small hardening, less fingerprinting |
| `reverse_proxy 127.0.0.1:…` | Sets `X-Forwarded-Proto: https`. Laravel trusts this header **only from 127.0.0.1/::1** (`trustProxies` in `bootstrap/app.php`), and so does the generator (`arrived_over_https()`). |
| `auto_https disable_redirects` | Caddy does not open port 80; plain HTTP is refused, not redirected (a redirect would mean the token was already sent in clear) |

## Production note

Use a real domain with a public certificate (Caddy gets one from Let's Encrypt automatically), and leave
`GENERATOR_CA_BUNDLE` empty so the system trust store is used. Better still, put the generator on a private network,
or use mutual TLS so the generator only accepts the backend's client certificate.
