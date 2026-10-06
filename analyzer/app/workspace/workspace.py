"""Per-run temporary workspaces.

Each analysis gets its own directory ``<root>/run-<32 random hex>``. The
name is random and never derived from request data (not even the run ID),
so no request can influence a filesystem path. Directories are created
with mode 0700 and removed on every exit path (success, error, timeout).
Leftovers from a crashed process are removed at startup.
"""

import os
import re
import secrets
import shutil
from collections.abc import Iterator
from contextlib import contextmanager

from app.errors import AnalyzerError, ErrorCode

_RUN_DIRECTORY = re.compile(r"^run-[0-9a-f]{32}$")


def ensure_root(root: str) -> None:
    os.makedirs(root, mode=0o700, exist_ok=True)
    if os.path.islink(root) or not os.path.isdir(root):
        raise RuntimeError("The workspace root must be a real directory.")


@contextmanager
def run_workspace(root: str) -> Iterator[str]:
    ensure_root(root)
    path = os.path.join(root, "run-" + secrets.token_hex(16))
    try:
        os.mkdir(path, mode=0o700)
    except OSError as exc:
        raise AnalyzerError(ErrorCode.INTERNAL_ERROR, {"reason": "workspace_unavailable"}) from exc
    try:
        yield path
    finally:
        remove(path)


def remove(path: str) -> None:
    # rmtree does not follow symlinks (and the workspace never contains any).
    shutil.rmtree(path, ignore_errors=True)


def sweep_stale(root: str) -> int:
    """Removes run directories left behind by a previous process."""
    if not os.path.isdir(root):
        return 0
    removed = 0
    for name in os.listdir(root):
        candidate = os.path.join(root, name)
        if _RUN_DIRECTORY.match(name) and os.path.isdir(candidate) and not os.path.islink(candidate):
            remove(candidate)
            removed += 1
    return removed


def active_runs(root: str) -> list[str]:
    if not os.path.isdir(root):
        return []
    return sorted(name for name in os.listdir(root) if _RUN_DIRECTORY.match(name))
