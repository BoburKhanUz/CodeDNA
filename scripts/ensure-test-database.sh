#!/usr/bin/env bash
# Creates the dedicated PostgreSQL test database (codedna_test) inside the
# running `postgres` service if it does not exist yet. Idempotent.
#
# The backend test suite is pinned to this database by backend/phpunit.xml;
# it never touches the development database.
set -euo pipefail

cd "$(dirname "$0")/.." || exit 1

test_db=codedna_test

# shellcheck disable=SC2016 # variables expand inside the container
docker compose exec -T -e TEST_DB="$test_db" postgres sh -c '
    if psql -U "$POSTGRES_USER" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '"'"'$TEST_DB'"'"'" | grep -q 1; then
        echo "test database $TEST_DB: exists"
    else
        createdb -U "$POSTGRES_USER" -O "$POSTGRES_USER" "$TEST_DB"
        echo "test database $TEST_DB: created"
    fi
'
