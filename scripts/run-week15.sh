#!/usr/bin/env bash
# Week 15 (IA6): runs the Postman collection with Newman against the LIVE system, switches the
# generator into failure modes for the dependency/timeout/recovery folders, runs the duplicate-submit
# race probe, the dependency audit and the automated tests, and saves everything to
# evidence/week15/<label>/.
#
# Before running (each in its own terminal), same as Week 14:
#   generator-service:  php -S 127.0.0.1:8081 -t public public/index.php
#   backend:            PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000
#   backend worker:     php artisan queue:work
#   repository root:    caddy run
#
# Usage: bash scripts/run-week15.sh before     (baseline, before the hardening sprint)
#        bash scripts/run-week15.sh after      (regression run after the fixes)
# Needs Node.js (Newman is fetched with npx; nothing is installed globally). Takes about 6 minutes.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LABEL="${1:-}"
[[ "$LABEL" =~ ^[a-z0-9-]+$ ]] || { echo "Usage: bash scripts/run-week15.sh <before|after>"; exit 2; }

OUT="$ROOT/evidence/week15/$LABEL"
COLLECTION="$ROOT/tests/postman/cert-queue.postman_collection.json"
ENV_FILE="$ROOT/tests/postman/cert-queue.local.postman_environment.json"
CA="$ROOT/certs/dev-ca.pem"
API="${API:-https://localhost:8443/api/v1}"
GEN_ENV="$ROOT/generator-service/.env"
LOG="$ROOT/backend/storage/logs/automation.log"
NEWMAN="npx --yes newman@6.2.1"
export CURL_CA_BUNDLE="$CA"

# The exported environment holds live tokens: keep it outside the evidence folder and delete it at the end.
RUN_ENV="$(mktemp -t cert-queue-env.XXXXXX)"
cp "$ENV_FILE" "$RUN_ENV"

set_mode() {
  php -r '$f=$argv[1]; $s=file_get_contents($f); $s=preg_replace("/^GENERATOR_MODE=.*/m","GENERATOR_MODE=".$argv[2],$s); file_put_contents($f,$s);' "$GEN_ENV" "$1"
  echo "   generator mode -> $1"
}
cleanup() { set_mode success >/dev/null; rm -f "$RUN_ENV"; }
trap cleanup EXIT

env_value() { php -r '$j=json_decode(file_get_contents($argv[1]),true); foreach($j["values"] as $v){ if($v["key"]===$argv[2]){ echo $v["value"]; } }' "$RUN_ENV" "$1"; }

newman_run() {
  # newman_run <output name> <folder>...
  local name=$1; shift
  local args=()
  for f in "$@"; do args+=(--folder "$f"); done
  $NEWMAN run "$COLLECTION" -e "$RUN_ENV" --export-environment "$RUN_ENV" "${args[@]}" \
    --ssl-extra-ca-certs "$CA" --color off --reporters cli,junit --reporter-junit-export "$OUT/$name.junit.xml" \
    > "$OUT/$name.txt" 2>&1
  local code=$?
  echo "newman exit code: $code" >> "$OUT/$name.txt"
  grep -E "│ +(requests|test-scripts|assertions) " "$OUT/$name.txt" | sed 's/^/   /'
}

rm -rf "$OUT"; mkdir -p "$OUT"
echo "== WEEK 15 ($LABEL) -> $OUT"
curl -s "$API/health" | grep -q '"database":"ok"' || { echo "API not reachable at $API. Start the services first."; exit 1; }

{
  date '+Run at %Y-%m-%d %H:%M:%S %z'
  echo "commit: $(cd "$ROOT" && git rev-parse --short HEAD)$(cd "$ROOT" && git diff --quiet HEAD -- backend frontend_flutter generator-service || echo ' (+ uncommitted changes)')"
  echo "branch: $(cd "$ROOT" && git rev-parse --abbrev-ref HEAD)"
  echo "php: $(php -r 'echo PHP_VERSION;')"
  echo "laravel: $(cd "$ROOT/backend" && php artisan --version)"
  echo "node: $(node --version)   newman: $($NEWMAN --version 2>/dev/null)"
  echo "database: $(grep -E '^DB_CONNECTION=' "$ROOT/backend/.env" | cut -d= -f2)   queue: $(grep -E '^QUEUE_CONNECTION=' "$ROOT/backend/.env" | cut -d= -f2)"
  echo "API: $API (Caddy -> php artisan serve, PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-see terminal})"
  echo "data: fictional seed accounts (juan, maria, approver, custodian @example.test)"
} > "$OUT/00-run-info.txt"
cat "$OUT/00-run-info.txt"

echo "== 15.1 Dependency audit (composer audit)"
(cd "$ROOT/backend" && composer audit --no-interaction 2>&1; echo "composer audit exit code: $?") > "$OUT/01-composer-audit.txt"
tail -1 "$OUT/01-composer-audit.txt" | sed 's/^/   /'

echo "== 15.2 Collection: folders 00-07 (normal, invalid, 401, 403, 404, duplicate, hardening)"
set_mode success
newman_run 02-newman-main "00 Setup" "01 Normal path" "02 Invalid and boundary input" "03 Unauthenticated (401)" \
  "04 Forbidden (403)" "05 Not found (404/405)" "06 Duplicate and conflict (409)" "07 Hardening checks"

echo "== 15.3 Dependency failure: generator returns 503 (folder 08)"
set_mode always_503
newman_run 03-newman-dependency-503 "08 Dependency failure (generator 503)"

echo "== 15.4 Timeout: generator slower than the 10 s client timeout (folder 09)"
set_mode slow
newman_run 04-newman-timeout "09 Timeout (generator slow)"

echo "== 15.5 Recovery: generator restored, staff re-queue (folder 10)"
set_mode success
newman_run 05-newman-recovery "10 Recovery"

echo "== 15.6 Worker log lines for the failure and recovery cases"
for var in failReqId slowReqId; do
  id=$(env_value "$var")
  [[ -n "$id" ]] && grep "\"request_id\":$id[,}]" "$LOG" > "$OUT/06-automation-log-$var-$id.jsonl"
  [[ -n "$id" ]] && echo "   $var=$id: $(wc -l < "$OUT/06-automation-log-$var-$id.jsonl" | tr -d ' ') log lines"
done

echo "== 15.7 Duplicate-submit race: 5 rounds x 12 parallel POSTs with the same Idempotency-Key"
TOKEN=$(env_value token_maria)
{
  echo "# Each round: 12 identical POST /requests sent at the same moment with ONE Idempotency-Key."
  echo "# Expected: exactly one 201 and eleven 200 (same request returned); never 500."
  for round in 1 2 3 4 5; do
    key="race-$LABEL-$round-$(date +%s)"
    codes=$(for i in $(seq 12); do
      curl -s -o /dev/null -w "%{http_code}\n" -X POST "$API/requests" -H "Authorization: Bearer $TOKEN" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Idempotency-Key: $key" \
        -d '{"certificate_type":"enrollment","purpose":"Week 15 race probe"}' &
    done; wait)
    echo "round $round: $(echo "$codes" | sort | uniq -c | awk '{printf "%sx%s ", $1, $2}')"
  done
  echo "# rows created per key:"
  php -r '$d=new PDO("sqlite:".$argv[1]); foreach($d->query("select idempotency_key k, count(*) c from certificate_requests where idempotency_key like \"race-".$argv[2]."-%\" group by idempotency_key") as $r) echo $r["k"]," -> ",$r["c"]," row(s)\n";' \
    "$ROOT/backend/database/database.sqlite" "$LABEL"
} > "$OUT/07-idempotency-race.txt" 2>&1
sed 's/^/   /' "$OUT/07-idempotency-race.txt" | grep -E "round|row"

echo "== 15.8 Automated tests (backend + Flutter)"
(cd "$ROOT/backend" && php artisan test 2>&1 | tail -4) > "$OUT/08-backend-tests.txt"
tail -3 "$OUT/08-backend-tests.txt" | sed 's/^/   /'
if command -v flutter >/dev/null; then
  (cd "$ROOT/frontend_flutter" && flutter test 2>&1 | tail -2) > "$OUT/09-flutter-tests.txt"
  tail -1 "$OUT/09-flutter-tests.txt" | sed 's/^/   /'
fi

echo "== 15.9 Secret scan of this evidence folder (tokens, keys, passwords)"
if grep -rIl -E '[0-9]+\|[A-Za-z0-9]{40}|base64:[A-Za-z0-9+/=]{40}|Password123!' "$OUT"; then
  echo "   WARNING: review the files listed above" | tee "$OUT/10-secret-scan.txt"
else
  echo "no tokens, keys or passwords in evidence/week15/$LABEL" | tee "$OUT/10-secret-scan.txt" | sed 's/^/   /'
fi

echo "Done. Evidence in $OUT"
