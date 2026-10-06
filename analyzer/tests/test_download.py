"""The downloader against a real local HTTP server.

The URL policy rejects loopback addresses, so these tests build the
validated target directly; the policy itself is tested in test_url_policy.
"""

import hashlib
import os
import threading
import time
from collections.abc import Callable, Iterator
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

from app.config import Settings
from app.deadline import Deadline
from app.errors import AnalyzerError, ErrorCode
from app.source.download import download
from app.source.url_policy import SourceTarget
from tests.support import make_settings

PAYLOAD = b"PK\x03\x04" + os.urandom(200_000)

Handler = Callable[[BaseHTTPRequestHandler], None]


@pytest.fixture
def server() -> Iterator[tuple[ThreadingHTTPServer, list[Handler], list[dict[str, str]]]]:
    handlers: list[Handler] = []
    seen: list[dict[str, str]] = []

    class RequestHandler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802
            seen.append(dict(self.headers.items()) | {"path": self.path})
            handlers[0](self)

        def log_message(self, format: str, *args: object) -> None:  # noqa: A002
            pass

    httpd = ThreadingHTTPServer(("127.0.0.1", 0), RequestHandler)
    thread = threading.Thread(target=httpd.serve_forever, daemon=True)
    thread.start()
    yield httpd, handlers, seen
    httpd.shutdown()
    httpd.server_close()


def serve(body: bytes, status: int = 200, headers: dict[str, str] | None = None, content_length: bool = True) -> Handler:
    def handle(request: BaseHTTPRequestHandler) -> None:
        request.send_response(status)
        for name, value in (headers or {}).items():
            request.send_header(name, value)
        if content_length:
            request.send_header("Content-Length", str(len(body)))
        request.end_headers()
        request.wfile.write(body)

    return handle


def target(httpd: ThreadingHTTPServer) -> SourceTarget:
    return SourceTarget("http", "minio", httpd.server_address[1], "127.0.0.1", "/codedna/projects/x/source.zip?X-Amz-Signature=secret")


def fetch(httpd: ThreadingHTTPServer, settings: Settings, destination: str, body: bytes = PAYLOAD, seconds: float = 30) -> None:
    download(
        target(httpd),
        destination,
        expected_size=len(body),
        expected_sha256=hashlib.sha256(body).hexdigest(),
        settings=settings,
        deadline=Deadline(seconds),
    )


def error(call: Callable[[], None]) -> AnalyzerError:
    with pytest.raises(AnalyzerError) as raised:
        call()
    return raised.value


def test_downloads_streams_and_verifies(server, settings: Settings, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, seen = server
    handlers.append(serve(PAYLOAD))
    destination = str(tmp_path / "source.zip")

    fetch(httpd, settings, destination)

    with open(destination, "rb") as stored:
        assert stored.read() == PAYLOAD
    assert oct(os.stat(destination).st_mode & 0o777) == "0o600"
    assert seen[0]["Host"] == f"minio:{httpd.server_address[1]}"
    assert "Authorization" not in seen[0] and "Cookie" not in seen[0]


def test_redirects_are_never_followed(server, settings: Settings, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, seen = server
    handlers.append(serve(b"", status=302, headers={"Location": "http://169.254.169.254/latest/meta-data/"}))

    failure = error(lambda: fetch(httpd, settings, str(tmp_path / "a.zip")))

    assert failure.code is ErrorCode.SOURCE_FETCH_FAILED
    assert failure.details["reason"] == "redirect_not_followed"
    assert len(seen) == 1


def test_error_statuses_are_fetch_failures(server, settings: Settings, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, _ = server
    handlers.append(serve(b"<Error>AccessDenied</Error>", status=403))

    failure = error(lambda: fetch(httpd, settings, str(tmp_path / "a.zip")))
    assert (failure.code, failure.details) == (ErrorCode.SOURCE_FETCH_FAILED, {"reason": "unexpected_status", "status": 403})


def test_a_declared_length_above_the_limit_stops_before_reading(server, workspace_root: str, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, _ = server
    handlers.append(serve(PAYLOAD))
    small = make_settings(workspace_root, max_archive_bytes=1000)

    failure = error(
        lambda: download(
            target(httpd), str(tmp_path / "a.zip"), expected_size=900, expected_sha256="0" * 64, settings=small, deadline=Deadline(30)
        )
    )
    assert failure.code is ErrorCode.SOURCE_TOO_LARGE


def test_the_byte_limit_is_enforced_while_streaming_without_content_length(server, workspace_root: str, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, _ = server
    handlers.append(serve(PAYLOAD, content_length=False, headers={"Connection": "close"}))
    small = make_settings(workspace_root, max_archive_bytes=100_000)

    failure = error(
        lambda: download(
            target(httpd), str(tmp_path / "a.zip"), expected_size=90_000, expected_sha256="0" * 64, settings=small, deadline=Deadline(30)
        )
    )
    # More bytes than the request declared (and than the limit) arrived.
    assert failure.code in (ErrorCode.SOURCE_TOO_LARGE, ErrorCode.SOURCE_CHECKSUM_MISMATCH)
    assert os.path.getsize(tmp_path / "a.zip") <= 100_000


def test_a_declared_size_above_the_limit_is_rejected_before_connecting(server, workspace_root: str, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, seen = server
    small = make_settings(workspace_root, max_archive_bytes=10)
    failure = error(lambda: fetch(httpd, small, str(tmp_path / "a.zip")))
    assert failure.code is ErrorCode.SOURCE_TOO_LARGE
    assert seen == []


def test_size_and_checksum_must_match(server, settings: Settings, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, _ = server
    handlers.append(serve(PAYLOAD))
    failure = error(
        lambda: download(
            target(httpd),
            str(tmp_path / "a.zip"),
            expected_size=len(PAYLOAD),
            expected_sha256="0" * 64,
            settings=settings,
            deadline=Deadline(30),
        )
    )
    assert failure.code is ErrorCode.SOURCE_CHECKSUM_MISMATCH

    handlers[0] = serve(PAYLOAD[:-1])
    failure = error(lambda: fetch(httpd, settings, str(tmp_path / "b.zip")))
    assert failure.code is ErrorCode.SOURCE_CHECKSUM_MISMATCH


def test_slow_servers_time_out(server, workspace_root: str, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, _ = server

    def stall(request: BaseHTTPRequestHandler) -> None:
        request.send_response(200)
        request.send_header("Content-Length", str(len(PAYLOAD)))
        request.end_headers()
        request.wfile.write(PAYLOAD[:10])
        request.wfile.flush()
        time.sleep(3)

    handlers.append(stall)
    quick = make_settings(workspace_root, source_read_timeout_seconds=0.5)
    started = time.monotonic()
    failure = error(lambda: fetch(httpd, quick, str(tmp_path / "a.zip")))

    assert failure.code is ErrorCode.SOURCE_FETCH_FAILED
    assert time.monotonic() - started < 2.5


def test_the_overall_deadline_becomes_an_analysis_timeout(server, settings: Settings, tmp_path) -> None:  # type: ignore[no-untyped-def]
    httpd, handlers, _ = server

    def stall(request: BaseHTTPRequestHandler) -> None:
        request.send_response(200)
        request.send_header("Content-Length", str(len(PAYLOAD)))
        request.end_headers()
        time.sleep(3)

    handlers.append(stall)
    failure = error(lambda: fetch(httpd, settings, str(tmp_path / "a.zip"), seconds=0.5))
    assert failure.code is ErrorCode.ANALYSIS_TIMEOUT


def test_connection_failures_are_retryable_fetch_failures(settings: Settings, tmp_path) -> None:  # type: ignore[no-untyped-def]
    closed = SourceTarget("http", "minio", 9, "127.0.0.1", "/x?y")
    failure = error(
        lambda: download(
            closed, str(tmp_path / "a.zip"), expected_size=10, expected_sha256="0" * 64, settings=settings, deadline=Deadline(5)
        )
    )
    assert failure.code is ErrorCode.SOURCE_FETCH_FAILED and failure.code.retryable
