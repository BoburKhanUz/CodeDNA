# Analyzer Architecture

The Python analyzer is the technical core of CodeDNA. It turns a source
snapshot into deterministic metrics, a feature vector and DNA scores.

**Status: Phase 09 static analysis.** Implemented: the authenticated internal
endpoint, source retrieval from pre-signed URLs with SSRF protection, safe
extraction into per-run workspaces, deterministic file discovery,
extension-based language detection (Phase 08), and bounded Tree-sitter
parsing of ten languages into **IR 1.1**, deterministic **static metrics**
and **structural findings** in a versioned, hashed `static_analysis` result
(Phase 09). Features and DNA scoring arrive in Phase 11. Nothing in a result
is a score: metrics are measurements ([metrics-v1.md](metrics-v1.md)), and
no AI model is involved.

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

**Implemented:** fetch, verify, safe extraction, discovery and filtering,
language detection (Phase 08); parsing, lowering to IR 1.1, metrics (per
language and overall) and structural findings (Phase 09); result assembly
with `result_hash` and a signed response. Secret scanning, features and
scoring are later phases.

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
│   ├── parsing/             grammars (pinned registry), specs (per-language adapters),
│   │                        parser (bounded parsing, statuses), walker (IR 1.1), text (sanitized names)
│   ├── metrics/             aggregate (static metrics 1.0), findings (structural rule set 1.0)
│   └── services/            the analysis pipeline, run registry (in-flight, cache, concurrency)
├── tests/                   unit, API, contract-schema, parsing, golden and execution-safety tests
│   ├── fixtures/parsing/    a valid and a broken file per language (never executed)
│   └── golden/              expected IR 1.1, metrics and findings of the fixture tree
├── requirements.in / .txt   runtime (FastAPI, Uvicorn, Tree-sitter + 10 grammars) — pinned, hash-locked
└── requirements-dev.in/.txt pytest, ruff, mypy — hash-locked
```

Runtime dependencies are FastAPI, Uvicorn, the Tree-sitter runtime and one
official grammar package per language, all pinned and hash-locked. HMAC,
ZIP, HTTP download, JSON and the metrics use the Python standard library.
Later phases add `features/` and `scoring/` packages.

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
(`.env`, `*.pem`, `*.min.js`, lockfiles, ...) and secret detection. Neither
is implemented yet: Phase 09 parses analyzable files but returns only
structure, never content, and generated or minified files of a supported
language are parsed and measured like any other file (a known limitation,
see [metrics-v1.md](metrics-v1.md)).

### Never executing source

The analyzer never executes, imports, builds or installs anything from an
archive. There is no process execution in the application at all: a test
parses every module and fails on `subprocess`, `os.system`/`popen`/`exec*`/
`spawn*`, `eval`, `exec`, `compile`, `__import__`, `pickle`, `marshal`,
`importlib`, `ctypes` or any `shell=` argument. Another test plants PHP,
shell, Python, Node, npm, Composer and Make payloads that would write a
marker file and proves they never run.

## Parsing (Phase 09)

### Architecture

```text
discovery (IR 1.0 records, path order)
   │  analyzable files only
   ▼
grammar_for(language, extension) ──► registry: pinned grammar (app/parsing/grammars.py)
   │                                  + language adapter (app/parsing/specs.py)
   ▼
bounded parse (Tree-sitter, in process, time budget) ──► status
   │  PARSED only
   ▼
iterative walk (TreeCursor, explicit stacks) ──► IR 1.1 structure
   ▼
metrics 1.0 + findings 1.0 (app/metrics) ──► result
```

- **Registry.** `GRAMMARS` maps a grammar key to an official, pinned
  Tree-sitter grammar package. `SPECS` holds one **adapter** per analyzer
  language: data (node types for functions, types, decisions, nesting,
  imports and comments) plus small hooks (names, parameters, bases,
  visibility, import targets). The walker is language-independent; adding a
  language means adding a grammar and an adapter, never changing the walker.
- **Grammar selection** is by language and extension only: `.tsx` uses the
  TSX dialect and every other TypeScript extension plain TypeScript (type
  assertions and JSX are ambiguous); `.jsx` uses the JavaScript grammar,
  which includes JSX; `.h` is C.
- **Parsers per run.** One parser per grammar per run, never shared between
  concurrent runs; compiled `Language` objects are immutable and cached for
  the process.
- **Single pass.** The walk visits every node once with a `TreeCursor` and
  explicit stacks instead of recursion, so a tree of any depth cannot exhaust
  the Python stack (tested with 20,000 nested parentheses and 2,000 nested
  `if`s).
- **Parsing happens inside the run workspace**, after discovery and before
  the workspace is removed; nothing parsed outlives the request.

### Languages and grammar versions

| Language | Extensions | Grammar package | Version |
|---|---|---|---|
| PHP | `.php` | `tree-sitter-php` (PHP dialect; inline HTML allowed) | 0.24.1 |
| Python | `.py` | `tree-sitter-python` | 0.25.0 |
| JavaScript | `.js` `.mjs` `.cjs` `.jsx` | `tree-sitter-javascript` | 0.25.0 |
| TypeScript | `.ts` `.mts` `.cts` / `.tsx` | `tree-sitter-typescript` (TypeScript / TSX) | 0.23.2 |
| Go | `.go` | `tree-sitter-go` | 0.25.0 |
| Java | `.java` | `tree-sitter-java` | 0.23.5 |
| C# | `.cs` | `tree-sitter-c-sharp` | 0.23.5 |
| Rust | `.rs` | `tree-sitter-rust` | 0.24.2 |
| C | `.c` `.h` | `tree-sitter-c` | 0.24.2 |
| C++ | `.cpp` `.cc` `.cxx` `.hpp` `.hh` `.hxx` | `tree-sitter-cpp` | 0.23.4 |

Runtime: `tree-sitter` (Python binding) **0.25.2**. All are packages of the
official Tree-sitter organisation with prebuilt `abi3` wheels; no grammar is
compiled at build or run time. Every result records them in
`versions.parser_runtime` and `versions.parsers`, and a test asserts the
recorded versions equal the installed distributions.

### Parse statuses

Every analyzable file gets exactly one status (IR 1.1 `parse.status`):

| Status | Meaning | Structure and metrics |
|---|---|---|
| `PARSED` | Tree without error or missing nodes | extracted and measured |
| `PARSE_ERROR` | The grammar recovered from syntax errors; `error_nodes` and `first_error` are reported, plus a `parse/syntax-error` finding | not measured |
| `PARSE_TIMEOUT` | Parsing took longer than `ANALYZER_PARSE_TIMEOUT_MS` | not measured |
| `LIMIT_EXCEEDED` | A deterministic limit was reached; `limit` is `ast_nodes`, `total_ast_nodes` or `parsed_files` | not measured |
| `UNSUPPORTED_PARSER` | No grammar is registered for the language | not measured |

Skipped files (IR 1.0 `skip_reason` set) are never read by the parser; their
`parse` and `structure` are `null`. **One malformed, slow or huge file never
fails the run**: it gets its status and the run continues. Only the run's
hard deadline (`ANALYSIS_TIMEOUT`) fails a run, as in Phase 08.
`parsing.files` and `parsing.languages` in the result count every status.

**Unsupported languages.** A file is parsed only if its extension maps to a
requested language (Phase 08 detection). Files in other languages stay
`skip_reason: "unsupported_language"` and are never parsed. All ten detected
languages have a grammar today; `UNSUPPORTED_PARSER` exists so that a
language can be detected before it can be parsed without the analyzer ever
inventing structure for it (tested by removing a registration).

### Parser resource limits

| Limit | Variable | Default | Behaviour |
|---|---|---|---|
| Bytes per file | `ANALYZER_MAX_FILE_BYTES` (Phase 08) | 1 MiB | larger files are `too_large` and never read |
| Parse time per file | `ANALYZER_PARSE_TIMEOUT_MS` | 5,000 ms | Tree-sitter aborts the parse → `PARSE_TIMEOUT` |
| Syntax-tree nodes per file | `ANALYZER_MAX_AST_NODES` | 1,000,000 | checked before the walk → `LIMIT_EXCEEDED` (`ast_nodes`) |
| Syntax-tree nodes per run | `ANALYZER_MAX_TOTAL_AST_NODES` | 10,000,000 | later files in path order → `LIMIT_EXCEEDED` (`total_ast_nodes`) |
| Files parsed per run | `ANALYZER_MAX_PARSED_FILES` | 20,000 | later files → `LIMIT_EXCEEDED` (`parsed_files`) |
| Run deadline | `ANALYZER_HARD_TIMEOUT_SECONDS` | 240 s | checked before every file and every 32,768 walked nodes; a parse never gets more time than the run has left → `ANALYSIS_TIMEOUT` |

Files are processed in path order, so the node and file limits always cut at
the same file. **`PARSE_TIMEOUT` is the only time-dependent status.** It
appears only for files that take thousands of times longer than ordinary
code (a 1 MiB file parses in about 1 s), and a result that contains one says
so in `parsing.files`; Laravel should not treat such a result as
reproducible.

How the parse is bounded: Tree-sitter's C runtime checks a time budget while
parsing (`Parser.timeout_micros`). The newer cancellation API (a progress
callback) **crashes the Python binding** (segmentation fault, reproduced with
0.25.2 and 0.26.0), and 0.26 removed `timeout_micros`. The runtime is
therefore pinned to 0.25.2 and uses the deprecated, working timeout; the
deprecation warning (that exact message only) is filtered once at import. Upgrading the runtime
requires an equivalent, tested per-file bound.

Measured inside the analyzer container (2 CPUs, 1.5 GiB): whole pipeline
with a simulated download (extraction, discovery, parsing, IR, metrics,
findings, result):

| Input | Files | Syntax-tree nodes | Time | Result | Peak RSS |
|---|---|---|---|---|---|
| CPython 3.11 standard library (`.py`, no tests) | 672 | 2.0 M | 6.7 s | 3.2 MiB | 63 MiB |
| Laravel framework `src/` (PHP) | 1,707 | 1.5 M | 5.6 s | 3.6 MiB | 60 MiB |
| Next.js `dist/` (`.js`, without `compiled/`) | 2,142 | 2.8 M | 9.5 s | 4.8 MiB | 74 MiB |
| One 1.8 MB Python file | 1 | 1.0 M | 1.7 s (parse) | — | — |
| 50,000 nested parentheses | 1 | 0.1 M, depth 50,004 | 0.06 s (parse) | — | — |

That is about 3–4 µs per node end to end, so the 10 M run-wide budget keeps
parsing and walking well under a minute per run and inside the 240 s
deadline with two concurrent runs. No container limit was raised.

### Error handling

- Syntax errors: `PARSE_ERROR` with counts and the first error position,
  never a run failure.
- Parse time budget exhausted: `PARSE_TIMEOUT`; the parser is reset and
  reused for the next file.
- Run deadline: `ANALYSIS_TIMEOUT` for the whole run (no partial result).
- An unexpected exception in parsing or metrics is `INTERNAL_ERROR`
  (Phase 08 handler), logged with safe fields only, never with source text.
- Files are opened with `O_NOFOLLOW` inside the private run workspace; a
  symlink there (which extraction never creates) fails closed.

### Security boundary

- **Nothing is executed.** Tree-sitter reads bytes and builds a syntax tree;
  no interpreter, compiler, build tool or package manager runs. The
  execution-safety test scans every module (including `app/parsing` and
  `app/metrics`) for execution primitives and proves planted payloads never
  run.
- **In-process parsing.** The grammars are C code parsing untrusted input
  inside the analyzer process (no subprocess isolation). Mitigations:
  official, pinned, hash-locked wheels; byte, time, node and file limits;
  the hardened container (read-only root, all capabilities dropped,
  `no-new-privileges`, internal network only, memory/CPU/pid limits); fuzz
  tests with mutated inputs for every language. A crash would end that
  worker process (the container restarts it); it cannot leak data into a
  result.
- **No source text leaves the parser.** IR 1.1 holds positions, counts, kinds
  and sanitized identifiers only. Every string taken from a tree goes
  through `app/parsing/text.py`: the byte span is bounded before slicing,
  must be valid UTF-8 and must match a strict pattern (no whitespace runs,
  quotes, semicolons, parentheses or braces); anything else becomes `null`.
  Comment text, string literals, docstrings, default values and function
  bodies never appear; tests plant canaries in each and assert none appears
  anywhere in the response.

## Limits

| Limit | Variable | Default | Enforced by |
|---|---|---|---|
| Archive size | `ANALYZER_MAX_ARCHIVE_BYTES` | 50 MiB | application |
| Extracted total | `ANALYZER_MAX_EXTRACTED_BYTES` | 200 MiB | application |
| Files | `ANALYZER_MAX_FILES` | 20 000 | application |
| One extracted entry | `ANALYZER_MAX_ENTRY_BYTES` | 25 MiB | application |
| One analyzed file | `ANALYZER_MAX_FILE_BYTES` | 1 MiB (larger: skipped) | application |
| Path length | `ANALYZER_MAX_PATH_LENGTH` | 512 bytes | application |
| Parse time per file | `ANALYZER_PARSE_TIMEOUT_MS` | 5 000 ms (`PARSE_TIMEOUT`) | Tree-sitter runtime |
| Syntax-tree nodes per file / per run | `ANALYZER_MAX_AST_NODES` / `ANALYZER_MAX_TOTAL_AST_NODES` | 1 000 000 / 10 000 000 (`LIMIT_EXCEEDED`) | application |
| Files parsed per run | `ANALYZER_MAX_PARSED_FILES` | 20 000 (`LIMIT_EXCEEDED`) | application |
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

### IR 1.1 (Phase 09, frozen): parse status and structure

IR 1.1 is **additive**: every IR 1.0 field keeps its name, type and meaning,
and two fields are added to every file record. JSON Schema:
`packages/api-contracts/analyzer/v1/static-analysis-result.schema.json`
(`$defs.file`, `$defs.type`, `$defs.function`); a test asserts the IR 1.0
field definitions are identical in both schemas.

```text
FileRecord (1.1) = FileRecord (1.0) +
  parse        null (skipped file) | Parse
  structure    null (unless parse.status == "PARSED") | Structure

Parse
  status          "PARSED" | "PARSE_ERROR" | "PARSE_TIMEOUT" | "LIMIT_EXCEEDED" | "UNSUPPORTED_PARSER"
  limit           null | "ast_nodes" | "total_ast_nodes" | "parsed_files"   (LIMIT_EXCEEDED only)
  ast_nodes       int | null       all syntax-tree nodes (null when the file was not parsed)
  ast_max_depth   int | null       deepest node, root = 1 (null unless PARSED or PARSE_ERROR)
  error_nodes     int | null       error + missing nodes (0 when PARSED)
  first_error     null | { line, column }

Structure
  lines       { total, code, comment, blank }        total == IR 1.0 "lines"
  imports     [ { line, target } ]                   target: module/namespace/path or null
  types       [ Type ]                               in source order
  functions   [ Function ]                           in source order (pre-order)

Type
  kind        "class" | "interface" | "trait" | "enum" | "struct" | "union" | "record" | "annotation"
  name        string | null                          null: anonymous or not a plain name
  line, column, end_line
  visibility  null | "public" | "protected" | "private" | "internal"
              | "protected internal" | "private protected" | "restricted"
  bases       [string | null] | null                 extends/implements/base list; null = unsupported
  methods     int | null                             methods declared in the body; null = not lexical

Function
  kind        "function" | "method" | "anonymous"
  name        string | null                          null for anonymous functions
  line, column, end_line
  visibility  as for Type (null for anonymous functions)
  parameters  int                                    see metrics-v1.md "Parameters"
  parent_type int | null                             index into "types" for methods declared in a type
  complexity  int                                    cyclomatic complexity (metrics-v1.md)
  max_nesting int                                    deepest control-structure nesting
```

### Design rules

1. **Structure only.** Positions, counts, kinds and identifiers. No code,
   comment text, docstrings, string or numeric literals, default values or
   syntax-tree dumps. Names, base names and import targets pass the
   sanitizer described under "Security boundary"; when they cannot (e.g. a
   base expression with a call), they are `null`, never truncated text.
2. **Lines are 1-based, columns 0-based** (in bytes) and point at the start
   of the declaration (including modifiers and decorators where the grammar
   includes them).
3. **Deterministic.** Files in path order; types and functions in source
   order; nothing depends on time, hash seeds or enumeration order (tested
   across processes with different `PYTHONHASHSEED`s and with shuffled
   archives).
4. **Honest gaps.** A value an adapter cannot determine is `null` (e.g.
   `bases` for Go/Rust/C, `methods` for Go/Rust, whose methods live outside
   the type, `visibility` for Python/C); see
   [metrics-v1.md](metrics-v1.md#support-by-language).

### Mapping rules

- A **function** is a function-like node **with a body**. Interface and
  abstract method signatures, C prototypes and Rust trait signatures are not
  functions.
- **method**: a node that is always a method (PHP/Java/C# method,
  constructor, destructor, operator and accessor declarations, JavaScript/
  TypeScript method definitions, Go method declarations) or a function
  declared directly in a type body or a Rust `impl` block. **anonymous**:
  lambdas, closures, arrow functions, function expressions, `func` literals.
  Everything else is a **function** (including nested named functions and
  C++ methods defined outside their class).
- **visibility** is what the source states: an access keyword (PHP, Java,
  C#, TypeScript), Rust `pub` (`public`) or `pub(…)` (`restricted`),
  JavaScript/TypeScript `#private` names (`private`), C++ access labels
  (`public:` …). Go has no keyword: by the language specification an
  identifier starting with an upper-case letter is exported (`public`),
  otherwise package-private (`private`). Python's leading-underscore
  convention is not interpreted (`null`). Language defaults that are not
  written (Java package-private, C# `private` members) are `null`.
- **bases**: PHP `extends`/`implements`, Python positional base classes
  (keyword arguments such as `metaclass=` excluded), JavaScript `extends`,
  TypeScript `extends`/`implements` (class and interface), Java
  `extends`/`implements`, C# base lists, C++ base clauses.
- **imports**: Python `import`/`from … import` (one per imported module,
  one per `from` statement), PHP `use` clauses, JavaScript/TypeScript static
  `import … from`, Go import specs, Java `import` (wildcards as `.*`), C#
  `using` directives, Rust `use` (path of a use list), C/C++ `#include`.

The draft parsed-document schema of Phase 08 (formatting, comment and
identifier lists, per-branch control points) was not adopted: IR 1.1
contains only what Phase 09's metrics and findings use, plus the
declaration structure the phase requires. Fields are added in later minor
versions when a metric needs them.

### IR size

Measured: about 1.7 bytes of result per syntax-tree node (3.2–4.8 MiB for
the 1.5–2.8 M-node repositories in the benchmark table). The run-wide node
budget bounds a result to roughly 17 MiB.

## Metrics

Implemented as **static metrics 1.0**: definitions, the cyclomatic
complexity formula, per-language support (SUPPORTED / PARTIAL /
UNSUPPORTED) and the structural finding rules are in
[metrics-v1.md](metrics-v1.md). Planned for later versions (not
implemented): cognitive complexity, naming-style classification, return
points, documentation coverage, formatting consistency and typed-parameter
shares.

## Scoring

The scoring engine maps features to the DNA dimensions using a versioned
definition file. The rules (Decimal arithmetic, evidence minimums, statuses,
and so on) are in [ADR-004](../decisions/ADR-004-dna-scoring.md). Formulas
are documented in `docs/architecture/dna-scoring-v1.md` (Phase 11).

## Analysis result

A `200` response has `result_type: "static_analysis"` (Phase 09). It
contains every field of the Phase 08 foundation result with unchanged
meaning (run ID, `versions`, the `analysis` configuration, `source` counts
and sizes, per-language `files`/`bytes`/`lines`, the IR file records,
`result_hash`, `diagnostics`), with these additive changes:

- `versions.ir` is `"1.1"`, `versions.metrics` is `"1.0"`,
  `versions.parser_runtime` and `versions.parsers` name the exact Tree-sitter
  runtime and grammar package versions; `versions.scoring` stays `null`.
- `analysis` also records the parser limits (`parse_timeout_ms`,
  `max_ast_nodes`, `max_total_ast_nodes`, `max_parsed_files`), because they
  shape the result.
- `parsing`: parse-status counts, overall and per language.
- `ir.files[*]` gain `parse` and `structure` (IR 1.1).
- `metrics`: static metrics 1.0, overall and per language
  ([metrics-v1.md](metrics-v1.md)).
- `findings`: structural findings (rule set 1.0), with totals per rule.

It contains **no features, DNA scores or AI output**; Laravel must not
create a DNA snapshot from it (scoring is Phase 11). The analyzer no longer
returns `result_type: "foundation"`; its schema stays published as the IR 1.0
reference.

`result_hash` is the SHA-256 of the canonical JSON (keys sorted by code
point, no whitespace, UTF-8, no NaN; `app/canonical.py`) of every top-level
field except `request_id`, `diagnostics` and `result_hash`. The same source
bytes, analyzer, IR, metrics, parser and contract versions and
configuration always give the same hash (tested in separate processes with
different hash seeds); `versions` and `analysis` are part of the hashed
result, so a grammar upgrade or a limit change visibly changes it. The one
exception is `PARSE_TIMEOUT` (see "Parser resource limits").

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

Implemented in Phase 09:

- **Parser registry:** every detected language has an adapter and a
  grammar, every grammar loads with a compatible ABI, recorded versions equal
  the installed distributions, extension-based dialect selection, and an
  unregistered language is `UNSUPPORTED_PARSER` (never faked).
- **Per-language fixtures** (`tests/fixtures/parsing`): a valid file per
  language (and TSX) with hand-derived expected lines, imports, types and
  functions (kinds, names, visibility, parameters, complexity, nesting); a
  syntax-error file per language → `PARSE_ERROR`, not measured; else-if
  chains, line classification at line boundaries, single-parameter lambdas
  and nested functions in several languages.
- **Metrics and findings:** Decimal rounding, per-language and overall
  aggregation, `null` for no data and for unsupported metrics, strict
  thresholds, sorting, truncation, messages without names or source.
- **Limits:** per-file nodes, run-wide nodes (deterministic cut in path
  order), parsed-file count, parse timeout (with parser reuse), run
  deadline before parsing and during the walk, 20,000-deep trees without
  recursion, startup validation of every limit.
- **Determinism:** identical canonical results in three separate processes
  with different `PYTHONHASHSEED`s; shuffled archive entry order gives the
  same analysis.
- **Security:** canaries in comments, strings, docstrings, defaults and
  bodies never reach the response; names and import targets cannot carry
  code; files are not read through symlinks; mutated inputs for every
  language never crash or escape the status set; a malformed file never
  fails the run; planted payloads still never run.
- **Contract:** results validate against
  `static-analysis-result.schema.json`, and IR 1.0 field definitions are
  unchanged in IR 1.1.
- **Golden regression:** the fixture tree's full IR 1.1, metrics and
  findings must equal `tests/golden/static-analysis.json` byte for byte.
- **Mutation checks** (run manually, not in CI): 26 deliberate defects in
  the walker, parser, adapters, sanitizer, metrics, findings and pipeline;
  each of the 25 non-equivalent mutants fails the suite.

Planned with scoring (Phase 11): feature and score golden fixtures, as
ADR-004 requires.
