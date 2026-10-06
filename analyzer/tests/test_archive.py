import os
import random
import stat
import zipfile

import pytest

from app.archive.extract import extract_archive
from app.archive.paths import validate_entry_name
from app.config import Settings
from app.deadline import Deadline
from app.errors import AnalyzerError, ErrorCode
from tests.support import make_settings, raw_zip, zip_bytes, zip_with_info


def extract(data: bytes, tmp_path, settings: Settings):  # type: ignore[no-untyped-def]
    archive = tmp_path / "source.zip"
    archive.write_bytes(data)
    destination = tmp_path / "out"
    destination.mkdir(exist_ok=True)
    return extract_archive(str(archive), str(destination), settings, Deadline(30)), destination


def rejected(data: bytes, tmp_path, settings: Settings) -> AnalyzerError:  # type: ignore[no-untyped-def]
    with pytest.raises(AnalyzerError) as raised:
        extract(data, tmp_path, settings)
    return raised.value


def test_extracts_a_valid_archive(tmp_path, settings: Settings) -> None:  # type: ignore[no-untyped-def]
    data = zip_bytes({"src/app.py": "print('hi')\n", "README.md": "# x\n"}, directories=["src/", "docs/"])

    summary, out = extract(data, tmp_path, settings)

    assert (summary.files, summary.directories) == (2, 2)
    assert (out / "src" / "app.py").read_text() == "print('hi')\n"
    assert (out / "docs").is_dir()
    assert stat.S_IMODE(os.stat(out / "src" / "app.py").st_mode) == 0o600


@pytest.mark.parametrize(
    ("name", "reason"),
    [
        ("../evil.php", "path_traversal"),
        ("src/../../evil.php", "path_traversal"),
        ("/absolute/path.php", "absolute_path"),
        ("C:/absolute/path.php", "drive_path"),
        ("C:\\absolute\\path.php", "backslash"),
        ("..\\evil.php", "backslash"),
        ("./a.php", "non_normalized_path"),
        ("a//b.php", "non_normalized_path"),
        ("a\nb.php", "control_character"),
        ("a" * 600, "path_too_long"),
    ],
)
def test_unsafe_names_are_rejected_before_anything_is_written(tmp_path, settings: Settings, name: str, reason: str) -> None:  # type: ignore[no-untyped-def]
    failure = rejected(
        raw_zip([{"name": "ok.py", "content": b"x"}, {"name": name, "content": b"<?php system($_GET['c']);"}]), tmp_path, settings
    )

    assert (failure.code, failure.details) == (ErrorCode.INVALID_ARCHIVE, {"reason": reason})
    assert list((tmp_path / "out").iterdir()) == []
    assert not (tmp_path / "evil.php").exists()


@pytest.mark.parametrize(
    ("mode", "reason"),
    [
        (stat.S_IFLNK | 0o777, "symlink"),
        (stat.S_IFCHR | 0o644, "special_file"),
        (stat.S_IFIFO | 0o644, "special_file"),
        (stat.S_IFSOCK | 0o644, "special_file"),
    ],
)
def test_symlinks_and_special_files_are_rejected(tmp_path, settings: Settings, mode: int, reason: str) -> None:  # type: ignore[no-untyped-def]
    failure = rejected(zip_with_info("link", b"/etc/passwd", mode=mode, compress_type=zipfile.ZIP_STORED), tmp_path, settings)
    assert failure.details == {"reason": reason}
    assert not os.path.lexists(tmp_path / "out" / "link")


def test_encrypted_and_unsupported_entries_are_rejected(tmp_path, settings: Settings) -> None:  # type: ignore[no-untyped-def]
    assert rejected(zip_with_info("a.py", b"x", flag_bits=0x1), tmp_path, settings).details == {"reason": "encrypted"}
    bzip = zip_with_info("a.py", b"x" * 100, compress_type=zipfile.ZIP_BZIP2)
    assert rejected(bzip, tmp_path, settings).details == {"reason": "unsupported_compression"}


def test_too_many_files_are_rejected(tmp_path, workspace_root: str) -> None:  # type: ignore[no-untyped-def]
    strict = make_settings(workspace_root, max_files=5)
    failure = rejected(zip_bytes({f"f{i}.py": "x" for i in range(6)}), tmp_path, strict)
    assert failure.code is ErrorCode.TOO_MANY_FILES


def test_oversized_entries_and_totals_are_rejected(tmp_path, workspace_root: str) -> None:  # type: ignore[no-untyped-def]
    per_entry = make_settings(workspace_root, max_entry_bytes=1000)
    failure = rejected(zip_bytes({"big.txt": "z" * 1001}), tmp_path, per_entry)
    assert (failure.code, failure.details["reason"]) == (ErrorCode.SOURCE_TOO_LARGE, "entry_too_large")

    total = make_settings(workspace_root, max_entry_bytes=1000, max_extracted_bytes=1500)
    failure = rejected(zip_bytes({"a.txt": "a" * 800, "b.txt": "b" * 800}), tmp_path, total)
    assert (failure.code, failure.details["reason"]) == (ErrorCode.SOURCE_TOO_LARGE, "extracted_too_large")


def test_a_header_that_understates_the_size_cannot_bypass_the_limits(tmp_path, workspace_root: str) -> None:  # type: ignore[no-untyped-def]
    # Decompression bomb: declares 100 bytes, expands to 2 MB.
    bomb = raw_zip([{"name": "bomb.txt", "content": b"0" * 2_000_000, "declared_size": 100}])
    failure = rejected(bomb, tmp_path, make_settings(workspace_root))
    assert failure.code is ErrorCode.INVALID_ARCHIVE
    assert os.path.getsize(tmp_path / "out" / "bomb.txt") <= 100


@pytest.mark.parametrize(
    ("data", "reasons"),
    [
        (b"this is not a zip archive at all", {"not_a_zip"}),
        (b"PK\x03\x04 truncated", {"corrupt"}),
        (zip_bytes({"a.py": "x" * 1000})[:-30], {"corrupt"}),
        (zip_bytes({}, directories=["src/"]), {"no_files"}),
        (raw_zip([{"name": "a.py", "content": b"x"}, {"name": "a.py", "content": b"y"}]), {"duplicate_entry"}),
        (raw_zip([{"name": "src", "content": b"x"}, {"name": "src/a.py", "content": b"y"}]), {"file_directory_conflict"}),
        (raw_zip([{"name": "a.py", "content": b"x"}], prefix=b"PK\x03\x04 hidden stub"), {"data_before_first_entry", "corrupt"}),
        (
            raw_zip([{"name": "a.txt", "content": b"aaaa"}, {"name": "b.txt", "content": b"bbbb", "offset": 0, "local_name": "a.txt"}]),
            {"overlapping_entries", "corrupt"},
        ),
        (raw_zip([{"name": "safe.py", "content": b"x", "local_name": "../e.py"}]), {"corrupt"}),
        (raw_zip([{"name": "dir/", "content": b"data", "method": 0, "mode": stat.S_IFDIR | 0o755}]), {"directory_with_content"}),
    ],
)
def test_corrupt_and_ambiguous_archives_are_rejected(tmp_path, settings: Settings, data: bytes, reasons: set[str]) -> None:  # type: ignore[no-untyped-def]
    failure = rejected(data, tmp_path, settings)
    assert failure.code is ErrorCode.INVALID_ARCHIVE
    assert failure.details["reason"] in reasons


def test_crc_mismatches_are_rejected(tmp_path, settings: Settings) -> None:  # type: ignore[no-untyped-def]
    data = bytearray(zip_bytes({"a.py": "print(1)\n"}))
    # Flip the stored CRC-32 in the central directory and the local header.
    for signature, offset in ((b"PK\x03\x04", 14), (b"PK\x01\x02", 16)):
        index = data.find(signature)
        data[index + offset] ^= 0xFF
    assert rejected(bytes(data), tmp_path, settings).code is ErrorCode.INVALID_ARCHIVE


def test_extraction_stops_at_the_deadline(tmp_path, settings: Settings) -> None:  # type: ignore[no-untyped-def]
    archive = tmp_path / "source.zip"
    archive.write_bytes(zip_bytes({"a.py": "x"}))
    (tmp_path / "out").mkdir()
    with pytest.raises(AnalyzerError) as raised:
        extract_archive(str(archive), str(tmp_path / "out"), settings, Deadline(-1))
    assert raised.value.code is ErrorCode.ANALYSIS_TIMEOUT


def test_generated_names_never_escape_or_crash() -> None:
    """Lightweight fuzzing of the name rules: accepted names are always plain relative paths."""
    rng = random.Random(2026)
    alphabet = ["a", "b", ".", "..", "/", "\\", ":", "C:", " ", "\x00", "é", "%2e", "~"]
    for _ in range(5000):
        name = "".join(rng.choice(alphabet) for _ in range(rng.randint(0, 12)))
        try:
            path, _ = validate_entry_name(name, 512)
        except AnalyzerError as error:
            assert error.code is ErrorCode.INVALID_ARCHIVE
            continue
        segments = path.split("/")
        assert not path.startswith("/") and "\\" not in path and "\x00" not in path
        assert all(segment not in ("", ".", "..") for segment in segments)
        assert os.path.normpath(os.path.join("/w", path)).startswith("/w/")
