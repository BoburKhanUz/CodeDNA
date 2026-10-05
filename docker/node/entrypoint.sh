#!/bin/sh
# Frontend container entrypoint (development).
#
# Installs npm dependencies into the node_modules volume when they are
# missing or package-lock.json has changed since the last install, then
# executes the container command (the Next.js dev server by default).
set -eu

cd /app

stamp=node_modules/.codedna-package-lock
if [ ! -d node_modules/next ] || ! cmp -s package-lock.json "$stamp"; then
    echo "codedna-entrypoint: installing npm dependencies"
    npm ci --no-audit --no-fund
    cp package-lock.json "$stamp"
fi

exec "$@"
