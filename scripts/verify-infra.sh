#!/usr/bin/env bash
# Runtime smoke test for the Docker development environment (Phase 02).
#
# Run after `make up` (all services healthy). Verifies, without any product
# feature: service health, Nginx single-origin routing, internal networking
# and isolation, Laravel <-> PostgreSQL/Redis, and MinIO S3 access including a
# pre-signed URL download performed by the analyzer. Later phases add their
# end-to-end checks through Nginx (auth, profile, projects and uploads); every
# probe account and object is removed again.
#
# Never prints secrets. Exit code 0 only if every check passes.
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

port=$(grep -E '^NGINX_HOST_PORT=' .env 2>/dev/null | cut -d= -f2-)
base="http://127.0.0.1:${port:-80}"
compose=(docker compose)
failures=0

pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; failures=$((failures + 1)); }
check() { # check "description" command...
    local description=$1; shift
    if "$@" >/dev/null 2>&1; then pass "$description"; else fail "$description"; fi
}
check_not() { # check_not "description" command...  (passes when the command FAILS)
    local description=$1; shift
    if "$@" >/dev/null 2>&1; then fail "$description"; else pass "$description"; fi
}
in_service() { local service=$1; shift; "${compose[@]}" exec -T "$service" "$@"; }

echo "Service health"
for service in nginx frontend backend analyzer evaluator postgres redis minio; do
    status=$("${compose[@]}" ps --format '{{.Health}}' "$service" 2>/dev/null)
    if [[ "$status" == "healthy" ]]; then pass "$service is healthy"; else fail "$service is healthy (got: ${status:-not running})"; fi
done
init_state=$("${compose[@]}" ps -a --format '{{.State}} {{.ExitCode}}' minio-init 2>/dev/null)
if [[ "$init_state" == "exited 0" ]]; then pass "minio-init completed successfully"; else fail "minio-init completed successfully (got: ${init_state:-missing})"; fi

echo "Nginx single-origin routing ($base)"
check "GET /nginx-health -> 200" curl -fsS "$base/nginx-health"
check "GET / -> Next.js page" bash -c "curl -fsS '$base/' | grep -q 'CodeDNA'"
check "GET /up -> Laravel health route 200" curl -fsS "$base/up"
check "GET /api/* -> Laravel (JSON 404)" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code} %{content_type}' '$base/api/__verify') == '404 application/json'* ]]"
check "GET /internal/v1/health -> 404 from Nginx (analyzer not exposed)" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code}' '$base/internal/v1/health') == 404 ]] && ! curl -s '$base/internal/v1/health' | grep -q '\"status\"'"

echo "Frontend through Nginx (Phase 04)"
check "GET /login and /register -> 200" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code}' '$base/login') == 200 && \$(curl -s -o /dev/null -w '%{http_code}' '$base/register') == 200 ]]"
check "anonymous GET /app -> 307 to /login" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' '$base/app') == '307 '*'/login' ]]"
check "frontend sends no X-Powered-By header" bash -c "! curl -sI '$base/login' | grep -qi '^x-powered-by'"

echo "Backend API through Nginx (Phase 03)"
check "GET /api/v1/health -> 200, database and redis ok" bash -c \
    "curl -fsS '$base/api/v1/health' | grep -q '\"checks\":{\"database\":\"ok\",\"redis\":\"ok\"}'"

# Browser-style Sanctum SPA flow. CSRF is skipped inside PHPUnit, so this is
# where enforcement is verified end to end. The probe user is deleted afterwards.
jar=$(mktemp)
probe_email="verify-$$-$RANDOM@example.invalid"
probe_password="verify-$RANDOM-$RANDOM-pass"
origin="Origin: http://localhost"
api() { # api METHOD PATH [JSON] -> prints "<status>"
    local method=$1 path=$2 body=${3:-}
    local xsrf
    xsrf=$(awk '$6 == "XSRF-TOKEN" {print $7}' "$jar" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))')
    curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" -X "$method" -H "$origin" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        ${xsrf:+-H "X-XSRF-TOKEN: $xsrf"} ${body:+-d "$body"} "$base$path"
}
# `check` runs commands in a child shell, which needs the helper and its inputs.
export jar origin base
export -f api
register_body="{\"name\":\"Verify\",\"email\":\"$probe_email\",\"password\":\"$probe_password\",\"password_confirmation\":\"$probe_password\"}"
check "GET /sanctum/csrf-cookie -> 204 + XSRF-TOKEN cookie" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code}' -c '$jar' -H '$origin' '$base/sanctum/csrf-cookie') == 204 ]] && grep -q XSRF-TOKEN '$jar'"
check "session cookie is HttpOnly" grep -qE '^#HttpOnly_.*codedna-session' "$jar"
check "POST without X-XSRF-TOKEN -> 419 (CSRF enforced)" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code}' -b '$jar' -X POST -H '$origin' -H 'Accept: application/json' '$base/api/v1/auth/logout') == 419 ]]"
check "cross-site POST (Sec-Fetch-Site: cross-site) without X-XSRF-TOKEN -> 419" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code}' -b '$jar' -X POST -H '$origin' -H 'Sec-Fetch-Site: cross-site' -H 'Accept: application/json' '$base/api/v1/auth/logout') == 419 ]]"
check "POST /api/v1/auth/register -> 201" bash -c "[[ \$(api POST /api/v1/auth/register '$register_body') == 201 ]]"
check "GET /api/v1/me with session -> 200" bash -c "[[ \$(api GET /api/v1/me) == 200 ]]"
check "GET /app with session -> 200, rendered for the user (Next.js server-side session check)" bash -c \
    "curl -fsS -b '$jar' '$base/app' | grep -q '$probe_email'"
check "POST /api/v1/auth/logout -> 204" bash -c "[[ \$(api POST /api/v1/auth/logout) == 204 ]]"
check "GET /api/v1/me after logout -> 401" bash -c "[[ \$(api GET /api/v1/me) == 401 ]]"
check "POST /api/v1/auth/login -> 200" bash -c \
    "[[ \$(api POST /api/v1/auth/login '{\"email\":\"$probe_email\",\"password\":\"$probe_password\"}') == 200 ]]"
check "GET /api/v1/profile with session -> 200 (created at registration)" bash -c "[[ \$(api GET /api/v1/profile) == 200 ]]"
check "PATCH /api/v1/profile -> 200" bash -c "[[ \$(api PATCH /api/v1/profile '{\"timezone\":\"Asia/Tashkent\"}') == 200 ]]"
check "cross-site PATCH /api/v1/profile without X-XSRF-TOKEN -> 419" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code}' -b '$jar' -X PATCH -H '$origin' -H 'Sec-Fetch-Site: cross-site' -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{\"city\":\"x\"}' '$base/api/v1/profile') == 419 ]]"
check "cross-site PATCH /api/v1/auth/password without X-XSRF-TOKEN -> 419" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code}' -b '$jar' -X PATCH -H '$origin' -H 'Sec-Fetch-Site: cross-site' -H 'Accept: application/json' '$base/api/v1/auth/password') == 419 ]]"
rm -f "$jar"
# developer_profiles references users with RESTRICT: remove the profile first.
check "remove probe user and profile" "${compose[@]}" exec -T postgres sh -c \
    "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -v ON_ERROR_STOP=1 -qc \"BEGIN; DELETE FROM developer_profiles WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM users WHERE email = '$probe_email'; COMMIT;\""

echo "Internal networking"
check "backend -> analyzer:8000 health" in_service backend curl -fsS http://analyzer:8000/internal/v1/health
check "backend -> minio:9000 health" in_service backend curl -fsS http://minio:9000/minio/health/live
check "Laravel -> PostgreSQL (artisan db:show)" in_service backend php artisan db:show
laravel_cache_roundtrip() {
    in_service backend php artisan tinker --execute \
        "Cache::put('codedna-verify', 'ok', 30); echo Cache::pull('codedna-verify');" | grep -q '^ok'
}
check "Laravel -> Redis (cache round-trip)" laravel_cache_roundtrip
check_not "nginx cannot reach analyzer (network isolation)" in_service nginx wget -q -T 3 -O /dev/null http://analyzer:8000/internal/v1/health
check_not "frontend cannot reach analyzer (network isolation)" in_service frontend node -e \
    "fetch('http://analyzer:8000/internal/v1/health').then(() => process.exit(0)).catch(() => process.exit(1))"
check_not "analyzer has no internet access" in_service analyzer python -c \
    "import urllib.request; urllib.request.urlopen('https://pypi.org', timeout=5)"

echo "MinIO / S3 (application credentials, bucket-scoped)"
probe_key="verify/probe-$$.txt"
# Runs `mc` in the minio-init image with the APPLICATION credentials only.
# shellcheck disable=SC2016 # variables expand inside the container, not here
mc_app() {
    "${compose[@]}" run --rm --no-deps -T --entrypoint sh minio-init -c \
        'mc alias set app http://minio:9000 "$SOURCE_STORAGE_ACCESS_KEY_ID" "$SOURCE_STORAGE_SECRET_ACCESS_KEY" >/dev/null && '"$1"
}
check "upload object" mc_app "echo codedna-presign-probe | mc pipe \"app/\$SOURCE_STORAGE_BUCKET/$probe_key\""
check_not "application user cannot create buckets" mc_app "mc mb app/codedna-verify-forbidden"
presigned=$(mc_app "mc share download --expire 5m \"app/\$SOURCE_STORAGE_BUCKET/$probe_key\"" 2>/dev/null | sed -n 's/^Share: //p' | tr -d '\r')
if [[ "$presigned" == http://minio:9000/* ]]; then
    pass "generate pre-signed GET URL"
    check "analyzer downloads object via pre-signed URL" in_service analyzer python -c \
        "import sys, urllib.request; sys.exit(urllib.request.urlopen(sys.argv[1], timeout=5).read() != b'codedna-presign-probe\\n')" \
        "$presigned"
    unsigned=${presigned%%\?*}
    check_not "unsigned URL is rejected (bucket is private)" in_service analyzer python -c \
        "import sys, urllib.request; urllib.request.urlopen(sys.argv[1], timeout=5)" "$unsigned"
else
    fail "generate pre-signed GET URL"
fi
check "delete object" mc_app "mc rm \"app/\$SOURCE_STORAGE_BUCKET/$probe_key\""

echo "Projects and source upload through Nginx (Phase 07)"
# A separate probe account; everything it creates (rows and objects) is removed below.
jar=$(mktemp)
upload_dir=$(mktemp -d)
probe_email="verify-src-$$-$RANDOM@example.invalid"
probe_password="verify-$RANDOM-$RANDOM-pass"
db() { "${compose[@]}" exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -v ON_ERROR_STOP=1 -tAc \"$1\""; }
upload_archive() { # upload_archive FILE [extra curl args...] -> prints "<status> <body>"
    local file=$1; shift
    local xsrf
    xsrf=$(awk '$6 == "XSRF-TOKEN" {print $7}' "$jar" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))')
    curl -s -w ' %{http_code}' -b "$jar" -c "$jar" -H "$origin" -H 'Accept: application/json' \
        ${xsrf:+-H "X-XSRF-TOKEN: $xsrf"} "$@" -F "archive=@$file;type=application/zip" \
        "$base/api/v1/projects/$project_id/source-snapshots" | awk '{status=$NF; $NF=""; print status, $0}'
}
export -f upload_archive
api_json() { # api_json METHOD PATH [JSON] -> prints "<status> <body>"
    local method=$1 path=$2 body=${3:-}
    local xsrf
    xsrf=$(awk '$6 == "XSRF-TOKEN" {print $7}' "$jar" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))')
    curl -s -w ' %{http_code}' -b "$jar" -c "$jar" -X "$method" -H "$origin" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        ${xsrf:+-H "X-XSRF-TOKEN: $xsrf"} ${body:+-d "$body"} "$base$path" | awk '{status=$NF; $NF=""; print status, $0}'
}
json_field() { python3 -c 'import json, sys; d = json.loads(sys.stdin.read().split(" ", 1)[1]); print(eval("d" + sys.argv[1]))' "$1"; }
wait_for_run() { # wait_for_run RUN_ID STATUS: polls the public API until the run reaches STATUS (60 s)
    for _ in $(seq 1 60); do
        status=$(api_json GET "/api/v1/projects/$project_id/analyses/$1" | json_field '["data"]["status"]' 2>/dev/null)
        [[ "$status" == "$2" ]] && return 0
        [[ "$status" == "FAILED" || "$status" == "CANCELLED" ]] && return 1
        sleep 1
    done
    return 1
}
export -f api_json json_field wait_for_run
curl -s -o /dev/null -c "$jar" -H "$origin" "$base/sanctum/csrf-cookie"
check "register a probe user" bash -c \
    "[[ \$(api POST /api/v1/auth/register '{\"name\":\"Verify\",\"email\":\"$probe_email\",\"password\":\"$probe_password\",\"password_confirmation\":\"$probe_password\"}') == 201 ]]"
check "POST /api/v1/projects -> 201" bash -c \
    "[[ \$(api POST /api/v1/projects '{\"name\":\"Verify\",\"slug\":\"verify-probe\",\"source_type\":\"UPLOAD\"}') == 201 ]]"
project_id=$(db "SELECT p.id FROM projects p JOIN users u ON u.id = p.user_id WHERE u.email = '$probe_email'" 2>/dev/null | tr -d '[:space:]')
export project_id
python3 -I -c 'import sys, zipfile
with zipfile.ZipFile(sys.argv[1], "w", zipfile.ZIP_DEFLATED) as z:
    z.writestr("src/main.php", "<?php echo 1;\n")' "$upload_dir/source.zip"
printf 'not a zip archive\n' > "$upload_dir/fake.zip"
truncate -s 56M "$upload_dir/huge.zip"
check "upload ZIP -> 201 source snapshot v1" bash -c \
    "upload_archive '$upload_dir/source.zip' | grep -q '^201 .*\"version\":1'"
storage_key=$(db "SELECT storage_key FROM source_snapshots WHERE project_id = '$project_id'" 2>/dev/null | tr -d '[:space:]')
check "snapshot object exists in MinIO under projects/{project}/snapshots/{snapshot}/source.zip" bash -c \
    "[[ '$storage_key' == projects/$project_id/snapshots/*/source.zip ]]"
check "object is readable with the application credentials" mc_app "mc stat \"app/\$SOURCE_STORAGE_BUCKET/$storage_key\""
# Phase 10: analysis through the public API, the analysis queue (queue service)
# and the analyzer; the run is polled until the worker finished it.
snapshot_id=$(db "SELECT id FROM source_snapshots WHERE project_id = '$project_id'" 2>/dev/null | tr -d '[:space:]')
export snapshot_id
check "queue worker and scheduler are running" bash -c \
    "[[ \$(${compose[*]} ps --format '{{.State}}' queue) == running && \$(${compose[*]} ps --format '{{.State}}' scheduler) == running ]]"
foundation_start=$(api_json POST "/api/v1/projects/$project_id/analyses" "{\"source_snapshot_id\":\"$snapshot_id\"}")
foundation_run=$(json_field '["data"]["id"]' <<< "$foundation_start" 2>/dev/null)
export foundation_run
check "POST analyses (default result type) -> 202 QUEUED foundation run" bash -c \
    "[[ '$foundation_start' == 202* && '$foundation_start' == *'\"result_type\":\"foundation\"'* && '$foundation_start' == *'\"status\":\"QUEUED\"'* ]]"
check "foundation run reaches SUCCEEDED (queue worker -> analyzer -> verified result)" wait_for_run "$foundation_run" SUCCEEDED
static_start=$(api_json POST "/api/v1/projects/$project_id/analyses" "{\"source_snapshot_id\":\"$snapshot_id\",\"result_type\":\"static_analysis\"}")
static_run=$(json_field '["data"]["id"]' <<< "$static_start" 2>/dev/null)
export static_run
check "POST analyses (static_analysis) -> 202, a separate run" bash -c "[[ '$static_start' == 202* && '$static_run' != '$foundation_run' ]]"
check "static_analysis run reaches SUCCEEDED" wait_for_run "$static_run" SUCCEEDED
# Phase 11: the worker scores the stored static_analysis result (the probe
# project is too small for an overall score: INSUFFICIENT_DATA).
# Scoring follows the run's SUCCEEDED commit in the same job: poll briefly.
static_dna=''
for _ in $(seq 1 20); do
    static_dna=$(db "SELECT d.scoring_version || ' ' || d.status || ' ' || (d.result_hash = r.result_hash) FROM dna_snapshots d JOIN analysis_runs r ON r.id = d.analysis_run_id WHERE d.analysis_run_id = '$static_run'" 2>/dev/null)
    [[ -n "$static_dna" ]] && break
    sleep 0.5
done
foundation_dna=$(db "SELECT count(*) FROM dna_snapshots WHERE analysis_run_id = '$foundation_run'" 2>/dev/null)
check "static_analysis run has one DNA snapshot (scoring 1.0.0, same result_hash)" bash -c "[[ '$static_dna' == '1.0.0 INSUFFICIENT_DATA true' ]]"
check "foundation run is not scored" bash -c "[[ '$foundation_dna' == 0 ]]"
# Phase 12: the read-only DNA API serves it to the owner, with the stored decimal strings.
check "GET dna -> 200, the static run's snapshot, scores as 4-place strings, no storage details" bash -c \
    "out=\$(api_json GET /api/v1/projects/$project_id/dna); [[ \$out == 200* && \$out == *'\"analysis_run_id\":\"$static_run\"'* && \$out == *'\"data_quality\":\"0.'* && \$out != *storage* && \$out != *X-Amz* ]]"
check "POST dna -> 405 (read-only)" bash -c "api_json POST /api/v1/projects/$project_id/dna '{}' | grep -q '^405 '"
# Phase 13: the worker derives the competency matrix from the DNA snapshot; read-only API.
static_competency=''
for _ in $(seq 1 20); do
    static_competency=$(db "SELECT c.competency_version || ' ' || c.status || ' ' || (c.dna_snapshot_id = d.id) FROM competency_snapshots c JOIN dna_snapshots d ON d.id = c.dna_snapshot_id WHERE d.analysis_run_id = '$static_run'" 2>/dev/null)
    [[ -n "$static_competency" ]] && break
    sleep 0.5
done
check "static_analysis run has one competency snapshot (competency 1.0.0, linked to its DNA)" bash -c "[[ '$static_competency' == '1.0.0 '*' true' ]]"
check "GET competencies -> 200 with the snapshot; POST -> 405 (read-only)" bash -c \
    "out=\$(api_json GET /api/v1/projects/$project_id/competencies); [[ \$out == 200* && \$out == *'\"competency_version\":\"1.0.0\"'* && \$out != *storage* ]] && api_json POST /api/v1/projects/$project_id/competencies '{}' | grep -q '^405 '"
# Phase 14: the worker compares the matrix with the target profile; read-only API.
static_skill_gap=''
for _ in $(seq 1 20); do
    static_skill_gap=$(db "SELECT g.skill_gap_version || ' ' || g.target_profile || ' ' || (SELECT count(*) FROM skill_gap_results r WHERE r.skill_gap_snapshot_id = g.id) FROM skill_gap_snapshots g JOIN competency_snapshots c ON c.id = g.competency_snapshot_id JOIN dna_snapshots d ON d.id = c.dna_snapshot_id WHERE d.analysis_run_id = '$static_run'" 2>/dev/null)
    [[ -n "$static_skill_gap" ]] && break
    sleep 0.5
done
check "static_analysis run has one skill gap snapshot (1.0.0, ENGINEERING_STANDARD, 4 results)" bash -c "[[ '$static_skill_gap' == '1.0.0 ENGINEERING_STANDARD 4' ]]"
check "GET skill-gaps -> 200 with the snapshot; POST with a target -> 405 (read-only)" bash -c \
    "out=\$(api_json GET /api/v1/projects/$project_id/skill-gaps); [[ \$out == 200* && \$out == *'\"target_profile\":{\"key\":\"ENGINEERING_STANDARD\"'* && \$out != *storage* ]] && api_json POST /api/v1/projects/$project_id/skill-gaps '{\"target\":0.95}' | grep -q '^405 '"
# Phase 16: coding challenges. The evaluator is isolated: no network at all,
# read-only root, unprivileged sandbox users; the API never runs code.
evaluator_offline() { [[ $(in_service evaluator ls /sys/class/net) == lo ]]; }
evaluator_locked_down() {
    ! in_service evaluator touch /probe &&
        in_service evaluator grep -Eq '^CapEff:[[:space:]]+00000000000000e0$' /proc/1/status &&
        ! in_service evaluator env | cut -d= -f1 | grep -Eq '^(DB_|REDIS_|AWS_|APP_KEY|AI_|MINIO_|POSTGRES_)|SECRET|PASSWORD|TOKEN'
}
check "evaluator has no network interface but loopback" evaluator_offline
check "evaluator: read-only root, only SETUID/SETGID/KILL capabilities, no credentials in its environment" evaluator_locked_down
check "GET challenges -> 200; POST with a definition -> 422 (server-owned catalog)" bash -c \
    "api_json GET /api/v1/projects/$project_id/challenges | grep -q '^200 ' && api_json POST /api/v1/projects/$project_id/challenges '{\"challenge_definition\":{\"key\":\"X\"}}' | grep -q '^422 '"
# Phase 17: learning roadmaps are generated on request from the newest skill gap analysis,
# deterministically; content is server-owned, so any request field is refused.
check "GET roadmaps -> 200; POST with content -> 422; POST {} -> 201 or 409 ROADMAP_NO_ACTIONABLE_GAPS, then idempotent" bash -c \
    "api_json GET /api/v1/projects/$project_id/roadmaps | grep -q '^200 ' && api_json POST /api/v1/projects/$project_id/roadmaps '{\"tracks\":[]}' | grep -q '^422 ' && out=\$(api_json POST /api/v1/projects/$project_id/roadmaps '{}'); if [[ \$out == 201* ]]; then api_json POST /api/v1/projects/$project_id/roadmaps '{}' | grep -q '^200 '; else [[ \$out == *ROADMAP_NO_ACTIONABLE_GAPS* ]]; fi"
# Phase 15: AI assessment is opt-in (AI_ENABLED=false by default); the list is readable either way.
check "GET assessments -> 200; POST -> 409 AI_ASSESSMENT_DISABLED unless AI is enabled" bash -c \
    "api_json GET /api/v1/projects/$project_id/assessments | grep -q '^200 ' && out=\$(api_json POST /api/v1/projects/$project_id/assessments '{}'); [[ \$out == 202* || \$out == *AI_ASSESSMENT_DISABLED* ]]"
check "repeating a request returns the existing run (200, same ID)" bash -c \
    "out=\$(api_json POST /api/v1/projects/$project_id/analyses '{\"source_snapshot_id\":\"$snapshot_id\"}'); [[ \$out == 200* && \$out == *'$foundation_run'* ]]"
check "GET result -> verified IR 1.1 static analysis with metrics, no source or URLs" bash -c \
    "out=\$(api_json GET /api/v1/projects/$project_id/analyses/$static_run/result); [[ \$out == 200* && \$(json_field '[\"data\"][\"result\"][\"ir\"][\"version\"]' <<< \"\$out\") == 1.1 && \$(json_field '[\"data\"][\"result\"][\"parsing\"][\"files\"][\"PARSED\"]' <<< \"\$out\") == 1 && \$out != *echo* && \$out != *X-Amz* && \$out != *minio* ]]"
check "invalid result_type -> 422 VALIDATION_FAILED" bash -c \
    "api_json POST /api/v1/projects/$project_id/analyses '{\"source_snapshot_id\":\"$snapshot_id\",\"result_type\":\"full\"}' | grep -q '^422 .*VALIDATION_FAILED'"
check "no analysis jobs left in Redis" bash -c \
    "[[ \$(${compose[*]} exec -T redis redis-cli --raw LLEN codedna-database-queues:analysis) == 0 && \$(${compose[*]} exec -T redis redis-cli --raw ZCARD codedna-database-queues:analysis:reserved) == 0 ]]"
check "cross-site upload without X-XSRF-TOKEN -> 419" bash -c \
    "[[ \$(curl -s -o /dev/null -w '%{http_code}' -b '$jar' -H '$origin' -H 'Sec-Fetch-Site: cross-site' -H 'Accept: application/json' -F 'archive=@$upload_dir/source.zip' '$base/api/v1/projects/$project_id/source-snapshots') == 419 ]]"
check "non-ZIP upload -> 422 SOURCE_ARCHIVE_INVALID" bash -c \
    "upload_archive '$upload_dir/fake.zip' | grep -q '^422 .*SOURCE_ARCHIVE_INVALID'"
check "body above the Nginx limit -> 413 PAYLOAD_TOO_LARGE (JSON envelope)" bash -c \
    "upload_archive '$upload_dir/huge.zip' | grep -q '^413 .*\"code\":\"PAYLOAD_TOO_LARGE\"'"
check "POST /api/v1/projects/{project}/archive -> 200" bash -c "[[ \$(api POST /api/v1/projects/$project_id/archive) == 200 ]]"
check "archived project rejects uploads -> 409 PROJECT_ARCHIVED" bash -c \
    "upload_archive '$upload_dir/source.zip' | grep -q '^409 .*PROJECT_ARCHIVED'"
check "archived project cannot start analyses -> 409 PROJECT_ARCHIVED" bash -c \
    "api_json POST /api/v1/projects/$project_id/analyses '{\"source_snapshot_id\":\"$snapshot_id\",\"result_type\":\"static_analysis\"}' | grep -q '^409 .*PROJECT_ARCHIVED'"
rm -rf "$jar" "$upload_dir"
if [[ -n "$project_id" ]]; then
    check "remove probe objects from MinIO" mc_app "mc rm --recursive --force \"app/\$SOURCE_STORAGE_BUCKET/projects/$project_id/\""
fi
check "remove probe rows (roadmaps, challenges, AI assessments, skill gaps, competencies, DNA, results, runs, snapshots, project, profile, user)" db \
    "BEGIN; DELETE FROM roadmap_step_completions WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM roadmap_steps WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM roadmap_snapshots WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM challenge_submissions WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM challenge_instances WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM ai_assessments WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM skill_gap_results WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM skill_gap_snapshots WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM competency_snapshots WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM dna_snapshots WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM analysis_results WHERE analysis_run_id IN (SELECT r.id FROM analysis_runs r JOIN projects p ON p.id = r.project_id JOIN users u ON u.id = p.user_id WHERE u.email = '$probe_email'); DELETE FROM analysis_runs WHERE project_id IN (SELECT p.id FROM projects p JOIN users u ON u.id = p.user_id WHERE u.email = '$probe_email'); DELETE FROM source_snapshots WHERE project_id IN (SELECT p.id FROM projects p JOIN users u ON u.id = p.user_id WHERE u.email = '$probe_email'); DELETE FROM projects WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM developer_profiles WHERE user_id IN (SELECT id FROM users WHERE email = '$probe_email'); DELETE FROM users WHERE email = '$probe_email'; COMMIT;"

echo "Analyzer service (Phases 08-09)"
check "analyzer publishes no host port" bash -c \
    "[[ \$(docker inspect --format '{{range \$p, \$b := .NetworkSettings.Ports}}{{if \$b}}{{\$p}} {{end}}{{end}}' \$(${compose[*]} ps -q analyzer)) == '' ]]"
check "health reports versions and limits only" bash -c \
    "${compose[*]} exec -T backend curl -fsS http://analyzer:8000/internal/v1/health | python3 -c 'import json, sys; d = json.load(sys.stdin); sys.exit(not (set(d) == {\"status\", \"versions\", \"limits\"} and d[\"versions\"][\"contract\"] == \"1.0\"))'"
check "unsigned POST /internal/v1/analyze -> 401" bash -c \
    "[[ \$(${compose[*]} exec -T backend curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' http://analyzer:8000/internal/v1/analyze) == 401 ]]"
analyzer_zip=$(python3 -I -c 'import base64, io, zipfile
buffer = io.BytesIO()
with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archive:
    archive.writestr("src/index.php", "<?php echo 1;\n")
    archive.writestr("src/tool.py", "print(1)\n")
    archive.writestr("README.md", "# verify\n")
print(base64.b64encode(buffer.getvalue()).decode())')
# Laravel -> analyzer over the internal network: pre-signed MinIO URL, HMAC,
# download, extraction, discovery, parsing, metrics, signed response
# (scripts/verify-analyzer.php).
while IFS= read -r line; do
    case "$line" in
        "PASS "*) pass "Laravel -> analyzer: ${line#PASS }" ;;
        "FAIL "*) fail "Laravel -> analyzer: ${line#FAIL }" ;;
    esac
done < <("${compose[@]}" exec -T -e VERIFY_ZIP_BASE64="$analyzer_zip" backend php /dev/stdin < scripts/verify-analyzer.php 2>/dev/null || echo "FAIL integration script exited with an error")
check "analyzer workspace is empty afterwards" bash -c "[[ -z \$(${compose[*]} exec -T analyzer ls -A /tmp/codedna) ]]"

echo
if [[ $failures -eq 0 ]]; then
    echo "All infrastructure checks passed."
else
    echo "$failures check(s) failed."
    exit 1
fi
