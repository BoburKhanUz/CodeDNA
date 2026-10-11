#!/usr/bin/env bash
# V1.0.0 production preflight (make prod-preflight): run on the production
# host after the migration job and before starting the application
# (docs/operations/production-deployment.md, step 14a).
#
#   scripts/preflight-production.sh                      # /etc/codedna/production.env
#   CODEDNA_ENV_FILE=/path/to/production.env scripts/preflight-production.sh
#   PREFLIGHT_STATIC_ONLY=1 scripts/preflight-production.sh   # no containers
#
# Checks, against the configuration `docker compose up` would use:
#   1. V1.0.0 scope: AI_ENABLED=false, CHALLENGE_ENABLED=false,
#      CHALLENGE_EVALUATOR=none, and neither the challenges nor the local-ai
#      Compose profile active (so no evaluator and no Ollama container).
#   2. The production baseline (scripts/check_production.py).
#   3. Runtime (data services running and migrations applied, steps 12-14):
#      the backend boots with this configuration (boot validation), reaches
#      PostgreSQL, Redis and object storage with no migration pending (php
#      artisan codedna:preflight), and AI is off (php artisan ai:status, which
#      contacts nothing while AI is disabled).
#
# Read-only: nothing is written, migrated or started except one-off backend
# containers that are removed. Prints only the scope settings above, never
# secrets. Exits non-zero on any failure.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
ENV_FILE=${CODEDNA_ENV_FILE:-/etc/codedna/production.env}
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
failures=0

pass() { printf 'PASS %s\n' "$1"; }
fail() { printf 'FAIL %s%s\n' "$1" "${2:+  ($2)}"; failures=$((failures + 1)); }
dc() { docker compose -f "$ROOT/docker-compose.prod.yml" --env-file "$ENV_FILE" "$@"; }

if [[ ! -r "$ENV_FILE" ]]; then
    echo "preflight: FAIL: cannot read $ENV_FILE (set CODEDNA_ENV_FILE)" >&2
    exit 1
fi
if [[ $(stat -c '%a' "$ENV_FILE") =~ [1-7]$ ]]; then
    fail "the environment file is not readable by other users" "chmod 600 $ENV_FILE"
else
    pass "the environment file is not readable by other users"
fi

echo "== scope (V1.0.0)"
if ! dc config --format json > "$WORK/default.json" 2> "$WORK/render.err"; then
    echo "preflight: FAIL: docker-compose.prod.yml does not render with $ENV_FILE:" >&2
    # Compose names the missing or invalid variable; values are not printed.
    grep -v '=' "$WORK/render.err" >&2 || true
    exit 1
fi
python3 - "$WORK/default.json" <<'EOF' || failures=$((failures + 1))
import json, sys
config = json.load(open(sys.argv[1]))
services = config["services"]
environment = services["backend"].get("environment") or {}
failed = False
for key, expected in (("AI_ENABLED", "false"), ("CHALLENGE_ENABLED", "false"), ("CHALLENGE_EVALUATOR", "none")):
    actual = environment.get(key)
    ok = actual == expected
    failed |= not ok
    print(f"{'PASS' if ok else 'FAIL'} {key}={actual}" + ("" if ok else f"  (V1.0.0 requires {expected})"))
for service, profile in (("evaluator", "challenges"), ("ollama", "local-ai")):
    ok = service not in services
    failed |= not ok
    print(f"{'PASS' if ok else 'FAIL'} no {service} service (profile {profile} not active)" + ("" if ok else "  (remove it from COMPOSE_PROFILES)"))
sys.exit(1 if failed else 0)
EOF

echo "== production baseline"
if dc --profile migrate --profile local-ai --profile challenges config --format json > "$WORK/full.json" 2>/dev/null &&
    python3 "$ROOT/scripts/check_production.py" "$WORK/full.json"; then
    pass "production baseline (check_production.py)"
else
    fail "production baseline (check_production.py)"
fi

if [[ "${PREFLIGHT_STATIC_ONLY:-0}" == 1 ]]; then
    echo "== runtime: skipped (PREFLIGHT_STATIC_ONLY=1)"
else
    echo "== runtime (data services running, migrations applied: steps 12-14)"
    if report=$(dc run --rm --no-deps -T backend php artisan codedna:preflight 2>&1); then
        pass "backend boots with this configuration; database, Redis and object storage reachable"
    else
        fail "backend boots with this configuration; database, Redis and object storage reachable"
    fi
    grep -E '^\|' <<< "$report" || true
    if grep -qE '\| migrations +\| ok +\| up to date' <<< "$report"; then
        pass "no migration pending"
    else
        fail "no migration pending" "run the migration job (step 14)"
    fi
    if ai=$(dc run --rm --no-deps -T backend php artisan ai:status --json 2>/dev/null) &&
        python3 -c 'import json, sys; text = sys.stdin.read(); sys.exit(0 if json.loads(text[text.index("{"):]).get("enabled") is False else 1)' <<< "$ai"; then
        pass "AI disabled at runtime (ai:status)"
    else
        fail "AI disabled at runtime (ai:status)"
    fi
fi

echo
if [[ $failures -eq 0 ]]; then
    echo "Preflight passed."
else
    echo "Preflight FAILED: $failures check(s)."
    exit 1
fi
