# ADR-002: Analysis Engine — Tree-sitter and a Versioned Intermediate Representation

- **Status:** Accepted
- **Date:** 2026-10-05
- **Related:** [ADR-001](ADR-001-stack.md), [ADR-004](ADR-004-dna-scoring.md), [Analyzer architecture](../architecture/analyzer.md)

## Context

CodeDNA must analyze several languages: PHP, Python, JavaScript and
TypeScript first, then Go, Java, C#, Rust and C/C++. Writing separate metric
and scoring logic for every language would multiply effort and make scores
incomparable across languages.

The analyzer must also be deterministic (ADR-004) and must never execute the
code it analyzes.

## Decision

1. **Parsing uses Tree-sitter** through `py-tree-sitter` with pinned,
   per-language grammar packages. Tree-sitter is error-tolerant: it produces a
   tree even for files with syntax errors. Error nodes are counted and
   reported, so analysis does not fail because of one bad file.
2. **Parsers lower each syntax tree into a language-independent Intermediate
   Representation (IR).** All metrics, features and scoring operate **only on
   the IR**, never on raw Tree-sitter nodes.

   ```text
   source file ─► language detector ─► Tree-sitter parser ─► language lowering ─► IR document
                                                                                      │
                     metrics ◄─────────────────────────────────────────────────────────┘
                        │
                     features ─► scoring (DNA engine)
   ```

3. **The IR is a versioned internal contract** (`ir_version`, starting at
   `1.0`). Its draft schema is specified in
   [analyzer.md § Intermediate Representation](../architecture/analyzer.md#intermediate-representation-ir).
   It **must be reviewed and frozen at the start of Phase 08, before any
   parser code is written.**
4. **The IR contains no raw source text and no literal values.** It holds
   structure, positions, counts, identifier names, import targets and
   relative file paths. This limits how far a secret embedded in source can
   spread through later pipeline stages.
5. **Static analysis only.** The analyzer never executes, imports, evaluates,
   builds or installs repository content.
6. **Language rollout is incremental.** Phase 08 delivers the analyzer
   skeleton and the IR. Phase 09 adds parsers one language at a time, in this
   priority order: PHP, Python, JavaScript, TypeScript. A language counts as
   "supported" only when it has lowering tests and golden fixtures.

## Consequences

- Adding a language means writing one lowering module and its fixtures.
  Metrics and scoring are reused unchanged.
- Tree-sitter gives syntax, not semantics: there is no type resolution and no
  cross-file symbol resolution. Metrics that need semantics, such as precise
  coupling, are approximated from imports and declarations. Their names and
  documentation must say so.
- IR changes are deliberate, versioned events, governed by the rules in
  ADR-004.
- Grammar upgrades can change parse trees. Grammar versions are pinned, and
  upgrading one requires re-running golden fixtures (see ADR-004).

## Alternatives considered

- **Native parsers per language** (`nikic/php-parser`, Python `ast`, the
  TypeScript compiler API, etc.): these give more semantic precision, but
  they need multiple runtimes inside the analyzer and have a different API
  for each language. They may be added later as optional enrichers for a
  specific language, feeding the same IR.
- **Existing linters** (PHPStan, ESLint, pylint) as the metric source: rejected
  as the primary engine. Their outputs differ between tools, their versions
  drift, and some of them load or execute project configuration.
- **LLM-based analysis:** rejected for metrics. Metrics must be
  deterministic (master instruction §15).
