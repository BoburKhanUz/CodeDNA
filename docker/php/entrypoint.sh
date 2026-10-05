#!/bin/sh
# Backend container entrypoint (development).
#
# Installs Composer dependencies into the bind-mounted backend/vendor when
# they are missing or composer.lock has changed since the last install, then
# executes the container command (php-fpm by default).
set -eu

cd /var/www/backend

stamp=vendor/.codedna-composer-lock
if [ ! -f vendor/autoload.php ] || ! cmp -s composer.lock "$stamp"; then
    echo "codedna-entrypoint: installing Composer dependencies"
    composer install --no-interaction --no-progress --prefer-dist
    cp composer.lock "$stamp"
fi

exec "$@"
