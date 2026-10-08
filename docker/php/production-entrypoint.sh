#!/bin/sh
# Backend container entrypoint (production, Phase 25).
#
# The image is immutable and the root filesystem read-only; storage/ is a
# tmpfs. This prepares Laravel's writable directories, then builds the
# configuration, route and event caches from the runtime environment. Building
# the configuration boots the application, so a production configuration that
# fails validation (ConfigurationValidator) stops the container here, before
# it serves a request or runs a job: fail fast, never fall back.
set -eu

cd /var/www/backend

umask 0077
mkdir -p storage/app storage/bootstrap storage/logs storage/tmp \
    storage/framework/cache/data storage/framework/sessions storage/framework/views

if [ -z "${APP_KEY:-}" ]; then
    # Never generated here: a new key would invalidate every session and
    # encrypted value (docs/operations/production-configuration.md).
    echo "codedna-entrypoint: APP_KEY is not set; refusing to start" >&2
    exit 1
fi

php artisan config:cache --no-ansi -q
php artisan route:cache --no-ansi -q
php artisan event:cache --no-ansi -q

exec "$@"
