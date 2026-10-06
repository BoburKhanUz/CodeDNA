"""Pinned Tree-sitter grammars, one per analyzer language.

Grammars are compiled C parsers shipped as wheels (requirements.txt pins
and hash-locks every package). They are imported statically: nothing is
loaded by name at run time, and nothing from an uploaded archive can choose
a grammar. The versions below are recorded in every result
(``versions.parsers``); tests/test_parsing_registry.py asserts they match
the installed distributions, so a dependency bump cannot silently change
results without changing the reported versions.

TypeScript has two grammars: ``.tsx`` files use the TSX dialect and every
other TypeScript extension the plain one (JSX syntax is ambiguous with
TypeScript type assertions). JavaScript's grammar includes JSX.
"""

from collections.abc import Callable
from dataclasses import dataclass
from functools import cache

import tree_sitter
import tree_sitter_c
import tree_sitter_c_sharp
import tree_sitter_cpp
import tree_sitter_go
import tree_sitter_java
import tree_sitter_javascript
import tree_sitter_php
import tree_sitter_python
import tree_sitter_rust
import tree_sitter_typescript

# The Python binding of the Tree-sitter runtime. 0.25.x is the newest line
# that can bound a parse in time (Parser.timeout_micros); see
# docs/architecture/analyzer.md "Parser resource limits".
RUNTIME = "tree-sitter@0.25.2"


@dataclass(frozen=True)
class Grammar:
    key: str
    package: str
    version: str
    loader: Callable[[], object]

    @property
    def label(self) -> str:
        return f"{self.package}@{self.version}"


GRAMMARS: dict[str, Grammar] = {
    grammar.key: grammar
    for grammar in (
        Grammar("c", "tree-sitter-c", "0.24.2", tree_sitter_c.language),
        Grammar("cpp", "tree-sitter-cpp", "0.23.4", tree_sitter_cpp.language),
        Grammar("csharp", "tree-sitter-c-sharp", "0.23.5", tree_sitter_c_sharp.language),
        Grammar("go", "tree-sitter-go", "0.25.0", tree_sitter_go.language),
        Grammar("java", "tree-sitter-java", "0.23.5", tree_sitter_java.language),
        Grammar("javascript", "tree-sitter-javascript", "0.25.0", tree_sitter_javascript.language),
        Grammar("php", "tree-sitter-php", "0.24.1", tree_sitter_php.language_php),
        Grammar("python", "tree-sitter-python", "0.25.0", tree_sitter_python.language),
        Grammar("rust", "tree-sitter-rust", "0.24.2", tree_sitter_rust.language),
        Grammar("typescript", "tree-sitter-typescript", "0.23.2", tree_sitter_typescript.language_typescript),
        Grammar("tsx", "tree-sitter-typescript", "0.23.2", tree_sitter_typescript.language_tsx),
    )
}


@cache
def language(key: str) -> tree_sitter.Language:
    """The compiled grammar for a key in GRAMMARS. Language objects are immutable and shared."""
    return tree_sitter.Language(GRAMMARS[key].loader())
