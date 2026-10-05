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
