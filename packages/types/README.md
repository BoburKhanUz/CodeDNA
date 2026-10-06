# types

**Status: not created yet.** In Phase 04 the frontend is the only TypeScript
consumer of the API, so its contract types live next to it in
`frontend/src/lib/api/types.ts` (a hand-maintained mirror of the Laravel
resources and error vocabulary, documented in `docs/api/README.md`).

Types move here, generated from an OpenAPI description in
`packages/api-contracts`, once a second TypeScript consumer appears or the
API surface grows enough to justify code generation. Generated types are
never edited by hand.
