#!/usr/bin/env bash
# Runs the evidence scenarios against the LIVE system and saves the results
# into evidence/. Works in Git Bash (Windows), macOS and Linux.
#
# Before running (each in its own terminal):
#   1. generator-service:  php -S 127.0.0.1:8081 -t public public/index.php
#   2. backend:            php artisan migrate:fresh --seed   (fresh fictional data)
#                          php artisan serve --host=127.0.0.1 --port=8000
#   3. backend worker:     php artisan queue:work
#   4. repository root:    caddy run      (Week 14: HTTPS on :8443 API and :8444 generator)
#
# Usage: bash scripts/run-evidence.sh            Week 13 + Week 14
#        bash scripts/run-evidence.sh week13     Week 13 only (needs a freshly seeded database)
#        bash scripts/run-evidence.sh week14     Week 14 only  -> evidence/week14/
# Uses PHP (already installed for Laravel) to read JSON, so jq is not needed.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WHICH="${1:-all}"
# Week 14: the API is only reachable over HTTPS; curl verifies it against the local dev CA.
API="${API:-https://localhost:8443/api/v1}"
export CURL_CA_BUNDLE="${CURL_CA_BUNDLE:-$ROOT/certs/dev-ca.pem}"
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

week13() {
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
}

week14() {
  local W="$EVIDENCE/week14" GEN_LOG="$ROOT/generator-service/storage/generator.log"
  local DB="$ROOT/backend/database/database.sqlite"
  mkdir -p "$W"
  echo "== WEEK 14: secure data exchange -> $W"
  APPROVER=$(login approver@example.test)
  [[ -n "$APPROVER" ]] || { echo "Login over HTTPS failed. Are Caddy, the API and certs/dev-ca.pem in place?"; exit 1; }

  echo "== 14.1 Transport: TLS handshake, certificate verification, plain HTTP refused"
  {
    echo "# expected -> actual"
    echo "## API over HTTPS with the dev CA (expect TLS 1.2/1.3, 'verify ok', HSTS, 200)"
    curl -sv "$API/health" -o /dev/null 2>&1 | grep -E "SSL connection|subject:|issuer:|SSL certificate verify|strict-transport|< HTTP" || true
    echo "## Generator over HTTPS (expect 200)"
    printf "https://localhost:8444/health -> "; curl -s -o /dev/null -w "%{http_code}\n" https://localhost:8444/health
    echo "## Normal system trust store, dev CA NOT trusted (expect curl exit 60 = certificate not trusted)"
    printf "curl exit code -> "; env -u CURL_CA_BUNDLE curl -s -o /dev/null "$API/health"; echo "$?" || true
    echo "## API over plain HTTP, straight to artisan serve (expect 403 HTTPS_REQUIRED)"
    curl -s -w " [HTTP %{http_code}]\n" http://127.0.0.1:8000/api/v1/health
    echo "## Login over plain HTTP (expect 403; the password is never accepted in clear)"
    curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8000/api/v1/auth/login -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"email":"juan@example.test","password":"x"}'
    echo "## Generator /generate over plain HTTP (expect 403 HTTPS_REQUIRED)"
    curl -s -w " [HTTP %{http_code}]\n" -X POST http://127.0.0.1:8081/generate -H 'Content-Type: application/json' -d '{}'
  } > "$W/01-transport.txt" 2>&1 || true
  cat "$W/01-transport.txt"

  echo "== 14.2 What goes on the wire (one sample request; personal fields must be ciphertext)"
  (cd "$ROOT/backend" && php -r '
    require "vendor/autoload.php"; $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $r = app(App\Security\SecureRequestBuilder::class)->build(["job_key" => "evidence-sample", "certificate_type" => "enrollment",
      "purpose" => "Scholarship application", "requester_name" => "Juan Dela Cruz", "requester_id_number" => "2026-00001"]);
    echo json_encode(["method" => $r->method, "path" => $r->path, "headers" => $r->headers, "body" => json_decode($r->body)], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
  ') > "$W/02-wire-sample.json"
  if grep -q -E "Juan Dela Cruz|2026-00001" "$W/02-wire-sample.json"; then echo "   WARNING: plaintext found in wire sample"; else echo "   no plaintext name/ID in wire sample"; fi

  echo "== 14.3 Failure injection against the live generator (7 cases)"
  : > "$W/03-security-test.txt"
  (cd "$ROOT/backend" && php artisan generator:security-test --json="$W/03-security-test.json") | tee "$W/03-security-test.txt" || true
  local CID; CID=$(json correlation_id < "$W/03-security-test.json")
  grep "\"correlation_id\":\"$CID\"" "$GEN_LOG" > "$W/04-generator-rejection-log.jsonl" || true
  grep "\"correlation_id\":\"$CID\"" "$LOG" > "$W/04-backend-security-test-log.jsonl" || true
  echo "   $(grep -c security.rejected "$W/04-generator-rejection-log.jsonl" || true) safe rejection lines saved"

  echo "== 14.4 Data at rest: requester ID numbers are ciphertext, passwords are bcrypt"
  php -r '
    $db = new PDO("sqlite:".$argv[1]);
    echo "# users table as stored on disk (id_number = Laravel encrypted, password = bcrypt)\n";
    foreach ($db->query("select id, email, id_number, password from users order by id") as $u) {
      printf("%d %-24s id_number=%s  password=%s\n", $u["id"], $u["email"],
        $u["id_number"] === null ? "NULL" : substr($u["id_number"], 0, 48)."... (".strlen($u["id_number"])." chars)",
        substr($u["password"], 0, 7)."...");
    }
    $plain = $db->query("select count(*) from users where id_number like \"2026-%\"")->fetchColumn();
    echo "\nrows with a plaintext ID number (expect 0): $plain\n";
  ' "$DB" > "$W/05-db-at-rest.txt" 2>&1 || echo "   (skipped: not using SQLite)" > "$W/05-db-at-rest.txt"
  cat "$W/05-db-at-rest.txt"

  echo "== 14.5 API controls still hold (auth, roles, validation, rate limit, token expiry)"
  local REQUESTER EXPIRED
  REQUESTER=$(login juan@example.test)
  EXPIRED=$(cd "$ROOT/backend" && php -r '
    require "vendor/autoload.php"; $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo App\Models\User::where("email", "juan@example.test")->first()->createToken("evidence-expired", ["*"], now()->subMinute())->plainTextToken;')
  {
    echo "# expected -> actual (HTTP code, error code)"
    code() { curl -s -w " %{http_code}" "$@" | php -r '$in=stream_get_contents(STDIN); $p=strrpos($in," "); $j=json_decode(substr($in,0,$p),true); echo substr($in,$p+1)," ",$j["error"]["code"]??"","\n";'; }
    printf "expired Sanctum token (expect 401 TOKEN_EXPIRED) -> "; code "$API/auth/me" -H "Authorization: Bearer $EXPIRED" -H 'Accept: application/json'
    printf "made-up token (expect 401 UNAUTHENTICATED) -> "; code "$API/auth/me" -H 'Authorization: Bearer 1|fake' -H 'Accept: application/json'
    printf "no token (expect 401 UNAUTHENTICATED) -> "; code "$API/requests" -H 'Accept: application/json'
    printf "requester approves (expect 403 FORBIDDEN) -> "; code -X POST "$API/requests/6/approve" -H "Authorization: Bearer $REQUESTER" -H 'Accept: application/json'
    printf "invalid input (expect 422 VALIDATION_FAILED) -> "; code -X POST "$API/requests" -H "Authorization: Bearer $REQUESTER" -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"certificate_type":"bogus","purpose":"x"}'
    for i in 1 2 3 4 5; do curl -s -o /dev/null -X POST "$API/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"email":"ratelimit@example.test","password":"wrong"}'; done
    printf "6th bad login in a minute (expect 429 TOO_MANY_REQUESTS) -> "; code -X POST "$API/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"email":"ratelimit@example.test","password":"wrong"}'
    printf "staff view shows masked ID only (expect 2026-***01) -> "; curl -s "$API/requests/1" -H "Authorization: Bearer $APPROVER" -H 'Accept: application/json' | json data.requester.id_number_masked; echo
  } > "$W/06-api-controls.txt" 2>&1
  cat "$W/06-api-controls.txt"

  echo "== 14.6 Secret scan: logs and tracked files must not contain keys, tokens or personal data"
  (cd "$ROOT/backend" && php -r '
    $env = [];
    foreach ([$argv[1]."/backend/.env", $argv[1]."/generator-service/.env"] as $f) {
      foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match("/^(APP_KEY|GENERATOR_(ENCRYPTION|SIGNING)_KEY\w*)=(.+)$/", $l, $m)) { $env[$m[1]] = trim($m[3], "\"\x27"); }
      }
    }
    $targets = ["backend/storage/logs/automation.log", "backend/storage/logs/laravel.log", "generator-service/storage/generator.log"];
    $needles = [];
    foreach ($env as $name => $value) { $needles["value of $name"] = $value; if (str_starts_with($value, "base64:")) { $needles["raw $name"] = substr($value, 7); } }
    $needles += ["requester name" => "Juan Dela Cruz", "plaintext ID number" => "2026-00001", "bearer token" => "Bearer ", "password field" => "\"password\""];
    $problems = 0;
    foreach ($targets as $t) {
      $text = (string) @file_get_contents($argv[1]."/".$t);
      foreach ($needles as $label => $n) { if ($n !== "" && str_contains($text, $n)) { echo "FOUND $label in $t\n"; $problems++; } }
      echo "scanned $t (".strlen($text)." bytes)\n";
    }
    $tracked = shell_exec("cd ".escapeshellarg($argv[1])." && git ls-files -z | xargs -0 cat 2>/dev/null");
    foreach ($env as $name => $value) { if (str_contains((string) $tracked, $value)) { echo "FOUND value of $name in a git-tracked file\n"; $problems++; } }
    echo "scanned all git-tracked files for the real key values\n";
    echo $problems === 0 ? "RESULT: no secrets or personal data found\n" : "RESULT: $problems problem(s) - review above\n";
  ' "$ROOT") | tee "$W/07-secret-scan.txt"

  echo "== 14.7 Automated tests"
  (cd "$ROOT/backend" && php artisan test 2>&1 | tail -3) | tee "$W/08-backend-tests.txt"

  date '+Run at %Y-%m-%d %H:%M:%S %z' > "$W/run-info.txt"
  (cd "$ROOT" && git rev-parse --short HEAD) >> "$W/run-info.txt"
  echo "Week 14 evidence saved in $W"
}

case "$WHICH" in
  week13) week13 ;;
  week14) week14 ;;
  all) week13; week14 ;;
  *) echo "Usage: bash scripts/run-evidence.sh [week13|week14|all]"; exit 2 ;;
esac
