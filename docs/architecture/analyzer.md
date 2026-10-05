# Analyzer Architecture

The Python analyzer is the technical core of CodeDNA. It turns a source
snapshot into deterministic metrics, a feature vector and DNA scores. It is
**not implemented yet**: Phase 08 creates the foundation and Phase 09 adds
the parsers and metrics.

Related decisions: [ADR-002](../decisions/ADR-002-analysis-engine.md)
(Tree-sitter and the IR), [ADR-004](../decisions/ADR-004-dna-scoring.md)
(determinism and versioning), [ADR-005](../decisions/ADR-005-service-communication.md)
(communication), [ADR-003](../decisions/ADR-003-storage.md) (source
storage). Its HTTP interface is the
[internal analyzer contract](../api/internal-analyzer-contract.md).

## Responsibilities and boundaries

| The analyzer does | The analyzer never does |
|---|---|
| Fetch one archive from a pre-signed URL on an allow-listed host | Hold storage, database or queue credentials |
| Safely extract it into an ephemeral directory | Execute, import, build or install repository code |
| Discover files, detect languages, apply ignore rules | Persist anything beyond the request lifetime |
| Detect secrets (reporting locations only) | Log or return source code, comments, literals or secret values |
| Parse with Tree-sitter and lower to the IR | Call LLM providers (AI interpretation is a separate later layer) |
| Compute metrics, features and DNA scores deterministically | Decide business rules (ownership, billing, permissions) |

## Pipeline

```text
fetch ─► verify (size, sha256) ─► safe extract ─► discover & filter ─► detect language
   │
   ▼
secret scan (raw text, location-only output)
   │
   ▼
parse (Tree-sitter) ─► lower to IR (per file, sorted order)
   │
   ▼
metrics (per file → per language → overall)
   │
   ▼
features (normalized vector) ─► scoring (DNA engine, versioned definition)
   │
   ▼
result assembly ─► canonical JSON ─► result_hash ─► signed response
```

## Planned module layout (Phase 08)

```text
analyzer/
├── app/
│   ├── api/          # FastAPI routes, HMAC verification, request/response models
│   ├── services/     # fetch, safe extraction, discovery, orchestration of one run
│   ├── parsers/      # Tree-sitter setup and per-language lowering into the IR
│   ├── ast/          # IR data model (versioned) and IR utilities
│   ├── metrics/      # metric computations over IR (language-independent)
│   ├── features/     # normalization of metrics into the feature vector
│   ├── scoring/      # DNA engine + versioned scoring definitions
│   └── security/     # secret detection, path and archive safety
├── tests/
│   ├── unit/
│   ├── fixtures/     # small source trees per language
│   └── golden/       # expected IR / metrics / features / scores per fixture
├── requirements.in
├── requirements.txt  # pinned with hashes
└── Dockerfile
```

`app/ast/` holds the IR model. It is called `ast` in the master instruction's
layout, but the code it contains is the language-independent IR, not raw
Tree-sitter trees. To avoid confusion with Python's standard-library `ast`
module, the package is always imported as `app.ast`, never bare.

## Source intake and safety

- **Fetch:** HTTPS GET of `source.url`. The host must be in
  `ANALYZER_ALLOWED_SOURCE_HOSTS`. No redirects are followed. The download is
  streamed and aborted once it passes `ANALYZER_MAX_ARCHIVE_BYTES`.
- **Verify:** the size and SHA-256 must match the request.
- **Extract** (ZIP) into a fresh temporary directory. Extraction **rejects**
  absolute paths, `..` segments, symlinks and device files. It enforces
  `ANALYZER_MAX_EXTRACTED_BYTES` and `ANALYZER_MAX_FILES` while extracting,
  rather than trusting the archive headers. Nested archives are not
  extracted.
- **Default ignore rules** (not configurable by repository content in v1):
  - VCS and tooling directories: `.git/`, `.svn/`, `.hg/`, `.idea/`,
    `.vscode/`
  - dependencies: `vendor/`, `node_modules/`, `bower_components/`,
    `.venv/`, `venv/`, `site-packages/`
  - build output: `dist/`, `build/`, `out/`, `.next/`, `coverage/`,
    `__pycache__/`
  - secret-bearing files, which are never parsed: `.env`, `.env.*`, `*.pem`,
    `*.key`, `*.p12`, `*.pfx`, `id_rsa*`, `id_ed25519*`, `*.kdbx`
  - minified and generated files: `*.min.js`, `*.bundle.js`, `*.map`,
    lockfiles
  - binary files, detected by content (a NUL byte in the first 8 KiB)
  - files larger than `ANALYZER_MAX_FILE_BYTES`
- **Language detection:** by extension (`.php`; `.py`; `.js`, `.mjs`, `.cjs`,
  `.jsx`; `.ts`, `.tsx`, `.mts`, `.cts`), with shebang detection for
  extensionless scripts. Detection is a pure function of the path and the
  first line, so it is deterministic.
- **Secret detection** runs on the raw text of candidate files, including
  config files that are not parsed. It combines pattern rules with entropy
  checks. Output is limited to `{path, line, rule}`. The matched value is
  never stored, logged or returned.
- **Cleanup:** the working directory is removed in a `finally` block, whatever
  the outcome.
- **Resource limits:** hard time limit (`ANALYZER_HARD_TIMEOUT_SECONDS`),
  concurrency limit (`ANALYZER_MAX_CONCURRENCY`), and container CPU and
  memory limits (Phase 02/21). The container runs as a non-root user with a
  read-only root filesystem and a size-limited writable temp directory.

## Intermediate Representation (IR) v1.0 (draft)

> **Status: draft.** The IR is a versioned internal contract (ADR-002). This
> draft must be reviewed and **frozen as `ir_version` 1.0 at the start of
> Phase 08**, before any lowering code is written. After that, changes follow
> the versioning rules in ADR-004.

### Design rules

1. **Language-independent.** Language-specific constructs are mapped onto a
   small set of common kinds (see the mapping table). Information that doesn't
   map is dropped in v1, not smuggled in through ad-hoc fields.
2. **No source text.** There are no code bodies, comment text or literal
   values. Only identifier names, import targets and relative paths, which
   naming and structure metrics need, appear as strings.
3. **Deterministic.** IDs are derived from positions. Lists are ordered by
   `(start_line, start_column)`. Files are ordered by path.
4. **Lines are 1-based, columns are 0-based** (in bytes), and spans are
   inclusive.

### Schema

```text
IRDocument
  ir_version          "1.0"
  path                relative POSIX path, e.g. "src/Http/Controller.php"
  language            "php" | "python" | "javascript" | "typescript"
  parser              { name, version }                # exact grammar package version
  parse_status        "ok" | "partial" | "failed"      # partial = tree contains error nodes
  error_node_count    int
  lines               { total, code, comment, blank }  # a line with code and a comment counts as code
  formatting          Formatting
  imports             [Import]
  types               [TypeDecl]
  functions           [FunctionDecl]
  identifiers         [IdentifierDecl]
  comments            [Comment]

Formatting
  indent_style        "spaces" | "tabs" | "mixed" | "none"
  indent_unit         int | null        # most frequent indentation step, in columns
  line_ending         "lf" | "crlf" | "mixed"
  max_line_length     int               # in characters
  long_lines          int               # lines over 120 characters
  trailing_ws_lines   int
  quote_counts        { single, double, backtick }   # string delimiters only

Span                  { start_line, start_column, end_line, end_column }

Import
  target              normalized module/namespace string, e.g. "App\\Models\\User", "os.path", "./utils"
  kind                "module" | "symbol" | "wildcard"
  relative            bool
  line                int

TypeDecl
  id                  "type:<start_line>:<start_column>"
  kind                "class" | "interface" | "trait" | "enum" | "type_alias"
  name                string
  span                Span
  modifiers           subset of ["abstract", "final", "exported", "static"]
  extends             [string]          # names as written
  implements          [string]
  property_count      int
  method_ids          [function id]
  has_doc_comment     bool

FunctionDecl
  id                  "fn:<start_line>:<start_column>"
  kind                "function" | "method" | "constructor" | "closure" | "arrow"
  name                string | null     # null for anonymous functions
  parent_type_id      type id | null
  visibility          "public" | "protected" | "private" | "unspecified"
  is_static           bool
  span                Span
  lines_code          int               # code lines inside the span
  parameters          [{ name, has_default, has_type, is_variadic }]
  has_return_type     bool
  return_count        int
  control             [ControlPoint]
  has_doc_comment     bool

ControlPoint                            # enough to compute cyclomatic and cognitive complexity
  kind                "if" | "else_if" | "else" | "switch" | "case" | "loop" | "catch"
                      | "ternary" | "logical_sequence" | "jump" | "null_coalesce"
  nesting_level       int               # 0 = top level of the function body
  line                int
  operator_changes    int               # only for logical_sequence: number of operator-type changes

IdentifierDecl                          # declarations only, not every usage
  name                string            # language sigils stripped (e.g. PHP "$")
  role                "variable" | "parameter" | "property" | "constant"
                      | "function" | "method" | "class" | "interface"
                      | "trait" | "enum" | "type_alias"
  line                int

Comment
  kind                "line" | "block" | "doc"
  start_line          int
  end_line            int
  attached_to         type id | function id | null
```

### Language mapping (v1 targets)

| Concept | PHP | Python | JavaScript | TypeScript |
|---|---|---|---|---|
| `class` | `class` | `class` (including ABC/Protocol) | `class` | `class` |
| `interface` | `interface` | — | — | `interface` |
| `trait` | `trait` | — | — | — |
| `enum` | `enum` | `Enum` subclass is a `class` in v1 | — | `enum` |
| `type_alias` | — | — | — | `type X = ...` |
| `function` | function | `def` at module level | function declaration | function declaration |
| `method` | class method | `def` in class | class method, object method | class method |
| `constructor` | `__construct` | `__init__` | `constructor` | `constructor` |
| `closure` | `function () use` | — | function expression | function expression |
| `arrow` | `fn () =>` | `lambda` | arrow function | arrow function |
| `doc` comment | `/** ... */` | docstring (presence only) | `/** ... */` | `/** ... */` |
| `exported` | — | — | `export` | `export` |
| implicit params excluded | — | `self`, `cls` | — | `this` parameter |

## Metrics (v1 outline)

These are computed only from the IR. The exact catalogue, with a key,
definition, unit and aggregation for each metric, is written as
`docs/architecture/metrics-v1.md` in Phase 09.

- **Codebase:** files and lines (total, code, comment, blank), per language;
  counts of types by kind and functions by kind.
- **Functions:** length (mean, median, p90), parameter count, return points,
  share of typed parameters and return types.
- **Complexity:** cyclomatic complexity (1 + decision points), cognitive
  complexity (computed from `ControlPoint` kinds and nesting), maximum
  nesting.
- **Naming:** style classification (`snake_case`, `camelCase`, `PascalCase`,
  `SCREAMING_SNAKE_CASE`, `flatcase`, other) per role; conformance to each
  language's convention (PSR-12 / PEP 8 / common JS and TS practice);
  identifier length distribution; abbreviation ratio.
- **Structure:** imports per file, relative vs. absolute imports, types per
  file, methods per type, inheritance usage.
- **Documentation:** doc-comment coverage of public types and functions,
  comment-line density.
- **Formatting:** consistency of indentation style and unit, quote style,
  line endings, and the share of long lines.

## Scoring

The scoring engine maps features to the DNA dimensions using a versioned
definition file. The rules (Decimal arithmetic, evidence minimums, statuses,
and so on) are in [ADR-004](../decisions/ADR-004-dna-scoring.md). Formulas
are documented in `docs/architecture/dna-scoring-v1.md` (Phase 11).

## Testing strategy

- **Unit tests** for extraction safety (zip-slip, symlinks, bombs), ignore
  rules, language detection, HMAC verification and each metric.
- **Lowering tests:** a small source file per construct per language →
  expected IR.
- **Golden regression fixtures:** a fixture tree → expected IR, metrics,
  features, scores and `result_hash`. A change in any golden output fails CI
  unless the golden files are updated in the same change, with a version bump
  as ADR-004 requires.
- **Determinism test:** each fixture is analyzed twice, including once with a
  shuffled filesystem enumeration order. The `result_hash` must be identical.
