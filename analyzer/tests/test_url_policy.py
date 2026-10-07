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
    answers = iter([["104.18.10.20"], ["127.0.0.1"]])
    lookups: list[tuple[str, int]] = []

    def rebinding(host: str, port: int) -> list[str]:
        lookups.append((host, port))
        return next(answers)

    target = validate_source_url(presigned_url(host="storage.example.com", scheme="https"), settings, rebinding)
    # The download connects to this address (test_download checks it connects to
    # SourceTarget.address); DNS is consulted exactly once, so a second answer never counts.
    assert target.address == "104.18.10.20"
    assert lookups == [("storage.example.com", 443)]


@pytest.mark.parametrize(
    "host",
    [
        "2130706433",
        "0x7f000001",
        "0177.0.0.1",
        "127.1",
        "[::ffff:127.0.0.1]",
        "[::ffff:7f00:1]",
        "[64:ff9b::a9fe:a9fe]",
        "169.254.169.254.",
        "[fd00:ec2::254]",
        "minio%2e",
        "MINIO.attacker.example",
    ],
)
def test_ip_literal_spellings_and_look_alike_hosts_are_refused(host: str, settings: Settings) -> None:
    """Phase 22: hosts are allow-listed by name; no spelling of an address gets past it."""
    assert rejected(presigned_url(host=host), settings) is ErrorCode.SOURCE_HOST_NOT_ALLOWED


def test_a_public_ip_literal_is_refused_even_on_the_https_port(settings: Settings) -> None:
    """Only the literal check stands between this URL and a download: HTTPS, port 443, a public address."""
    for host in ("104.18.10.20", "[2606:4700::6812:a14]"):
        assert rejected(presigned_url(host=host, scheme="https"), settings, PUBLIC) is ErrorCode.SOURCE_HOST_NOT_ALLOWED


def test_a_trailing_dot_and_upper_case_name_the_same_allowed_host(settings: Settings) -> None:
    for host in ("minio.", "MINIO"):
        target = validate_source_url(presigned_url(host=f"{host}:9000"), settings, PRIVATE)
        assert (target.host, target.address) == ("minio", "172.18.0.5")


@pytest.mark.parametrize(
    "address",
    [
        "64:ff9b::7f00:1",  # NAT64 of loopback
        "64:ff9b::a9fe:a9fe",  # NAT64 of the metadata address
        "64:ff9b:1::a9fe:a9fe",  # local-use NAT64
        "2002:7f00:1::1",  # 6to4 of loopback
        "2002:a9fe:a9fe::1",  # 6to4 of the metadata address
        "2001:0:4136:e378:8000:63bf:3fff:fdd2",  # Teredo
        "fc00::1",  # unique local
        "::ffff:169.254.169.254",  # IPv4-mapped metadata address
        "::127.0.0.1",  # IPv4-compatible loopback
        "100.64.0.1",  # carrier-grade NAT
        "198.18.0.1",  # benchmarking
        "192.0.0.170",  # NAT64 discovery
        "255.255.255.255",
        "not-an-address",
    ],
)
def test_a_public_host_resolving_to_a_disguised_internal_address_is_refused(address: str, settings: Settings) -> None:
    url = presigned_url(host="storage.example.com", scheme="https")
    assert rejected(url, settings, resolver(address)) is ErrorCode.SOURCE_HOST_NOT_ALLOWED


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


def test_a_public_host_is_only_reached_on_the_https_port(settings: Settings) -> None:
    """Phase 21: an allow-listed public name cannot be used to probe other ports."""
    for port in (22, 80, 6379, 8443):
        url = presigned_url(host=f"storage.example.com:{port}", scheme="https")
        assert rejected(url, settings, PUBLIC) == ErrorCode.SOURCE_HOST_NOT_ALLOWED
    target = validate_source_url(presigned_url(host="storage.example.com:443", scheme="https"), settings, PUBLIC)
    assert target.port == 443
