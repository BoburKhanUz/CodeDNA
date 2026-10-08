#!/bin/sh
# Fail fast (Phase 25): the production edge refuses to start without a valid
# domain and the TLS certificate and key it serves. Nothing is generated or
# defaulted here.
set -eu

case "${CODEDNA_DOMAIN:-}" in
    "" | *[!a-z0-9.-]* | .* | *. | *..*)
        echo "codedna-nginx: CODEDNA_DOMAIN must be the public hostname (lowercase, e.g. app.example.com)" >&2
        exit 1
        ;;
esac
for secret in tls_certificate tls_private_key; do
    if [ ! -s "/run/secrets/$secret" ]; then
        echo "codedna-nginx: /run/secrets/$secret is missing (see docs/operations/production-deployment.md#tls)" >&2
        exit 1
    fi
done
mkdir -p /tmp/conf.d
