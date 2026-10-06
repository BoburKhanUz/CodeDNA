import os

from app.config import DEFAULT_IGNORED_DIRECTORIES
from app.deadline import Deadline
from app.discovery.discover import discover
from app.discovery.languages import SUPPORTED_LANGUAGES, extension_of, language_of

SECRET_LINE = "API_TOKEN = 'do-not-leak-this-value'"


def tree(root, files: dict[str, bytes]) -> None:  # type: ignore[no-untyped-def]
    for path, content in files.items():
        full = os.path.join(str(root), *path.split("/"))
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "wb") as handle:
            handle.write(content)


ALL_LANGUAGES = tuple(sorted(SUPPORTED_LANGUAGES))


def run(root, languages=ALL_LANGUAGES, max_file_bytes=1_000_000):  # type: ignore[no-untyped-def]
    return discover(
        str(root),
        ignored_directories=DEFAULT_IGNORED_DIRECTORIES,
        languages=languages,
        max_file_bytes=max_file_bytes,
        deadline=Deadline(30),
    )


def test_language_detection_is_extension_based_and_explicit() -> None:
    assert [
        language_of(p) for p in ["a.php", "b.PY", "c.tsx", "d.d.ts", "e.cs", "f.rs", "g.h", "h.hpp", "i.go", "j.java", "k.mjs", "l.c"]
    ] == [
        "php",
        "python",
        "typescript",
        "typescript",
        "csharp",
        "rust",
        "c",
        "cpp",
        "go",
        "java",
        "javascript",
        "c",
    ]
    assert [language_of(p) for p in ["README.md", "Makefile", ".env", "script", "x.", "noext/", "a.rb"]] == [None] * 7
    assert extension_of("src/App.PHP") == ".php" and extension_of(".gitignore") is None
    assert {"php", "python", "javascript", "typescript", "go", "java", "csharp", "rust", "c", "cpp"} == SUPPORTED_LANGUAGES


def test_discovery_is_deterministic_and_metadata_only(tmp_path) -> None:  # type: ignore[no-untyped-def]
    tree(
        tmp_path,
        {
            "src/b.py": f"{SECRET_LINE}\nprint(1)\n".encode(),
            "src/a.php": b"<?php\necho 1;",
            "README.md": b"# readme\n",
            "assets/logo.ts": b"\x00\x01binary",
            "src/Big.java": b"x" * 2000,
        },
    )

    first = run(tmp_path, max_file_bytes=1000)
    second = run(tmp_path, max_file_bytes=1000)

    assert first == second
    assert [record.to_dict() for record in first.files] == [
        {"path": "README.md", "extension": ".md", "language": None, "size_bytes": 9, "lines": None, "skip_reason": "unsupported_language"},
        {"path": "assets/logo.ts", "extension": ".ts", "language": "typescript", "size_bytes": 8, "lines": None, "skip_reason": "binary"},
        {"path": "src/Big.java", "extension": ".java", "language": "java", "size_bytes": 2000, "lines": None, "skip_reason": "too_large"},
        {"path": "src/a.php", "extension": ".php", "language": "php", "size_bytes": 13, "lines": 2, "skip_reason": None},
        {"path": "src/b.py", "extension": ".py", "language": "python", "size_bytes": 46, "lines": 2, "skip_reason": None},
    ]
    assert "do-not-leak" not in repr(first)


def test_ignored_directories_are_excluded_but_counted(tmp_path) -> None:  # type: ignore[no-untyped-def]
    tree(
        tmp_path,
        {
            "app.js": b"1",
            "node_modules/lib/index.js": b"12",
            "vendor/x/y.php": b"123",
            "packages/web/.next/cache.js": b"1234",
            ".git/HEAD": b"ref",
            "coverage/lcov.info": b"x",
        },
    )

    found = run(tmp_path)

    assert [record.path for record in found.files] == ["app.js"]
    assert (found.ignored_files, found.ignored_bytes) == (5, 2 + 3 + 4 + 3 + 1)


def test_requested_languages_limit_what_is_analyzable(tmp_path) -> None:  # type: ignore[no-untyped-def]
    tree(tmp_path, {"a.py": b"x", "b.php": b"y"})
    found = run(tmp_path, languages=("php",))
    assert [(r.path, r.skip_reason) for r in found.files] == [("a.py", "unsupported_language"), ("b.php", None)]
