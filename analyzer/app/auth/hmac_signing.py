"""Laravel → analyzer request authentication (contract section 3).

Request canonical string (lines joined with "\\n", no trailing newline):

    v1
    <X-CodeDNA-Timestamp: unix seconds>
    <HTTP method, uppercase>
    <request path as sent, including "?query" if any>
    <X-Request-ID>
    <lowercase hex SHA-256 of the exact raw request body bytes>

Signature header: ``X-CodeDNA-Signature: v1=<lowercase hex HMAC-SHA256>``,
keyed with the UTF-8 bytes of ANALYZER_HMAC_SECRET (or, during rotation,
ANALYZER_HMAC_SECRET_PREVIOUS). Because the exact transmitted body bytes are
hashed, JSON key order, whitespace and Unicode escaping never need to be
canonicalized: any change to the bytes invalidates the signature.

Responses are signed over ``v1, timestamp, status, path, request id, body
hash`` with the current secret.
"""

import hashlib
import hmac
import re
import time
import uuid

from app.errors import AnalyzerError, ErrorCode

SIGNATURE_PREFIX = "v1="
_TIMESTAMP = re.compile(r"^[0-9]{1,12}$")
_SIGNATURE = re.compile(r"^v1=[0-9a-f]{64}$")


def request_canonical_string(timestamp: str, method: str, path: str, request_id: str, body: bytes) -> bytes:
    return "\n".join(["v1", timestamp, method.upper(), path, request_id, hashlib.sha256(body).hexdigest()]).encode("utf-8")


def response_canonical_string(timestamp: str, status: int, path: str, request_id: str, body: bytes) -> bytes:
    return "\n".join(["v1", timestamp, str(status), path, request_id, hashlib.sha256(body).hexdigest()]).encode("utf-8")


def sign(secret: str, canonical: bytes) -> str:
    return SIGNATURE_PREFIX + hmac.new(secret.encode("utf-8"), canonical, hashlib.sha256).hexdigest()


def is_uuid(value: str) -> bool:
    if len(value) != 36:
        return False
    try:
        uuid.UUID(value)
    except ValueError:
        return False
    return True


def verify_request(
    *,
    secrets: tuple[str, ...],
    max_skew_seconds: int,
    method: str,
    path: str,
    body: bytes,
    timestamp: str | None,
    signature: str | None,
    request_id: str | None,
    now: float | None = None,
) -> int:
    """Verifies the headers and signature; returns the timestamp.

    Raises INVALID_SIGNATURE for missing/malformed headers or a wrong
    signature, STALE_TIMESTAMP outside the freshness window (past or future).
    """
    if not timestamp or not signature or not request_id:
        raise AnalyzerError(ErrorCode.INVALID_SIGNATURE, {"reason": "missing_header"})
    if not _TIMESTAMP.match(timestamp) or not _SIGNATURE.match(signature) or not is_uuid(request_id):
        raise AnalyzerError(ErrorCode.INVALID_SIGNATURE, {"reason": "malformed_header"})

    signed_at = int(timestamp)
    current = time.time() if now is None else now
    if abs(current - signed_at) > max_skew_seconds:
        raise AnalyzerError(ErrorCode.STALE_TIMESTAMP)

    canonical = request_canonical_string(timestamp, method, path, request_id, body)
    # Every configured secret is checked (no early exit), each in constant time.
    matches = [hmac.compare_digest(sign(secret, canonical), signature) for secret in secrets]
    if not any(matches):
        raise AnalyzerError(ErrorCode.INVALID_SIGNATURE, {"reason": "signature_mismatch"})

    return signed_at


def sign_response(secret: str, status: int, path: str, request_id: str, body: bytes, now: float | None = None) -> dict[str, str]:
    timestamp = str(int(time.time() if now is None else now))
    return {
        "X-CodeDNA-Timestamp": timestamp,
        "X-CodeDNA-Signature": sign(secret, response_canonical_string(timestamp, status, path, request_id, body)),
    }
