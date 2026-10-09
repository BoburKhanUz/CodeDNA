#!/usr/bin/env python3
"""Static checks of the production deployment profile (Phase 25).

Reads the fully rendered Compose model of docker-compose.prod.yml (JSON from
``docker compose -f docker-compose.prod.yml config --format json``, rendered
with placeholder values) and the production Nginx and image configuration,
and fails on anything that would weaken the production baseline
(docs/operations/security-baseline.md):

- only Nginx publishes ports; no data or internal service is published;
- every service: read-only root filesystem, no-new-privileges, all
  capabilities dropped (only the documented ones added back), memory, CPU
  and process limits, a restart policy, rotated logs, no bind mounts, no
  privileged mode, no host namespaces, no Docker socket;
- network segmentation: the documented networks, internal except the public
  and egress ones; each service only on the networks it needs;
- the evaluator: no network, the gVisor runtime, production mode;
- fixed production values: APP_ENV=production, APP_DEBUG=false, secure
  cookies, explicit trusted proxies, gVisor evaluator isolation, no fake
  billing, JSON logs;
- no development commands (artisan serve, queue:listen, next dev, --reload);
- Nginx: HSTS only in the TLS server, the CSP present, /internal blocked,
  client forwarded headers never passed through;
- production images: non-root users, no dev targets.

Usage: scripts/check_production.py [--external] <rendered-compose.json>
(render with ``--profile migrate`` so the one-shot migration is included).
``--external`` checks the production file combined with every
docker/enterprise/compose.external-*.yml overlay (Phase 27): the bundled data
services are off, and only the scheduler, the migration job and the analyzer
gain the routes those overlays document; every other rule is unchanged.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent.parent

SERVICES = {"nginx", "frontend", "backend", "queue", "scheduler", "migrate", "analyzer", "evaluator", "postgres", "redis", "minio", "minio-init"}
NETWORKS = {
    "nginx": {"public", "web", "app"},
    "frontend": {"web"},
    "backend": {"app", "data", "storage", "egress"},
    "queue": {"data", "storage", "analysis", "egress"},
    "scheduler": {"data"},
    "migrate": {"data"},
    "analyzer": {"analysis"},
    "postgres": {"data"},
    "redis": {"data"},
    "minio": {"storage", "analysis"},
    "minio-init": {"storage"},
}
EXTERNAL_NETWORKS = {"public", "egress"}
# Customer-run PostgreSQL, Redis and object storage (Phase 27 overlays).
BUNDLED_DATA = {"postgres", "redis", "minio", "minio-init"}
EXTERNAL_DATA_NETWORKS = {
    "scheduler": {"data", "egress"},
    "migrate": {"data", "egress"},
    "analyzer": {"analysis", "analyzer-egress"},
}
LICENSE_PATH = "/run/secrets/codedna_license"
ALLOWED_CAPS = {
    "evaluator": {"SETUID", "SETGID", "KILL"},
    "postgres": {"CHOWN", "DAC_OVERRIDE", "FOWNER", "SETGID", "SETUID"},
}
FIXED_BACKEND_ENV = {
    "APP_ENV": "production",
    "APP_DEBUG": "false",
    "SESSION_SECURE_COOKIE": "true",
    "SESSION_DRIVER": "redis",
    "CACHE_STORE": "redis",
    "QUEUE_CONNECTION": "redis",
    "LOG_CHANNEL": "stderr",
    "LOG_STDERR_FORMATTER": "Monolog\\Formatter\\JsonFormatter",
    "TRUSTED_PROXIES": "172.30.10.0/24",
    "CHALLENGE_EVALUATOR_ISOLATION": "gvisor",
    "BILLING_PROVIDER": "none",
}
DEV_COMMANDS = re.compile(r"(artisan serve|queue:listen|schedule:test|next dev|npm run dev|--reload|tinker|xdebug)")


def check_compose(model: dict[str, Any], external: bool = False) -> list[str]:
    errors: list[str] = []
    services: dict[str, Any] = model.get("services", {})
    expected_services = SERVICES - BUNDLED_DATA if external else SERVICES
    expected_networks = {name: nets for name, nets in NETWORKS.items() if name in expected_services}
    if external:
        expected_networks.update(EXTERNAL_DATA_NETWORKS)
    external_networks = EXTERNAL_NETWORKS | ({"analyzer-egress"} if external else set())
    if set(services) != expected_services:
        errors.append(f"services must be exactly {sorted(expected_services)}, got {sorted(services)}")

    for name, service in services.items():
        where = f"service {name}"
        ports = service.get("ports") or []
        if name == "nginx":
            if sorted(int(p.get("target", 0)) for p in ports) != [8080, 8443]:
                errors.append(f"{where}: must publish exactly the HTTP and HTTPS listeners (8080, 8443)")
        elif ports:
            errors.append(f"{where}: must not publish ports")
        if service.get("expose"):
            errors.append(f"{where}: must not declare extra exposed ports")
        if service.get("read_only") is not True:
            errors.append(f"{where}: root filesystem must be read-only")
        if "no-new-privileges:true" not in (service.get("security_opt") or []):
            errors.append(f"{where}: no-new-privileges is required")
        if service.get("cap_drop") != ["ALL"]:
            errors.append(f"{where}: all capabilities must be dropped")
        added = set(service.get("cap_add") or [])
        if not added <= ALLOWED_CAPS.get(name, set()):
            errors.append(f"{where}: capabilities {sorted(added - ALLOWED_CAPS.get(name, set()))} are not allowed")
        if service.get("privileged"):
            errors.append(f"{where}: privileged mode is forbidden")
        for key in ("pid", "ipc", "userns_mode", "uts"):
            if service.get(key) == "host":
                errors.append(f"{where}: host {key} namespace is forbidden")
        if service.get("network_mode") == "host":
            errors.append(f"{where}: host networking is forbidden")
        if service.get("devices"):
            errors.append(f"{where}: devices are forbidden")
        for volume in service.get("volumes") or []:
            if volume.get("type") == "bind":
                errors.append(f"{where}: bind mount {volume.get('target')} is forbidden")
            if "docker.sock" in str(volume.get("source", "")) + str(volume.get("target", "")):
                errors.append(f"{where}: the Docker socket must never be mounted")
        if name not in ("migrate", "minio-init"):
            if service.get("restart") != "unless-stopped":
                errors.append(f"{where}: restart policy unless-stopped is required")
            for limit in ("mem_limit", "cpus", "pids_limit"):
                if not service.get(limit):
                    errors.append(f"{where}: {limit} is required")
        if (service.get("logging") or {}).get("options", {}).get("max-size") is None:
            errors.append(f"{where}: log rotation (max-size) is required")
        command = " ".join(service.get("command") or []) + " " + " ".join(service.get("entrypoint") or [])
        if DEV_COMMANDS.search(command):
            errors.append(f"{where}: development command {command.strip()!r}")
        build = service.get("build") or {}
        if build and build.get("target") not in (None, "production"):
            errors.append(f"{where}: images must be built from the production target")
        if build and "args" in build and any(re.search(r"(SECRET|PASSWORD|TOKEN|KEY)", k) for k in build["args"]):
            errors.append(f"{where}: secrets must never be build arguments")
        if name != "evaluator" and "runtime" in service:
            errors.append(f"{where}: only the evaluator selects a runtime")

        expected = expected_networks.get(name)
        if expected is not None and set(service.get("networks") or {}) != expected:
            errors.append(f"{where}: networks must be {sorted(expected)}, got {sorted(service.get('networks') or {})}")

    evaluator = services.get("evaluator", {})
    if evaluator.get("network_mode") != "none" or evaluator.get("networks"):
        errors.append("service evaluator: must have no network (network_mode: none)")
    if not str(evaluator.get("runtime", "")).startswith("runsc"):
        errors.append("service evaluator: the gVisor runtime (runsc) is required")
    environment = evaluator.get("environment") or {}
    if environment.get("EVALUATOR_PRODUCTION") != "true" or environment.get("EVALUATOR_ISOLATION") != "gvisor":
        errors.append("service evaluator: EVALUATOR_PRODUCTION=true and EVALUATOR_ISOLATION=gvisor are required")

    for name in ("backend", "queue", "scheduler", "migrate"):
        environment = (services.get(name) or {}).get("environment") or {}
        for key, value in FIXED_BACKEND_ENV.items():
            if environment.get(key) != value:
                errors.append(f"service {name}: {key} must be {value!r}")
        if not str(environment.get("APP_URL", "")).startswith("https://"):
            errors.append(f"service {name}: APP_URL must be https")
        # Enterprise license (Phase 27): read from the read-only secret only;
        # verification keys are code, never configuration.
        if environment.get("CODEDNA_LICENSE_PATH") != LICENSE_PATH or "codedna_license" not in [
            secret.get("source") for secret in (services.get(name) or {}).get("secrets") or []
        ]:
            errors.append(f"service {name}: the license must come from the codedna_license secret at {LICENSE_PATH}")
        if [key for key in environment if "LICENSE" in key and key != "CODEDNA_LICENSE_PATH"]:
            errors.append(f"service {name}: no license key or document may be set through the environment")
    if (services.get("frontend") or {}).get("environment", {}).get("NODE_ENV") != "production":
        errors.append("service frontend: NODE_ENV must be production")
    if [s for s in ("migrate",) if "migrate" not in ((services.get(s) or {}).get("profiles") or [])]:
        errors.append("service migrate: must only run explicitly (profile migrate)")

    networks: dict[str, Any] = model.get("networks", {})
    all_networks = set().union(*expected_networks.values()) | ({"public", "web", "app", "data", "storage", "analysis", "egress"} if external else set())
    if set(networks) != all_networks:
        errors.append(f"networks must be exactly {sorted(all_networks)}, got {sorted(networks)}")
    for name, network in networks.items():
        internal = bool(network.get("internal"))
        if name in external_networks and internal:
            errors.append(f"network {name}: must not be internal")
        if name not in external_networks and not internal:
            errors.append(f"network {name}: must be internal (no route to the internet)")
    return errors


def check_nginx() -> list[str]:
    errors: list[str] = []
    base = ROOT / "docker/nginx/production"
    template = (base / "templates/default.conf.template").read_text(encoding="utf-8")
    headers = (base / "snippets/security-headers.conf").read_text(encoding="utf-8")
    fastcgi = (base / "snippets/laravel-fastcgi.conf").read_text(encoding="utf-8")
    tls = (base / "snippets/tls.conf").read_text(encoding="utf-8")
    if "Strict-Transport-Security" not in headers:
        errors.append("nginx: HSTS is required on the TLS server")
    if re.search(r"^\s*add_header\s+Strict-Transport-Security", (ROOT / "docker/nginx/snippets/security-headers.conf").read_text(encoding="utf-8"), re.MULTILINE):
        errors.append("nginx (development): HSTS must not be sent over plain HTTP")
    if "add_header Content-Security-Policy $codedna_csp always;" not in headers or "default-src 'none'" not in template:
        errors.append("nginx: the Content-Security-Policy is required (API: default-src 'none')")
    for header in ("X-Content-Type-Options", "X-Frame-Options", "Referrer-Policy", "Permissions-Policy"):
        if header not in headers:
            errors.append(f"nginx: {header} is required")
    if not re.search(r"location \^~ /internal/ \{\s*return 404;", template):
        errors.append("nginx: /internal/ must return 404")
    if "server_tokens off" not in (base / "nginx.conf").read_text(encoding="utf-8"):
        errors.append("nginx: server_tokens off is required")
    for param in ("HTTP_X_FORWARDED_FOR $remote_addr", "HTTP_X_FORWARDED_PROTO https", 'HTTP_X_FORWARDED_HOST ""', 'HTTP_FORWARDED ""', 'HTTP_PROXY ""'):
        if f"fastcgi_param {param};" not in fastcgi:
            errors.append(f"nginx: fastcgi_param {param} is required (client forwarded headers are never passed through)")
    if re.search(r"set_real_ip_from\s+(0\.0\.0\.0/0|::/0)", template) or template.count("set_real_ip_from") != 1:
        errors.append("nginx: real_ip only from the web network's fixed subnet")
    if re.search(r"TLSv1(\.1)?[ ;]", tls) or "TLSv1.2 TLSv1.3" not in tls:
        errors.append("nginx: TLS 1.2 and 1.3 only")
    if "ssl_reject_handshake on" not in template:
        errors.append("nginx: unknown server names must be rejected")
    if "proxy_set_header X-Forwarded-For $remote_addr;" not in template:
        errors.append("nginx: the frontend must receive the TCP peer address only")
    return errors


def production_stage(dockerfile: str) -> str:
    """The instructions of the final production stage (or the whole file when it has no stages)."""
    match = re.search(r"^FROM \S+ AS production\n(.*?)(?=^FROM |\Z)", dockerfile, re.MULTILINE | re.DOTALL)
    return match.group(1) if match else dockerfile


def check_images() -> list[str]:
    errors: list[str] = []
    for path, user in (
        ("docker/php/Dockerfile", "codedna"),
        ("docker/node/Dockerfile", "node"),
        ("docker/python/Dockerfile", "analyzer"),
        ("docker/nginx/production/Dockerfile", "nginx"),
    ):
        text = (ROOT / path).read_text(encoding="utf-8")
        production = production_stage(text)
        if f"USER {user}" not in production:
            errors.append(f"{path}: the production image must run as {user}")
        if re.search(r"^(ARG|ENV)\s+\S*(SECRET|PASSWORD|TOKEN|API_KEY|APP_KEY)", production, re.MULTILINE):
            errors.append(f"{path}: no secret may be an ARG or ENV")
    evaluator = production_stage((ROOT / "docker/evaluator/Dockerfile").read_text(encoding="utf-8"))
    if "EVALUATOR_PRODUCTION=true" not in evaluator or "EVALUATOR_ISOLATION=gvisor" not in evaluator:
        errors.append("docker/evaluator/Dockerfile: the production target must default to production mode with gVisor")
    if re.search(r"^COPY\b.*\btests\b", evaluator, re.MULTILINE):
        errors.append("docker/evaluator/Dockerfile: the production target must not contain tests")
    return errors


def main() -> int:
    args = sys.argv[1:]
    external = args[:1] == ["--external"]
    if external:
        args = args[1:]
    if len(args) != 1:
        print("usage: scripts/check_production.py [--external] <rendered-compose.json>", file=sys.stderr)
        return 2
    model = json.loads(Path(args[0]).read_text(encoding="utf-8"))
    errors = check_compose(model, external) + check_nginx() + check_images()
    for error in errors:
        print(f"ERROR: {error}")
    if errors:
        return 1
    print("Production configuration checks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
