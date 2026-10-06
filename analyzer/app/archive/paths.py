"""Archive entry name rules (independent of the Laravel upload checks).

An entry name is accepted only if it is a plain, relative, normalized UTF-8
path: no absolute paths, no drive letters, no backslashes, no control
characters, no "." / ".." / empty segments, and at most
ANALYZER_MAX_PATH_LENGTH bytes. Rejection reasons are fixed identifiers;
they never contain the name itself.
"""

import re

from app.errors import AnalyzerError, ErrorCode

_CONTROL = re.compile(r"[\x00-\x1f\x7f]")
_DRIVE = re.compile(r"^[A-Za-z]:")


def invalid(reason: str) -> AnalyzerError:
    return AnalyzerError(ErrorCode.INVALID_ARCHIVE, {"reason": reason})


def validate_entry_name(name: str, max_path_length: int) -> tuple[str, bool]:
    """Returns (path without trailing slash, is_directory) or raises INVALID_ARCHIVE."""
    if name == "":
        raise invalid("empty_name")
    if len(name.encode("utf-8", errors="surrogatepass")) > max_path_length:
        raise invalid("path_too_long")
    if _CONTROL.search(name):
        raise invalid("control_character")
    if "\\" in name:
        raise invalid("backslash")
    if name.startswith("/"):
        raise invalid("absolute_path")
    if _DRIVE.match(name):
        raise invalid("drive_path")

    is_directory = name.endswith("/")
    path = name[:-1] if is_directory else name
    for segment in path.split("/"):
        if segment == "..":
            raise invalid("path_traversal")
        if segment in ("", "."):
            raise invalid("non_normalized_path")
    return path, is_directory
