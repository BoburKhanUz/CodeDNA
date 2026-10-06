"""Per-language fixtures: IR 1.1 structure for valid files, PARSE_ERROR for broken ones.

Every expected value below was derived by hand from the fixture source
(tests/fixtures/parsing) and the rules in app.parsing.specs, not copied
from the implementation's output.
"""

import shutil
from pathlib import Path
from typing import Any

import pytest

from app.config import Settings
from app.deadline import Deadline
from app.discovery.discover import discover
from app.discovery.languages import SUPPORTED_LANGUAGES
from app.parsing.parser import PARSE_ERROR, PARSED, ParsedFile, parse_files

FIXTURES = Path(__file__).parent / "fixtures" / "parsing"


def parse_fixture(tmp_path: Path, name: str, settings: Settings) -> ParsedFile:
    shutil.copy(FIXTURES / name, tmp_path / name)
    found = discover(
        str(tmp_path),
        ignored_directories=settings.ignored_directories,
        languages=tuple(sorted(SUPPORTED_LANGUAGES)),
        max_file_bytes=settings.max_file_bytes,
        deadline=Deadline(30),
    )
    return parse_files(str(tmp_path), found.files, settings, Deadline(30))[name]


def summary(result: ParsedFile) -> dict[str, Any]:
    structure = result.structure_dict()
    assert structure is not None
    return {
        "lines": structure["lines"],
        "imports": [item["target"] for item in structure["imports"]],
        "types": [(t["kind"], t["name"], t["visibility"], t["bases"], t["methods"]) for t in structure["types"]],
        "functions": [
            (f["kind"], f["name"], f["visibility"], f["parameters"], f["complexity"], f["max_nesting"]) for f in structure["functions"]
        ],
    }


EXPECTED: dict[str, dict[str, Any]] = {
    "valid.py": {
        "lines": {"total": 18, "code": 13, "comment": 1, "blank": 4},
        "imports": ["os", "collections"],
        "types": [("class", "Account", None, ["Base"], 1)],
        "functions": [("method", "deposit", None, 2, 5, 2), ("function", "helper", None, 3, 4, 0)],
    },
    "valid.php": {
        "lines": {"total": 30, "code": 22, "comment": 4, "blank": 4},
        "imports": ["App\\Models\\Invoice"],
        "types": [("class", "InvoiceService", None, ["Service", "Billable"], 2)],
        "functions": [
            ("method", "total", "public", 2, 6, 2),
            ("method", "check", "private", 1, 1, 0),
            ("function", "helper", None, 1, 3, 1),
        ],
    },
    "valid.js": {
        "lines": {"total": 26, "code": 19, "comment": 3, "blank": 4},
        "imports": ["node:fs", "./helper.js"],
        "types": [("class", "Cart", None, ["Base"], 1)],
        "functions": [
            ("method", "add", None, 2, 6, 3),
            ("anonymous", None, None, 1, 1, 0),
            ("anonymous", None, None, 2, 1, 0),
            ("function", "legacy", None, 3, 3, 1),
        ],
    },
    "valid.ts": {
        "lines": {"total": 28, "code": 21, "comment": 1, "blank": 6},
        "imports": ["@angular/core"],
        "types": [
            ("interface", "Repository", None, ["Base<T>"], 0),
            ("class", "UserService", None, ["Repository<User>"], 3),
            ("enum", "Role", None, [], 0),
        ],
        "functions": [
            ("method", "constructor", None, 1, 1, 0),
            ("method", "find", "public", 1, 5, 1),
            ("method", "load", "protected", 1, 2, 0),
        ],
    },
    "valid.tsx": {
        "lines": {"total": 7, "code": 5, "comment": 0, "blank": 2},
        "imports": ["react"],
        "types": [],
        "functions": [("function", "Header", None, 1, 2, 0)],
    },
    "valid.go": {
        "lines": {"total": 32, "code": 25, "comment": 2, "blank": 5},
        "imports": ["errors", "strings"],
        "types": [("struct", "Invoice", "public", None, None), ("interface", "totaler", "private", None, None)],
        "functions": [("method", "Total", "public", 2, 5, 2), ("function", "normalize", "private", 2, 1, 0)],
    },
    "Valid.java": {
        "lines": {"total": 27, "code": 21, "comment": 1, "blank": 5},
        "imports": ["java.util.List", "java.util.*"],
        "types": [
            ("class", "OrderService", "public", ["BaseService", "Auditable", "Closeable"], 2),
            ("class", "Inner", None, [], 0),
        ],
        "functions": [("method", "OrderService", "public", 1, 1, 0), ("method", "count", "protected", 2, 5, 2)],
    },
    "Valid.cs": {
        "lines": {"total": 28, "code": 23, "comment": 1, "blank": 4},
        "imports": ["System", "System.Collections.Generic"],
        "types": [("class", "Ledger", "public", ["BaseLedger", "IDisposable"], 3)],
        "functions": [
            ("method", "Add", "public", 2, 4, 1),
            ("method", "Label", "internal", 1, 3, 1),
            ("method", "Dispose", "public", 0, 1, 0),
        ],
    },
    "valid.rs": {
        "lines": {"total": 30, "code": 24, "comment": 2, "blank": 4},
        "imports": ["std::collections::HashMap", "crate::model"],
        "types": [("struct", "Cart", "public", None, None), ("trait", "Priced", "public", None, None)],
        "functions": [
            ("method", "add", "public", 2, 5, 1),
            ("function", "helper", None, 1, 1, 0),
            ("anonymous", None, None, 1, 1, 0),
        ],
    },
    "valid.c": {
        "lines": {"total": 22, "code": 17, "comment": 2, "blank": 3},
        "imports": ["stdio.h", "ledger.h"],
        "types": [("struct", "ledger", None, None, 0)],
        "functions": [("function", "add", None, 2, 5, 2), ("function", "noop", None, 0, 1, 0)],
    },
    "valid.cpp": {
        "lines": {"total": 24, "code": 21, "comment": 1, "blank": 2},
        "imports": ["vector", "shape.hpp"],
        "types": [("class", "Circle", None, ["Shape"], 3)],
        "functions": [
            ("method", "Circle", "public", 1, 1, 0),
            ("method", "area", "public", 0, 2, 0),
            ("method", "reset", "private", 0, 1, 0),
            ("function", "clamp", None, 3, 3, 1),
        ],
    },
}

ERRORS = ["error.py", "error.php", "error.js", "error.ts", "error.go", "Error.java", "Error.cs", "error.rs", "error.c", "error.cpp"]


@pytest.mark.parametrize("name", sorted(EXPECTED))
def test_valid_fixtures_produce_the_expected_structure(tmp_path: Path, settings: Settings, name: str) -> None:
    result = parse_fixture(tmp_path, name, settings)
    assert result.status == PARSED
    assert result.error_nodes == 0 and result.first_error is None
    assert result.ast_nodes is not None and result.ast_nodes > 0
    assert result.ast_max_depth is not None and result.ast_max_depth > 1
    assert summary(result) == EXPECTED[name]


def test_every_language_has_a_valid_and_a_broken_fixture() -> None:
    from app.discovery.languages import language_of

    assert {language_of(name) for name in EXPECTED} == SUPPORTED_LANGUAGES
    assert {language_of(name) for name in ERRORS} == SUPPORTED_LANGUAGES


@pytest.mark.parametrize("name", ERRORS)
def test_syntax_errors_are_reported_and_not_measured(tmp_path: Path, settings: Settings, name: str) -> None:
    result = parse_fixture(tmp_path, name, settings)
    assert result.status == PARSE_ERROR
    assert result.error_nodes is not None and result.error_nodes >= 1
    assert result.first_error is not None and result.first_error[0] >= 1
    assert result.structure_dict() is None
    assert result.parse_dict()["status"] == "PARSE_ERROR"


def test_the_complexity_of_a_nested_function_belongs_to_that_function(tmp_path: Path, settings: Settings) -> None:
    (tmp_path / "n.py").write_text(
        "def outer(a):\n    def inner(b):\n        if b:\n            return 1\n        return 0\n    return inner\n"
    )
    found = discover(str(tmp_path), ignored_directories=(), languages=("python",), max_file_bytes=1024, deadline=Deadline(5))
    result = parse_files(str(tmp_path), found.files, settings, Deadline(5))["n.py"]
    functions = summary(result)["functions"]
    assert functions == [("function", "outer", None, 1, 1, 0), ("function", "inner", None, 1, 2, 1)]


def test_else_if_chains_do_not_increase_nesting(tmp_path: Path, settings: Settings) -> None:
    source = "function f(a) {\n  if (a) {} else if (b) {} else if (c) {} else if (d) { if (e) {} }\n}\n"
    (tmp_path / "chain.js").write_text(source)
    found = discover(str(tmp_path), ignored_directories=(), languages=("javascript",), max_file_bytes=1024, deadline=Deadline(5))
    result = parse_files(str(tmp_path), found.files, settings, Deadline(5))["chain.js"]
    # 1 + four ifs + the inner if; nesting: the chain is level 1, the inner if level 2.
    assert summary(result)["functions"] == [("function", "f", None, 1, 6, 2)]


def test_typescript_and_tsx_use_their_own_dialects(tmp_path: Path, settings: Settings) -> None:
    # A type assertion is valid TypeScript but a syntax error in TSX, and JSX the other way round.
    (tmp_path / "cast.ts").write_text("const x = <number>y;\n")
    (tmp_path / "cast.tsx").write_text("const x = <number>y;\n")
    (tmp_path / "view.tsx").write_text("const v = <div>{x}</div>;\n")
    found = discover(str(tmp_path), ignored_directories=(), languages=("typescript",), max_file_bytes=1024, deadline=Deadline(5))
    results = parse_files(str(tmp_path), found.files, settings, Deadline(5))
    assert {path: result.status for path, result in results.items()} == {
        "cast.ts": PARSED,
        "cast.tsx": PARSE_ERROR,
        "view.tsx": PARSED,
    }


def parse_written(root: Path, name: str, settings: Settings) -> ParsedFile:
    languages = tuple(sorted(SUPPORTED_LANGUAGES))
    found = discover(str(root), ignored_directories=(), languages=languages, max_file_bytes=4096, deadline=Deadline(5))
    return parse_files(str(root), found.files, settings, Deadline(5))[name]


ELSE_IF_CHAINS = {
    "chain.go": "package p\nfunc f(a, b, c bool) {\n\tif a {\n\t} else if b {\n\t} else if c {\n\t\tif a {\n\t\t}\n\t}\n}\n",
    "Chain.java": "class C { void f(boolean a, boolean b, boolean c) { if (a) {} else if (b) {} else if (c) { if (a) {} } } }\n",
    "Chain.cs": "class C { void F(bool a, bool b, bool c) { if (a) {} else if (b) {} else if (c) { if (a) {} } } }\n",
    "chain.php": "<?php\nfunction f($a, $b, $c) { if ($a) {} elseif ($b) {} else if ($c) { if ($a) {} } }\n",
    "chain.c": "void f(int a, int b, int c) { if (a) {} else if (b) {} else if (c) { if (a) {} } }\n",
    "chain.rs": "fn f(a: bool, b: bool, c: bool) { if a {} else if b {} else if c { if a {} } }\n",
    "chain.py": "def f(a, b, c):\n    if a:\n        pass\n    elif b:\n        pass\n    elif c:\n        if a:\n            pass\n",
}


@pytest.mark.parametrize("name", sorted(ELSE_IF_CHAINS))
def test_else_if_chains_count_as_decisions_but_not_as_nesting(tmp_path: Path, settings: Settings, name: str) -> None:
    (tmp_path / name).write_text(ELSE_IF_CHAINS[name])
    result = parse_written(tmp_path, name, settings)
    assert result.status == PARSED
    (function,) = summary(result)["functions"]
    # Four conditions (if, else if, else if, inner if); the chain is one level, the inner if a second.
    assert function[4:] == (5, 2)


LINE_LAYOUTS = {
    "l.py": "# comment\n\nx = 1\n",
    "l.js": "// comment\n\nlet x = 1;\n",
    "l.ts": "/* comment */\n\nlet x = 1;\n",
    "l.php": "<?php\n// comment\n\n$x = 1;\n",
    "l.go": "// comment\n\npackage p\n",
    "L.java": "// comment\n\nclass L {}\n",
    "L.cs": "// comment\n\nclass L {}\n",
    "l.rs": "// comment\n\n/// doc\n\nfn f() {}\n",
    "l.c": "#include <a.h>\n\n// comment\n\nint x;\n",
    "l.cpp": "#include <a.h>\n\n/* comment\n   more */\n\nint x;\n",
}
LINE_COUNTS = {
    "l.py": (3, 1, 1, 1),
    "l.js": (3, 1, 1, 1),
    "l.ts": (3, 1, 1, 1),
    "l.php": (4, 2, 1, 1),
    "l.go": (3, 1, 1, 1),
    "L.java": (3, 1, 1, 1),
    "L.cs": (3, 1, 1, 1),
    "l.rs": (5, 1, 2, 2),
    "l.c": (5, 2, 1, 2),
    "l.cpp": (6, 2, 2, 2),
}


@pytest.mark.parametrize("name", sorted(LINE_LAYOUTS))
def test_line_classification_does_not_spill_onto_the_next_line(tmp_path: Path, settings: Settings, name: str) -> None:
    (tmp_path / name).write_text(LINE_LAYOUTS[name])
    result = parse_written(tmp_path, name, settings)
    lines = summary(result)["lines"]
    assert (lines["total"], lines["code"], lines["comment"], lines["blank"]) == LINE_COUNTS[name]


SINGLE_PARAMETER_LAMBDAS = {
    "s.js": "const f = x => x;\n",
    "S.java": "class S { Object f = (java.util.function.Function<Integer, Integer>) x -> x; }\n",
    "S.cs": "class S { System.Func<int, int> f = q => q; }\n",
    "s.ts": "const f = (x: number) => x;\n",
}


@pytest.mark.parametrize("name", sorted(SINGLE_PARAMETER_LAMBDAS))
def test_a_lambda_with_one_bare_parameter_has_one_parameter(tmp_path: Path, settings: Settings, name: str) -> None:
    (tmp_path / name).write_text(SINGLE_PARAMETER_LAMBDAS[name])
    result = parse_written(tmp_path, name, settings)
    assert [(f[0], f[3]) for f in summary(result)["functions"]] == [("anonymous", 1)]
