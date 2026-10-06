"""Canonical JSON and hashing.

Canonical form: UTF-8, object keys sorted by Unicode code point, no
insignificant whitespace (``,`` and ``:`` separators), non-ASCII characters
written literally (not ``\\u`` escaped), NaN and infinities rejected. The
foundation result contains only strings, integers, booleans, nulls, lists
and objects; floats are not used (later phases round per ADR-004 before
hashing). Lists keep their order, so producers must emit lists in a
deterministic order (files are sorted by path, languages by identifier).
"""

import hashlib
import json
from typing import Any


def canonical_json(value: Any) -> bytes:
    return json.dumps(
        value,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=False,
        allow_nan=False,
    ).encode("utf-8")


def sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_hash(value: Any) -> str:
    return sha256_hex(canonical_json(value))
