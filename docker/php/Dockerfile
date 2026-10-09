# syntax=docker/dockerfile:1
#
# CodeDNA backend — PHP-FPM images for Laravel 13.
#
# Build context: backend/      (see backend/.dockerignore)
# Extra contexts: "php"       -> docker/php/ (configuration and scripts)
#                 "contracts" -> packages/api-contracts/ (production only)
#
# Targets:
#   development (default, docker-compose.yml): source bind-mounted at
#     runtime, Composer and dev dependencies, OPcache revalidation.
#   production (docker-compose.prod.yml, Phase 25): source and production
#     dependencies baked in, owned by root and read-only, no Composer, no
#     dev dependencies, production php.ini, fixed unprivileged user. One
#     image runs PHP-FPM, the queue workers, the scheduler and migrations.

FROM composer:2.10 AS composer

# PHP with the extensions CodeDNA needs. Bundled and already enabled in the
# official image: ctype, curl, dom, fileinfo, filter, mbstring, openssl, pdo,
# session, tokenizer, xml, opcache.
#   pdo_pgsql  PostgreSQL (ADR-001)
#   intl       required by Laravel's Number helper and locale-aware formatting
#   pcntl      required by `queue:work` to enforce job timeouts (ADR-005)
#   redis      phpredis, Laravel's default Redis client (cache, queue, sessions)
# System packages:
#   libpq-dev, libicu-dev  build/runtime libraries for pdo_pgsql and intl
#   unzip                  lets Composer extract dist archives quickly
#   libfcgi-bin            provides cgi-fcgi, used by the healthcheck to call PHP-FPM directly
FROM php:8.4-fpm-trixie AS php-extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip libfcgi-bin \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql intl pcntl \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# ---------------------------------------------------------------------------
# Production build stage: production dependencies and an optimized,
# authoritative class map. Nothing from this stage but /build reaches the
# final image (no Composer, no cache, no dev dependencies).
# ---------------------------------------------------------------------------
FROM php-extensions AS production-build
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader \
    && rm -rf /root/.composer
COPY . .
# The package manifest is built without booting the application (booting
# validates the runtime configuration, which does not exist at build time).
# Tests, development tooling and local state never enter the image.
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts \
    && php -r 'require "vendor/autoload.php"; (new Illuminate\Foundation\PackageManifest(new Illuminate\Filesystem\Filesystem, getcwd(), getcwd()."/bootstrap/cache/packages.php"))->build();' \
    && rm -rf tests phpunit.xml .phpunit.cache storage/logs/* storage/framework/*/* bootstrap/cache/services.php \
        .editorconfig .gitattributes .gitignore pint.json phpstan.neon* README.md \
    && find . -name '.git*' -prune -exec rm -rf {} + \
    && rm -f .dockerignore /usr/local/bin/composer \
    && chmod -R a+rX,go-w /build

# ---------------------------------------------------------------------------
# production
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-trixie AS production

# Runtime libraries only (no compilers, no -dev packages, no Composer).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq5 libicu76 libfcgi-bin \
    && rm -rf /var/lib/apt/lists/*

COPY --from=php-extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Production php.ini as the base, CodeDNA's production overrides on top.
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY --from=php conf.d/production.ini /usr/local/etc/php/conf.d/zz-codedna.ini
COPY --from=php conf.d/fpm-production.conf /usr/local/etc/php-fpm.d/zz-codedna.conf
COPY --from=php --chmod=0755 production-entrypoint.sh /usr/local/bin/codedna-entrypoint
COPY --from=php --chmod=0755 healthcheck.sh /usr/local/bin/codedna-healthcheck

# Fixed unprivileged user (no login shell, no home). PHP-FPM's master and
# workers both run as this user; the pool's user/group lines are removed.
RUN groupadd --gid 10010 codedna \
    && useradd --uid 10010 --gid 10010 --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin codedna \
    && sed -i -e '/^user = /d' -e '/^group = /d' /usr/local/etc/php-fpm.d/www.conf \
    && install -d -o codedna -g codedna -m 0700 /var/cache/codedna/uploads

# Application code and the analyzer contracts: owned by root, read-only to
# the application user. Writable state lives only in tmpfs mounts
# (storage/, see docker-compose.prod.yml); objects live in S3-compatible
# storage, never on the container filesystem.
# (Modes are set in the build stage: a chmod here would copy every file into
# another layer.)
COPY --from=production-build --chown=root:root /build /var/www/backend
COPY --from=contracts --chown=root:root . /var/www/contracts

# Laravel's generated caches (config, routes, events, services) go to the
# writable storage tmpfs; bootstrap/cache keeps only the package manifest
# built above.
ENV APP_SERVICES_CACHE=/var/www/backend/storage/bootstrap/services.php \
    APP_CONFIG_CACHE=/var/www/backend/storage/bootstrap/config.php \
    APP_ROUTES_CACHE=/var/www/backend/storage/bootstrap/routes-v7.php \
    APP_EVENTS_CACHE=/var/www/backend/storage/bootstrap/events.php

WORKDIR /var/www/backend
USER codedna

ENTRYPOINT ["codedna-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# development (default target)
# ---------------------------------------------------------------------------
FROM php-extensions AS development

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY --from=php conf.d/codedna.ini /usr/local/etc/php/conf.d/zz-codedna.ini
COPY --from=php conf.d/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-codedna.conf
COPY --from=php --chmod=0755 entrypoint.sh /usr/local/bin/codedna-entrypoint
COPY --from=php --chmod=0755 healthcheck.sh /usr/local/bin/codedna-healthcheck

# Run as an unprivileged user whose UID/GID match the host user, so files
# written to the bind-mounted source (vendor/, storage/) stay owned by the
# developer. PHP-FPM then runs its master and workers as this user.
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN groupadd --gid "${HOST_GID}" codedna 2>/dev/null || true \
    && useradd --uid "${HOST_UID}" --gid "${HOST_GID}" --create-home --shell /bin/bash codedna \
    && sed -i -e '/^user = /d' -e '/^group = /d' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/backend
USER codedna

ENTRYPOINT ["codedna-entrypoint"]
CMD ["php-fpm"]
