"""Extension-based language detection (foundation, Phase 08).

Deterministic and explicit: a file's language comes only from its final
extension, lowercased, through this table. No content sniffing, no
shebangs, no guessing; anything not listed has language ``None``. ``.h`` is
mapped to C (it is shared by C and C++, and only parsing could tell them
apart). Identifiers match the backend's ProgrammingLanguage enum.
"""

EXTENSION_LANGUAGES: dict[str, str] = {
    ".php": "php",
    ".py": "python",
    ".js": "javascript",
    ".mjs": "javascript",
    ".cjs": "javascript",
    ".jsx": "javascript",
    ".ts": "typescript",
    ".tsx": "typescript",
    ".mts": "typescript",
    ".cts": "typescript",
    ".go": "go",
    ".java": "java",
    ".cs": "csharp",
    ".rs": "rust",
    ".c": "c",
    ".h": "c",
    ".cpp": "cpp",
    ".cc": "cpp",
    ".cxx": "cpp",
    ".hpp": "cpp",
    ".hh": "cpp",
    ".hxx": "cpp",
}

SUPPORTED_LANGUAGES: frozenset[str] = frozenset(EXTENSION_LANGUAGES.values())


def extension_of(path: str) -> str | None:
    """The lowercased final extension including the dot, or None.

    "src/App.PHP" -> ".php"; "Makefile" -> None; ".env" -> None (a dotfile
    has no extension); "a.d.ts" -> ".ts".
    """
    name = path.rsplit("/", 1)[-1]
    dot = name.rfind(".")
    if dot <= 0 or dot == len(name) - 1:
        return None
    return name[dot:].lower()


def language_of(path: str) -> str | None:
    extension = extension_of(path)
    return None if extension is None else EXTENSION_LANGUAGES.get(extension)
