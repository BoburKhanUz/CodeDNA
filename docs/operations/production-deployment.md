# Production deployment

How to run CodeDNA in production with [`docker-compose.prod.yml`](../../docker-compose.prod.yml)
(Phase 25). The procedure is provider-neutral: it needs one Linux host (or VM)
with Docker, a DNS name and a TLS certificate. It assumes no cloud service, and
nothing in the repository holds a production value.

Related: [production configuration](production-configuration.md) (every
variable) · [security baseline](security-baseline.md) ·
[backup and restore](backup-and-restore.md) · [rollback](rollback.md).

## What runs where

| Service | Image (target `production`) | Networks | Published |
|---|---|---|---|
| `nginx` | `docker/nginx/production/Dockerfile` | public, web, app | 80 and 443 only |
| `frontend` | `docker/node/Dockerfile` (Next.js standalone) | web | — |
| `backend` | `docker/php/Dockerfile` (PHP-FPM) | app, data, storage, egress | — |
| `queue` | same image, `queue:work` | data, storage, analysis, egress | — |
| `scheduler` | same image, `schedule:work` (exactly one) | data | — |
| `migrate` | same image, one-shot (profile `migrate`) | data | — |
| `analyzer` | `docker/python/Dockerfile` | analysis | — |
| `evaluator` | `docker/evaluator/Dockerfile`, runtime `runsc` | **none** | — |
| `postgres` | `postgres:16-alpine` | data | — |
| `redis` | `redis:7.4-alpine` | data | — |
| `minio`, `minio-init` | pinned MinIO images | storage (+ analysis) | — |

The network design and per-container hardening are in the
[security baseline](security-baseline.md#network-segmentation).

## Prerequisites

- Linux x86_64 host with Docker Engine 25+ and the Compose v2 plugin.
- **gVisor** (`runsc`) installed and registered as a Docker runtime, so the
  challenge evaluator can run (see [step 3](#3-install-gvisor-for-the-evaluator)).
  Without it, everything else works and challenge evaluation is reported as
  unavailable (fail closed).
- A DNS name for the application, and a TLS certificate chain and key for it.
- Inbound 80 and 443 only. Nothing else needs to be reachable from outside.
- Outbound HTTPS from the host for GitHub, the AI provider and an external
  object store, if those are used.

## Procedure

### 1. Prepare the host

Create a deploying user in the `docker` group. Keep the host patched, enable
a firewall that allows only SSH (restricted), 80 and 443, and turn on
unattended security updates. Put Docker's data root on a disk with room for
images, the PostgreSQL, Redis and MinIO volumes, and logs.

### 2. Install Docker

Install Docker Engine and the Compose plugin from Docker's repository for the
distribution. Check: `docker version` and `docker compose version`.

### 3. Install gVisor for the evaluator

Install `runsc` from gVisor's release repository, then register it with
Docker:

```bash
sudo runsc install          # adds the "runsc" runtime to /etc/docker/daemon.json
sudo systemctl restart docker
docker info --format '{{json .Runtimes}}'   # must list "runsc"
```

If the runtime is registered under another name (e.g. a KVM platform
variant), set `EVALUATOR_RUNTIME` to that name. A non-gVisor runtime cannot
be substituted: the evaluator attests its isolation and refuses anything
else ([production sandbox](../architecture/challenge-evaluator.md#production-sandbox)).

### 4. Get the code at a release

```bash
git clone https://github.com/BoburKhanUz/CodeDNA.git /opt/codedna
cd /opt/codedna && git checkout <release tag or commit>
```

Deploy reviewed commits only, never a working tree with local changes.

### 5. Point DNS at the host

Create an A/AAAA record for the application name. Lower its TTL before the
first deployment so a later move is quick.

### 6. Obtain the TLS certificate

Use any CA or ACME client to obtain a certificate for the name. Store the
full chain and the key **outside the repository**, for example:

```text
/etc/codedna/tls/fullchain.pem   root:root  0644
/etc/codedna/tls/privkey.pem     root:101   0640
```

Nginx runs as the unprivileged `nginx` user (uid/gid 101) and reads the
files through Compose secrets. Compose without Swarm keeps the host
ownership and mode, so the key must be readable by group 101. Renew before
expiry; after a renewal run
`docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env exec nginx nginx -s reload`.

HSTS (`max-age=31536000; includeSubDomains`) is sent on HTTPS only. Before
adding the name to a browser preload list, make sure every subdomain serves
HTTPS. Preloading is hard to undo.

### 7. Create the environment file

```bash
sudo install -d -m 0750 -o deploy -g deploy /etc/codedna
install -m 0600 .env.production.example /etc/codedna/production.env
```

Fill in every `[required]` value as described in
[production configuration](production-configuration.md#required-values).
Never commit this file or copy it into an image.

### 8. Generate the secrets

Generate each secret once, with a CSPRNG, directly into the environment file:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
openssl rand -hex 32    # DB_PASSWORD, REDIS_PASSWORD, ANALYZER_HMAC_SECRET,
                        # SOURCE_STORAGE_SECRET_ACCESS_KEY, MINIO_ROOT_PASSWORD
```

`APP_KEY` must never change after the first start: rotating it ends every
session and makes encrypted values (GitHub tokens) unreadable. Keep a copy
in the organization's secret manager.

### 9. Validate the configuration

```bash
docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env config --quiet
make prod-config      # the production baseline checks (no secrets needed)
```

A missing required value stops `config` with its name.

### 10. Build (or pull) the images

```bash
APP_VERSION=<release> docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env --profile migrate build
```

Images are tagged `${CODEDNA_IMAGE_PREFIX}-<service>:${APP_VERSION}`. A
pipeline may build them once and push them to a private registry. Then set
`CODEDNA_IMAGE_PREFIX` to the registry path and use `pull` instead of
`build`. Images never contain secrets: all configuration is read at runtime.

### 11. Verify the evaluator isolation on this host

```bash
APP_VERSION=<release> make prod-evaluator-attest    # must print: gvisor
```

### 12. Start the data services

```bash
docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env up -d --wait postgres redis minio minio-init
```

### 13. Back up before changing the schema

On every deployment after the first, take and verify a backup first
([backup and restore](backup-and-restore.md#before-every-deployment)).

### 14. Run the migrations explicitly

```bash
docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env run --rm migrate
```

Migrations never run at container start. `--force` is used only by this
one-shot. Read the release notes for migrations that need a maintenance
window.

### 15. Start the application

```bash
docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env up -d --wait
```

The Laravel containers build their configuration, route and event caches at
start. An invalid production configuration stops them there with a list of
problems (it never falls back to defaults). Read it with
`docker compose ... logs backend`.

### 16. Check health

```bash
curl -fsS https://<domain>/api/v1/health   # readiness: database and Redis
curl -fsS https://<domain>/up              # liveness: Laravel boots
docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env ps
```

All services must be `healthy` (`queue` and `scheduler` have no health check;
they must be `running`). The evaluator is healthy only when its heartbeat
reports `gvisor`.

### 17. Verify the edge

- `http://<domain>/` answers 301 to `https://<domain>/`.
- Responses carry `Strict-Transport-Security`, `Content-Security-Policy`,
  `X-Frame-Options: DENY` and `X-Content-Type-Options: nosniff`.
- `https://<domain>/internal/v1/health` answers 404.
- Sign in, upload a small archive, run an analysis and open the CodeDNA page.

### 18. Enable monitoring and log collection

Container logs are JSON lines on stdout/stderr, rotated by Docker (10 MB ×
5 per container). Ship them to the organization's log system and alert on:
unhealthy containers, `evaluator.refused`, `Invalid CodeDNA configuration`,
5xx rates, failed jobs (`php artisan queue:failed`), disk usage on the
volumes, and certificate expiry.

### 19. Record the release

Record the deployed commit, `APP_VERSION`, the migration batch
(`php artisan migrate:status`) and the backup taken in step 13. These
are the inputs to [rollback](rollback.md).

## Updating

Repeat steps 4, 9, 10, 13, 14, 15, 16 and 19 for each release. `up -d`
replaces only changed containers. The queue worker finishes its current job
before stopping (grace period 360 s). PHP-FPM finishes in-flight requests
(`SIGQUIT`, 30 s).

## Operating notes

- **Scaling:** `queue` can run several replicas (`--scale queue=N`); jobs are
  idempotent and retried with backoff per job class. Never scale `scheduler`
  or `migrate`. The analyzer's replay protection is per process: run one
  analyzer.
- **External object storage:** see
  [production configuration](production-configuration.md#object-storage).
- **Managed PostgreSQL:** set `DB_HOST` and `DB_SSLMODE=verify-full`, and
  remove the bundled `postgres` service from the deployment.
- **Behind a load balancer:** a TCP (layer-4) load balancer works unchanged.
  An HTTP load balancer that terminates TLS changes the client address
  Nginx sees. Then add a `real_ip` configuration listing only the load
  balancer's addresses, and keep TLS to Nginx.
