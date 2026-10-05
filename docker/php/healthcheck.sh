#!/bin/sh
# Backend healthcheck: requests Laravel's built-in /up route directly over
# PHP-FPM's FastCGI port, so it verifies PHP-FPM *and* that Laravel boots,
# without depending on Nginx.
set -eu

response=$(
    SCRIPT_NAME=/index.php \
    SCRIPT_FILENAME=/var/www/backend/public/index.php \
    REQUEST_METHOD=GET \
    REQUEST_URI=/up \
    SERVER_NAME=localhost \
    SERVER_PORT=80 \
    cgi-fcgi -bind -connect 127.0.0.1:9000
)

# PHP-FPM reports error responses through a "Status: 4xx/5xx" header.
case "$response" in
    *"Status: 4"* | *"Status: 5"*) exit 1 ;;
esac
