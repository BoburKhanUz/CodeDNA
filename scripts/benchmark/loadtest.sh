#!/usr/bin/env bash
# HTTP load test against the development stack (Phase 26,
# docs/performance/load-testing.md). Needs `make benchmark-seed` first.
#
# Points the development backend and queue worker at the benchmark database
# (and Redis databases 5 and 6) for the duration of the test, creates
# sessions, runs scripts/benchmark/loadtest.py, then restores the services
# and removes the sessions and the Redis data. Development data is never read
# or written.
#
#   scripts/benchmark/loadtest.sh [loadtest.py options, e.g. --concurrency 32 --duration 60]
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
cd "$ROOT"
OVERLAY=(-f docker-compose.yml -f docker/benchmark/compose.loadtest.yml)
SESSIONS=backend/storage/app/benchmark/sessions.json
SOURCES=backend/storage/app/benchmark/sources

if ! docker compose exec -T postgres psql -U codedna -d codedna -Atc "SELECT 1 FROM pg_database WHERE datname = 'codedna_benchmark'" | grep -q 1; then
    echo "No benchmark database: run make benchmark-seed first." >&2
    exit 1
fi

restore() {
    rm -f "$SESSIONS"
    docker compose exec -T redis sh -c 'redis-cli -n 5 flushdb >/dev/null; redis-cli -n 6 flushdb >/dev/null' || true
    docker compose up -d --no-deps --wait backend queue >/dev/null
    echo "Development backend and worker restored."
}
trap restore EXIT

mkdir -p "$SOURCES"
python3 scripts/benchmark/make_sources.py "$SOURCES" --profile small >/dev/null
chmod -R a+rX "$SOURCES"
docker compose exec -T redis sh -c 'redis-cli -n 5 flushdb >/dev/null; redis-cli -n 6 flushdb >/dev/null'
docker compose "${OVERLAY[@]}" up -d --no-deps --wait backend queue >/dev/null
docker compose exec -T queue php artisan benchmark:sessions --archive=storage/app/benchmark/sources/small.zip
python3 scripts/benchmark/loadtest.py "$SESSIONS" "$@"
