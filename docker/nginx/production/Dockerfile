# syntax=docker/dockerfile:1
#
# CodeDNA production Nginx (Phase 25): the public TLS edge.
#
# Build context: docker/nginx/production/
#
# Configuration is baked in (no bind mounts); the domain is substituted from
# CODEDNA_DOMAIN at start and the TLS certificate and key arrive as Compose
# secrets. Runs as the unprivileged nginx user (uid 101) on unprivileged
# ports, with a read-only root filesystem (/tmp is a tmpfs).

FROM nginx:1.28-alpine

RUN rm -f /etc/nginx/conf.d/default.conf /docker-entrypoint.d/10-listen-on-ipv6-by-default.sh

COPY --chown=root:root nginx.conf /etc/nginx/nginx.conf
COPY --chown=root:root snippets/ /etc/nginx/snippets/
COPY --chown=root:root templates/ /etc/nginx/templates/
COPY --chown=root:root --chmod=0755 entrypoint/05-codedna-check.sh /docker-entrypoint.d/05-codedna-check.sh

# The official entrypoint renders /etc/nginx/templates/*.template into the
# output directory, substituting only CODEDNA_* variables.
ENV NGINX_ENVSUBST_OUTPUT_DIR=/tmp/conf.d \
    NGINX_ENVSUBST_FILTER=CODEDNA_ \
    NGINX_ENTRYPOINT_QUIET_LOGS=1

# Request bodies (uploads up to 55 MiB) are buffered here, on a disk volume
# (docker-compose.prod.yml), never in the /tmp tmpfs: memory-backed buffering
# failed every upload above ~32 MiB (Phase 30).
RUN install -d -o nginx -g nginx -m 0700 /var/cache/codedna/client_body

USER nginx
EXPOSE 8080 8443 8081
