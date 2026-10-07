"""Request validation and runner-output handling (no sandbox needed)."""

from __future__ import annotations

import json
import unittest

from evaluator import protocol
from evaluator.config import Limits

LIMITS = Limits(5, 5, 256 << 20, 16, 1 << 20, 32, 65536, 16384, 64)
ID = "01m4abcdefghjkmnpqrstvwxyz"


def request(**changes: object) -> bytes:
    body: dict[str, object] = {
        "protocol": "codedna-evaluator/1",
        "id": ID,
        "language": "python",
        "entrypoint": "solve",
        "source": "def solve(x):\n    return x\n",
        "cases": [{"id": "v1", "args": [1]}],
    }
    body.update(changes)
    return json.dumps(body).encode()


class RequestValidationTest(unittest.TestCase):
    def test_a_valid_request_is_accepted(self) -> None:
        self.assertEqual("solve", protocol.parse_request(request(), LIMITS)["entrypoint"])

    def test_anything_unexpected_is_rejected(self) -> None:
        bad = {
            "extra command field": request(command="rm -rf /"),
            "image field": json.dumps({**json.loads(request()), "image": "evil"}).encode(),
            "other protocol": request(protocol="codedna-evaluator/2"),
            "unsupported language": request(language="bash"),
            "shell in entrypoint": request(entrypoint="solve; rm -rf /"),
            "path traversal id": request(id="../../etc/passwd"),
            "NUL in source": request(source="def solve(x):\x00 return x"),
            "oversized source": request(source="#" * 20000),
            "no cases": request(cases=[]),
            "duplicate case ids": request(cases=[{"id": "v1", "args": []}, {"id": "v1", "args": []}]),
            "case id traversal": request(cases=[{"id": "../x", "args": []}]),
            "case with expected": request(cases=[{"id": "v1", "args": [], "expected": 1}]),
            "args not a list": request(cases=[{"id": "v1", "args": "x"}]),
            "huge args": request(cases=[{"id": "v1", "args": ["x" * 20000]}]),
            "too many cases": request(cases=[{"id": f"c{i}", "args": []} for i in range(65)]),
            "oversized request": b" " * 140000 + request(),
            "not json": b"{nope",
            "not an object": b"[]",
        }
        for name, raw in bad.items():
            with self.subTest(name), self.assertRaises(protocol.RejectedRequestError):
                protocol.parse_request(raw, LIMITS)


class RunnerOutputTest(unittest.TestCase):
    def test_case_results_follow_the_request_and_ignore_noise(self) -> None:
        records = protocol.parse_records(
            [
                "garbage",
                '{"type": "case", "id": "v2", "ok": true, "value": 2}',
                '{"type": "case", "id": "v1", "ok": false, "error": "ZeroDivisionError"}',
                '{"type": "case", "id": "v2", "ok": true, "value": "forged second"}',
                '{"type": "case", "id": "zz", "ok": true, "value": 9}',
                "[1, 2]",
            ]
        )
        self.assertEqual(
            [
                {"id": "v1", "status": "ERROR", "error": "ZeroDivisionError"},
                {"id": "v2", "status": "OK", "value": 2},
                {"id": "v3", "status": "MISSING"},
            ],
            protocol.case_results(["v1", "v2", "v3"], records),
        )

    def test_oversized_values_are_errors(self) -> None:
        records = [{"type": "case", "id": "v1", "ok": True, "value": "x" * 5000}]
        self.assertEqual("ValueTooLarge", protocol.case_results(["v1"], records)[0]["error"])

    def test_inspection_is_sanitized(self) -> None:
        clean = protocol.sanitize_inspection(
            {
                "syntax_valid": True,
                "functions": [{"name": "f" * 100, "line": -1, "lines": "9", "parameters": 2, "complexity": 3, "nesting": True}],
            }
        )
        self.assertEqual({"name": "f" * 64, "line": 0, "lines": 0, "parameters": 2, "complexity": 3, "nesting": 0}, clean["functions"][0])
        self.assertFalse(protocol.sanitize_inspection(None)["syntax_valid"])


if __name__ == "__main__":
    unittest.main()


class Phase21HardeningTest(unittest.TestCase):
    def test_error_names_are_builtin_or_generic(self) -> None:
        self.assertEqual("ZeroDivisionError", protocol.error_name("ZeroDivisionError"))
        self.assertEqual("MissingEntrypoint", protocol.error_name("MissingEntrypoint"))
        for leaked in ("[{'price_cents': 1999}]", "Hidden_args_1_2_3", "", None, 42):
            with self.subTest(leaked):
                self.assertEqual("Error", protocol.error_name(leaked))

    def test_case_errors_never_carry_submitted_names(self) -> None:
        records = [
            {"type": "case", "id": "h1", "ok": False, "error": "args_are_1_2_3"},
            {"type": "case", "id": "h2", "ok": False, "error": "KeyError"},
        ]
        self.assertEqual(
            [{"id": "h1", "status": "ERROR", "error": "Error"}, {"id": "h2", "status": "ERROR", "error": "KeyError"}],
            protocol.case_results(["h1", "h2"], records),
        )

    def test_deeply_nested_values_are_errors_not_crashes(self) -> None:
        deep: object = 1
        for _ in range(protocol.MAX_VALUE_DEPTH + 1):
            deep = [deep]
        ok: object = 1
        for _ in range(protocol.MAX_VALUE_DEPTH - 1):
            ok = {"k": ok}
        self.assertEqual(
            [{"id": "a", "status": "ERROR", "error": "ValueTooDeep"}, {"id": "b", "status": "OK", "value": ok}],
            protocol.case_results(
                ["a", "b"], [{"type": "case", "id": "a", "ok": True, "value": deep}, {"type": "case", "id": "b", "ok": True, "value": ok}]
            ),
        )
        # Far beyond Python's recursion limit: still a plain error result.
        very: object = 1
        for _ in range(5000):
            very = [very]
        self.assertEqual("ValueTooDeep", protocol.case_results(["a"], [{"type": "case", "id": "a", "ok": True, "value": very}])[0]["error"])
