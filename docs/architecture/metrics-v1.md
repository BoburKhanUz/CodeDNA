# Static Metrics and Findings — version 1.0

**Status: implemented (Phase 09).** `versions.metrics = "1.0"`. Code:
`analyzer/app/metrics/` (aggregation and findings) and
`analyzer/app/parsing/` (the IR 1.1 facts they are computed from).

Static metrics are **measurements, not scores**. They are computed
deterministically from IR 1.1, never from raw source text and never by an
AI model. There is no aggregate "code quality score". Scoring (DNA
dimensions) is a separate, later layer (Phase 11) and is not part of this
catalogue.

Related: [analyzer architecture](analyzer.md) (parsing, IR 1.1, limits),
[internal contract](../api/internal-analyzer-contract.md) (result shape),
[ADR-004](../decisions/ADR-004-dna-scoring.md) (determinism and versioning).

## General rules

1. **Only PARSED files are measured.** A file whose syntax tree contains
   error or missing nodes (`PARSE_ERROR`) is counted and reported (finding
   `parse/syntax-error`), but none of its lines, declarations or functions
   enter a metric: the analyzer does not measure a tree it knows is wrong.
   The same holds for `PARSE_TIMEOUT`, `LIMIT_EXCEEDED` and
   `UNSUPPORTED_PARSER` files.
2. **Groups.** Every metric is reported once for the whole run
   (`metrics.overall`) and once per language (`metrics.by_language.<language>`),
   for every language with at least one analyzable file.
3. **Numbers.** Counts, sums and maxima are integers. Averages are computed
   with `Decimal` arithmetic and rounded half-to-even to four decimal
   places, then serialized as JSON numbers; they are identical on every
   platform.
4. **`null` means "cannot be computed", never zero.** An average or maximum
   over zero functions is `null`. A metric the language adapter cannot
   determine is `null` and its key is listed in the group's `unsupported`
   array. In `overall`, a metric is `null` when any measured language does
   not support it (a partial sum would look complete).
5. **Versioning.** Any change to a definition, threshold, rounding rule or
   rule set changes `METRICS_VERSION` (and the golden fixture,
   `analyzer/tests/golden/static-analysis.json`).

## Metric catalogue

| Key | Definition | Unit / aggregation |
|---|---|---|
| `files_analyzable` | Analyzable files (IR `skip_reason` null) in the group | count |
| `files_parsed` | Files with parse status `PARSED` | count |
| `files_parse_error` | Files with `PARSE_ERROR` | count |
| `files_parse_timeout` | Files with `PARSE_TIMEOUT` | count |
| `files_limit_exceeded` | Files with `LIMIT_EXCEEDED` | count |
| `files_unsupported_parser` | Files with `UNSUPPORTED_PARSER` | count |
| `lines_total` | Lines of PARSED files (= IR 1.0 `lines`) | sum |
| `lines_code` | Lines on which at least one non-comment token starts, ends or spans | sum |
| `lines_comment` | Lines covered only by comment tokens (and whitespace) | sum |
| `lines_blank` | `lines_total − lines_code − lines_comment` (empty or whitespace-only) | sum |
| `ast_nodes` | Syntax-tree nodes (named and anonymous) of PARSED files | sum |
| `ast_max_depth` | Deepest node in any PARSED file's tree (root = 1; comment subtrees are not entered) | max, `null` if none |
| `imports` | Import records (see IR 1.1 `imports`) | count |
| `types` | Type declarations (class, interface, trait, enum, struct, union, record, annotation) | count |
| `types_by_kind` | `types` broken down by kind (only kinds that occur) | object of counts |
| `types_with_bases` | Types that declare at least one base class / interface (`extends`, `implements`, base lists) | count, or `null` (unsupported) |
| `functions_total` | All functions with a body: named functions, methods and anonymous functions | count |
| `functions` | Functions of kind `function` (free/top-level or nested, named) | count |
| `methods` | Functions of kind `method` | count |
| `anonymous_functions` | Lambdas, closures, arrow functions, function expressions | count |
| `function_lines_avg` / `_max` | Function span in lines: `end_line − line + 1` (signature to closing token, including nested functions) | average / max over `functions_total` |
| `parameters_avg` / `_max` | Declared parameters per function (see "Parameters") | average / max |
| `nesting_avg` / `_max` | Maximum control-flow nesting depth inside each function (see "Nesting") | average / max |
| `complexity_total` | Sum of cyclomatic complexity over all functions | sum |
| `complexity_avg` / `_max` | Cyclomatic complexity per function | average / max |
| `complexity_threshold` | The threshold used below (10) | constant |
| `complexity_over_threshold` | Functions with cyclomatic complexity **> 10** | count |
| `unsupported` | Keys of metrics that are `null` because a language cannot provide them | array |

`functions_total = functions + methods + anonymous_functions`. Code that is
not inside any function (module-level statements, class bodies, field
initializers) contributes to lines and AST metrics but to no function
metric.

### Cyclomatic complexity

For each function (McCabe, 1976):

```text
CC(f) = 1 + D(f)
```

where `D(f)` is the number of **decision points** lexically inside `f` and
not inside a nested function, lambda or closure (those have their own CC).
A decision point is:

| Construct | Counts | Languages |
|---|---|---|
| `if` / `else if` / `elif` / `elseif` | 1 each (an `else` counts 0) | all |
| Loops: `for`, `for-in/of`, `foreach`, `while`, `do … while`, range-`for` | 1 each | all (Rust: `for`, `while`; an unconditional `loop` counts 0) |
| `case` labels of a `switch` | 1 per `case` label (a label with several values counts 1); `default` counts 0 | C, C++, C#, Go (`case` of expression, type and `select` switches), Java, JavaScript, TypeScript, PHP |
| Pattern matches without an explicit default label | arms − 1 per match | Rust `match`, Python `match`, C# `switch` expression |
| PHP `match` arms | 1 per non-`default` arm | PHP |
| `catch` / `except` clauses | 1 each | C++, C#, Java, JavaScript, TypeScript, PHP, Python |
| Ternary / conditional expressions (`?:`, `x if c else y`) | 1 each | all except Go and Rust (which have none) |
| Short-circuit boolean operators `&&`, `\|\|`, `and`, `or` | 1 per operator | all |
| Comprehension clauses (`for … in`, `if`) | 1 each | Python |

Not counted: `else`, `default`, `finally`, `try`, `goto`, `break`,
`continue`, `return`, null-coalescing (`??`, `?.`), Rust's `?` operator,
exception `when` filters, and the conditions of C/C++ preprocessor
directives (`#if`) — the preprocessor is not run.

### Nesting

`max_nesting` of a function is the deepest stack of nested **control
structures** inside it: `if`, loops, `switch`/`match`/`select`, and
`try` (whose `catch` bodies are inside it). The function body itself is
level 0; one `if` gives 1. An `else if` (or `elif`/`elseif`) continues its
chain at the same level instead of nesting deeper. Nesting restarts at 0
inside a nested function.

### Parameters

Declared parameters of the function's own parameter list, including
optional, defaulted, variadic/rest (`*args`, `...rest`, `params`) and
destructured parameters (each pattern counts 1). Not counted: Python's
`self`/`cls` as the first parameter of a method, TypeScript's `this`
parameter, Rust's `self` receiver, Java's receiver parameter, Go method
receivers, the `/` and `*` markers in Python signatures, and C's `(void)`.
A Go declaration `a, b int` counts 2.

### Lines

A line is **code** if a non-comment syntax token covers it (a multi-line
string or template literal makes all its lines code). A line is
**comment** if comment tokens cover it and no code token does. Every other
line is **blank**. Python docstrings are string literals and therefore
code; PHP inline HTML outside `<?php … ?>` is code. A token that ends with
a newline does not spill onto the next line.

## Support by language

`SUPPORTED`: measured exactly as defined. `PARTIAL`: measured as defined,
with a documented gap. `UNSUPPORTED`: `null` and listed in `unsupported`.

| Metric | PHP | Python | JS | TS | Go | Java | C# | Rust | C | C++ |
|---|---|---|---|---|---|---|---|---|---|---|
| File status counts, lines, AST | S | S | S | S | S | S | S | S | P¹ | P¹ |
| Imports | S | S | P² | P² | S | S | S | S | P³ | P³ |
| Types, types by kind | S | S | S | S | S | S | S | S | S | S |
| Types with bases | S | S | S | S | **U** | S | S | **U** | **U** | S |
| Functions, methods, anonymous | S | S | S | S | S | S | P⁴ | S | S | P⁵ |
| Function lines, parameters | S | S | S | S | S | S | S | S | S | S |
| Nesting | S | S | S | S | S | S | S | S | P¹ | P¹ |
| Cyclomatic complexity | S | S | S | S | S | S | S | S | P¹ | P¹ |

1. C/C++ are parsed **without running the preprocessor**: macros are not
   expanded and every branch of `#if`/`#ifdef` is parsed as written. Code
   that only makes sense after preprocessing may be `PARSE_ERROR`, and
   decisions hidden in macros are not counted.
2. Static `import` statements only; `require()`, dynamic `import()` and
   `export … from` are not imports in v1.
3. `#include` directives only.
4. Property accessors (`get`/`set`) are methods; expression-bodied
   properties without accessors are not functions.
5. A method defined outside its class (`void A::m() {}`) is a `function`,
   because methods are recognized lexically (inside the class body).

Go has no inheritance and Rust/C have no base-type syntax, so "types with
bases" is genuinely not applicable there; Go embedding and Rust supertraits
are not reported as bases in v1.

## Structural findings (rule set 1.0)

A finding is a threshold violation on IR 1.1. Thresholds are **strict**
(`value > threshold`). Messages are built only from the kind of declaration,
the measured value and the threshold, e.g. `"Method has cyclomatic
complexity 14 (threshold 10)."`: never from names, source text or
snippets, and never by an AI model.

| `rule_id` | Severity | Applies to | `value` | Threshold |
|---|---|---|---|---|
| `complexity/cyclomatic` | medium | every function | CC | 10 |
| `structure/nesting-depth` | medium | every function | `max_nesting` | 4 |
| `structure/function-length` | low | every function | span in lines | 100 |
| `structure/parameter-count` | low | every function | parameters | 5 |
| `structure/class-length` | low | every type | span in lines | 500 |
| `parse/syntax-error` | medium | every `PARSE_ERROR` file | error + missing nodes | — (`null`) |

Each finding has `rule_id`, `severity`, `path`, `line` and `column` of the
declaration (1-based line, 0-based byte column; for syntax errors the first
error node), `value`, `threshold` and `message`. Findings are sorted by
(`path`, `line`, `column`, `rule_id`). At most 2,000 are returned;
`findings.total` and `findings.by_rule` always count all of them and
`findings.truncated` says whether items were cut. `PARSE_TIMEOUT` and
`LIMIT_EXCEEDED` are analyzer limits, not properties of the code, so they
are reported in `parsing` and in the IR, not as findings.

Severities describe how strongly the rule suggests a closer look; they are
fixed per rule and never computed from other results.
