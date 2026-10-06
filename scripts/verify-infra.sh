#!/usr/bin/env bash
# Runtime smoke test for the Docker development environment (Phase 02).
#
# Run after `make up` (all services healthy). Verifies, without any product
# feature: service health, Nginx single-origin routing, internal networking
# and isolation, Laravel <-> PostgreSQL/Redis, and MinIO S3 access including a
# pre-signed URL download performed by the analyzer.
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
for service in nginx frontend backend analyzer postgres redis minio; do
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

echo
if [[ $failures -eq 0 ]]; then
    echo "All infrastructure checks passed."
else
    echo "$failures check(s) failed."
    exit 1
fi
