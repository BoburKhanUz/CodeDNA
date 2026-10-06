import hashlib
import hmac
import inspect
import random
import string

import pytest

from app.auth import hmac_signing
from app.auth.hmac_signing import request_canonical_string, sign, sign_response, verify_request
from app.auth.replay import ReplayCache
from app.errors import AnalyzerError, ErrorCode
from tests.support import PATH, PREVIOUS_SECRET, SECRET

REQUEST_ID = "6f1c2a4e-1d3b-4c55-9a7e-2b8f0c9d1e23"
BODY = b'{"contract_version":"1.0"}'
NOW = 1_790_000_000


def verify(**overrides):  # type: ignore[no-untyped-def]
    values = {
        "secrets": (SECRET,),
        "max_skew_seconds": 300,
        "method": "POST",
        "path": PATH,
        "body": BODY,
        "timestamp": str(NOW),
        "signature": sign(SECRET, request_canonical_string(str(NOW), "POST", PATH, REQUEST_ID, BODY)),
        "request_id": REQUEST_ID,
        "now": NOW,
    }
    values.update(overrides)
    return verify_request(**values)


def test_canonical_string_matches_the_contract_exactly() -> None:
    canonical = request_canonical_string("1790000000", "post", "/internal/v1/analyze", REQUEST_ID, BODY)
    assert canonical == (
        b"v1\n1790000000\nPOST\n/internal/v1/analyze\n" + REQUEST_ID.encode() + b"\n" + hashlib.sha256(BODY).hexdigest().encode()
    )
    assert not canonical.endswith(b"\n")
    expected = hmac.new(SECRET.encode(), canonical, hashlib.sha256).hexdigest()
    assert sign(SECRET, canonical) == "v1=" + expected


def test_a_valid_signature_is_accepted() -> None:
    assert verify() == NOW


def test_the_previous_secret_is_accepted_during_rotation() -> None:
    signature = sign(PREVIOUS_SECRET, request_canonical_string(str(NOW), "POST", PATH, REQUEST_ID, BODY))
    assert verify(secrets=(SECRET, PREVIOUS_SECRET), signature=signature) == NOW
    with pytest.raises(AnalyzerError):
        verify(signature=signature)


@pytest.mark.parametrize(
    ("overrides", "code"),
    [
        ({"signature": None}, ErrorCode.INVALID_SIGNATURE),
        ({"timestamp": None}, ErrorCode.INVALID_SIGNATURE),
        ({"request_id": None}, ErrorCode.INVALID_SIGNATURE),
        ({"signature": "v1=" + "0" * 64}, ErrorCode.INVALID_SIGNATURE),
        ({"signature": "sha256=" + "0" * 64}, ErrorCode.INVALID_SIGNATURE),
        ({"signature": "v1=" + "A" * 64}, ErrorCode.INVALID_SIGNATURE),
        ({"timestamp": "1790000000.5"}, ErrorCode.INVALID_SIGNATURE),
        ({"timestamp": "-1"}, ErrorCode.INVALID_SIGNATURE),
        ({"request_id": "not-a-uuid"}, ErrorCode.INVALID_SIGNATURE),
        ({"body": BODY + b" "}, ErrorCode.INVALID_SIGNATURE),
        ({"path": PATH + "?x=1"}, ErrorCode.INVALID_SIGNATURE),
        ({"method": "PUT"}, ErrorCode.INVALID_SIGNATURE),
        ({"secrets": ("another-secret-of-sufficient-length-000000",)}, ErrorCode.INVALID_SIGNATURE),
        ({"now": NOW + 301}, ErrorCode.STALE_TIMESTAMP),
        ({"now": NOW - 301}, ErrorCode.STALE_TIMESTAMP),
    ],
)
def test_invalid_requests_are_rejected(overrides, code) -> None:  # type: ignore[no-untyped-def]
    with pytest.raises(AnalyzerError) as raised:
        verify(**overrides)
    assert raised.value.code is code


def test_the_freshness_window_is_inclusive() -> None:
    assert verify(now=NOW + 300) == NOW
    assert verify(now=NOW - 300) == NOW


def test_signatures_are_compared_in_constant_time() -> None:
    source = inspect.getsource(hmac_signing.verify_request)
    assert "hmac.compare_digest" in source
    assert "== signature" not in source and "signature ==" not in source


def test_any_single_byte_change_invalidates_the_signature() -> None:
    rng = random.Random(1337)
    for _ in range(200):
        body = "".join(rng.choice(string.printable + "é€😀") for _ in range(rng.randint(0, 64))).encode()
        canonical = request_canonical_string(str(NOW), "POST", PATH, REQUEST_ID, body)
        signature = sign(SECRET, canonical)
        assert verify(body=body, signature=signature) == NOW
        if body:
            index = rng.randrange(len(body))
            tampered = body[:index] + bytes([body[index] ^ 0x01]) + body[index + 1 :]
            with pytest.raises(AnalyzerError):
                verify(body=tampered, signature=signature)


def test_reordered_json_is_a_different_request() -> None:
    # The exact bytes are signed: semantically equal JSON with another key order does not verify.
    signature = sign(SECRET, request_canonical_string(str(NOW), "POST", PATH, REQUEST_ID, b'{"a":1,"b":2}'))
    with pytest.raises(AnalyzerError):
        verify(body=b'{"b":2,"a":1}', signature=signature)


def test_response_signature_covers_status_path_request_id_and_body() -> None:
    headers = sign_response(SECRET, 200, PATH, REQUEST_ID, b"{}", now=NOW)
    canonical = (
        b"v1\n"
        + str(NOW).encode()
        + b"\n200\n"
        + PATH.encode()
        + b"\n"
        + REQUEST_ID.encode()
        + b"\n"
        + hashlib.sha256(b"{}").hexdigest().encode()
    )
    assert headers == {"X-CodeDNA-Timestamp": str(NOW), "X-CodeDNA-Signature": sign(SECRET, canonical)}


def test_replayed_request_ids_are_rejected_and_the_cache_is_bounded() -> None:
    cache = ReplayCache(max_entries=2, window_seconds=300)
    cache.remember("id-1", NOW, now=NOW)
    with pytest.raises(AnalyzerError) as replay:
        cache.remember("id-1", NOW, now=NOW + 10)
    assert replay.value.code is ErrorCode.REPLAY_DETECTED

    cache.remember("id-2", NOW, now=NOW)
    with pytest.raises(AnalyzerError) as full:
        cache.remember("id-3", NOW, now=NOW)
    assert full.value.code is ErrorCode.ANALYZER_BUSY
    assert len(cache) == 2

    # Once a request is outside the window it cannot be replayed anyway, so it is forgotten.
    cache.remember("id-3", NOW + 400, now=NOW + 400)
    assert len(cache) == 1
