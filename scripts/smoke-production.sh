#!/usr/bin/env bash
# Production-like smoke test (Phase 25, docs/testing/test-strategy.md#production-smoke-test).
#
# Starts docker-compose.prod.yml locally with throwaway values: random
# secrets, a self-signed certificate for the reserved name codedna.test,
# loopback-only ports. Then checks, from outside and inside the stack:
#
#   - TLS edge: HTTPS only, HSTS, security headers, nonce CSP on pages,
#     strict CSP on the API, redirect pinned to the configured domain,
#     unknown names and spoofed Host headers refused, /internal and dotfiles
#     404, TLS < 1.2 refused, error bodies without internals;
#   - forwarded headers: a spoofed X-Forwarded-Proto/Host never weakens
#     cookies, and rotating spoofed client IPs never escape the login limit;
#   - the evaluator: without an attested gVisor runtime it refuses to start
#     and Laravel reports challenge evaluation unavailable (fail closed);
#     with gVisor (runsc registered) it must become healthy;
#   - containers: only Nginx publishes ports; read-only root filesystems,
#     non-root users, no-new-privileges, dropped capabilities, resource
#     limits, no bind mounts except the TLS secrets; network segmentation;
#   - fail fast: unsafe overrides stop the backend at start;
#   - local AI (Phase 29): ai-worker hardened like the other workers, the
#     Ollama runtime opt-in, AI off by default, remote endpoints refused
#     without the explicit opt-in;
#   - no generated secret appears in logs, image metadata or the frontend.
#
# Nothing here is a real credential; everything is removed at the end
# (containers, volumes, the temporary directory).
#
#   scripts/smoke-production.sh             # build the images, then test
#   SMOKE_BUILD=0 scripts/smoke-production.sh   # use images already built
#                                              # (tag: $APP_VERSION, default "smoke")
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
VERSION=${APP_VERSION:-smoke}
PROJECT=codedna-smoke
HTTPS_PORT=${SMOKE_HTTPS_PORT:-443}
HTTP_PORT=${SMOKE_HTTP_PORT:-18080}
DOMAIN=codedna.test
failures=0

dc() { docker compose -f "$ROOT/docker-compose.prod.yml" --env-file "$WORK/prod.env" "$@"; }
pass() { printf 'PASS %s\n' "$1"; }
fail() { printf 'FAIL %s%s\n' "$1" "${2:+  ($2)}"; failures=$((failures + 1)); }
expect() { # name actual expected
    if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "got '$2', expected '$3'"; fi
}
https() { curl --noproxy '*' -s --resolve "$DOMAIN:$HTTPS_PORT:127.0.0.1" --cacert "$WORK/tls.crt" "$@"; }
url() { printf 'https://%s:%s%s' "$DOMAIN" "$HTTPS_PORT" "$1"; }

cleanup() {
    dc down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

# --- Throwaway configuration ---------------------------------------------------
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$WORK/tls.key" -out "$WORK/tls.crt" -days 1 \
    -subj "/CN=$DOMAIN" -addext "subjectAltName=DNS:$DOMAIN" 2>/dev/null
# A throwaway key: readable by the container's nginx user (real keys: root:101, 0640).
chmod 0644 "$WORK/tls.key"
runtime=runc
if docker info --format '{{json .Runtimes}}' | grep -q '"runsc"'; then runtime=runsc; fi
random() { openssl rand -hex 24; }
cat > "$WORK/prod.env" <<EOF
COMPOSE_PROJECT_NAME=$PROJECT
APP_VERSION=$VERSION
CODEDNA_DOMAIN=$DOMAIN
TLS_CERTIFICATE_FILE=$WORK/tls.crt
TLS_PRIVATE_KEY_FILE=$WORK/tls.key
PUBLIC_BIND_ADDRESS=127.0.0.1
HTTP_PORT=$HTTP_PORT
HTTPS_PORT=$HTTPS_PORT
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=$(random)
REDIS_PASSWORD=$(random)
SOURCE_STORAGE_ACCESS_KEY_ID=smoke$(openssl rand -hex 8)
SOURCE_STORAGE_SECRET_ACCESS_KEY=$(random)
MINIO_ROOT_USER=smokeroot$(openssl rand -hex 4)
MINIO_ROOT_PASSWORD=$(random)
ANALYZER_HMAC_SECRET=$(openssl rand -hex 32)
EVALUATOR_RUNTIME=$runtime
EOF

echo "== configuration"
dc config --quiet && pass "docker-compose.prod.yml renders with a complete environment"
if docker compose -f "$ROOT/docker-compose.prod.yml" --env-file /dev/null config --quiet >/dev/null 2>&1; then
    fail "an empty environment is refused"
else
    pass "an empty environment is refused (required variables)"
fi

if [ "${SMOKE_BUILD:-1}" = "1" ]; then
    echo "== build"
    dc build
fi

echo "== start"
dc up -d --no-build --wait --wait-timeout 300 nginx frontend backend queue ai-worker scheduler analyzer postgres redis minio
dc run --rm migrate >/dev/null
dc up -d --no-build evaluator
pass "stack started and migrations applied with the explicit one-shot"

echo "== TLS edge"
expect "health over https" "$(https -o /dev/null -w '%{http_code}' "$(url /api/v1/health)")" 200
headers=$(https -D - -o "$WORK/page.html" "$(url /login)")
if grep -qi '^strict-transport-security: max-age=31536000' <<<"$headers"; then
    pass "HSTS on https"
else
    fail "HSTS on https"
fi
if grep -qiE "^content-security-policy: default-src 'self'; script-src 'self' 'nonce-[A-Za-z0-9+/=]+' 'strict-dynamic'" <<<"$headers"; then
    pass "pages carry the nonce CSP"
else
    fail "pages carry the nonce CSP"
fi
nonce=$(grep -oiE "'nonce-[A-Za-z0-9+/=]+'" <<<"$headers" | head -1 | sed -E "s/'nonce-(.*)'/\1/")
scripts=$(grep -o '<script' "$WORK/page.html" | wc -l)
nonced=$(grep -o "<script[^>]*nonce=\"$nonce\"" "$WORK/page.html" | wc -l)
if [ "$scripts" -gt 0 ] && [ "$scripts" = "$nonced" ]; then
    pass "every script carries this response's nonce ($scripts)"
else
    fail "every script carries the nonce" "$nonced of $scripts"
fi
second=$(https -D - -o /dev/null "$(url /login)" | grep -oiE "'nonce-[A-Za-z0-9+/=]+'" | head -1)
if [ -n "$second" ] && [ "$second" != "'nonce-$nonce'" ]; then
    pass "a fresh nonce per response"
else
    fail "a fresh nonce per response"
fi
if grep -qiE "unsafe-eval" <<<"$headers"; then
    fail "no unsafe-eval"
else
    pass "no unsafe-eval"
fi
for header in 'x-content-type-options: nosniff' 'x-frame-options: DENY' 'referrer-policy: strict-origin-when-cross-origin'; do
    if grep -qi "^$header" <<<"$headers"; then
        pass "header $header"
    else
        fail "header $header"
    fi
done
if grep -qiE '^(x-powered-by|server: nginx/)' <<<"$headers"; then
    fail "no version or framework disclosure"
else
    pass "no version or framework disclosure"
fi
api=$(https -D - -o /dev/null "$(url /api/v1/health)")
if grep -qi "^content-security-policy: default-src 'none'" <<<"$api"; then
    pass "API responses: default-src 'none'"
else
    fail "API responses: default-src 'none'"
fi
expect "http redirects to the configured domain, whatever the Host" \
    "$(curl --noproxy '*' -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: evil.example' "http://127.0.0.1:$HTTP_PORT/app")" \
    "301 https://$DOMAIN/app"
expect "spoofed Host after a valid handshake: 421" "$(https -o /dev/null -w '%{http_code}' -H 'Host: evil.example' "$(url /api/v1/health)")" 421
if curl --noproxy '*' -s -o /dev/null -k --resolve "evil.example:$HTTPS_PORT:127.0.0.1" "https://evil.example:$HTTPS_PORT/"; then
    fail "unknown server names: handshake rejected"
else
    pass "unknown server names: handshake rejected"
fi
if https -o /dev/null --tlsv1.1 --tls-max 1.1 "$(url /)"; then fail "TLS 1.1 refused"; else pass "TLS 1.1 refused"; fi
expect "/internal is never routed" "$(https -o /dev/null -w '%{http_code}' "$(url /internal/v1/health)")" 404
expect "dotfiles are never routed" "$(https -o /dev/null -w '%{http_code}' "$(url /.env)")" 404
body=$(https "$(url /api/v1/does-not-exist)")
if grep -q '"code":"RESOURCE_NOT_FOUND"' <<<"$body" && ! grep -qiE 'vendor/|/var/www|stack|trace|SQLSTATE|exception' <<<"$body"; then
    pass "error bodies carry no internals"
else
    fail "error bodies carry no internals" "$body"
fi

echo "== uploads (Phase 30)"
# A signed-in user uploads archives near the 50 MiB limit through the TLS
# edge: one alone, then two at the same time. Every request body is buffered
# by Nginx and by PHP before Laravel sees it, so this proves the temporary
# space of both containers, not only the configured size limits.
ujar="$WORK/upload-cookies"
https -c "$ujar" -o /dev/null "$(url /sanctum/csrf-cookie)"
uxsrf() { awk '$6 == "XSRF-TOKEN" {print $7}' "$ujar" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))'; }
uapi() { # method path [curl args...]
    local method=$1 path=$2
    shift 2
    https -b "$ujar" -c "$ujar" -X "$method" "$(url "$path")" -H 'Accept: application/json' -H "Origin: https://$DOMAIN" \
        -H "Referer: https://$DOMAIN/app" -H "X-XSRF-TOKEN: $(uxsrf)" "$@"
}
registered=$(uapi POST /api/v1/auth/register -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' \
    --data '{"name":"Smoke Uploader","email":"smoke-upload@example.invalid","password":"Smoke-upload-pass-1","password_confirmation":"Smoke-upload-pass-1"}')
expect "registration through the edge" "$registered" 201
project=$(uapi POST /api/v1/projects -H 'Content-Type: application/json' \
    --data '{"name":"Smoke upload","slug":"smoke-upload","source_type":"UPLOAD"}' | python3 -c 'import json, sys; print(json.load(sys.stdin)["data"]["id"])' 2>/dev/null || true)
python3 - "$WORK/large.zip" <<'PY'
import os, sys, zipfile
# 45 MiB of incompressible content: the body Nginx and PHP must buffer.
with zipfile.ZipFile(sys.argv[1], "w", zipfile.ZIP_STORED) as archive:
    archive.writestr("src/main.py", "def main():\n    return 1\n")
    for i in range(45):
        archive.writestr(f"assets/blob-{i:02d}.bin", os.urandom(1024 * 1024))
PY
upload() { uapi POST "/api/v1/projects/$project/source-snapshots" -o /dev/null -w '%{http_code}' -F "archive=@$WORK/large.zip;type=application/zip"; }
if [ -n "$project" ]; then
    expect "a 45 MiB upload is accepted" "$(upload)" 201
    upload > "$WORK/upload-a" & first=$!
    upload > "$WORK/upload-b" & second=$!
    wait "$first" "$second" || true
    expect "two concurrent 45 MiB uploads are accepted" "$(cat "$WORK/upload-a") $(cat "$WORK/upload-b")" "201 201"
else
    fail "project created for the upload checks"
fi

echo "== forwarded headers"
jar="$WORK/cookies"
cookies=$(https -c "$jar" -o /dev/null -D - -H 'X-Forwarded-Proto: http' -H 'X-Forwarded-Host: evil.example' -H 'X-Forwarded-Port: 80' "$(url /sanctum/csrf-cookie)" | grep -i '^set-cookie')
if grep -i 'codedna-session=' <<<"$cookies" | grep -qi 'secure; httponly; samesite=lax'; then
    pass "session cookie stays Secure, HttpOnly, SameSite=Lax under spoofed X-Forwarded-*"
else
    fail "session cookie flags under spoofed X-Forwarded-*" "$cookies"
fi
if grep -qi 'domain=evil' <<<"$cookies"; then
    fail "no cookie for a spoofed host"
else
    pass "no cookie for a spoofed host"
fi
xsrf=$(awk '$6 == "XSRF-TOKEN" {print $7}' "$jar" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))')
codes=""
for i in $(seq 1 25); do
    codes="$codes $(https -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$(url /api/v1/auth/login)" \
        -H 'Content-Type: application/json' -H 'Accept: application/json' -H "Origin: https://$DOMAIN" -H "Referer: https://$DOMAIN/login" \
        -H "X-XSRF-TOKEN: $xsrf" -H "X-Forwarded-For: 203.0.113.$i" -H "X-Real-IP: 198.51.100.$i" -H "Forwarded: for=192.0.2.$i" \
        --data "{\"email\":\"nobody-$i@example.invalid\",\"password\":\"not-the-password\"}")"
done
if grep -q 429 <<<"$codes"; then
    pass "rotating spoofed client IPs still hit the per-IP login limit"
else
    fail "spoofed IPs escaped the login limit" "$codes"
fi

echo "== evaluator (runtime: $runtime)"
sleep 8
if [ "$runtime" = runsc ]; then
    if dc up -d --no-build --wait --wait-timeout 120 evaluator >/dev/null; then
        pass "evaluator healthy under gVisor (attested)"
    else
        fail "evaluator healthy under gVisor"
    fi
else
    if dc logs evaluator 2>&1 | grep -q 'evaluator.refused reason=EVALUATOR_ISOLATION=gvisor but the runtime provides container'; then
        pass "evaluator refuses to run without gVisor (fail closed)"
    else
        fail "evaluator refuses to run without gVisor"
    fi
    if dc exec -T backend test -e /var/spool/codedna-challenges/heartbeat 2>/dev/null; then
        fail "no heartbeat without gVisor"
    else
        pass "no heartbeat without gVisor: Laravel treats evaluation as unavailable"
    fi
    # shellcheck disable=SC2016 # PHP code, not shell expansions
    available=$(dc exec -T backend php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo $app->make(App\Services\Challenge\Evaluator\ChallengeEvaluator::class)->available() ? "yes" : "no";' 2>/dev/null | tail -1)
    expect "Laravel: challenge evaluator unavailable" "$available" no
fi

echo "== containers"
published=$(for c in $(dc ps -q); do docker inspect "$c" --format '{{.Name}} {{range $p, $b := .HostConfig.PortBindings}}{{$p}} {{end}}'; done | awk 'NF > 1 {print $1}' | tr '\n' ' ')
expect "only nginx publishes ports" "$published" "/$PROJECT-nginx-1 "
for c in $(dc ps -q); do
    name=$(docker inspect "$c" --format '{{.Name}}')
    hardening=$(docker inspect "$c" --format '{{.HostConfig.ReadonlyRootfs}} {{.HostConfig.SecurityOpt}} {{.HostConfig.CapDrop}} {{.HostConfig.Memory}} {{.HostConfig.PidsLimit}}')
    if grep -qE '^true \[no-new-privileges:true\] \[ALL\] [1-9][0-9]* [1-9]' <<<"$hardening"; then
        pass "$name: read-only, no-new-privileges, caps dropped, limits"
    else
        fail "$name: hardening" "$hardening"
    fi
    # Compose secrets are read-only bind mounts: the TLS files (Nginx) and the
    # enterprise license (Laravel containers, Phase 27). Nothing else.
    binds=$(docker inspect "$c" --format '{{range .Mounts}}{{if eq .Type "bind"}}{{.Destination}} {{end}}{{end}}' | sed -e 's#/run/secrets/tls_[a-z_]*##g' -e 's#/run/secrets/codedna_license##g' | tr -d ' ')
    expect "$name: no bind mounts (secrets aside)" "$binds" ""
    writable=$(docker inspect "$c" --format '{{range .Mounts}}{{if and (eq .Type "bind") .RW}}{{.Destination}} {{end}}{{end}}' | tr -d ' ')
    expect "$name: secrets are read-only" "$writable" ""
done
for svc in nginx frontend backend queue ai-worker scheduler analyzer redis minio; do
    uid=$(dc exec -T "$svc" id -u)
    if [ "$uid" != 0 ]; then
        pass "$svc runs as uid $uid"
    else
        fail "$svc runs as root"
    fi
done
expect "evaluator: no network" "$(docker inspect "$PROJECT-evaluator-1" --format '{{.HostConfig.NetworkMode}}')" none
reach() { # service host port -> reachable|blocked
    dc exec -T "$1" php -r "echo @fsockopen('$2', $3, \$e, \$m, 3) ? 'reachable' : 'blocked';" 2>/dev/null || echo blocked
}
pyreach() { # service host port
    dc exec -T "$1" python3 -c "import socket
try:
    socket.create_connection(('$2', $3), 3); print('reachable')
except Exception:
    print('blocked')" 2>/dev/null || echo blocked
}
noreach() { # service host port
    dc exec -T "$1" node -e "const s=require('net').connect($3,'$2');s.setTimeout(3000);s.on('connect',()=>{console.log('reachable');process.exit(0)});s.on('error',()=>{console.log('blocked');process.exit(0)});s.on('timeout',()=>{console.log('blocked');process.exit(0)})" 2>/dev/null || echo blocked
}
for target in "backend 9000" "postgres 5432" "redis 6379" "nginx 8443" "1.1.1.1 443"; do
    read -r host port <<<"$target"
    expect "analyzer cannot reach $target" "$(pyreach analyzer "$host" "$port")" blocked
done
expect "analyzer reaches object storage" "$(pyreach analyzer minio 9000)" reachable
for target in "backend 9000" "postgres 5432" "redis 6379" "minio 9000" "analyzer 8000" "1.1.1.1 443"; do
    read -r host port <<<"$target"
    expect "frontend cannot reach $target" "$(noreach frontend "$host" "$port")" blocked
done
expect "backend cannot reach the analyzer" "$(reach backend analyzer 8000)" blocked
expect "scheduler has no internet" "$(reach scheduler 1.1.1.1 443)" blocked
expect "ai-worker cannot reach the analyzer" "$(reach ai-worker analyzer 8000)" blocked

echo "== local AI (Phase 29)"
expect "the local AI runtime is opt-in (profile local-ai)" "$(dc ps --services | grep -c '^ollama$' || true)" 0
ai_status=$(dc exec -T backend php artisan ai:status --json 2>&1 || true)
if grep -q '"enabled": false' <<<"$ai_status"; then
    pass "AI is disabled by default"
else
    fail "AI is disabled by default" "$ai_status"
fi
# Control: AI enabled with the internal default endpoint starts (no runtime needed to boot).
if dc run --rm --no-deps -e AI_ENABLED=true backend php -r 'exit(0);' >/dev/null 2>&1; then
    pass "backend starts with AI enabled on the internal endpoint"
else
    fail "backend starts with AI enabled on the internal endpoint"
fi
for override in 'AI_BASE_URL=http://ai.example.com:11434' 'AI_BASE_URL=https://ai.example.com/v1'; do
    if dc run --rm --no-deps -e AI_ENABLED=true -e "$override" backend php -r 'exit(0);' >/dev/null 2>&1; then
        fail "backend refuses a remote AI endpoint without the opt-in ($override)"
    else
        pass "backend refuses a remote AI endpoint without the opt-in ($override)"
    fi
done

echo "== fail fast"
# Control: the unchanged configuration starts, so each refusal below is real.
if dc run --rm --no-deps backend php -r 'exit(0);' >/dev/null 2>&1; then
    pass "backend starts with the valid configuration"
else
    fail "backend starts with the valid configuration"
fi
# Phase 27: an external database or Redis without TLS, and invalid registration settings.
for override in 'APP_DEBUG=true' 'LOG_LEVEL=debug' 'TRUSTED_PROXIES=*' 'CHALLENGE_EVALUATOR_ISOLATION=container' 'CORS_ALLOWED_ORIGINS=http://evil.example' 'BILLING_PROVIDER=fake' 'APP_KEY=' \
    'DB_HOST=db.customer.example' 'REDIS_HOST=redis.customer.example' 'REGISTRATION_MODE=invite' 'REGISTRATION_MODE=restricted'; do
    if dc run --rm --no-deps -e "$override" backend php -r 'exit(0);' >/dev/null 2>&1; then
        fail "backend refuses to start with $override"
    else
        pass "backend refuses to start with $override"
    fi
done

echo "== self-hosted (Phase 27)"
license=$(dc exec -T backend php artisan codedna:license 2>&1 || true)
if grep -q 'Edition: Community' <<<"$license" && grep -q 'License: ABSENT' <<<"$license"; then
    pass "no license configured: Community edition, status ABSENT"
else
    fail "no license configured: Community edition, status ABSENT" "$license"
fi
if dc exec -T backend php artisan codedna:preflight >/dev/null 2>&1; then
    pass "codedna:preflight passes on the running stack"
else
    fail "codedna:preflight passes on the running stack"
fi
expect "the license secret is empty by default" "$(dc exec -T backend sh -c 'wc -c < /run/secrets/codedna_license' | tr -d ' ')" "0"

echo "== secrets"
leaks=0
while IFS='=' read -r name value; do
    value=${value#base64:}
    [ -n "$value" ] || continue
    if dc logs --no-color 2>/dev/null | grep -qF -- "$value"; then fail "$name absent from logs"; leaks=1; fi
    for image in backend frontend analyzer evaluator nginx; do
        docker image inspect "codedna-$image:$VERSION" 2>/dev/null | grep -qF -- "$value" && { fail "$name absent from the $image image"; leaks=1; }
    done
    dc exec -T frontend sh -c "grep -rqF -- '$value' /app" && { fail "$name absent from the frontend"; leaks=1; }
done < <(grep -E '^(APP_KEY|DB_PASSWORD|REDIS_PASSWORD|SOURCE_STORAGE_SECRET_ACCESS_KEY|MINIO_ROOT_PASSWORD|ANALYZER_HMAC_SECRET)=' "$WORK/prod.env")
[ "$leaks" = 0 ] && pass "no generated secret in logs, image metadata or the frontend"

echo
if [ "$failures" -eq 0 ]; then
    echo "Production smoke test passed."
else
    echo "Production smoke test FAILED: $failures check(s)."
    exit 1
fi
