"""Runs the runner for one job as an isolated, unprivileged process.

Isolation layers (docs/architecture/challenge-evaluator.md#isolation):

1. the container: no network interface but loopback, read-only root
   filesystem, all capabilities dropped except SETUID/SETGID/KILL,
   no-new-privileges, cgroup memory, CPU and pids limits, no secrets;
2. the process: a dedicated slot uid/gid with no supplementary groups
   (it cannot read the spool or the supervisor's code), a private tmpfs as
   working directory, an empty environment, isolated Python (``-I``);
3. resource limits: CPU time, address space, processes, file size, open
   files, no core dumps; a wall-clock timeout; capped output;
4. cleanup: the whole process group and every remaining process of the
   slot uid are killed, and the slot directory is emptied by the slot uid.

The command is fixed: there is no shell and no user-controlled argument.
"""

from __future__ import annotations

import contextlib
import json
import os
import resource
import signal
import subprocess
import sys
import threading
import time
from dataclasses import dataclass
from pathlib import Path

from evaluator.config import Limits, Slot

RUNNER = str(Path(__file__).resolve().parent / "runner" / "driver.py")
CLEANER = str(Path(__file__).resolve().parent / "runner" / "clean.py")


@dataclass(frozen=True)
class SandboxOutcome:
    lines: list[str]
    timed_out: bool
    output_truncated: bool
    exit_code: int | None
    duration_ms: int


def command() -> list[str]:
    """The only command ever executed: the runner, by absolute path, in isolated mode."""
    return [sys.executable, "-I", "-S", RUNNER]


def environment(slot: Slot) -> dict[str, str]:
    return {"PATH": "/usr/bin:/bin", "HOME": str(slot.directory), "TMPDIR": str(slot.directory), "PYTHONHASHSEED": "0", "LANG": "C.UTF-8"}


def _limit(slot: Slot, limits: Limits) -> None:
    """Runs in the child between fork and exec: limits first, then drop privileges."""
    os.setsid()
    resource.setrlimit(resource.RLIMIT_CPU, (limits.cpu_seconds, limits.cpu_seconds))
    resource.setrlimit(resource.RLIMIT_AS, (limits.memory_bytes, limits.memory_bytes))
    resource.setrlimit(resource.RLIMIT_NPROC, (limits.max_processes, limits.max_processes))
    resource.setrlimit(resource.RLIMIT_FSIZE, (limits.max_file_bytes, limits.max_file_bytes))
    resource.setrlimit(resource.RLIMIT_NOFILE, (limits.max_open_files, limits.max_open_files))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    os.setgroups([])
    os.setgid(slot.gid)
    os.setuid(slot.uid)
    if os.getuid() != slot.uid or os.geteuid() != slot.uid or os.getgid() != slot.gid:
        os._exit(126)
    os.umask(0o077)
    # Only the slot user can enter its directory (the supervisor cannot).
    os.chdir(slot.directory)


def _slot_processes(uid: int) -> list[int]:
    """Live (non-zombie) processes owned by the slot uid."""
    pids: list[int] = []
    for entry in Path("/proc").iterdir():
        if not entry.name.isdigit():
            continue
        try:
            if entry.stat().st_uid != uid:
                continue
            state = (entry / "stat").read_text().rsplit(")", 1)[1].split()[0]
        except (FileNotFoundError, ProcessLookupError, PermissionError, IndexError):
            continue
        if state != "Z":
            pids.append(int(entry.name))
    return pids


def kill_slot_processes(uid: int, rounds: int = 50) -> int:
    """Kills every process owned by the slot uid, also ones that left the
    process group, repeating until none is left (a fork bomb keeps forking
    until its processes are gone). Zombies are reaped by the container init."""
    killed = 0
    for _ in range(rounds):
        pids = _slot_processes(uid)
        if not pids:
            break
        for pid in pids:
            try:
                os.kill(pid, signal.SIGKILL)
                killed += 1
            except (ProcessLookupError, PermissionError):
                continue
        time.sleep(0.01)
    return killed


def run(slot: Slot, limits: Limits, job: dict[str, object]) -> SandboxOutcome:
    payload = json.dumps(job).encode()
    started = time.monotonic()
    process = subprocess.Popen(  # noqa: S603 - fixed command, no shell, no user arguments
        command(),
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        cwd="/",
        env=environment(slot),
        preexec_fn=lambda: _limit(slot, limits),  # noqa: PLW1509 - required to drop privileges in the child
        close_fds=True,
    )
    chunks: list[bytes] = []
    state = {"size": 0, "truncated": False}

    def read() -> None:
        assert process.stdout is not None
        while True:
            chunk = process.stdout.read(65536)
            if not chunk:
                return
            room = limits.max_output_bytes - state["size"]
            if len(chunk) > room:
                chunks.append(chunk[: max(room, 0)])
                state["size"] = limits.max_output_bytes
                state["truncated"] = True
                os.killpg(process.pid, signal.SIGKILL)
                return
            chunks.append(chunk)
            state["size"] += len(chunk)

    reader = threading.Thread(target=read, daemon=True)
    reader.start()
    try:
        assert process.stdin is not None
        process.stdin.write(payload)
        process.stdin.close()
    except (BrokenPipeError, OSError):
        pass

    timed_out = False
    try:
        process.wait(timeout=limits.wall_seconds)
    except subprocess.TimeoutExpired:
        timed_out = True
    finally:
        with contextlib.suppress(ProcessLookupError, PermissionError):
            os.killpg(process.pid, signal.SIGKILL)
        kill_slot_processes(slot.uid)
        process.wait()
        reader.join(timeout=2)
        if process.stdout is not None:
            process.stdout.close()
        clean(slot, limits)

    text = b"".join(chunks).decode("utf-8", errors="replace")
    lines = [line for line in text.split("\n") if line]
    return SandboxOutcome(
        lines=lines,
        timed_out=timed_out,
        output_truncated=bool(state["truncated"]),
        exit_code=None if timed_out else process.returncode,
        duration_ms=int((time.monotonic() - started) * 1000),
    )


def clean(slot: Slot, limits: Limits) -> None:
    """Empties the slot directory as the slot user (the supervisor cannot read it)."""
    cleaner = subprocess.Popen(  # noqa: S603 - fixed command
        [sys.executable, "-I", "-S", CLEANER, str(slot.directory)],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        cwd="/",
        env=environment(slot),
        preexec_fn=lambda: _limit(slot, limits),  # noqa: PLW1509
        close_fds=True,
    )
    try:
        cleaner.wait(timeout=10)
    except subprocess.TimeoutExpired:
        os.killpg(cleaner.pid, signal.SIGKILL)
        cleaner.wait()
    kill_slot_processes(slot.uid)
