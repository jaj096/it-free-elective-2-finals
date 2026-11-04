#!/usr/bin/env bash
# Week 14: creates a LOCAL development CA and a TLS certificate for localhost / 127.0.0.1.
# Output goes to certs/ (git-ignored). Private keys never leave this machine.
#
#   certs/dev-ca.pem       CA certificate  -> give to Laravel (GENERATOR_CA_BUNDLE) and curl --cacert
#   certs/dev-ca.key       CA private key  -> keep secret; only needed to issue new certs
#   certs/localhost.pem    server certificate (signed by the dev CA), used by Caddy and Flutter
#   certs/localhost.key    server private key
#
# Usage: bash scripts/dev-certs.sh          (re-run any time; it replaces the server cert, keeps the CA)
# Only for development. Production uses a public CA (e.g. Let's Encrypt via Caddy).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$ROOT/certs"
mkdir -p "$DIR"
chmod 700 "$DIR"
cd "$DIR"

if [[ ! -f dev-ca.key ]]; then
  echo "== Creating development CA (valid 2 years)"
  openssl genrsa -out dev-ca.key 3072 2>/dev/null
  openssl req -x509 -new -key dev-ca.key -sha256 -days 730 -out dev-ca.pem \
    -subj "/O=Cert Queue Dev CA (class project)/CN=Cert Queue Dev Root CA"
fi

echo "== Creating localhost certificate (valid 90 days)"
openssl genrsa -out localhost.key 2048 2>/dev/null
openssl req -new -key localhost.key -out localhost.csr -subj "/O=Cert Queue Dev/CN=localhost"
cat > localhost.ext <<'EXT'
basicConstraints=CA:FALSE
keyUsage=digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:localhost,IP:127.0.0.1,IP:::1
EXT
openssl x509 -req -in localhost.csr -CA dev-ca.pem -CAkey dev-ca.key -CAcreateserial \
  -out localhost.pem -days 90 -sha256 -extfile localhost.ext 2>/dev/null
rm -f localhost.csr localhost.ext
chmod 600 dev-ca.key localhost.key

openssl verify -CAfile dev-ca.pem localhost.pem
echo "Done. Next: trust certs/dev-ca.pem in your browser/OS (see docs/week14/https-setup.md), then run: caddy run"
