# Coding Challenges v1

- **Phase:** 16
- **Versions:**
  - catalog `1.0.0`;
  - selection `challenge-selection/1.0.0`;
  - evaluation `challenge-evaluation/1.0.0`;
  - definition schema `challenge-definition/1`;
  - evaluator protocol `codedna-evaluator/1`.
- **Decision:** [ADR-008](../decisions/ADR-008-coding-challenges.md)
- **Sandbox:** [challenge-evaluator.md](challenge-evaluator.md)

A coding challenge is a short, self-contained exercise. It is selected
deterministically from a measurable skill gap of a project's newest skill
gap analysis ([skill-gap-v1.md](skill-gap-v1.md)), then checked by
deterministic tests and code-structure rules in an isolated sandbox.

## Challenge completion ≠ CodeDNA improvement

Challenges are a **practice layer**. They read stored analysis results and
never write them:

```text
Source → Analysis → DNA → Competencies → Skill gaps ──(read only)──► Challenge selection
                                                                       │
                                                         Submission → Evaluator → Evaluation result
```

There is **no arrow from an evaluation result to CodeDNA**. A passed
challenge never changes, recomputes, closes or reprioritizes any of these:

- a DNA score;
- a competency score or level;
- a skill gap, its priority, or the target profile;
- any snapshot or fingerprint.

The only thing that can change CodeDNA is a new analysis of new source
code. The API (`notice` field) and the UI say so on every challenge:

> Completing this challenge does not immediately change your CodeDNA score
> or skill gap. Reassessment occurs from new code analysis.

Challenges use no AI. They work with `AI_ENABLED=false`, and a test
asserts this (`ChallengeApiTest`).

## Catalog

The catalog is owned by the server: it is versioned, and a published
version is immutable.

- **Location.** `backend/resources/challenges/v1/<KEY>.json`. Each file is
  validated against `challenge-definition-1.schema.json` by
  `ChallengeCatalog` at load, and by `ChallengeCatalogTest`.
- **Identity.** A definition is identified by a stable key and version
  (`FUNCTION_DESIGN_001` `1.0.0`). Changing a published definition means
  publishing a new version under a new catalog version; nothing is edited
  in place.
- **Fingerprints.** A definition has a `definition_fingerprint` (canonical
  JSON) and a `test_suite_fingerprint` (cases and rules). The catalog has
  a fingerprint over all of its definitions. Catalog 1.0.0 is
  `23ede448f8b6a6ad97025e3b19745957dfda1875c76c483651fc6d6f6001ab9c`.
- **Stored copies.** On first use, a definition is copied into
  `challenge_definitions`. A database trigger makes that copy immutable.
  The job compares the stored fingerprints before every evaluation.
- **Categories.** Only the measurable competencies have definitions:
  `COMPLEXITY_MANAGEMENT`, `FUNCTION_DESIGN`, `TYPE_STRUCTURE` and
  `CODE_HYGIENE`. A gap in any other competency gets no challenge.
- **Difficulty.** An enum: `BEGINNER`, `INTERMEDIATE` or `ADVANCED`. It
  describes the exercise. It never describes the developer and never
  implies a seniority.
- **Language.** Python 3.11 only, the one language with a real sandbox
  ([challenge-evaluator.md](challenge-evaluator.md)). A definition in any
  other language is rejected at load, because there is no execution path
  for it and no execution is faked.

A definition contains:

- the title, summary, instructions, constraints and estimated minutes;
- the entrypoint (a function name) and the starter code;
- the acceptance criteria, the code-structure rules, and the test cases.

A test case is a call with JSON arguments and an expected JSON value. Each
case is `VISIBLE` or `HIDDEN`.
Clients can never supply definitions, tests, commands, images, runtimes,
scripts, dependencies or evaluation code. Any request field outside the
documented ones is rejected.

### Catalog 1.0.0

| Key | Category | Difficulty | Title |
|---|---|---|---|
| CODE_HYGIENE_001 | CODE_HYGIENE | BEGINNER | Repair the configuration parser |
| COMPLEXITY_MANAGEMENT_001 | COMPLEXITY_MANAGEMENT | BEGINNER | Shipping costs without the branching |
| COMPLEXITY_MANAGEMENT_002 | COMPLEXITY_MANAGEMENT | INTERMEDIATE | Password rules as data |
| FUNCTION_DESIGN_001 | FUNCTION_DESIGN | BEGINNER | Split the order summary |
| FUNCTION_DESIGN_002 | FUNCTION_DESIGN | INTERMEDIATE | Narrow the formatting interface |
| TYPE_STRUCTURE_001 | TYPE_STRUCTURE | INTERMEDIATE | Split the inventory type |

Reference solutions are in `backend/tests/Fixtures/challenges/`. Tests
check that each reference solution passes and each starter fails. This was
also run against the real evaluator.

## Selection

`ChallengeSelector` (`challenge-selection/1.0.0`) is a pure function. The
same gaps, catalog, history and request always give the same selection. It
uses no randomness, no clock and no AI.

1. **Eligible gaps.** Skill gap results with status `GAP` whose competency
   has a catalog category.
2. **Gap order.**
   1. Priority: `HIGH`, then `MEDIUM`, then `LOW`.
   2. Raw gap, largest first.
   3. Competency key.

   When the request names a `competency_key`, only that gap is considered.
3. **Candidates.** The newest version of each definition of the gap's
   category, in an executable language, minus the definitions already
   assigned for this skill gap snapshot.
4. **Candidate order.**
   1. Definitions not yet passed in this project come first.
   2. Then the difficulty preferred for the gap's priority: `BEGINNER` for
      `HIGH` and `MEDIUM`, `INTERMEDIATE` for `LOW`.
   3. Then the nearest difficulty.
   4. Then the key.
5. **Result.** The first gap that has a candidate wins, and its first
   candidate is selected.

The rule that applied is recorded with the challenge in `selection`, along
with the gap's values at selection time:

| Rule | When it applies |
|---|---|
| `TOP_PRIORITY_GAP` | The first eligible gap was used. |
| `NEXT_ELIGIBLE_GAP` | Earlier gaps had no candidates left. |
| `REQUESTED_COMPETENCY` | The client named the competency. |

Errors, all 409:

- `CHALLENGE_NO_ELIGIBLE_GAP`: no gap of a supported competency.
- `CHALLENGE_NONE_AVAILABLE`: every candidate was already assigned for this
  snapshot.

## Assignment

`POST /projects/{project}/challenges`:

- **Request.** `{}` or `{skill_gap_snapshot_id?, competency_key?}`. The
  default snapshot is the newest one.
- **Lineage.** A challenge instance records the full lineage:
  - skill gap snapshot;
  - competency snapshot;
  - DNA snapshot;
  - analysis run;
  - source snapshot.

  A composite foreign key onto the skill gap snapshot's lineage makes
  mismatches impossible. The instance also records the definition key and
  version, catalog version and fingerprint, selection version and
  provenance, and `max_attempts`.
- **Idempotency.** An active challenge (ASSIGNED or EVALUATING) for the
  same snapshot and competency is returned with 200 and
  `Idempotent-Replayed: true`. Without a requested competency, the newest
  active challenge of the snapshot is returned.
- **Concurrency.** The project row is locked for the decision. Two
  database constraints back it up:
  - the partial unique index `challenge_instances_active_per_gap_unique`;
  - the unique key `challenge_instances_definition_per_snapshot_unique`
    on `(skill_gap_snapshot_id, definition_key)`.
- **Read-only towards analysis.** The action reads skill gaps and never
  changes them. A test compares the skill gap snapshot and results, the
  competency snapshot, the DNA snapshot and the analysis run before and
  after a challenge is passed (`ChallengeApiTest`).

## Submission

`POST /projects/{project}/challenges/{challenge}/submissions` takes
`{language, source}`.

**Validation, before anything is stored:**

- `language` must equal the challenge's language (a database trigger
  checks this too);
- the source is UTF-8, with no NUL characters and not blank;
- at most `CHALLENGE_MAX_SOURCE_BYTES` (16384) bytes and
  `CHALLENGE_MAX_SOURCE_LINES` (400) lines;
- any other field is rejected;
- `TrimStrings` is disabled for this route, so the stored source is
  exactly what was sent.

**Preconditions:**

- the challenge is `ASSIGNED`;
- the project is active (archived projects are read-only);
- graded attempts are left;
- an evaluator is configured and its heartbeat is fresh. Otherwise the API
  returns `CHALLENGE_EVALUATION_UNAVAILABLE`, and nothing ever runs the
  code anywhere else.

**Immutable attempts.** Every attempt is a new row, with:

- its attempt number;
- the source and its SHA-256 (a CHECK constraint recomputes it);
- the definition and test suite fingerprints;
- the evaluation version.

A trigger allows only the lifecycle columns to change, and only forward.
The source, attempt number and fingerprints can never change.

**One pending attempt.** The challenge becomes `EVALUATING` until its
result arrives. The partial unique index
`challenge_submissions_one_pending_unique` backs this up, so a second
submit returns `CHALLENGE_EVALUATION_PENDING`.

**Idempotency-Key** (optional, 8–128 characters of `A-Z a-z 0-9 . _ : -`):

- the same key with the same source returns the original attempt (200,
  `Idempotent-Replayed: true`);
- the same key with a different source returns `IDEMPOTENCY_KEY_REUSED`;
- only the key's SHA-256 is stored.

**Queueing.** The job is dispatched after commit on queue `challenge`
(connection `analysis`). The response is 202.

## Evaluation

The job `EvaluateChallengeSubmission` runs on the queue worker:

1. **Claim.** It claims the submission with a fresh `claim_token` and a
   lease. The lease can be taken over from a job whose lease has expired.
2. **Check.** It re-checks the stored definition against the catalog
   fingerprints.
3. **Evaluate.** It sends the request to the evaluator through the spool
   ([challenge-evaluator.md](challenge-evaluator.md)). The request carries
   the source, the entrypoint, the case arguments and the rule names.
   **Expected values are never sent to the evaluator.**
4. **Validate.** It validates the result against
   `evaluator-result-1.schema.json` and grades it with `ChallengeGrader`.
5. **Write.** It writes the result **only if it still holds its claim
   token**, in the same transaction that updates the challenge. A stale job
   whose lease was taken over writes nothing. This is tested with two real
   processes (`ChallengeMigrationAndConcurrencyTest`).

**Grading** (`challenge-evaluation/1.0.0`, a pure function):

- **Cases.** A case passes only on exact JSON equality, type-strict (`5`
  is not `5.0`). A raised exception is an `ERROR` case, reported with its
  type.
- **Rules.** Each structural rule is checked against the evaluator's `ast`
  metrics:

  | Rule | Requirement |
  |---|---|
  | `syntax_valid` | The file parses. |
  | `max_function_complexity` | Cyclomatic complexity of every function is at most the limit. |
  | `max_function_nesting` | Block nesting of every function is at most the limit. |
  | `max_function_lines` | Every function has at most the limit of lines. |
  | `max_function_parameters` | Every function has at most the limit of parameters. |
  | `max_class_lines` | Every class has at most the limit of lines. |
  | `max_class_methods` | Every class has at most the limit of methods. |
  | `min_classes` | The file defines at least the limit of classes. |

- **Criteria.** Each acceptance criterion lists the cases and rules it
  needs.
- **Verdict.** `PASSED` only when execution `COMPLETED` and every
  criterion passed; otherwise `FAILED`.
- **Execution statuses.** `SYNTAX_ERROR`, `LOAD_ERROR`, `TIMEOUT`,
  `OUTPUT_LIMIT` and `CRASHED` are graded outcomes: the attempt is FAILED
  and counts.
- **Feedback.**
  - Visible cases show the arguments, the expected value and the observed
    value. The observed value is truncated to 1000 bytes; a value nested
    too deeply never matches and is flagged.
  - Hidden cases show only id, status and exception type. Hidden arguments
    and expected values are never in any response.
  - Feedback is about the exercise only. It contains no score, no judgment
    of the developer and no CodeDNA value.
- **Fingerprint.** The evaluation is stored with its fingerprint.

**Failures that consume no attempt** (submission status `ERROR`):

| Code | Cause |
|---|---|
| `EVALUATOR_UNAVAILABLE` | The evaluator stayed unavailable after the job's retries (3 attempts, backoff 10 s and 30 s). |
| `EVALUATION_TIMEOUT` | No result arrived within the wait (retried like an unavailable evaluator), or the job hit its worker timeout. |
| `EVALUATION_INTERRUPTED` | The evaluator restarted during the run. Interrupted runs are never re-executed. |
| `EVALUATION_REJECTED` | The evaluator refused the request. |
| `EVALUATION_INVALID` | The result failed schema validation or had no graded status, or the stored definition no longer matches its fingerprints. |
| `EVALUATION_STALE` | `challenge:fail-stale` ended a submission stuck in QUEUED or RUNNING. |
| `EVALUATION_FAILED` | Any other failure. |

`challenge:fail-stale` runs every five minutes. It fails submissions left
RUNNING beyond 10 minutes or QUEUED beyond an hour (as `ERROR` with
`EVALUATION_STALE`), and returns their challenge to `ASSIGNED`.

## States

**Challenge instance:**

| From | To | When |
|---|---|---|
| ASSIGNED | EVALUATING | An attempt is submitted. |
| EVALUATING | PASSED | The attempt passed. Terminal. |
| EVALUATING | ASSIGNED | The attempt failed with attempts left, or ended in ERROR. |
| EVALUATING | FAILED | The attempt failed and attempts are exhausted. Terminal. |

**Submission:**

| From | To |
|---|---|
| QUEUED | RUNNING |
| RUNNING | PASSED, FAILED or ERROR |
| QUEUED | ERROR (stale, or evaluator unavailable) |

Only these transitions are possible. A trigger forbids every other one and
every change to a terminal row.

## Access

- **Ownership.** Every route is owner-only. A missing project, a project
  of another user, and a challenge or submission of another project all
  return the same 404.
- **Archived projects.** They keep their challenges readable, but refuse
  new assignments and submissions.
- **Rate limits.**
  - `challenge-assign`: 10 per minute.
  - `challenge-submit`: 10 per minute and 60 per hour, per user.
- **Disabled.** `CHALLENGE_ENABLED=false` returns `CHALLENGES_DISABLED`
  for assignment and submission. Listing and reading still work.
- **Logging.** Logs carry identifiers, statuses, durations and failure
  codes only. Source, hidden tests, expected values and evaluator internals
  are never logged.

## Not included

These are out of scope:

- learning roadmaps;
- reassessment from challenges;
- growth or history analytics;
- user-created challenges, a playground, a terminal or packages;
- languages other than Python;
- AI hints.
