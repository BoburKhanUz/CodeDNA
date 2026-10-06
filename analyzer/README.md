# analyzer/ — Python Code Analysis Engine

**Status: Phase 08 foundation.** An internal FastAPI service that:

- authenticates `POST /internal/v1/analyze` with HMAC-SHA256 (timestamp
  window, constant-time comparison, secret rotation, bounded replay cache)
  and signs its responses;
- downloads a source snapshot only from an allow-listed, SigV4 pre-signed
  URL, connecting to a validated, pinned address (SSRF protection), with
  size, checksum and timeout limits;
- extracts the ZIP safely into a random per-run workspace that is always
  removed (no traversal, symlinks, special files, bombs);
- discovers files deterministically, detects languages by extension and
  returns a versioned **foundation result** (IR 1.0 file records) with a
  deterministic `result_hash`.

It computes **no metrics, features or scores** yet: parsers and metrics come
in **Phase 09**, DNA scoring in **Phase 11**. It never executes, imports or
installs anything from a source archive.

Everything runs in Docker (see
[infrastructure.md](../docs/architecture/infrastructure.md)): Python 3.11,
FastAPI, Uvicorn (`--factory`, `--reload`), source mounted read-only,
internal network only (no internet, no host port, not routed by Nginx),
non-root, read-only root filesystem, workspaces on a dedicated tmpfs. The
process refuses to start without a valid configuration (HMAC secret, source
allow-list, limits).

```bash
make test            # pytest in the container (plus backend and frontend tests)
make lint-analyzer   # ruff check, ruff format --check, mypy --strict
make shell-analyzer
```

Dependencies are hash-locked and baked into the image (runtime: FastAPI and
Uvicorn only; HTTP, ZIP and HMAC use the standard library):

```bash
pip-compile --generate-hashes --strip-extras -o requirements.txt requirements.in
pip-compile --generate-hashes --strip-extras -o requirements-dev.txt requirements-dev.in
make build
```

- Architecture, limits and the IR: [docs/architecture/analyzer.md](../docs/architecture/analyzer.md)
- HTTP contract: [docs/api/internal-analyzer-contract.md](../docs/api/internal-analyzer-contract.md)
- JSON Schemas: [packages/api-contracts/](../packages/api-contracts/README.md)
- Scoring rules (later): [ADR-004](../docs/decisions/ADR-004-dna-scoring.md)
