"""The parser registry: pinned grammars, one adapter per language, honest UNSUPPORTED_PARSER."""

from importlib import metadata
from pathlib import Path

import pytest
import tree_sitter

from app.config import Settings
from app.deadline import Deadline
from app.discovery.discover import discover
from app.discovery.languages import SUPPORTED_LANGUAGES
from app.parsing import parser as parser_module
from app.parsing.grammars import GRAMMARS, RUNTIME, language
from app.parsing.parser import PARSED, UNSUPPORTED_PARSER, parse_files
from app.parsing.specs import SPECS, grammar_for


def test_every_discovered_language_has_an_adapter_and_a_grammar() -> None:
    assert set(SPECS) == SUPPORTED_LANGUAGES
    for name, spec in SPECS.items():
        assert spec.language == name
        assert spec.grammar in GRAMMARS


def test_reported_grammar_versions_are_the_installed_ones() -> None:
    for grammar in GRAMMARS.values():
        assert metadata.version(grammar.package) == grammar.version, grammar.package
    assert f"tree-sitter@{metadata.version('tree-sitter')}" == RUNTIME


@pytest.mark.parametrize("key", sorted(GRAMMARS))
def test_every_grammar_loads_with_a_compatible_abi(key: str) -> None:
    compiled = language(key)
    assert tree_sitter.MIN_COMPATIBLE_LANGUAGE_VERSION <= compiled.abi_version <= tree_sitter.LANGUAGE_VERSION
    assert tree_sitter.Parser(compiled).parse(b"").root_node is not None


def test_grammar_selection_by_extension() -> None:
    assert grammar_for("typescript", ".tsx") == "tsx"
    for extension in (".ts", ".mts", ".cts"):
        assert grammar_for("typescript", extension) == "typescript"
    assert grammar_for("javascript", ".jsx") == "javascript"
    assert grammar_for("c", ".h") == "c"
    assert grammar_for("cobol", ".cbl") is None


def test_a_language_without_a_registered_parser_is_reported_not_faked(
    tmp_path: Path, settings: Settings, monkeypatch: pytest.MonkeyPatch
) -> None:
    (tmp_path / "a.go").write_text("package a\n")
    (tmp_path / "b.py").write_text("x = 1\n")
    monkeypatch.setattr(
        parser_module, "grammar_for", lambda language, extension: None if language == "go" else grammar_for(language, extension)
    )
    found = discover(str(tmp_path), ignored_directories=(), languages=("go", "python"), max_file_bytes=1024, deadline=Deadline(5))
    results = parse_files(str(tmp_path), found.files, settings, Deadline(5))
    assert results["a.go"].status == UNSUPPORTED_PARSER
    assert results["a.go"].parse_dict() == {
        "status": "UNSUPPORTED_PARSER",
        "limit": None,
        "ast_nodes": None,
        "ast_max_depth": None,
        "error_nodes": None,
        "first_error": None,
    }
    assert results["a.go"].structure_dict() is None
    assert results["b.py"].status == PARSED


def test_skipped_files_are_never_parsed(tmp_path: Path, settings: Settings) -> None:
    (tmp_path / "big.py").write_text("x = 1\n" * 400)
    (tmp_path / "bin.py").write_bytes(b"\x00\x01")
    (tmp_path / "notes.txt").write_text("hello")
    found = discover(str(tmp_path), ignored_directories=(), languages=("python",), max_file_bytes=1024, deadline=Deadline(5))
    assert parse_files(str(tmp_path), found.files, settings, Deadline(5)) == {}
