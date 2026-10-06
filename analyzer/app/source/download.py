"""Bounded, streaming source download from a validated pre-signed URL.

- Connects to the pinned, validated IP (``SourceTarget.address``) and sends
  the original Host header; for HTTPS the certificate is verified against
  the hostname (SNI), so the pin cannot be abused to reach another host.
- Never follows redirects (``http.client`` does not), and treats any 3xx as
  a failed fetch.
- Streams to a file in the run's workspace while counting bytes and hashing;
  never holds the archive in memory. Stops as soon as the archive exceeds
  ANALYZER_MAX_ARCHIVE_BYTES or the size the request declared.
- Connect, per-read and total timeouts all apply.
- Verifies the declared size and SHA-256 afterwards.

No credentials are sent: the pre-signed query string is the only
authorization, and it is never logged.
"""

import hashlib
import http.client
import os
import socket
import ssl

from app import __version__
from app.config import Settings
from app.deadline import Deadline
from app.errors import AnalyzerError, ErrorCode
from app.source.url_policy import SourceTarget

CHUNK_BYTES = 64 * 1024


class _PinnedHTTPConnection(http.client.HTTPConnection):
    def __init__(self, target: SourceTarget, timeout: float) -> None:
        super().__init__(target.host, target.port, timeout=timeout)
        self._address = target.address

    def connect(self) -> None:
        self.sock = socket.create_connection((self._address, self.port), self.timeout)


class _PinnedHTTPSConnection(http.client.HTTPSConnection):
    def __init__(self, target: SourceTarget, timeout: float) -> None:
        super().__init__(target.host, target.port, timeout=timeout, context=ssl.create_default_context())
        self._address = target.address

    def connect(self) -> None:
        raw = socket.create_connection((self._address, self.port), self.timeout)
        try:
            self.sock = self._context.wrap_socket(raw, server_hostname=self.host)  # type: ignore[attr-defined]
        except BaseException:
            raw.close()
            raise


def download(
    target: SourceTarget,
    destination: str,
    *,
    expected_size: int,
    expected_sha256: str,
    settings: Settings,
    deadline: Deadline,
) -> None:
    if expected_size > settings.max_archive_bytes:
        raise AnalyzerError(ErrorCode.SOURCE_TOO_LARGE, {"limit_bytes": settings.max_archive_bytes})

    fetch_deadline = deadline.sooner(settings.source_download_timeout_seconds, ErrorCode.SOURCE_FETCH_FAILED)
    connection_class = _PinnedHTTPSConnection if target.scheme == "https" else _PinnedHTTPConnection
    connection = connection_class(target, timeout=min(settings.source_connect_timeout_seconds, _positive(fetch_deadline)))
    digest = hashlib.sha256()
    received = 0
    response: http.client.HTTPResponse | None = None

    try:
        connection.request(
            "GET",
            target.path_and_query,
            headers={"Host": _host_header(target), "User-Agent": f"codedna-analyzer/{__version__}", "Accept": "*/*"},
        )
        if connection.sock is not None:
            connection.sock.settimeout(min(settings.source_read_timeout_seconds, _positive(fetch_deadline)))
        response = connection.getresponse()

        if 300 <= response.status < 400:
            raise AnalyzerError(ErrorCode.SOURCE_FETCH_FAILED, {"reason": "redirect_not_followed"})
        if response.status != 200:
            raise AnalyzerError(ErrorCode.SOURCE_FETCH_FAILED, {"reason": "unexpected_status", "status": response.status})

        declared = response.getheader("Content-Length")
        if declared is not None and declared.isdigit():
            if int(declared) > settings.max_archive_bytes:
                raise AnalyzerError(ErrorCode.SOURCE_TOO_LARGE, {"limit_bytes": settings.max_archive_bytes})
            if int(declared) != expected_size:
                raise AnalyzerError(ErrorCode.SOURCE_CHECKSUM_MISMATCH)

        descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, "wb") as file:
            while True:
                fetch_deadline.check()
                if connection.sock is not None:
                    connection.sock.settimeout(min(settings.source_read_timeout_seconds, _positive(fetch_deadline)))
                chunk = response.read(CHUNK_BYTES)
                if not chunk:
                    break
                received += len(chunk)
                if received > settings.max_archive_bytes:
                    raise AnalyzerError(ErrorCode.SOURCE_TOO_LARGE, {"limit_bytes": settings.max_archive_bytes})
                if received > expected_size:
                    raise AnalyzerError(ErrorCode.SOURCE_CHECKSUM_MISMATCH)
                digest.update(chunk)
                file.write(chunk)
    except AnalyzerError:
        raise
    except TimeoutError:
        deadline.check()
        raise AnalyzerError(ErrorCode.SOURCE_FETCH_FAILED, {"reason": "timeout"}) from None
    except (OSError, http.client.HTTPException):
        raise AnalyzerError(ErrorCode.SOURCE_FETCH_FAILED, {"reason": "connection_failed"}) from None
    finally:
        if response is not None:
            response.close()
        connection.close()

    if received != expected_size or digest.hexdigest() != expected_sha256:
        raise AnalyzerError(ErrorCode.SOURCE_CHECKSUM_MISMATCH)


def _positive(deadline: Deadline) -> float:
    remaining = deadline.remaining()
    if remaining <= 0:
        deadline.check()
    return max(remaining, 0.001)


def _host_header(target: SourceTarget) -> str:
    default = 443 if target.scheme == "https" else 80
    return target.host if target.port == default else f"{target.host}:{target.port}"
