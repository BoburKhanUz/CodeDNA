# Analyzer Architecture

The Python analyzer is the technical core of CodeDNA. It turns a source
snapshot into deterministic metrics, a feature vector and DNA scores.

**Status: Phase 08 foundation.** Implemented: the authenticated internal
endpoint, source retrieval from pre-signed URLs with SSRF protection, safe
extraction into per-run workspaces, deterministic file discovery,
extension-based language detection, and a versioned, hashed **foundation
result**. Parsing (Tree-sitter), the parsed IR, metrics and features begin in
Phase 09, and scoring in Phase 11. Nothing in a foundation result is a score
or a quality metric.

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

**Phase 08 implements** fetch, verify, safe extraction, discovery and
filtering, language detection, and result assembly with `result_hash` and a
signed response (a *foundation* result). Secret scanning, parsing, the
parsed IR, metrics, features and scoring are later phases.

## Module layout

```text
analyzer/
├── app/
│   ├── main.py              create_app(): settings, routes, error handlers, startup workspace sweep
│   ├── config.py            Settings from the environment, validated at startup (all limits live here)
│   ├── versions.py          ANALYZER_VERSION, CONTRACT_VERSION, IR_VERSION, result types
│   ├── errors.py            contract error vocabulary (code, HTTP status, retryable, fixed message)
│   ├── canonical.py         canonical JSON and SHA-256
│   ├── deadline.py          cooperative time limits
│   ├── logging_setup.py     structured JSON logs (safe fields only)
│   ├── api/                 health, analyze (HMAC, signed responses), request IDs
│   ├── auth/                HMAC signing/verification, bounded replay cache
│   ├── contracts/           request model (strict, duplicate keys rejected)
│   ├── source/              URL policy (SSRF boundary), bounded pinned-IP download
│   ├── workspace/           per-run directories, cleanup, stale sweep
│   ├── archive/             entry-name rules, safe ZIP extraction
│   ├── discovery/           language table, deterministic discovery (IR 1.0 file records)
│   └── services/            the foundation pipeline, run registry (in-flight, cache, concurrency)
├── tests/                   unit, API, contract-schema and execution-safety tests
├── requirements.in / .txt   runtime (FastAPI, Uvicorn) — hash-locked
└── requirements-dev.in/.txt pytest, ruff, mypy — hash-locked
```

The runtime has **no dependencies beyond FastAPI and Uvicorn**: HMAC, ZIP,
HTTP download and JSON use the Python standard library. Later phases add
`parsers/`, `ir/`, `metrics/`, `features/` and `scoring/` packages.

## Request handling

`POST /internal/v1/analyze` ([contract](../api/internal-analyzer-contract.md)):

1. **Body bound:** at most 64 KiB, checked before the body is read fully.
2. **Authentication:** HMAC-SHA256 over the canonical string (timestamp,
   method, path, `X-Request-ID`, SHA-256 of the exact body bytes), compared
   with `hmac.compare_digest` against the current and, during rotation, the
   previous secret. The timestamp must be within ±300 s. Failures are
   `401` and unsigned; nothing else happens.
3. **Replay protection:** see below.
4. **Parsing:** strict JSON (duplicate keys rejected, no type coercion),
   unknown fields ignored, `Idempotency-Key` must equal `analysis_run_id`.
5. **Run coordination:** completed-result cache, in-flight and conflict
   checks, concurrency limit (see [Idempotency](#idempotency)).
6. **Pipeline** in a worker thread under the hard deadline. Every response
   from here on, errors included, is signed.

### Replay protection

A captured request stays valid for the ±300 s window, so each accepted
`X-Request-ID` is remembered until its timestamp leaves the window. A second
request with the same ID is `401 REPLAY_DETECTED` (legitimate retries use a
new request ID per attempt). The cache is bounded
(`ANALYZER_REPLAY_CACHE_ENTRIES`, default 100 000): expired IDs are evicted
first, and if it is still full, requests get `503 ANALYZER_BUSY` instead of
forgetting IDs that could be replayed.

**Limitation:** the cache is per process. With several analyzer instances
behind a load balancer, a replay could reach another instance. That needs a
shared store owned by the analyzer (it must not use Laravel's Redis); it is
deferred until the analyzer is scaled out. A replay can only repeat a
deterministic analysis of a source the original request was authorized for.

### Idempotency

`analysis_run_id` (also the `Idempotency-Key`) identifies a run. The run's
fingerprint is the SHA-256 of its run ID, contract major, source identity
(type, format, SHA-256, size) and analysis configuration; the pre-signed URL
is excluded because Laravel signs a new one per attempt.

| Situation | Response |
|---|---|
| Same run in flight, same fingerprint | `409 RUN_IN_PROGRESS` (retryable) |
| Same run, different fingerprint (in flight or recently completed) | `409 RUN_CONFLICT` |
| Same run completed recently, same fingerprint | `200` with the cached result, `Idempotent-Replayed: true`, no reanalysis |
| Otherwise | analyzed; deterministic, so repeating it gives the same `result_hash` |

The completed-result cache is bounded by size and age
(`ANALYZER_RESULT_CACHE_BYTES` 32 MiB, `ANALYZER_RESULT_CACHE_SECONDS` 900).
At most `ANALYZER_MAX_CONCURRENCY` analyses run at once; more get
`503 ANALYZER_BUSY` with `Retry-After: 5`. All of this is coordination state
per process, not analysis state, and is safely lost on restart.

## Source intake and safety

### Source access (SSRF boundary)

The analyzer has **no storage credentials**. It accepts only a SigV4
pre-signed GET URL (`app/source/url_policy.py`):

- scheme `https`; plain `http` only for hosts in
  `ANALYZER_LOCAL_SOURCE_HOSTS` (local development: `minio`; must be empty in
  production);
- the hostname exactly one of `ANALYZER_ALLOWED_SOURCE_HOSTS` (no wildcards;
  IP literals, `user:password@` and fragments rejected);
- the query has every SigV4 parameter, is not expired (`SOURCE_URL_EXPIRED`,
  retryable because Laravel signs a fresh URL per attempt), was not signed
  in the future, and its lifetime is at most
  `ANALYZER_MAX_URL_LIFETIME_SECONDS` (3600);
- the object key ends in `projects/<ULID>/snapshots/<ULID>/source.zip`;
- **every** address the host resolves to must be public (`is_global`). For a
  local host, private addresses are allowed but loopback, link-local
  (including `169.254.169.254` metadata endpoints), multicast, unspecified
  and reserved addresses never are. IPv4-mapped IPv6 is unwrapped first.

The downloader then connects to the **validated address** (pinned), sending
the original `Host` header and, for HTTPS, verifying the certificate against
the hostname. A DNS answer cannot change between the check and the
connection (no DNS rebinding).

### Download

`app/source/download.py`, standard-library `http.client`:

- never follows redirects (a `3xx` is `SOURCE_FETCH_FAILED`, reason
  `redirect_not_followed`); sends no credentials or cookies;
- rejects a request whose declared size exceeds `ANALYZER_MAX_ARCHIVE_BYTES`
  before connecting, and a `Content-Length` above it before reading;
- streams 64 KiB chunks to `source.zip` in the run workspace (`O_EXCL |
  O_NOFOLLOW`, mode 0600), counting and hashing, and stops as soon as the
  bytes exceed the limit or the declared size;
- connect timeout 5 s, per-read timeout 30 s, whole download 120 s, all
  capped by the run's hard deadline;
- afterwards the size and SHA-256 must equal the request
  (`SOURCE_CHECKSUM_MISMATCH`).

### Workspaces

Each run gets `<ANALYZER_WORKSPACE_ROOT>/run-<32 random hex>` (mode 0700). The
name never comes from request data (not even the run ID), so no input can
influence a filesystem path. The directory is removed in a `finally` block on
success, error and timeout; leftovers from a crashed process are removed at
startup. In Docker the root is a dedicated 512 MiB tmpfs owned by the
analyzer user.

### Archive extraction

The analyzer trusts nothing from Laravel's upload checks
(`app/archive/`). Before anything is written, every central-directory entry
is checked:

| Check | Error |
|---|---|
| Name: `..`, absolute path, drive letter, backslash, control character, `.`/empty segment, longer than `ANALYZER_MAX_PATH_LENGTH` (512 bytes) | `INVALID_ARCHIVE` (`details.reason`) |
| Type: symlink, device, FIFO, socket | `INVALID_ARCHIVE` |
| Encrypted entries, compression other than stored/deflated | `INVALID_ARCHIVE` |
| Duplicates, a path that is both file and directory, data before the first entry, overlapping entries, corrupt structures | `INVALID_ARCHIVE` |
| More than `ANALYZER_MAX_FILES` files (or twice that many entries) | `413 TOO_MANY_FILES` |
| An entry over `ANALYZER_MAX_ENTRY_BYTES`, or a total over `ANALYZER_MAX_EXTRACTED_BYTES` | `413 SOURCE_TOO_LARGE` (`entry_too_large` / `extracted_too_large`) |

Files are then streamed out with `zipfile` (which also checks each CRC-32) in
64 KiB chunks, re-checking the real byte counts, into directories the
analyzer created, with `O_EXCL | O_NOFOLLOW` and mode 0600. A header that
understates a size (a zip bomb) cannot exceed the limits. Nested archives
are not extracted. The archive file is deleted after extraction to free
space.

### Discovery and language detection

`app/discovery/` walks the extracted tree in sorted order and produces one
IR file record per regular file. Files under an ignored directory (any path
segment equal to one of `ANALYZER_IGNORED_DIRECTORIES`) are only counted.
Default ignored directories: `.git`, `.hg`, `.svn`, `node_modules`,
`vendor`, `bower_components`, `dist`, `build`, `out`, `.next`, `.nuxt`,
`coverage`, `.cache`, `cache`, `tmp`, `temp`, `.tmp`, `__pycache__`,
`.venv`, `venv`, `.tox`, `.mypy_cache`, `.pytest_cache`, `__MACOSX`.

Language detection is the lowercased final extension through a fixed table,
nothing else (no shebangs, no content sniffing):

| Language | Extensions |
|---|---|
| `php` | `.php` |
| `python` | `.py` |
| `javascript` | `.js`, `.mjs`, `.cjs`, `.jsx` |
| `typescript` | `.ts`, `.tsx`, `.mts`, `.cts` |
| `go` | `.go` |
| `java` | `.java` |
| `csharp` | `.cs` |
| `rust` | `.rs` |
| `c` | `.c`, `.h` (shared with C++; only parsing could tell) |
| `cpp` | `.cpp`, `.cc`, `.cxx`, `.hpp`, `.hh`, `.hxx` |

Anything else has `language: null`. A file is **analyzable** when its
language is requested (all ten by default, or `options.languages`), it is at
most `ANALYZER_MAX_FILE_BYTES` (1 MiB), and it is not binary (no NUL byte in
the first 8 KiB); otherwise it is skipped as `unsupported_language`,
`too_large` or `binary`. Only analyzable files are read, to count lines. A
source with no analyzable file is `422 NO_SUPPORTED_FILES`.

The Phase 00 plan also listed secret-bearing and generated-file patterns
(`.env`, `*.pem`, `*.min.js`, lockfiles, ...) and secret detection. They
matter once file contents are parsed and arrive with Phase 09; the
foundation reads no content beyond counting newlines and never returns any.

### Never executing source

The analyzer never executes, imports, builds or installs anything from an
archive. There is no process execution in the application at all: a test
parses every module and fails on `subprocess`, `os.system`/`popen`/`exec*`/
`spawn*`, `eval`, `exec`, `compile`, `__import__`, `pickle`, `marshal`,
`importlib`, `ctypes` or any `shell=` argument. Another test plants PHP,
shell, Python, Node, npm, Composer and Make payloads that would write a
marker file and proves they never run.

## Limits

| Limit | Variable | Default | Enforced by |
|---|---|---|---|
| Archive size | `ANALYZER_MAX_ARCHIVE_BYTES` | 50 MiB | application |
| Extracted total | `ANALYZER_MAX_EXTRACTED_BYTES` | 200 MiB | application |
| Files | `ANALYZER_MAX_FILES` | 20 000 | application |
| One extracted entry | `ANALYZER_MAX_ENTRY_BYTES` | 25 MiB | application |
| One analyzed file | `ANALYZER_MAX_FILE_BYTES` | 1 MiB (larger: skipped) | application |
| Path length | `ANALYZER_MAX_PATH_LENGTH` | 512 bytes | application |
| Hard time limit per request | `ANALYZER_HARD_TIMEOUT_SECONDS` | 240 s | application (cooperative deadline) |
| Download | `ANALYZER_SOURCE_CONNECT/READ/DOWNLOAD_TIMEOUT_SECONDS` | 5 / 30 / 120 s | application |
| Concurrent analyses | `ANALYZER_MAX_CONCURRENCY` | 2 | application |
| Request body | — | 64 KiB | application |
| Memory | `mem_limit` | 1.5 GiB (includes the workspace tmpfs) | container |
| CPU | `cpus` | 2 | container |
| Processes | `pids_limit` | 256 | container |
| Workspace disk | tmpfs `/tmp/codedna` | 512 MiB | container |

The archive, extracted and file-count defaults equal the Laravel upload
limits (Phase 07), so an accepted upload is analyzable. Python does not try
to enforce CPU or memory itself; the container does. Because nothing is
executed, a cooperative deadline (checked between chunks, entries and
files) is enough to bound every step.

## Intermediate Representation (IR)

The IR is a versioned internal contract (ADR-002, ADR-004).

### IR 1.0 (Phase 08, frozen): file records

The foundation's IR is the file inventory: one record per discovered file,
sorted by path (Unicode code point order). It never contains source text.
JSON Schema: `packages/api-contracts/analyzer/v1/foundation-result.schema.json`
(`$defs.file`).

```text
FileRecord
  path          relative POSIX path, exactly as in the archive
  extension     lowercased final extension including the dot, or null
  language      language identifier from the table above, or null
  size_bytes    int
  lines         int (newline count, plus one for a final line without a newline) | null when not read
  skip_reason   null (analyzable) | "unsupported_language" | "too_large" | "binary"
```

### Parsed documents (draft, Phase 09)

> **Status: draft.** The per-file parsed document below is frozen in Phase 09
> together with the first parser, and added to the IR as an additive minor
> version (IR 1.1). Its `lines.total` matches IR 1.0's `lines`. Freezing it
> in Phase 08 would have fixed a schema that no code exercised yet.

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

## Foundation result

A `200` response with `result_type: "foundation"` contains the run ID,
`versions` (`analyzer`, `ir`, and `metrics`/`scoring` as `null`), the
`analysis` configuration that shaped it (requested languages, ignored
directories, max file size), `source` counts and sizes, per-language
`files`/`bytes`/`lines`, the IR file records, `result_hash` and
`diagnostics`. It contains **no metrics, features, DNA scores or findings**;
Laravel must not create a DNA snapshot from it (Phase 10).

`result_hash` is the SHA-256 of the canonical JSON (keys sorted by code
point, no whitespace, UTF-8, no NaN; `app/canonical.py`) of every top-level
field except `request_id`, `diagnostics` and `result_hash`. The same source
bytes, analyzer version, IR and contract versions and configuration always
give the same hash; the configuration is part of the hashed result, so a
different configuration visibly changes it.

## Testing strategy

Implemented (Phase 08, `make test` and `make lint-analyzer`: ruff, ruff
format, mypy `--strict`):

- HMAC: canonical string, valid/invalid/missing/malformed signatures,
  rotation, freshness window (past and future), constant-time comparison,
  every single-byte change invalidates (generated cases), replay cache.
- Source access: allow-list, schemes, IP literals, loopback, private,
  link-local and metadata addresses, DNS pinning, expired and not-yet-valid
  URLs, key layout; the downloader against a real local HTTP server
  (redirects, statuses, size limits with and without `Content-Length`,
  checksums, read and overall timeouts).
- Archives: traversal, absolute, drive and Windows paths, symlinks, special
  files, encryption, compression methods, limits, bombs, duplicates,
  overlaps, prepended data, CRC errors, generated-name fuzzing.
- Workspaces, discovery, language table, determinism, result hashing,
  idempotency, concurrency, timeouts, safe errors and logs, and the
  published JSON Schemas.
- Execution safety: planted payloads never run; no execution primitives in
  the code.

`make verify` also runs a live Laravel → analyzer call through the Docker
network (`scripts/verify-analyzer.php`).

Planned with parsing and metrics:

- **Unit tests** for each metric and for secret detection.
- **Lowering tests:** a small source file per construct per language →
  expected IR.
- **Golden regression fixtures:** a fixture tree → expected IR, metrics,
  features, scores and `result_hash`. A change in any golden output fails CI
  unless the golden files are updated in the same change, with a version bump
  as ADR-004 requires.
- **Determinism test:** each fixture is analyzed twice, including once with a
  shuffled filesystem enumeration order. The `result_hash` must be identical.
