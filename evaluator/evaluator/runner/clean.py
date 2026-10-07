"""Empties a slot directory. Runs as the slot user, after every job."""

from __future__ import annotations

import os
import stat
import sys
from pathlib import Path


def remove_contents(root: Path) -> None:
    """Removes everything under root, which the slot user owns.

    Iterative (no recursion limit, however deep the tree), restores owner
    permissions before descending (a directory made unreadable with chmod 000
    is still emptied) and removes symlinks without following them (Phase 21).
    """
    os.chmod(root, 0o700)
    pending = [root]
    directories: list[Path] = []
    while pending:
        directory = pending.pop()
        for entry in list(directory.iterdir()):
            if stat.S_ISDIR(entry.lstat().st_mode):
                os.chmod(entry, 0o700)
                pending.append(entry)
                directories.append(entry)
            else:
                entry.unlink(missing_ok=True)
    for directory in reversed(directories):
        directory.rmdir()


def main() -> None:
    root = Path(sys.argv[1])
    try:
        remove_contents(root)
    except OSError:
        sys.exit(1)
    # Never report success for a slot that is not empty.
    sys.exit(1 if any(root.iterdir()) else 0)


if __name__ == "__main__":
    main()
