"""Source URL validation: the analyzer's SSRF boundary.

A source URL is accepted only if all of these hold:

- scheme ``https``; plain ``http`` only for hosts in
  ANALYZER_LOCAL_SOURCE_HOSTS (local development, e.g. ``minio``);
- the hostname is exactly one of ANALYZER_ALLOWED_SOURCE_HOSTS (no
  wildcards, no IP literals, no user:password@, no fragment);
- it is a SigV4 pre-signed GET URL that has not expired and whose lifetime
  is at most ANALYZER_MAX_URL_LIFETIME_SECONDS;
- the object key ends in ``projects/<ULID>/snapshots/<ULID>/source.zip``
  (the layout Laravel writes, optionally under a bucket/prefix);
- every address the hostname resolves to is public (``is_global``), or, for
  local hosts, at least not loopback, link-local (which includes cloud
  metadata endpoints), multicast, unspecified or reserved.

The download then connects to one of the validated addresses directly, so a
DNS answer cannot change between the check and the connection (no DNS
rebinding). Redirects are never followed.
"""

import ipaddress
import re
import socket
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from urllib.parse import parse_qs, urlsplit

from app.config import Settings
from app.errors import AnalyzerError, ErrorCode

Resolver = Callable[[str, int], Sequence[str]]

_OBJECT_PATH = re.compile(r"^/(?:[A-Za-z0-9._-]+/)*projects/[0-9a-z]{26}/snapshots/[0-9a-z]{26}/source\.zip$")
_AMZ_DATE = "%Y%m%dT%H%M%SZ"
_REQUIRED_PRESIGN_PARAMS = (
    "X-Amz-Algorithm",
    "X-Amz-Credential",
    "X-Amz-Date",
    "X-Amz-Expires",
    "X-Amz-SignedHeaders",
    "X-Amz-Signature",
)


@dataclass(frozen=True)
class SourceTarget:
    """A validated download target. ``address`` is the pinned IP to connect to."""

    scheme: str
    host: str
    port: int
    address: str
    path_and_query: str


def system_resolver(host: str, port: int) -> list[str]:
    infos = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    return sorted({str(info[4][0]) for info in infos})


def validate_source_url(
    url: str,
    settings: Settings,
    resolver: Resolver = system_resolver,
    now: datetime | None = None,
) -> SourceTarget:
    denied = AnalyzerError(ErrorCode.SOURCE_HOST_NOT_ALLOWED)

    try:
        parts = urlsplit(url)
        port = parts.port
    except ValueError:
        raise denied from None

    if any(ord(char) < 0x21 or ord(char) > 0x7E for char in url):
        raise denied
    if parts.username is not None or parts.password is not None or parts.fragment:
        raise denied

    host = (parts.hostname or "").lower().rstrip(".")
    if host == "" or _is_ip_literal(host) or host not in settings.allowed_source_hosts:
        raise denied

    is_local = host in settings.local_source_hosts
    # Phase 21: a public storage host is reached on the HTTPS port only, so
    # an allow-listed name cannot be used to probe its other ports.
    if not is_local and port not in (None, 443):
        raise denied
    if parts.scheme == "https":
        default_port = 443
    elif parts.scheme == "http" and is_local:
        default_port = 80
    else:
        raise denied

    if not _OBJECT_PATH.match(parts.path) or ".." in parts.path or "%" in parts.path:
        raise denied

    _check_presigned(parts.query, settings, now or datetime.now(UTC))

    resolved_port = port or default_port
    try:
        addresses = list(resolver(host, resolved_port))
    except OSError:
        raise AnalyzerError(ErrorCode.SOURCE_FETCH_FAILED, {"reason": "dns_failure"}) from None
    if not addresses or not all(_address_allowed(address, is_local) for address in addresses):
        raise denied

    return SourceTarget(
        scheme=parts.scheme,
        host=host,
        port=resolved_port,
        address=addresses[0],
        path_and_query=parts.path + "?" + parts.query,
    )


def _check_presigned(query: str, settings: Settings, now: datetime) -> None:
    params = parse_qs(query, keep_blank_values=True, strict_parsing=False)
    if any(len(params.get(name, [])) != 1 for name in _REQUIRED_PRESIGN_PARAMS):
        raise AnalyzerError(ErrorCode.SOURCE_HOST_NOT_ALLOWED, {"reason": "not_presigned"})
    if params["X-Amz-Algorithm"][0] != "AWS4-HMAC-SHA256":
        raise AnalyzerError(ErrorCode.SOURCE_HOST_NOT_ALLOWED, {"reason": "not_presigned"})
    try:
        signed_at = datetime.strptime(params["X-Amz-Date"][0], _AMZ_DATE).replace(tzinfo=UTC)
        lifetime = int(params["X-Amz-Expires"][0])
    except ValueError:
        raise AnalyzerError(ErrorCode.SOURCE_HOST_NOT_ALLOWED, {"reason": "not_presigned"}) from None
    if lifetime < 1 or lifetime > settings.max_url_lifetime_seconds:
        raise AnalyzerError(ErrorCode.SOURCE_HOST_NOT_ALLOWED, {"reason": "url_lifetime"})
    if now >= signed_at + timedelta(seconds=lifetime):
        raise AnalyzerError(ErrorCode.SOURCE_URL_EXPIRED)
    # A URL signed noticeably in the future is not one Laravel just made.
    if signed_at > now + timedelta(seconds=settings.hmac_max_skew_seconds):
        raise AnalyzerError(ErrorCode.SOURCE_HOST_NOT_ALLOWED, {"reason": "url_not_yet_valid"})


def _address_allowed(address: str, is_local: bool) -> bool:
    try:
        ip = ipaddress.ip_address(address.split("%", 1)[0])
    except ValueError:
        return False
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    if ip.is_loopback or ip.is_link_local or ip.is_multicast or ip.is_unspecified or ip.is_reserved:
        return False
    if is_local:
        # Local development: the storage container has a private address.
        return ip.is_private
    return ip.is_global


def _is_ip_literal(host: str) -> bool:
    try:
        ipaddress.ip_address(host.strip("[]"))
    except ValueError:
        return False
    return True


__all__ = ["Resolver", "SourceTarget", "system_resolver", "validate_source_url"]
