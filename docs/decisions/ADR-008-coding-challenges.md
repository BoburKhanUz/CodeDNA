# ADR-008: Coding Challenges — A Practice Layer With an Isolated Evaluator

- **Status:** Accepted
- **Date:** 2026-10-07
- **Related:**
  - [ADR-004](ADR-004-dna-scoring.md)
  - [ADR-005](ADR-005-service-communication.md)
  - [ADR-007](ADR-007-ai-interpretation.md)
  - [Coding challenges v1](../architecture/coding-challenges-v1.md)
  - [Challenge evaluator](../architecture/challenge-evaluator.md)

## Context

By Phase 15, CodeDNA measures source code and identifies skill gaps
against a target profile. Phase 16 lets a developer practice on short
exercises chosen for those gaps. Two things make this risky.

1. **Trust in the measurement.** If passing an exercise changed a score,
   CodeDNA would measure exercise-taking instead of code. Scores would
   rise without any change in the measured source, and ADR-004's
   reproducibility would break: the same source would give different
   results depending on practice history.
2. **Hostile code.** Checking an exercise means running code a user wrote.
   Running it in any service that holds credentials, data or network
   access (Laravel, the queue, the analyzer) would turn every submission
   into a remote code execution vulnerability.

## Decision

### 1. Practice never changes measurement

Challenges read stored skill gaps. They never write a score, level, gap,
priority, target or snapshot. There is no path from an evaluation result
to CodeDNA. Only a new analysis of new source can change CodeDNA. The API
and the UI state this on every challenge, and tests compare the analysis
rows before and after a passed challenge.

### 2. A server-owned, versioned, immutable catalog

- **Definitions are data in the repository.** Each one has a stable key, a
  version and fingerprints. A stored copy cannot change: a database
  trigger forbids it, and the job checks the fingerprints before every
  evaluation.
- **Clients only choose.** A client chooses which stored gap to practice.
  It never supplies tests, commands, runtimes, images or code to run other
  than its own solution.
- **Difficulty describes the exercise, never the person.**
- **Python only.** Python 3.11 is the one language with a real sandbox. A
  language with no safe execution path is not offered, and no execution
  is simulated.

### 3. Deterministic selection, no AI

Selection is a documented pure function of the gaps, the catalog and the
project's challenge history (`challenge-selection/1.0.0`). It records the
rule that applied. There is no randomness and no language model, and
challenges work with AI disabled.

### 4. Execution only in a dedicated, network-less evaluator

A separate `evaluator` service runs submissions. It has:

- `network_mode: none`;
- a read-only root and tmpfs workspaces;
- no secrets;
- only the SETUID, SETGID and KILL capabilities;
- container memory, CPU and pids limits.

Its supervisor runs each job as a dedicated unprivileged slot user, with:

- rlimits, a wall timeout and capped output;
- a fixed command and an empty environment;
- cleanup that kills every process of the slot and empties its workspace.

Laravel never runs submitted code. If no evaluator is available,
submissions are refused.

### 5. A file spool instead of an HTTP API

The evaluator exchanges request and result files with the queue worker on
a private volume, using atomic renames and a heartbeat. This keeps the
evaluator off every network, including the internal one ADR-005 uses for
the analyzer. Delivery is at most once: an interrupted run is reported,
never re-run. The evaluator never receives expected outputs. Laravel
grades what the evaluator observed.

### 6. Immutable attempts and lease-guarded results

Every attempt is a new row whose source, hash and fingerprints never
change. Only lifecycle columns move, and only forward. Database triggers
and constraints enforce this along with the attempt limit and the single
pending attempt. A job writes its result only while it holds the
submission's claim token, so a job whose lease was taken over writes
nothing.

## Consequences

### Positive

- CodeDNA results stay reproducible and evidence-based. Practice cannot
  inflate them.
- A sandbox escape would land in a container with no network, no secrets
  and no data.
- Results are reproducible and auditable. Each attempt stores the
  definition, test suite, evaluation and evaluator versions and
  fingerprints.

### Negative

- Users who pass challenges see no score change until they analyze new
  code. The UI explains why.
- Only Python is supported. Other languages need their own sandboxed
  runtime first.
- The default `runc` runtime shares the host kernel. Production should use
  gVisor or a microVM runtime for the evaluator (see
  [challenge-evaluator.md](../architecture/challenge-evaluator.md#residual-risk)).
- The spool polls, which adds up to 200 ms of latency per evaluation, and
  needs a shared volume. It does not scale beyond one host without a
  different transport, which would need its own ADR.

## Alternatives considered

| Alternative | Why it was rejected |
|---|---|
| Run code in the queue worker with PHP `proc_open` or Python `subprocess` | The worker holds database, Redis and storage credentials and has network access. |
| Run code in the analyzer | It is on the internal network and handles uploaded archives. Mixing the two would widen both attack surfaces. |
| An HTTP evaluator on the internal network | It needs a network interface. A sandbox escape could then reach PostgreSQL, Redis and MinIO. |
| A Docker-in-Docker or Docker-socket runner | The socket is root on the host. |
| Let completed challenges close gaps or adjust scores | This contradicts ADR-004 and makes measurement depend on practice. |
| LLM-chosen or LLM-graded exercises | These are non-deterministic and not reproducible, and ADR-007 limits AI to non-authoritative explanation. |
