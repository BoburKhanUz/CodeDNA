from datetime import UTC, datetime, timedelta

import pytest

from app.config import Settings
from app.errors import AnalyzerError, ErrorCode
from app.source.url_policy import validate_source_url
from tests.support import PROJECT_ID, SNAPSHOT_ID, presigned_url, resolver

PRIVATE = resolver("172.18.0.5")
PUBLIC = resolver("104.18.10.20")


def test_accepts_a_presigned_url_on_an_allowed_local_host(settings: Settings) -> None:
    target = validate_source_url(presigned_url(), settings, PRIVATE)

    assert (target.scheme, target.host, target.port, target.address) == ("http", "minio", 9000, "172.18.0.5")
    assert target.path_and_query.startswith(f"/codedna/projects/{PROJECT_ID}/snapshots/{SNAPSHOT_ID}/source.zip?X-Amz-Algorithm=")


def test_accepts_https_on_an_allowed_public_host(settings: Settings) -> None:
    target = validate_source_url(presigned_url(host="storage.example.com", scheme="https"), settings, PUBLIC)
    assert (target.scheme, target.port, target.address) == ("https", 443, "104.18.10.20")


def rejected(url: str, settings: Settings, resolve=PRIVATE) -> ErrorCode:  # type: ignore[no-untyped-def]
    with pytest.raises(AnalyzerError) as raised:
        validate_source_url(url, settings, resolve)
    return raised.value.code


@pytest.mark.parametrize(
    "url",
    [
        presigned_url(host="evil.example.com", scheme="https"),
        presigned_url(host="localhost"),
        presigned_url(host="127.0.0.1"),
        presigned_url(host="0.0.0.0"),
        presigned_url(host="169.254.169.254"),
        presigned_url(host="[::1]"),
        presigned_url(host="10.0.0.5"),
        presigned_url(host="postgres:5432"),
        presigned_url(host="backend:9000"),
        presigned_url(host="minio.attacker.example"),
        presigned_url(host="user:pass@minio:9000"),
        presigned_url().replace("http://", "file://"),
        presigned_url().replace("http://", "ftp://"),
        presigned_url().replace("http://", "gopher://"),
        presigned_url(host="storage.example.com", scheme="http"),
        presigned_url() + "#fragment",
        presigned_url(key="codedna/etc/passwd"),
        presigned_url(key="codedna/projects/../../etc/source.zip"),
        presigned_url(key=f"codedna/projects/{PROJECT_ID}/snapshots/{SNAPSHOT_ID}/source.zip%2F.."),
        presigned_url(key=f"codedna/projects/{PROJECT_ID}/snapshots/{SNAPSHOT_ID}/other.zip"),
        presigned_url().split("?")[0],
        presigned_url().replace("X-Amz-Signature", "Signature"),
        presigned_url().replace("AWS4-HMAC-SHA256", "AWS2"),
        presigned_url(expires=86400),
        presigned_url() + " ",
        "not a url",
    ],
)
def test_rejects_urls_outside_the_policy(url: str, settings: Settings) -> None:
    assert rejected(url, settings) is ErrorCode.SOURCE_HOST_NOT_ALLOWED


@pytest.mark.parametrize(
    "addresses",
    [
        ("127.0.0.1",),
        ("169.254.169.254",),
        ("0.0.0.0",),
        ("::1",),
        ("fe80::1",),
        ("::ffff:127.0.0.1",),
        ("224.0.0.1",),
        ("172.18.0.5", "127.0.0.1"),
    ],
)
def test_a_local_host_may_not_resolve_to_loopback_link_local_or_metadata(addresses: tuple[str, ...], settings: Settings) -> None:
    assert rejected(presigned_url(), settings, resolver(*addresses)) is ErrorCode.SOURCE_HOST_NOT_ALLOWED


@pytest.mark.parametrize(
    "address", ["10.1.2.3", "192.168.1.10", "172.18.0.5", "100.100.100.200", "169.254.169.254", "127.0.0.1", "fd00:ec2::254"]
)
def test_a_public_host_must_resolve_to_public_addresses(address: str, settings: Settings) -> None:
    url = presigned_url(host="storage.example.com", scheme="https")
    assert rejected(url, settings, resolver(address)) is ErrorCode.SOURCE_HOST_NOT_ALLOWED


def test_dns_rebinding_is_prevented_by_pinning_the_validated_address(settings: Settings) -> None:
    calls = iter([["104.18.10.20"], ["127.0.0.1"]])
    target = validate_source_url(presigned_url(host="storage.example.com", scheme="https"), settings, lambda h, p: next(calls))
    # The download connects to this address; DNS is not consulted again.
    assert target.address == "104.18.10.20"


def test_expired_urls_are_rejected(settings: Settings) -> None:
    url = presigned_url(signed_at=datetime.now(UTC) - timedelta(minutes=20), expires=900)
    assert rejected(url, settings) is ErrorCode.SOURCE_URL_EXPIRED


def test_urls_signed_in_the_future_are_rejected(settings: Settings) -> None:
    url = presigned_url(signed_at=datetime.now(UTC) + timedelta(hours=1))
    assert rejected(url, settings) is ErrorCode.SOURCE_HOST_NOT_ALLOWED


def test_dns_failures_are_fetch_failures(settings: Settings) -> None:
    def failing(host: str, port: int) -> list[str]:
        raise OSError("no such host")

    assert rejected(presigned_url(), settings, failing) is ErrorCode.SOURCE_FETCH_FAILED
