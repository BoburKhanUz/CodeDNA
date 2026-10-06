"""Safe extraction of a ZIP archive into a run workspace.

The archive is hostile until proven otherwise; nothing from Laravel's
upload inspection is trusted. Before anything is written, every entry of
the central directory is checked:

- name rules (app.archive.paths): traversal, absolute and drive paths,
  backslashes, control characters, path length;
- type: Unix symlinks, devices, FIFOs and sockets are rejected;
- encryption and compression methods other than stored/deflated are
  rejected;
- duplicates, file/directory conflicts, data before the first entry and
  overlapping entries are rejected;
- declared limits: entries, files, per-entry size, total size.

Then each file is streamed out with ``zipfile`` while the real byte counts
are enforced again (a lying header cannot bypass them; ``zipfile`` also
verifies each CRC-32). Files are created with O_EXCL | O_NOFOLLOW inside
directories the analyzer created itself, mode 0600. Nothing is executed,
imported or interpreted.
"""

import os
import stat
import struct
import zipfile
import zlib
from dataclasses import dataclass

from app.archive.paths import invalid, validate_entry_name
from app.config import Settings
from app.deadline import Deadline
from app.errors import AnalyzerError, ErrorCode

CHUNK_BYTES = 64 * 1024
_LOCAL_HEADER = struct.Struct("<4s5H3L2H")
_LOCAL_SIGNATURE = b"PK\x03\x04"
_ENCRYPTED = 0x0001 | 0x0040 | 0x2000
_ALLOWED_METHODS = (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED)
_UNIX_HOSTS = (3, 19)


@dataclass(frozen=True)
class ExtractionSummary:
    files: int
    directories: int
    bytes: int


def extract_archive(archive_path: str, destination: str, settings: Settings, deadline: Deadline) -> ExtractionSummary:
    with open(archive_path, "rb") as handle:
        if handle.read(4) != _LOCAL_SIGNATURE:
            raise invalid("not_a_zip")

    try:
        archive = zipfile.ZipFile(archive_path)
    except (zipfile.BadZipFile, zipfile.LargeZipFile, OSError, ValueError, EOFError):
        raise invalid("corrupt") from None

    with archive:
        entries = _inspect(archive, archive_path, settings)
        written = 0
        files = 0
        for info, path, is_directory in entries:
            deadline.check()
            target = _target_path(destination, path)
            if is_directory:
                os.makedirs(target, mode=0o700, exist_ok=True)
                continue
            os.makedirs(os.path.dirname(target), mode=0o700, exist_ok=True)
            written += _extract_file(archive, info, target, settings, deadline, written)
            files += 1

    return ExtractionSummary(files=files, directories=sum(1 for _, _, d in entries if d), bytes=written)


def _inspect(archive: zipfile.ZipFile, archive_path: str, settings: Settings) -> list[tuple[zipfile.ZipInfo, str, bool]]:
    infos = archive.infolist()
    if not infos:
        raise invalid("empty")
    # Directories are bounded too: at most as many as files are allowed.
    if len(infos) > settings.max_files * 2:
        raise AnalyzerError(ErrorCode.TOO_MANY_FILES, {"limit_files": settings.max_files})

    seen: dict[str, bool] = {}
    entries: list[tuple[zipfile.ZipInfo, str, bool]] = []
    files = 0
    declared_total = 0

    for info in infos:
        path, is_directory = validate_entry_name(info.filename, settings.max_path_length)
        _check_type(info, is_directory)
        if info.flag_bits & _ENCRYPTED:
            raise invalid("encrypted")
        if info.compress_type not in _ALLOWED_METHODS:
            raise invalid("unsupported_compression")
        if path in seen:
            raise invalid("duplicate_entry")
        seen[path] = is_directory

        if is_directory:
            if info.file_size != 0:
                raise invalid("directory_with_content")
        else:
            files += 1
            if files > settings.max_files:
                raise AnalyzerError(ErrorCode.TOO_MANY_FILES, {"limit_files": settings.max_files})
            if info.file_size > settings.max_entry_bytes:
                raise AnalyzerError(ErrorCode.SOURCE_TOO_LARGE, {"reason": "entry_too_large", "limit_bytes": settings.max_entry_bytes})
            declared_total += info.file_size
            if declared_total > settings.max_extracted_bytes:
                raise AnalyzerError(
                    ErrorCode.SOURCE_TOO_LARGE, {"reason": "extracted_too_large", "limit_bytes": settings.max_extracted_bytes}
                )
        entries.append((info, path, is_directory))

    # "a" cannot be a file and also the parent of "a/b".
    for path in seen:
        parent = path
        while "/" in parent:
            parent = parent.rsplit("/", 1)[0]
            if seen.get(parent) is False:
                raise invalid("file_directory_conflict")

    if files == 0:
        raise invalid("no_files")

    _check_layout(infos, archive_path)
    return entries


def _check_type(info: zipfile.ZipInfo, is_directory: bool) -> None:
    if info.create_system not in _UNIX_HOSTS:
        return
    mode = info.external_attr >> 16
    kind = stat.S_IFMT(mode)
    if kind == 0:
        return
    if stat.S_ISLNK(mode):
        raise invalid("symlink")
    if not (stat.S_ISREG(mode) or stat.S_ISDIR(mode)):
        raise invalid("special_file")
    if stat.S_ISDIR(mode) != is_directory:
        raise invalid("type_name_mismatch")


def _check_layout(infos: list[zipfile.ZipInfo], archive_path: str) -> None:
    """No data before the first entry, and no two entries share bytes."""
    ordered = sorted(infos, key=lambda info: info.header_offset)
    if ordered[0].header_offset != 0:
        raise invalid("data_before_first_entry")
    previous_end = 0
    with open(archive_path, "rb") as handle:
        for info in ordered:
            if info.header_offset < previous_end:
                raise invalid("overlapping_entries")
            handle.seek(info.header_offset)
            header = handle.read(_LOCAL_HEADER.size)
            if len(header) != _LOCAL_HEADER.size:
                raise invalid("corrupt")
            fields = _LOCAL_HEADER.unpack(header)
            if fields[0] != _LOCAL_SIGNATURE:
                raise invalid("corrupt")
            name_length, extra_length = fields[9], fields[10]
            previous_end = info.header_offset + _LOCAL_HEADER.size + name_length + extra_length + info.compress_size


def _target_path(destination: str, path: str) -> str:
    target = os.path.join(destination, *path.split("/"))
    root = os.path.realpath(destination)
    # Defense in depth: names were validated, and no symlinks exist here.
    if os.path.commonpath([root, os.path.realpath(target)]) != root:
        raise invalid("path_traversal")
    return target


def _extract_file(
    archive: zipfile.ZipFile,
    info: zipfile.ZipInfo,
    target: str,
    settings: Settings,
    deadline: Deadline,
    written_before: int,
) -> int:
    written = 0
    try:
        descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    except FileExistsError:
        raise invalid("duplicate_entry") from None
    try:
        with archive.open(info) as source, os.fdopen(descriptor, "wb") as output:
            while True:
                deadline.check()
                chunk = source.read(CHUNK_BYTES)
                if not chunk:
                    break
                written += len(chunk)
                if written > info.file_size or written > settings.max_entry_bytes:
                    raise invalid("size_mismatch")
                if written_before + written > settings.max_extracted_bytes:
                    raise AnalyzerError(
                        ErrorCode.SOURCE_TOO_LARGE, {"reason": "extracted_too_large", "limit_bytes": settings.max_extracted_bytes}
                    )
                output.write(chunk)
    except (zipfile.BadZipFile, zlib.error, EOFError, ValueError, NotImplementedError, RuntimeError):
        # zipfile raises BadZipFile for CRC errors and header/name mismatches.
        raise invalid("corrupt") from None
    except OSError:
        raise AnalyzerError(ErrorCode.INTERNAL_ERROR, {"reason": "workspace_write_failed"}) from None
    if written != info.file_size:
        raise invalid("size_mismatch")
    return written


__all__ = ["ExtractionSummary", "extract_archive"]
