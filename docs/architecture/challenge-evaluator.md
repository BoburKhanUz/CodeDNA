# Challenge Evaluator

- **Phase:** 16
- **Service:** `evaluator`
- **Code:** `evaluator/`
- **Image:** `docker/evaluator/Dockerfile`
- **Protocol:** `codedna-evaluator/1`
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
   supervisor itself cannot enter it.

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
- **`run`** executes the module, then calls the entrypoint once per case
  with deep-copied JSON arguments. For each case it reports the returned
  value as JSON, or the exception type. A value that is not plain JSON, or
  that is too large, is reported as an error.

The structural rules come from `inspect`, which runs no submitted code, so
a submission cannot influence them. The case values in `run` are reported
by the process that runs the submission. The supervisor keeps the first record per
case id in request order and ignores anything else (tested). A submission
could still write a record for a case before the runner does, but forging
a value is equivalent to returning it. The evaluator
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

## Residual risk

The container runs on the default `runc` runtime and shares the host
kernel. A kernel vulnerability reachable from an unprivileged process with
no network is the remaining attack surface. For production:

- run the evaluator under a sandboxed runtime such as gVisor (`runsc`) or a
  microVM runtime (Kata, Firecracker);
- add a seccomp profile narrower than Docker's default;
- give it its own host or node pool.

None of these change the protocol.

Without user namespaces, the supervisor is root inside the container. It
handles only files it parses strictly and never executes them; the code it
runs always runs as a slot user.
