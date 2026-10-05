# analyzer/ — Python Code Analysis Engine

**Status: not created yet.** The FastAPI service and the IR model are created
in **Phase 08**; parsers and metrics in **Phase 09**; DNA scoring in
**Phase 11**.

> Before any parser code is written, the Intermediate Representation (IR)
> draft must be reviewed and frozen as `ir_version` 1.0
> ([ADR-002](../docs/decisions/ADR-002-analysis-engine.md)).

Responsibilities: fetch a source snapshot via a pre-signed URL, safely
extract it, detect languages, detect secrets (locations only), parse with
Tree-sitter, lower to the IR, compute deterministic metrics, features and
versioned DNA scores. It is internal-only, stateless, holds no credentials
and never executes repository code.

- Architecture and IR: [docs/architecture/analyzer.md](../docs/architecture/analyzer.md)
- HTTP contract (server side): [docs/api/internal-analyzer-contract.md](../docs/api/internal-analyzer-contract.md)
- Scoring rules: [ADR-004](../docs/decisions/ADR-004-dna-scoring.md)
