# scripts/

Repository-level helper scripts. Scripts must be deterministic, must have no
side effects outside the repository unless documented, and must never print
secrets.

| Script | Purpose | Used by |
|---|---|---|
| `check_repo.py` | Required files exist; Markdown links and anchors resolve; `.env.example` has no secret values; no real `.env` files are tracked | `make check-repo`, CI |
| `setup.sh` | Creates `.env` from `.env.example` and generates random local secrets for empty required values. Idempotent: never changes values that are already set | `make setup`, CI |
| `verify-infra.sh` | Runtime smoke test of the running Docker environment: health, routing, network isolation, PostgreSQL/Redis from Laravel, S3 and pre-signed URLs | `make verify`, CI |

Run: `python3 scripts/check_repo.py` (add `--docs-only` to check only the
documentation).
