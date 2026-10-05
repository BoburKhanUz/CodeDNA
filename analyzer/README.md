# analyzer/ — Python Code Analysis Engine

**Status: Phase 02 bootstrap.** A FastAPI service with **one** endpoint,
`GET /internal/v1/health`, which returns `{"status": "ok", "versions":
{"analyzer": "<version>"}}`. There is **no analysis**: no parsing, metrics or
scoring. The service skeleton and the IR come in **Phase 08**, parsers and
metrics in **Phase 09**, and DNA scoring in **Phase 11**.

> Before any parser code is written, the Intermediate Representation (IR)
> draft must be reviewed and frozen as `ir_version` 1.0
> ([ADR-002](../docs/decisions/ADR-002-analysis-engine.md)).

What runs today (via Docker, see
[infrastructure.md](../docs/architecture/infrastructure.md)):

- Python 3.11, FastAPI, Uvicorn (`--reload`), source mounted read-only.
- Internal-only. It sits on the `codedna-internal` network with no internet
  access, is not routed by Nginx, publishes no port, and runs as a
  non-root user on a read-only root filesystem.
- Dependencies are hash-locked and baked into the image:

  ```bash
  pip-compile --generate-hashes --strip-extras -o requirements.txt requirements.in
  pip-compile --generate-hashes --strip-extras -o requirements-dev.txt requirements-dev.in
  make build
  ```

```bash
make test                  # runs pytest in the container (plus backend tests)
make shell-analyzer
```

Responsibilities (target): fetch a source snapshot via a pre-signed URL,
safely extract it, detect languages, detect secrets (locations only), parse
with Tree-sitter, lower to the IR, compute deterministic metrics, features
and versioned DNA scores. It never executes repository code.

- Architecture and IR: [docs/architecture/analyzer.md](../docs/architecture/analyzer.md)
- HTTP contract (server side): [docs/api/internal-analyzer-contract.md](../docs/api/internal-analyzer-contract.md)
- Scoring rules: [ADR-004](../docs/decisions/ADR-004-dna-scoring.md)
