"""Bounded, sanitized identifier text taken from a syntax tree.

The IR may carry declaration names, base type names and import targets
(design rule 2 in docs/architecture/analyzer.md), never code, comments or
literal values. Every string that leaves the parser goes through these
functions: the byte span is bounded *before* it is sliced, it must be valid
UTF-8, whitespace is collapsed, and the result must match a strict pattern.
Anything else becomes ``None``. A name can therefore never carry quotes,
semicolons, braces, parentheses or line breaks, i.e. never a code fragment.
"""

import re

from tree_sitter import Node

MAX_NAME_BYTES = 200
MAX_TARGET_BYTES = 256

# Identifier-like names, including qualified names (A\B, a.b, A::B), generic
# arguments (List<T>, Map[K, V]) and C++ operator/destructor names.
_NAME = re.compile(r"^[\w$#@~.:\\<>,\[\]!=+\-*/%&|^ ]+$")
# Module paths: PHP/Java/C# namespaces, Go/JS/C paths. No whitespace.
_TARGET = re.compile(r"^[\w$@~.:/\\*+\-]+$")
_WHITESPACE = re.compile(r"\s+")


def _slice(source: bytes, node: Node, limit: int) -> str | None:
    if node.end_byte - node.start_byte > limit or node.end_byte <= node.start_byte:
        return None
    try:
        return source[node.start_byte : node.end_byte].decode("utf-8")
    except UnicodeDecodeError:
        return None


def name_text(source: bytes, node: Node | None) -> str | None:
    if node is None:
        return None
    text = _slice(source, node, MAX_NAME_BYTES)
    if text is None:
        return None
    text = _WHITESPACE.sub(" ", text).strip()
    return text if text and _NAME.match(text) else None


def target_text(source: bytes, node: Node | None, *, strip: str = "") -> str | None:
    if node is None:
        return None
    text = _slice(source, node, MAX_TARGET_BYTES)
    if text is None:
        return None
    text = text.strip().strip(strip) if strip else text.strip()
    return text if text and _TARGET.match(text) else None


def keyword_text(source: bytes, node: Node) -> str | None:
    """Text of a short keyword-like node (modifiers, access specifiers)."""
    text = _slice(source, node, 64)
    return None if text is None else _WHITESPACE.sub(" ", text).strip()
