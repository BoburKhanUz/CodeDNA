"""Empties a slot directory. Runs as the slot user, after every job."""

from __future__ import annotations

import shutil
import sys
from pathlib import Path


def main() -> None:
    root = Path(sys.argv[1])
    for entry in root.iterdir():
        if entry.is_dir() and not entry.is_symlink():
            shutil.rmtree(entry, ignore_errors=True)
        else:
            entry.unlink(missing_ok=True)


if __name__ == "__main__":
    main()
