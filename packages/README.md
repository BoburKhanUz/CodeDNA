# packages/ — Shared contracts

**Status:** `api-contracts/` holds the analyzer JSON Schemas (Phase 08); `types/` is still a placeholder.

| Package | Purpose | Created in |
|---|---|---|
| [`api-contracts/`](api-contracts/README.md) | Machine-readable API contracts | Analyzer JSON Schemas (Phase 08); public OpenAPI when introduced (until then `docs/api/README.md` is the contract) |
| [`types/`](types/README.md) | Shared TypeScript types generated from the contracts | When a second TS consumer or an OpenAPI description exists |

Contracts are the source of truth for both sides of every interface; the
human-readable specifications live in [docs/api/](../docs/api/README.md).
