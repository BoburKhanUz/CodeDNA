import json
import random

from app.canonical import canonical_hash, canonical_json


def test_canonical_json_is_compact_sorted_and_utf8() -> None:
    assert canonical_json({"b": 1, "a": {"y": [3, 1], "x": "é"}}) == '{"a":{"x":"é","y":[3,1]},"b":1}'.encode()


def test_key_order_never_changes_the_hash() -> None:
    rng = random.Random(42)
    for _ in range(200):
        items = [(f"k{rng.randint(0, 50)}-{'ü' * rng.randint(0, 2)}", rng.randint(-1000, 1000)) for _ in range(rng.randint(1, 15))]
        forward = dict(items)
        backward = dict(reversed(list(forward.items())))
        assert canonical_hash({"outer": forward}) == canonical_hash({"outer": backward})
        assert json.loads(canonical_json(forward)) == forward


def test_nan_is_rejected() -> None:
    try:
        canonical_json({"x": float("nan")})
    except ValueError:
        return
    raise AssertionError("NaN must not be serializable")
