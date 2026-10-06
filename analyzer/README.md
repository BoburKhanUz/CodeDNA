# analyzer/ — Python Code Analysis Engine

**Status: Phase 09 static analysis.** An internal FastAPI service that:

- authenticates `POST /internal/v1/analyze` with HMAC-SHA256 (timestamp
  window, constant-time comparison, secret rotation, bounded replay cache)
  and signs its responses;
- downloads a source snapshot only from an allow-listed, SigV4 pre-signed
  URL, connecting to a validated, pinned address (SSRF protection), with
  size, checksum and timeout limits;
- extracts the ZIP safely into a random per-run workspace that is always
  removed (no traversal, symlinks, special files, bombs);
- discovers files deterministically and detects languages by extension
  (Phase 08);
- parses PHP, Python, JavaScript, TypeScript/TSX, Go, Java, C#, Rust, C and
  C++ with pinned Tree-sitter grammars, in process and bounded per file and
  per run (bytes, time, syntax-tree nodes, files), giving every file a parse
  status (`PARSED`, `PARSE_ERROR`, `PARSE_TIMEOUT`, `LIMIT_EXCEEDED`,
  `UNSUPPORTED_PARSER`);
- lowers syntax trees to **IR 1.1** (declarations, imports, types,
  functions, parameters, visibility, inheritance, complexity, nesting,
  positions; never source text) and computes deterministic **static metrics
  1.0** and **structural findings**, returned as a versioned
  `static_analysis` result with a deterministic `result_hash` when the
  request sets `options.result_type: "static_analysis"`. Without it the
  Phase 08 `foundation` result (inventory only, IR 1.0) is returned
  unchanged.

It computes **no features, DNA scores or AI output**: scoring comes in
**Phase 11**. It never executes, imports or installs anything from a source
archive. Metric definitions, the cyclomatic complexity formula, per-language
support and finding rules: [metrics-v1.md](../docs/architecture/metrics-v1.md).

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

Dependencies are pinned, hash-locked and baked into the image (runtime:
FastAPI, Uvicorn, the Tree-sitter runtime 0.25.2 and ten official grammar
packages; HTTP, ZIP, HMAC and metrics use the standard library). Grammar
versions are part of every result; after changing one, regenerate the golden
fixture (`tests/test_golden.py` explains how) and check the change is
intended:

```bash
pip-compile --generate-hashes --strip-extras -o requirements.txt requirements.in
pip-compile --generate-hashes --strip-extras -o requirements-dev.txt requirements-dev.in
make build
```

- Architecture, parsing, limits and the IR: [docs/architecture/analyzer.md](../docs/architecture/analyzer.md)
- Static metrics and findings: [docs/architecture/metrics-v1.md](../docs/architecture/metrics-v1.md)
- HTTP contract: [docs/api/internal-analyzer-contract.md](../docs/api/internal-analyzer-contract.md)
- JSON Schemas: [packages/api-contracts/](../packages/api-contracts/README.md)
- Scoring rules (later): [ADR-004](../docs/decisions/ADR-004-dna-scoring.md)
