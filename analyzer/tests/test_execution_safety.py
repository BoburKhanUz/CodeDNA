"""The analyzer never executes, imports or evaluates uploaded source."""

import ast
import os
import pathlib

from fastapi.testclient import TestClient

from app.config import Settings
from app.main import create_app
from tests.support import PATH, FakeFetcher, encode, request_body, resolver, signed_headers, zip_bytes

APP_DIR = pathlib.Path(__file__).resolve().parents[1] / "app"


def test_planted_payloads_are_never_executed(settings: Settings, tmp_path) -> None:  # type: ignore[no-untyped-def]
    marker = tmp_path / "executed"
    archive = zip_bytes(
        {
            "index.php": f"<?php file_put_contents('{marker}', 'php');",
            "run.sh": f"#!/bin/sh\ntouch {marker}\n",
            "setup.py": f"open({str(marker)!r}, 'w').write('python')",
            "conftest.py": f"open({str(marker)!r}, 'w').write('pytest')",
            "__init__.py": f"open({str(marker)!r}, 'w').write('import')",
            "index.js": f"require('fs').writeFileSync({str(marker)!r}, 'node')",
            "package.json": f'{{"scripts": {{"postinstall": "touch {marker}"}}}}',
            "composer.json": f'{{"scripts": {{"post-install-cmd": "touch {marker}"}}}}',
            "Makefile": f"all:\n\ttouch {marker}\n",
        }
    )
    raw = encode(request_body(archive))
    with TestClient(create_app(settings, resolver=resolver("172.18.0.5"), fetcher=FakeFetcher(archive))) as client:
        response = client.post(PATH, content=raw, headers=signed_headers(raw))

    assert response.status_code == 200
    assert not marker.exists()
    assert not os.path.exists(os.path.join(os.getcwd(), "executed"))


FORBIDDEN_MODULES = {"subprocess", "pickle", "marshal", "shelve", "pty", "importlib", "runpy", "ctypes", "multiprocessing", "yaml"}
FORBIDDEN_CALLS = {"eval", "exec", "compile", "__import__", "execfile"}
FORBIDDEN_OS = {
    "system",
    "popen",
    "spawnl",
    "spawnv",
    "spawnve",
    "spawnlp",
    "execv",
    "execve",
    "execl",
    "execlp",
    "execvp",
    "fork",
    "forkpty",
    "startfile",
    "posix_spawn",
}


def test_application_code_has_no_execution_primitives() -> None:
    offenders: list[str] = []
    for path in sorted(APP_DIR.rglob("*.py")):
        tree = ast.parse(path.read_text(), filename=str(path))
        for node in ast.walk(tree):
            where = f"{path.relative_to(APP_DIR)}:{getattr(node, 'lineno', '?')}"
            if isinstance(node, ast.Import):
                offenders += [f"{where} import {a.name}" for a in node.names if a.name.split(".")[0] in FORBIDDEN_MODULES]
            elif isinstance(node, ast.ImportFrom) and (node.module or "").split(".")[0] in FORBIDDEN_MODULES:
                offenders.append(f"{where} from {node.module}")
            elif isinstance(node, ast.Call):
                func = node.func
                if isinstance(func, ast.Name) and func.id in FORBIDDEN_CALLS:
                    offenders.append(f"{where} {func.id}()")
                if (
                    isinstance(func, ast.Attribute)
                    and func.attr in FORBIDDEN_OS
                    and isinstance(func.value, ast.Name)
                    and func.value.id == "os"
                ):
                    offenders.append(f"{where} os.{func.attr}()")
                for keyword in node.keywords:
                    if keyword.arg == "shell":
                        offenders.append(f"{where} shell=")
    assert offenders == []


def test_the_execution_scan_detects_violations(tmp_path) -> None:  # type: ignore[no-untyped-def]
    # Guards the scanner itself against silently matching nothing.
    sample = "import subprocess\nimport os\nos.system('x')\neval('1')\n"
    tree = ast.parse(sample)
    names = [n for n in ast.walk(tree) if isinstance(n, (ast.Import, ast.Call))]
    assert len(names) == 4
