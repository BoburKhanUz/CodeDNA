# api-contracts

Machine-readable contracts. The human-readable specifications are in
[docs/api/](../../docs/api/README.md); when they disagree, fix both in the
same change.

| Path | Contract |
|---|---|
| [`analyzer/v1/analyze-request.schema.json`](analyzer/v1/analyze-request.schema.json) | `POST /internal/v1/analyze` request body (contract 1.0) |
| [`analyzer/v1/foundation-result.schema.json`](analyzer/v1/foundation-result.schema.json) | Its `200` body when `result_type` is `"foundation"` (Phase 08, the default), including the IR 1.0 file record |
| [`analyzer/v1/static-analysis-result.schema.json`](analyzer/v1/static-analysis-result.schema.json) | Its `200` body when the request asks for `"static_analysis"` (Phase 09): every foundation field unchanged, plus IR 1.1 file records, parse statuses, static metrics 1.0 and structural findings |
| [`analyzer/v1/error.schema.json`](analyzer/v1/error.schema.json) | The analyzer's error envelope |

The schemas are JSON Schema 2020-12 and use only a small subset of it
(`type`, `required`, `properties`, `additionalProperties` (`false` or a schema), `items`, `enum`,
`const`, `pattern`, length/number bounds, `uniqueItems` and local `$ref`),
so any validator can check them. The analyzer test suite validates real
requests and responses against them (`analyzer/tests/test_contract_schemas.py`).
The public API keeps `docs/api/README.md` as its contract until an OpenAPI
description is introduced.
