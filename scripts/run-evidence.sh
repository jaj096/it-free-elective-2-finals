#!/usr/bin/env bash
# Runs the Week 13 evidence scenarios against the LIVE system and saves the results
# into evidence/. Works in Git Bash (Windows), macOS and Linux.
#
# Before running (each in its own terminal):
#   1. generator-service:  php -S 127.0.0.1:8081 -t public public/index.php
#   2. backend:            php artisan migrate:fresh --seed   (fresh fictional data)
#                          php artisan serve --port=8000
#   3. backend worker:     php artisan queue:work
#
# Usage: bash scripts/run-evidence.sh
# Uses PHP (already installed for Laravel) to read JSON, so jq is not needed.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
API="${API:-http://localhost:8000/api/v1}"
GEN_ENV="$ROOT/generator-service/.env"
LOG="$ROOT/backend/storage/logs/automation.log"
EVIDENCE="$ROOT/evidence"
PASSWORD='Password123!'

json() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(explode(".",$argv[1]) as $k){ $v=$v[$k]??null; } echo is_array($v)?json_encode($v):$v;' "$1"; }

set_mode() {
  # Switch the generator's failure mode (it re-reads .env on every request).
  php -r '$f=$argv[1]; $s=file_get_contents($f); $s=preg_replace("/^GENERATOR_MODE=.*/m","GENERATOR_MODE=".$argv[2],$s); file_put_contents($f,$s);' "$GEN_ENV" "$1"
  echo "   generator mode -> $1"
}

login() {
  curl -s -X POST "$API/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d "{\"email\":\"$1\",\"password\":\"$PASSWORD\"}" | json data.token
}

status_of() { curl -s "$API/requests/$1" -H "Authorization: Bearer $APPROVER" -H 'Accept: application/json' | json data.status; }

wait_for() {
  # wait_for <request id> <expected status> <timeout seconds>
  local id=$1 want=$2 timeout=$3 s=""
  for ((t = 0; t < timeout; t++)); do
    s=$(status_of "$id")
    [[ "$s" == "$want" ]] && { echo "   request #$id -> $s after ~${t}s"; return 0; }
    sleep 1
  done
  echo "   request #$id is '$s' (expected '$want') after ${timeout}s"
}

save_case() {
  # save_case <folder> <request id>
  local dir="$EVIDENCE/$1" id=$2
  mkdir -p "$dir"
  curl -s "$API/requests/$id" -H "Authorization: Bearer $APPROVER" -H 'Accept: application/json' > "$dir/request-$id.json"
  grep "\"request_id\":$id[,}]" "$LOG" > "$dir/automation-log-request-$id.jsonl" || true
  echo "   saved $dir"
}

approve() {
  curl -s -D "$EVIDENCE/$2/approve-headers.txt" -o "$EVIDENCE/$2/approve-response.json" \
    -X POST "$API/requests/$1/approve" -H "Authorization: Bearer $APPROVER" -H 'Accept: application/json'
}

mkdir -p "$EVIDENCE"
echo "== Health check"
curl -s "$API/health" | tee "$EVIDENCE/health-before.json"; echo

APPROVER=$(login approver@example.test)
[[ -n "$APPROVER" ]] || { echo "Login failed. Is the backend running and seeded?"; exit 1; }

echo "== 1. Normal success (request #1)"
mkdir -p "$EVIDENCE/01-normal-success"; set_mode success
approve 1 01-normal-success; wait_for 1 generated 20; save_case 01-normal-success 1

echo "== 2. Transient failure recovered by retry (request #2)"
mkdir -p "$EVIDENCE/02-transient-recovered"; set_mode fail_twice_then_succeed
approve 2 02-transient-recovered; wait_for 2 generated 90; save_case 02-transient-recovered 2

echo "== 3. Permanent failure routed to a human (request #3)"
mkdir -p "$EVIDENCE/03-permanent-to-review"; set_mode always_422
approve 3 03-permanent-to-review; wait_for 3 needs_review 20; save_case 03-permanent-to-review 3

echo "== 4. Retries exhausted (request #4)"
mkdir -p "$EVIDENCE/04-retries-exhausted"; set_mode always_503
approve 4 04-retries-exhausted; wait_for 4 needs_review 120; save_case 04-retries-exhausted 4
set_mode success

echo "== 5. Duplicate dispatch (request #1, 3 extra copies)"
mkdir -p "$EVIDENCE/05-duplicate-dispatch"
(cd "$ROOT/backend" && php artisan certificates:dispatch-duplicate 1 --times=3) | tee "$EVIDENCE/05-duplicate-dispatch/command-output.txt"
sleep 5
curl -s "$API/automation/jobs" -H "Authorization: Bearer $APPROVER" -H 'Accept: application/json' > "$EVIDENCE/05-duplicate-dispatch/automation-metrics.json"
save_case 05-duplicate-dispatch 1
echo "   duplicates skipped (total): $(json data.metrics.duplicates_skipped_total < "$EVIDENCE/05-duplicate-dispatch/automation-metrics.json")"

echo "== 6. Recovery: missing ID number fixed, then re-queued (request #5, Pedro Reyes)"
mkdir -p "$EVIDENCE/06-recovery"
approve 5 06-recovery; wait_for 5 needs_review 20
curl -s "$API/requests/5" -H "Authorization: Bearer $APPROVER" -H 'Accept: application/json' > "$EVIDENCE/06-recovery/before-fix.json"
curl -s -X POST "$API/requests/5/requeue" -H "Authorization: Bearer $APPROVER" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -d '{"requester_id_number":"2026-00003","note":"Added missing ID number"}' \
  > "$EVIDENCE/06-recovery/requeue-response.json"
wait_for 5 generated 20; save_case 06-recovery 5

echo "== 7. Adverse API checks"
mkdir -p "$EVIDENCE/07-api-adverse"
REQUESTER=$(login juan@example.test)
OTHER=$(login maria@example.test)
{
  echo "# expected -> actual (HTTP)"
  printf "no token (expect 401) -> "; curl -s -o /dev/null -w "%{http_code}\n" "$API/requests" -H 'Accept: application/json'
  printf "missing certificate type (expect 422) -> "; curl -s -o /dev/null -w "%{http_code}\n" -X POST "$API/requests" -H "Authorization: Bearer $REQUESTER" -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"purpose":"x"}'
  printf "requester approves (expect 403) -> "; curl -s -o /dev/null -w "%{http_code}\n" -X POST "$API/requests/6/approve" -H "Authorization: Bearer $REQUESTER" -H 'Accept: application/json'
  printf "approve twice (expect 409) -> "; curl -s -o /dev/null -w "%{http_code}\n" -X POST "$API/requests/1/approve" -H "Authorization: Bearer $APPROVER" -H 'Accept: application/json'
  printf "other requester's request (expect 404) -> "; curl -s -o /dev/null -w "%{http_code}\n" "$API/requests/1" -H "Authorization: Bearer $OTHER" -H 'Accept: application/json'
  printf "download before release (expect 403) -> "; curl -s -o /dev/null -w "%{http_code}\n" "$API/certificates/1/download" -H "Authorization: Bearer $REQUESTER" -H 'Accept: application/json'
} | tee "$EVIDENCE/07-api-adverse/results.txt"

echo "== Secret scan of the automation log (expect no matches)"
if grep -n -i -E 'password|bearer|service[-_]key|token' "$LOG"; then echo "   WARNING: review the lines above"; else echo "   no secrets found" | tee "$EVIDENCE/secret-scan.txt"; fi

curl -s "$API/health" > "$EVIDENCE/health-after.json"
echo
echo "Done. Evidence saved in $EVIDENCE. Add your screenshots to evidence/08-flutter-responsive/ and update evidence/index.md."
