# syntax=docker/dockerfile:1
#
# CodeDNA MinIO initialisation (production profile, Phase 25): the
# development `minio-init` one-shot with its script baked in, so the
# production stack needs no bind mount. Same pinned client image.
#
# Build context: docker/minio/

FROM cgr.dev/chainguard/minio-client:latest-dev@sha256:7341127e324e0e8d7f399e199d0890564f5af9f5770e8b8956e2c620ed1d302d

COPY --chmod=0555 init.sh /scripts/init.sh

ENTRYPOINT ["sh", "/scripts/init.sh"]
