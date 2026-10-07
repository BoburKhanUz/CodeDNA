"""The shared HMAC vector (packages/api-contracts/analyzer/v1/hmac-vectors.json).

Laravel's HmacSignerTest checks its signer against the same file, so a
change to either side's canonical string or encoding fails one of the two
suites instead of every live request.
"""

import pytest

from app.auth.hmac_signing import sign_response, verify_request
from app.errors import AnalyzerError
from tests.schema_check import load
from tests.support import PREVIOUS_SECRET, SECRET


def test_the_shared_vector_was_signed_with_the_test_secret() -> None:
    vector = load("hmac-vectors.json")

    assert vector["secret"].startswith("'t' * 64")
    assert SECRET == "t" * 64


def test_the_analyzer_produces_the_shared_response_signature() -> None:
    v = load("hmac-vectors.json")

    headers = sign_response(SECRET, v["status"], v["path"], v["request_id"], v["body"].encode(), now=v["timestamp"])

    assert headers == {"X-CodeDNA-Timestamp": str(v["timestamp"]), "X-CodeDNA-Signature": v["response_signature"]}


def test_the_analyzer_accepts_the_shared_request_signature_and_only_with_its_secret() -> None:
    v = load("hmac-vectors.json")
    args = {
        "max_skew_seconds": 300,
        "method": v["method"],
        "path": v["path"],
        "body": v["body"].encode(),
        "timestamp": str(v["timestamp"]),
        "signature": v["request_signature"],
        "request_id": v["request_id"],
        "now": v["timestamp"],
    }

    assert verify_request(secrets=(SECRET,), **args) == v["timestamp"]
    assert verify_request(secrets=(PREVIOUS_SECRET, SECRET), **args) == v["timestamp"]
    with pytest.raises(AnalyzerError):
        verify_request(secrets=(PREVIOUS_SECRET,), **args)
