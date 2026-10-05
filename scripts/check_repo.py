#!/usr/bin/env python3
"""Repository foundation checks for CodeDNA.

Checks (standard library only, deterministic output):
  1. Required foundation files exist.
  2. Relative Markdown links and #anchors resolve.
  3. .env.example contains no values for secret-like variables.
  4. No real .env files are tracked by git.

Usage: python3 scripts/check_repo.py [--docs-only]
Exit code 0 on success, 1 on any failure.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

REQUIRED_DOCS = [
    "docs/product/vision.md",
    "docs/product/mvp.md",
    "docs/architecture/overview.md",
    "docs/architecture/backend.md",
    "docs/architecture/analyzer.md",
    "docs/architecture/data-flow.md",
    "docs/api/README.md",
    "docs/api/internal-analyzer-contract.md",
    "docs/decisions/ADR-001-stack.md",
    "docs/decisions/ADR-002-analysis-engine.md",
    "docs/decisions/ADR-003-storage.md",
    "docs/decisions/ADR-004-dna-scoring.md",
    "docs/decisions/ADR-005-service-communication.md",
    "docs/decisions/ADR-006-authentication.md",
]

REQUIRED_FOUNDATION = [
    "README.md",
    ".gitignore",
    ".editorconfig",
    ".env.example",
    "Makefile",
    "docker-compose.yml",
    "backend/README.md",
    "frontend/README.md",
    "analyzer/README.md",
    "docker/README.md",
    "packages/README.md",
    "scripts/README.md",
    ".github/workflows/ci.yml",
]

# Variable names that must never carry a value in .env.example.
SECRET_NAME = re.compile(r"(PASSWORD|SECRET|TOKEN|PRIVATE|_KEY$|_KEY_ID$|DSN$|APP_KEY$)")

LINK = re.compile(r"(?<!!)\[[^\]]*\]\(([^)\s]+)\)")
HEADING = re.compile(r"^(#{1,6})\s+(.*?)\s*#*\s*$")
FENCE = re.compile(r"^\s*(```|~~~)")


def github_slug(text: str) -> str:
    """Approximate GitHub's heading anchor algorithm."""
    text = re.sub(r"`([^`]*)`", r"\1", text)
    text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", text)
    text = text.strip().lower()
    text = re.sub(r"[^\w\- ]", "", text)
    return text.replace(" ", "-")


def markdown_lines_outside_fences(path: Path) -> list[tuple[int, str]]:
    lines: list[tuple[int, str]] = []
    in_fence = False
    for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        if FENCE.match(line):
            in_fence = not in_fence
            continue
        if not in_fence:
            lines.append((number, line))
    return lines


def anchors_of(path: Path) -> set[str]:
    anchors: set[str] = set()
    seen: dict[str, int] = {}
    for _, line in markdown_lines_outside_fences(path):
        match = HEADING.match(line)
        if not match:
            continue
        slug = github_slug(match.group(2))
        count = seen.get(slug, 0)
        anchors.add(slug if count == 0 else f"{slug}-{count}")
        seen[slug] = count + 1
    return anchors


def tracked_files() -> list[str]:
    result = subprocess.run(
        ["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
        cwd=ROOT,
        capture_output=True,
        check=True,
    )
    return sorted(p for p in result.stdout.decode("utf-8").split("\0") if p)


def check_required(paths: list[str]) -> list[str]:
    return [f"missing required file: {p}" for p in paths if not (ROOT / p).is_file()]


def check_links(markdown_files: list[str]) -> list[str]:
    errors: list[str] = []
    for rel in markdown_files:
        source = ROOT / rel
        for number, line in markdown_lines_outside_fences(source):
            for target in LINK.findall(line):
                if re.match(r"^[a-z][a-z0-9+.-]*:", target):  # http:, https:, mailto:
                    continue
                file_part, _, anchor = target.partition("#")
                resolved = (source.parent / file_part).resolve() if file_part else source
                if not resolved.exists():
                    errors.append(f"{rel}:{number}: broken link -> {target}")
                    continue
                if anchor and resolved.suffix == ".md" and anchor not in anchors_of(resolved):
                    errors.append(f"{rel}:{number}: missing anchor -> {target}")
    return errors


def check_env_example() -> list[str]:
    path = ROOT / ".env.example"
    if not path.is_file():
        return []
    errors: list[str] = []
    for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        name, _, value = stripped.partition("=")
        if SECRET_NAME.search(name.strip()) and value.strip():
            errors.append(f".env.example:{number}: secret-like variable {name.strip()} must be empty")
    return errors


def check_no_env_files(files: list[str]) -> list[str]:
    pattern = re.compile(r"(^|/)\.env(\.[^/]+)?$")
    return [
        f"real environment file must not be committed: {p}"
        for p in files
        if pattern.search(p) and not p.endswith(".env.example")
    ]


def main() -> int:
    docs_only = "--docs-only" in sys.argv[1:]
    files = tracked_files()
    markdown = [p for p in files if p.endswith(".md")]

    errors = check_required(REQUIRED_DOCS)
    errors += check_links(markdown)
    if not docs_only:
        errors += check_required(REQUIRED_FOUNDATION)
        errors += check_env_example()
        errors += check_no_env_files(files)

    for error in errors:
        print(f"ERROR {error}")
    print(f"checked {len(markdown)} markdown files; {len(errors)} problem(s)")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
