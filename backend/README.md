# backend/ — Laravel API

**Status: not created yet.** The Laravel application is scaffolded in
**Phase 03** (backend foundation). This directory holds only this README
until then.

Responsibilities: authentication, developer profiles, projects, repositories,
source snapshots, analysis orchestration (queue jobs), result persistence,
DNA snapshots, authorization and business rules. Laravel **never** parses or
analyzes source code.

- Architecture: [docs/architecture/backend.md](../docs/architecture/backend.md)
- Public API conventions: [docs/api/README.md](../docs/api/README.md)
- Analyzer contract (client side): [docs/api/internal-analyzer-contract.md](../docs/api/internal-analyzer-contract.md)
- Decisions: [ADR-001](../docs/decisions/ADR-001-stack.md), [ADR-005](../docs/decisions/ADR-005-service-communication.md), [ADR-006](../docs/decisions/ADR-006-authentication.md)

The exact Laravel and PHP versions are recorded here when the application is
created ([ADR-001 version policy](../docs/decisions/ADR-001-stack.md#version-policy)).
