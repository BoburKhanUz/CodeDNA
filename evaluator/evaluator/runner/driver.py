"""Sandbox-side runner (docs/architecture/challenge-evaluator.md#runner).

Runs as an unprivileged slot user inside the evaluator container, with no
network, resource limits and a private temporary directory. It never sees
expected outputs: it receives source, an entry point and test *inputs* on
stdin, and writes one JSON line per result to stdout.

Modes:

- ``inspect``: parse the source with ``ast`` (never executes it) and report
  structural metrics;
- ``run``: execute the source as a module, call the entry point once per
  case and report each returned value or the exception type.

Only the standard library is used. The supervisor treats everything this
process writes as untrusted: the submission runs in the same interpreter
and can write anything to stdout.
"""

from __future__ import annotations

import ast
import builtins
import copy
import json
import os
import sys
from typing import Any

MAX_REPORTED = 200
MAX_VALUE_BYTES = 4096
DECISIONS = (ast.If, ast.For, ast.AsyncFor, ast.While, ast.IfExp, ast.ExceptHandler, ast.Assert, ast.match_case)
BLOCKS = (ast.If, ast.For, ast.AsyncFor, ast.While, ast.Try, ast.TryStar, ast.With, ast.AsyncWith, ast.Match)
FUNCTIONS = (ast.FunctionDef, ast.AsyncFunctionDef)


def complexity(node: ast.AST) -> int:
    """1 + decision points of one function, not descending into nested functions or classes."""
    total = 1
    for child in iter_own_nodes(node):
        if isinstance(child, DECISIONS):
            total += 1
        elif isinstance(child, ast.BoolOp):
            total += len(child.values) - 1
        elif isinstance(child, ast.comprehension):
            total += 1 + len(child.ifs)
    return total


def iter_own_nodes(node: ast.AST) -> list[ast.AST]:
    found: list[ast.AST] = []
    stack = list(ast.iter_child_nodes(node))
    while stack:
        child = stack.pop()
        if isinstance(child, (*FUNCTIONS, ast.ClassDef, ast.Lambda)):
            continue
        found.append(child)
        stack.extend(ast.iter_child_nodes(child))
    return found


def nesting(node: ast.AST) -> int:
    """Deepest nesting of control-flow blocks inside one function."""
    deepest = 0
    stack: list[tuple[ast.AST, int]] = [(child, 0) for child in ast.iter_child_nodes(node)]
    while stack:
        child, depth = stack.pop()
        if isinstance(child, (*FUNCTIONS, ast.ClassDef, ast.Lambda)):
            continue
        if isinstance(child, BLOCKS):
            depth += 1
            deepest = max(deepest, depth)
        stack.extend((grandchild, depth) for grandchild in ast.iter_child_nodes(child))
    return deepest


def parameters(node: ast.FunctionDef | ast.AsyncFunctionDef, in_class: bool) -> int:
    args = node.args
    names = [a.arg for a in [*args.posonlyargs, *args.args, *args.kwonlyargs]]
    if in_class and names and names[0] in ("self", "cls"):
        names = names[1:]
    return len(names) + (args.vararg is not None) + (args.kwarg is not None)


def span(node: ast.AST) -> int:
    start = getattr(node, "lineno", 0)
    end = getattr(node, "end_lineno", start) or start
    return end - start + 1


def inspect_source(source: str) -> dict[str, Any]:
    try:
        tree = ast.parse(source, filename="submission.py")
    except (SyntaxError, ValueError, RecursionError, MemoryError) as error:
        line = getattr(error, "lineno", None)
        return {"syntax_valid": False, "syntax_error_line": line if isinstance(line, int) else None, "functions": [], "classes": []}

    functions: list[dict[str, Any]] = []
    classes: list[dict[str, Any]] = []
    stack: list[tuple[ast.AST, bool]] = [(tree, False)]
    while stack:
        node, in_class = stack.pop()
        for child in ast.iter_child_nodes(node):
            if isinstance(child, FUNCTIONS):
                functions.append(
                    {
                        "name": child.name[:64],
                        "line": child.lineno,
                        "lines": span(child),
                        "parameters": parameters(child, in_class),
                        "complexity": complexity(child),
                        "nesting": nesting(child),
                    }
                )
                stack.append((child, False))
            elif isinstance(child, ast.ClassDef):
                methods = sum(isinstance(item, FUNCTIONS) for item in child.body)
                classes.append({"name": child.name[:64], "line": child.lineno, "lines": span(child), "methods": methods})
                stack.append((child, True))
            else:
                stack.append((child, in_class))
    functions.sort(key=lambda f: (f["line"], f["name"]))
    classes.sort(key=lambda c: (c["line"], c["name"]))
    return {"syntax_valid": True, "syntax_error_line": None, "functions": functions[:MAX_REPORTED], "classes": classes[:MAX_REPORTED]}


def encode_value(value: Any) -> tuple[bool, Any]:
    """The value as JSON data, or False when it is not plain JSON or too large."""
    try:
        text = json.dumps(value, sort_keys=True, allow_nan=False)
    except (TypeError, ValueError, RecursionError):
        return False, None
    if len(text.encode()) > MAX_VALUE_BYTES:
        return False, None
    return True, json.loads(text)


def run_cases(source: str, entrypoint: str, cases: list[dict[str, Any]], emit: Any) -> None:
    module: dict[str, Any] = {"__name__": "submission", "__builtins__": builtins}
    try:
        code = compile(source, "submission.py", "exec")
        exec(code, module)  # noqa: S102 - the whole point, inside the sandbox
    except BaseException as error:  # noqa: BLE001 - any failure is a result
        emit({"type": "load", "ok": False, "error": type(error).__name__[:64]})
        return
    function = module.get(entrypoint)
    if not callable(function):
        emit({"type": "load", "ok": False, "error": "MissingEntrypoint"})
        return
    emit({"type": "load", "ok": True})
    for case in cases:
        args = copy.deepcopy(case.get("args", []))
        try:
            value = function(*args)
        except BaseException as error:  # noqa: BLE001 - reported per case
            emit({"type": "case", "id": case["id"], "ok": False, "error": type(error).__name__[:64]})
            continue
        ok, encoded = encode_value(value)
        if ok:
            emit({"type": "case", "id": case["id"], "ok": True, "value": encoded})
        else:
            emit({"type": "case", "id": case["id"], "ok": False, "error": "UnserializableResult"})


def main() -> None:
    job = json.loads(sys.stdin.read())
    out = os.fdopen(os.dup(1), "w", encoding="utf-8")
    # Anything the submission prints goes nowhere useful.
    sys.stdout = open(os.devnull, "w", encoding="utf-8")  # noqa: SIM115

    def emit(record: dict[str, Any]) -> None:
        out.write(json.dumps(record, sort_keys=True) + "\n")
        out.flush()

    source = job["source"]
    if job["mode"] == "inspect":
        emit({"type": "inspection", **inspect_source(source)})
    else:
        run_cases(source, job["entrypoint"], job["cases"], emit)
    emit({"type": "done"})


if __name__ == "__main__":
    main()
