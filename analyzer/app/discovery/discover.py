"""Deterministic file discovery over an extracted workspace.

Produces IR file records (metadata only, never content) for every regular
file outside ignored directories, sorted by path (Unicode code point
order). Files inside an ignored directory (any path segment equal to an
ANALYZER_IGNORED_DIRECTORIES name) are only counted.

A file is *analyzable* when its extension maps to a requested language, it
is at most ANALYZER_MAX_FILE_BYTES, and it is not binary (no NUL byte in
its first 8 KiB). Only analyzable files are read, to count lines; nothing
read is kept, returned or logged.
"""

import os
import stat
from dataclasses import dataclass, field

from app.deadline import Deadline
from app.discovery.languages import extension_of, language_of

BINARY_SNIFF_BYTES = 8192

SKIP_UNSUPPORTED_LANGUAGE = "unsupported_language"
SKIP_TOO_LARGE = "too_large"
SKIP_BINARY = "binary"


@dataclass(frozen=True)
class FileRecord:
    """IR 1.0 file record. Never contains source text."""

    path: str
    extension: str | None
    language: str | None
    size_bytes: int
    lines: int | None
    skip_reason: str | None

    def to_dict(self) -> dict[str, object]:
        return {
            "path": self.path,
            "extension": self.extension,
            "language": self.language,
            "size_bytes": self.size_bytes,
            "lines": self.lines,
            "skip_reason": self.skip_reason,
        }


@dataclass
class Discovery:
    files: list[FileRecord] = field(default_factory=list)
    ignored_files: int = 0
    ignored_bytes: int = 0

    @property
    def analyzable(self) -> list[FileRecord]:
        return [record for record in self.files if record.skip_reason is None]


def discover(
    root: str,
    *,
    ignored_directories: tuple[str, ...],
    languages: tuple[str, ...],
    max_file_bytes: int,
    deadline: Deadline,
) -> Discovery:
    ignored = frozenset(ignored_directories)
    requested = frozenset(languages)
    result = Discovery()

    for directory, subdirectories, filenames in os.walk(root, followlinks=False):
        deadline.check()
        subdirectories.sort()
        relative_directory = os.path.relpath(directory, root)
        segments = [] if relative_directory == "." else relative_directory.split(os.sep)
        inside_ignored = any(segment in ignored for segment in segments)

        for name in sorted(filenames):
            full_path = os.path.join(directory, name)
            info = os.lstat(full_path)
            if not _is_regular(info.st_mode):
                continue
            if inside_ignored:
                result.ignored_files += 1
                result.ignored_bytes += info.st_size
                continue
            result.files.append(_record("/".join([*segments, name]), full_path, info.st_size, requested, max_file_bytes))

    result.files.sort(key=lambda record: record.path)
    return result


def _record(path: str, full_path: str, size: int, requested: frozenset[str], max_file_bytes: int) -> FileRecord:
    extension = extension_of(path)
    language = language_of(path)
    if language is None or language not in requested:
        return FileRecord(path, extension, language, size, None, SKIP_UNSUPPORTED_LANGUAGE)
    if size > max_file_bytes:
        return FileRecord(path, extension, language, size, None, SKIP_TOO_LARGE)

    with open(full_path, "rb") as handle:
        data = handle.read(max_file_bytes + 1)
    if b"\0" in data[:BINARY_SNIFF_BYTES]:
        return FileRecord(path, extension, language, size, None, SKIP_BINARY)
    lines = data.count(b"\n") + (1 if data and not data.endswith(b"\n") else 0)
    return FileRecord(path, extension, language, size, lines, None)


def _is_regular(mode: int) -> bool:
    return stat.S_ISREG(mode)
