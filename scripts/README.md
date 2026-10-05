# scripts/

Repository-level helper scripts. Scripts must be deterministic, have no
side effects outside the repository unless documented, and never print
secrets.

| Script | Purpose | Used by |
|---|---|---|
| `check_repo.py` | Required files exist; Markdown links and anchors resolve; `.env.example` has no secret values; no real `.env` files are tracked | `make check-repo`, CI |

Run: `python3 scripts/check_repo.py` (add `--docs-only` to check only the
documentation).
