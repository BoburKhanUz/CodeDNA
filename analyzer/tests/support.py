"""Test helpers: signing, ZIP construction, settings, fake resolver/fetcher.

Nothing built here is ever executed by the analyzer or the tests.
"""

import hashlib
import io
import json
import stat
import struct
import time
import uuid
import zipfile
import zlib
from collections.abc import Sequence
from datetime import UTC, datetime
from typing import Any

from app.auth.hmac_signing import request_canonical_string, sign
from app.config import Settings
from app.contracts.request import AnalyzeRequest
from app.deadline import Deadline
from app.source.url_policy import SourceTarget

# Obviously fake, test-only signing keys (64 characters, like a real hex secret).
SECRET = "t" * 64
PREVIOUS_SECRET = "p" * 64
PATH = "/internal/v1/analyze"
RUN_ID = "01k6p0a1b2c3d4e5f6g7h8j9km"
PROJECT_ID = "01k6p0a1b2c3d4e5f6g7h8j9pq"
SNAPSHOT_ID = "01k6p0a1b2c3d4e5f6g7h8j9rs"


def make_settings(workspace_root: str, **overrides: Any) -> Settings:
    values: dict[str, Any] = {
        "hmac_secret": SECRET,
        "allowed_source_hosts": frozenset({"minio", "storage.example.com"}),
        "local_source_hosts": frozenset({"minio"}),
        "workspace_root": workspace_root,
    }
    values.update(overrides)
    return Settings(**values)


def presigned_url(
    host: str = "minio:9000",
    scheme: str = "http",
    key: str = f"codedna/projects/{PROJECT_ID}/snapshots/{SNAPSHOT_ID}/source.zip",
    signed_at: datetime | None = None,
    expires: int = 900,
) -> str:
    stamp = (signed_at or datetime.now(UTC)).strftime("%Y%m%dT%H%M%SZ")
    return (
        f"{scheme}://{host}/{key}?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=app%2F20261006%2Fus-east-1%2Fs3%2Faws4_request"
        f"&X-Amz-Date={stamp}&X-Amz-Expires={expires}&X-Amz-SignedHeaders=host&X-Amz-Signature={'ab' * 32}"
    )


def request_body(archive: bytes, run_id: str = RUN_ID, url: str | None = None, **overrides: Any) -> dict[str, Any]:
    body: dict[str, Any] = {
        "contract_version": "1.0",
        "analysis_run_id": run_id,
        "attempt": 1,
        "source": {
            "type": "archive",
            "format": "zip",
            "url": url or presigned_url(),
            "sha256": hashlib.sha256(archive).hexdigest(),
            "size_bytes": len(archive),
        },
    }
    body.update(overrides)
    return body


def signed_headers(
    body: bytes,
    *,
    secret: str = SECRET,
    run_id: str = RUN_ID,
    request_id: str | None = None,
    timestamp: int | None = None,
    path: str = PATH,
    method: str = "POST",
) -> dict[str, str]:
    request_id = request_id or str(uuid.uuid4())
    stamp = str(int(time.time()) if timestamp is None else timestamp)
    return {
        "Content-Type": "application/json",
        "X-Request-ID": request_id,
        "Idempotency-Key": run_id,
        "X-CodeDNA-Timestamp": stamp,
        "X-CodeDNA-Signature": sign(secret, request_canonical_string(stamp, method, path, request_id, body)),
    }


def encode(body: dict[str, Any]) -> bytes:
    return json.dumps(body).encode("utf-8")


def resolver(*addresses: str):  # type: ignore[no-untyped-def]
    def resolve(host: str, port: int) -> Sequence[str]:
        return list(addresses)

    return resolve


class FakeFetcher:
    """Serves archives by SHA-256 instead of downloading (pipeline/API tests)."""

    def __init__(self, *archives: bytes) -> None:
        self.archives = {hashlib.sha256(a).hexdigest(): a for a in archives}
        self.calls = 0

    def __call__(self, target: SourceTarget, destination: str, request: AnalyzeRequest, settings: Settings, deadline: Deadline) -> None:
        self.calls += 1
        with open(destination, "xb") as handle:
            handle.write(self.archives[request.source.sha256])


def zip_bytes(files: dict[str, bytes | str], directories: Sequence[str] = ()) -> bytes:
    """A normal archive written by Python's zipfile (deflated)."""
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archive:
        for directory in directories:
            archive.writestr(zipfile.ZipInfo(directory), b"")
        for name, content in files.items():
            archive.writestr(name, content if isinstance(content, bytes) else content.encode())
    return buffer.getvalue()


def zip_with_info(
    name: str, content: bytes, *, mode: int | None = None, flag_bits: int = 0, compress_type: int = zipfile.ZIP_DEFLATED
) -> bytes:
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as archive:
        info = zipfile.ZipInfo(name)
        info.compress_type = compress_type
        info.create_system = 3
        if mode is not None:
            info.external_attr = mode << 16
        archive.writestr(info, content)
        if flag_bits:
            info.flag_bits |= flag_bits
    data = buffer.getvalue()
    if flag_bits:
        data = _set_flags(data, flag_bits)
    return data


def _set_flags(data: bytes, flag_bits: int) -> bytes:
    """Sets general-purpose flags in every local and central header."""
    out = bytearray(data)
    for signature, offset in ((b"PK\x03\x04", 6), (b"PK\x01\x02", 8)):
        start = 0
        while (index := out.find(signature, start)) != -1:
            (flags,) = struct.unpack_from("<H", out, index + offset)
            struct.pack_into("<H", out, index + offset, flags | flag_bits)
            start = index + 4
    return bytes(out)


def raw_zip(entries: Sequence[dict[str, Any]], prefix: bytes = b"") -> bytes:
    """Writes ZIP bytes by hand, for archives zipfile refuses to write.

    Entry keys: name, content, method (0/8), declared_size, local_name,
    offset (overrides the recorded local header offset), mode (Unix).
    """
    body = bytearray(prefix)
    central = bytearray()
    for entry in entries:
        content: bytes = entry.get("content", b"")
        method = entry.get("method", 8)
        data = zlib.compress(content)[2:-4] if method == 8 else content
        crc = zlib.crc32(content)
        size = entry.get("declared_size", len(content))
        name = entry["name"].encode()
        local_name = entry.get("local_name", entry["name"]).encode()
        offset = entry.get("offset", len(body))
        mode = entry.get("mode", stat.S_IFREG | 0o644)
        body += struct.pack("<4s5H3L2H", b"PK\x03\x04", 20, 0x0800, method, 0, 0x5B21, crc, len(data), size, len(local_name), 0)
        body += local_name + data
        central += (
            struct.pack(
                "<4s6H3L5H2L",
                b"PK\x01\x02",
                (3 << 8) | 20,
                20,
                0x0800,
                method,
                0,
                0x5B21,
                crc,
                len(data),
                size,
                len(name),
                0,
                0,
                0,
                0,
                mode << 16,
                offset,
            )
            + name
        )
    end = struct.pack("<4s4H2LH", b"PK\x05\x06", 0, 0, len(entries), len(entries), len(central), len(body), 0)
    return bytes(body + central + end)
