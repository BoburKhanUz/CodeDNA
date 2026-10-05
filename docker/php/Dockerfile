# syntax=docker/dockerfile:1
#
# CodeDNA backend — PHP-FPM DEVELOPMENT image for Laravel 13.
#
# Build context: backend/      (see backend/.dockerignore)
# Extra context: "php" -> docker/php/ (configuration and scripts)
#
# Application source is bind-mounted at runtime, not copied. A separate,
# optimized production image is a later phase (Phase 25).

FROM composer:2.10 AS composer

FROM php:8.4-fpm-trixie

# System packages:
#   libpq-dev, libicu-dev  build/runtime libraries for pdo_pgsql and intl
#   unzip                  lets Composer extract dist archives quickly
#   libfcgi-bin            provides cgi-fcgi, used by the healthcheck to call PHP-FPM directly
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip libfcgi-bin \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions not bundled with the official image. Bundled and already
# enabled: ctype, curl, dom, fileinfo, filter, mbstring, openssl, pdo, session,
# tokenizer, xml, opcache.
#   pdo_pgsql  PostgreSQL (ADR-001)
#   intl       required by Laravel's Number helper and locale-aware formatting
#   pcntl      required by `queue:work` to enforce job timeouts (ADR-005)
#   redis      phpredis, Laravel's default Redis client (cache, queue, sessions)
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql intl pcntl \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

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
