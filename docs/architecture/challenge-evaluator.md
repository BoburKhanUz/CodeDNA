# Challenge Evaluator

- **Phase:** 16
- **Service:** `evaluator`
- **Code:** `evaluator/`
- **Image:** `docker/evaluator/Dockerfile`
- **Protocol:** `codedna-evaluator/1`
- **Production runtime:** gVisor, attested and enforced (Phase 25, [production sandbox](#production-sandbox))
- **Decision:** [ADR-008](../decisions/ADR-008-coding-challenges.md)

The evaluator is the only place where submitted challenge code runs. All
submitted code is treated as hostile.

Submitted code never runs in any of these places:

- the Laravel host processes (API, queue, scheduler);
- the analyzer;
- the frontend, Nginx, PostgreSQL, Redis or MinIO;
- a developer's workstation process.

The Laravel side only writes request files and reads result files. When no
evaluator is available, submissions are refused
(`CHALLENGE_EVALUATION_UNAVAILABLE`). There is no fallback.

## Isolation

The evaluator applies four layers. Each one holds on its own.

### 1. Container (`docker-compose.yml`)

| Control | Setting |
|---|---|
| Network | `network_mode: none`: no interface but loopback, so no internal network, no DNS and no internet. No port is published. |
| Root filesystem | `read_only: true`. |
| Writable paths | tmpfs only: `/tmp` (8 MB, root only) and one tmpfs per slot (`/sandbox/<n>`, 16 MB, mode 0700, owned by the slot user); plus the spool volume. |
| Capabilities | `cap_drop: ALL`, then only `SETUID`, `SETGID` and `KILL`, which the supervisor needs to drop each job to its slot user and kill it. |
| Privilege escalation | `no-new-privileges`. |
| Resources | `mem_limit` 768 MB, `cpus` 1, `pids_limit` 128. |
| PID 1 | `init: true`, so tini reaps killed processes. |
| Isolation from the host | No Docker socket, no host mounts, no bind mount of code. The code is baked into the image. |
| Secrets | None. No database, Redis, MinIO, app key or AI credentials are in its environment; `make verify` checks this. |

### 2. Process

Each job runs in a child process. It is set up in this order between fork
and exec:

1. `setsid` (a new session and process group).
2. Resource limits (layer 3).
3. `setgroups([])`, `setgid` and `setuid` to the slot user (uid
   10002 + slot, its own group). The slot users are in no other group.
4. `umask 077` and `chdir` into the slot's private tmpfs.

Because the slot user is in no other group, it cannot read:

- the spool, which is `root:10500` with mode 2770;
- the supervisor code, which is root-only;
- other slots.

It can only read the runner (`evaluator/runner/`), which holds nothing
secret.

Execution is fixed:

- **Environment.** Exactly `PATH`, `HOME`, `TMPDIR`, `PYTHONHASHSEED=0`
  and `LANG`.
- **Command.** `python -I -S /opt/evaluator/evaluator/runner/driver.py`.
  It uses isolated mode, no site packages and an absolute path. There is no
  shell and no argument derived from user input. Source and inputs arrive
  on stdin.
- **Output.** Whatever the submission prints goes to `/dev/null`. The
  runner reports results on a duplicated descriptor.

### 3. Limits (per run)

| Limit | Value |
|---|---|
| CPU time (`RLIMIT_CPU`) | `CHALLENGE_EXECUTION_TIMEOUT`, default 5 s |
| Wall clock | Same timeout; then the whole process group is killed |
| Address space (`RLIMIT_AS`) | `CHALLENGE_MAX_MEMORY_MB`, default 256 MB |
| Processes (`RLIMIT_NPROC`) | `CHALLENGE_MAX_PROCESSES`, default 16 (per slot uid) |
| File size (`RLIMIT_FSIZE`) | 1 MB |
| Open files (`RLIMIT_NOFILE`) | 32 |
| Core dumps | 0 |
| Output read | `CHALLENGE_MAX_OUTPUT_BYTES`, default 64 KB, then `OUTPUT_LIMIT` |
| Workspace | 16 MB tmpfs per slot (bounds file size and file count) |
| Source | `CHALLENGE_MAX_SOURCE_BYTES` (16 KB); also checked by Laravel |
| Cases | At most 64 per request |

### 4. Cleanup

After every run, these steps happen even when the run timed out or
crashed:

1. The supervisor kills the process group.
2. It kills every remaining process of the slot uid, in a loop that skips
   zombies. This catches processes that called `setsid` to escape.
3. A cleaner, run as the slot user, empties the slot directory. The
   supervisor itself cannot enter it. The cleaner is iterative and restores
   owner permissions before descending, so a directory set to `chmod 000`
   is emptied too. If the slot is still not empty, the supervisor takes it
   out of rotation; with no usable slot left the service exits and the
   restarted container gets fresh tmpfs mounts (Phase 21).

Sandboxed processes also get `RLIMIT_MSGQUEUE 0` (no POSIX message queues)
and `oom_score_adj 1000` (the OOM killer picks them before the supervisor).
`/dev/shm` is a 64 KiB root-owned tmpfs that slot users cannot write, so no
file outlives a job (Phase 21). System V shared memory, message queues and
semaphores are disabled in the container's IPC namespace (`kernel.shmmni`,
`kernel.msgmni` and `kernel.sem` set to 0, Phase 22): such objects would
outlive a job, be visible to the other slot and hold memory outside the
job's limits.

When the service starts, it kills every process left over for each slot
uid.

## Runner

`evaluator/runner/driver.py` runs as the slot user. It has two modes, and
each one is a separate sandboxed run.

- **`inspect`** parses the source with `ast` and **never executes it**. It
  reports:
  - syntax validity and the error line;
  - for each function: cyclomatic complexity, nesting, lines and
    parameters;
  - for each class: lines and methods;
  - the number of classes.
- **`run`** executes the module, then calls the entrypoint with the
  case's deep-copied JSON arguments, and reports the returned value as JSON,
  or the exception type. **Since Phase 30 every case is its own sandboxed
  run** that receives only that case's arguments: the submission shares the
  runner's interpreter, so in one process it could read every case's inputs
  (stack frames, `gc`) and return hidden inputs as a visible case's value,
  which the user sees. All runs of a request share one wall-clock budget
  (`CHALLENGE_EXECUTION_TIMEOUT`); when it is spent the status is `TIMEOUT`
  and the remaining cases are `MISSING`. Module state never carries over
  from one case to the next. A value that is not plain JSON, too
  large, or nested more than 32 levels (`ValueTooDeep`) is reported as an
  error.
- **Error names (Phase 21).** Submitted code names its own exception
  classes, so a name could carry hidden test arguments out. The evaluator
  reports load errors and case errors only by Python's builtin exception
  names and the runner's markers (`MissingEntrypoint`,
  `UnserializableResult`, `ValueTooLarge`, `ValueTooDeep`); anything else is
  `Error`. Laravel applies the same allowlist to hidden cases and load
  errors. Visible cases keep the reported name.
- **One result per request.** Whatever the runner's output provokes, a
  claimed request always gets a result (`CRASHED` on an unexpected error),
  so the same code is never resubmitted.

The structural rules come from `inspect`, which runs no submitted code, so
a submission cannot influence them. The case values in `run` are reported
by the process that runs the submission. The supervisor accepts only
records for the one case that process ran, and keeps the first (tested,
including records written straight to file descriptor 1). A submission can
still write a record for its own case before the runner does, but forging
that value is equivalent to returning it. The evaluator
never receives expected values (see [Protocol](#protocol)), so there is
nothing to read or leak.

## Protocol

`codedna-evaluator/1` exchanges files through the `challenge-spool`
volume.

- **Mounts.** The volume is mounted at `/spool` in the evaluator and at
  `/var/spool/codedna-challenges` in the backend, queue and scheduler.
  Those containers are in group 10500 (`group_add`). No HTTP port and no
  network are involved.
- **Layout.**

  ```text
  /spool/requests/<id>.json   written by Laravel (temp file + atomic rename)
  /spool/work/<id>.json       claimed by the evaluator (atomic rename)
  /spool/results/<id>.json    written by the evaluator (temp file + atomic rename), mode 0640
  /spool/heartbeat            {"at": <unix time>, "version": "1.0.0"}, refreshed every poll
  ```

- **Request.**
  - Keys are strict: `protocol`, `id`, `language`, `entrypoint`, `source`
    and `cases`. Structural rules are applied in Laravel to the inspection
    metrics, so the evaluator never sees them.
  - `cases` hold only `id` and `args`. **There are no expected values.**
  - Unknown keys, wrong types, ids that are not ULIDs, oversized source and
    too many cases are rejected with a `REJECTED` result. A rejected
    request is never run.
- **Result.**
  - A `status`: `COMPLETED`, `SYNTAX_ERROR`, `LOAD_ERROR`, `TIMEOUT`,
    `OUTPUT_LIMIT`, `CRASHED`, `INTERRUPTED` or `REJECTED`.
  - The inspection metrics, per-case observations, and `duration_ms`.
  - Laravel validates it against
    `backend/resources/challenges/evaluator-result-1.schema.json`, decodes
    it with a depth limit, and grades it
    ([coding-challenges-v1.md](coding-challenges-v1.md#evaluation)).
- **At most once.** A request found in `work/` when the service starts was
  interrupted by a restart. It is reported as `INTERRUPTED` and never run
  again. Laravel records an interrupted run as `ERROR`, which consumes no
  attempt.
- **Availability.** Laravel treats the evaluator as unavailable when the
  heartbeat is older than 30 s. The container healthcheck requires a
  heartbeat younger than 15 s.

## Logging

The evaluator logs request ids, slot numbers, statuses, durations and
rejection reasons. It never logs source, inputs, outputs or environment.

## Tests

`make test-evaluator` runs the protocol and sandbox tests inside the
running container, against the real sandbox. They check the protocol, and that submitted code:

- cannot open a network connection or resolve a name;
- cannot write outside its workspace, or read the spool, the supervisor
  code or other slots;
- runs as the slot uid with no groups and an empty environment;
- is stopped by the limits on CPU loops, memory, fork bombs and output
  floods;
- does not survive the run, including detached `setsid` processes;
- leaves its slot directory empty afterwards.

`make lint-evaluator` runs ruff and mypy. `make verify` checks the running
container: health, no network interface but loopback, a read-only root,
the capability set, and no credentials in its environment.

## Production sandbox

Phase 25 makes a sandboxed runtime a hard requirement in production, enforced
in code rather than by convention ([`evaluator/isolation.py`](../../evaluator/evaluator/isolation.py)):

| Level | Meaning | Allowed |
|---|---|---|
| `container` | The layers above on the host kernel (`runc`) | Development and tests only |
| `gvisor` | The same layers inside gVisor (`runsc`): submitted code talks to gVisor's user-space kernel, never to the host kernel | Required in production |

- **Configuration.** `EVALUATOR_ISOLATION` (`container` | `gvisor`) and
  `EVALUATOR_PRODUCTION` (`true` | `false`, strict values).
  `EVALUATOR_PRODUCTION=true` with anything but `gvisor` is a configuration
  error. The image's production target sets both.
- **Attestation.** At start, before the spool is prepared or a heartbeat is
  written, the service detects the runtime it actually runs under (gVisor's
  fixed synthetic kernel identity in `/proc/version`). A runtime weaker than
  configured stops the service (`evaluator.refused`, exit 2).
- **No fallback.** There is no fallback to weaker isolation. A heartbeat left
  by an earlier run is deleted first, so nothing vouches for a refused
  start. If a gVisor upgrade changes the identity, attestation fails closed
  until the fingerprint is updated.
- **Heartbeat.** It now carries `isolation` and `production`. A heartbeat
  without `isolation` (an older evaluator) counts as `container`.
- **Laravel.** `CHALLENGE_EVALUATOR_ISOLATION` is the weakest level it
  accepts (`container` locally, `gvisor` wherever it is deployed; the boot
  validation refuses anything else). `SpoolChallengeEvaluator::available()`
  requires a fresh heartbeat whose attested level meets it, and an unknown
  level fails closed. Where `gvisor` is required, the queued job checks
  again right before writing a request. A refused submission is retryable,
  and nothing is executed.
- **Compose.** `docker-compose.prod.yml` runs the evaluator with
  `runtime: runsc`, no network, the same capabilities, tmpfs mounts and
  limits as in development. Its health check requires `isolation ==
  "gvisor"`.
- **Host check.** `make prod-evaluator-attest` runs the configured image
  under the configured runtime and exits non-zero unless it attests gVisor.

Prerequisites, installation and the operator check are in
[production deployment](../operations/production-deployment.md#3-install-gvisor-for-the-evaluator).
Tests: `evaluator/tests/test_isolation.py` (attestation, strict
configuration, refusal before any heartbeat, exit code) and
`SpoolChallengeEvaluatorTest` (required level, unknown and stale
heartbeats, no submission without attestation). The production smoke test
starts the production image without gVisor and checks the refusal end to end.

## Residual risk

In development the container runs on the default `runc` runtime and shares
the host kernel. In production gVisor removes that exposure, at the cost of
trusting gVisor's own kernel implementation. Further options:

- add a seccomp profile narrower than Docker's default (gVisor applies its
  own to the sandbox);
- give the evaluator its own host or node pool;
- a microVM runtime (Kata, Firecracker), which would need its own
  attestation.

None of these change the protocol.

Without user namespaces, the supervisor is root inside the container. It
handles only files it parses strictly and never executes them; the code it
runs always runs as a slot user.
