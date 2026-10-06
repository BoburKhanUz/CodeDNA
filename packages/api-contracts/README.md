# api-contracts

**Status: not created yet.**

Planned layout:

```text
api-contracts/
├── public/v1/openapi.yaml          # public /api/v1, when introduced (docs/api/README.md is the contract until then)
└── analyzer/v1/                    # internal analyzer contract (Phase 08)
    ├── analyze-request.schema.json
    ├── analyze-response.schema.json
    ├── error.schema.json
    └── hmac-test-vectors.json      # shared signing test vectors for PHP and Python
```

Specifications: [public API conventions](../../docs/api/README.md),
[internal analyzer contract](../../docs/api/internal-analyzer-contract.md).
