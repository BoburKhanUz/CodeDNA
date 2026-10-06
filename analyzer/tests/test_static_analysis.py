"""End-to-end static analysis: contract, determinism across processes, and the security boundary."""

import hashlib
import io
import os
import random
import subprocess
import sys
import zipfile
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.canonical import canonical_json
from app.config import Settings
from app.deadline import Deadline
from app.discovery.discover import discover
from app.main import create_app
from app.parsing import parser as parser_module
from app.parsing.parser import STATUSES, parse_files
from app.parsing.text import name_text, target_text
from tests.schema_check import load, validate
from tests.support import PATH, FakeFetcher, encode, request_body, resolver, signed_headers, zip_bytes
from tests.test_parsing_languages import ERRORS, EXPECTED, FIXTURES

ANALYZER_ROOT = Path(__file__).resolve().parents[1]

CANARIES = {
    "src/leak.py": (
        "# COMMENT_CANARY_7f3a\n"
        'API_KEY = "STRING_CANARY_9b1c"\n'
        "def handler(request, retries=DEFAULT_CANARY_44):\n"
        '    """DOCSTRING_CANARY_d2e8"""\n'
        "    local_canary_variable = request.body\n"
        "    return local_canary_variable\n"
    ),
    "web/leak.ts": ("// TS_COMMENT_CANARY_51\nexport function render(): string {\n  return `TEMPLATE_CANARY_${1 + 1}`;\n}\n"),
    "lib/leak.c": '#define MACRO_CANARY 1\nstatic const char *k = "C_STRING_CANARY";\nint main(void) { return MACRO_CANARY; }\n',
}
CANARY_WORDS = (
    "COMMENT_CANARY",
    "STRING_CANARY",
    "DEFAULT_CANARY",
    "DOCSTRING_CANARY",
    "local_canary_variable",
    "TEMPLATE_CANARY",
    "MACRO_CANARY",
    "C_STRING_CANARY",
)


def fixture_archive(order: list[str] | None = None) -> bytes:
    names = sorted(EXPECTED) + sorted(ERRORS)
    if order is not None:
        names = order
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archive:
        for name in names:
            archive.writestr(f"src/{name}", (FIXTURES / name).read_bytes())
        for path, text in CANARIES.items():
            archive.writestr(path, text)
    return buffer.getvalue()


def post(settings: Settings, archive: bytes) -> dict:  # type: ignore[type-arg]
    raw = encode(request_body(archive))
    with TestClient(create_app(settings, resolver=resolver("172.18.0.5"), fetcher=FakeFetcher(archive))) as client:
        response = client.post(PATH, content=raw, headers=signed_headers(raw))
    assert response.status_code == 200, response.text
    return response.json()  # type: ignore[no-any-return]


def test_the_result_matches_the_static_analysis_schema(settings: Settings) -> None:
    data = post(settings, fixture_archive())
    assert validate(data, load("static-analysis-result.schema.json")) == []
    assert data["parsing"]["files"]["PARSED"] == len(EXPECTED) + len(CANARIES)
    assert data["parsing"]["files"]["PARSE_ERROR"] == len(ERRORS)
    assert data["findings"]["by_rule"]["parse/syntax-error"] == len(ERRORS)
    assert set(data["metrics"]["by_language"]) == {entry["language"] for entry in data["languages"]}


def test_no_source_text_reaches_the_result(settings: Settings) -> None:
    data = post(settings, fixture_archive())
    text = canonical_json(data).decode()
    for word in CANARY_WORDS:
        assert word not in text, word
    # Declaration names are structure and may appear; bodies, comments and literals may not.
    assert "handler" in text and "render" in text


def test_the_result_hash_is_identical_across_processes_and_hash_seeds(tmp_path: Path) -> None:
    archive_path = tmp_path / "source.zip"
    archive_path.write_bytes(fixture_archive())
    probe = (
        "import hashlib, sys\n"
        "from app.canonical import canonical_json\n"
        "from app.contracts.request import parse_request\n"
        "from app.services.analysis import analyze\n"
        "from tests.support import FakeFetcher, encode, make_settings, request_body, resolver\n"
        "archive = open(sys.argv[2], 'rb').read()\n"
        "request = parse_request(encode(request_body(archive)))\n"
        "result = analyze(request, make_settings(sys.argv[1]), resolver=resolver('172.18.0.5'), fetcher=FakeFetcher(archive))\n"
        "print(result['result_hash'], hashlib.sha256(canonical_json(result)).hexdigest())\n"
    )
    outputs = []
    for seed in ("0", "1", "random"):
        workspaces = tmp_path / f"ws-{seed}"
        workspaces.mkdir(mode=0o700)
        completed = subprocess.run(  # noqa: S603 - the test interpreter running the analyzer's own probe
            [sys.executable, "-c", probe, str(workspaces), str(archive_path)],
            cwd=ANALYZER_ROOT,
            env={**os.environ, "PYTHONHASHSEED": seed},
            capture_output=True,
            text=True,
            timeout=120,
            check=True,
        )
        outputs.append(completed.stdout.strip())
        assert os.listdir(workspaces) == []
    assert len(set(outputs)) == 1, outputs


def test_archive_entry_order_does_not_change_the_result(settings: Settings) -> None:
    names = sorted(EXPECTED) + sorted(ERRORS)
    shuffled = names[:]
    random.Random(7).shuffle(shuffled)
    first = post(settings, fixture_archive(names))
    second = post(settings, fixture_archive(shuffled))
    # Different archive bytes (sha256, size), otherwise the identical analysis.
    assert first["source"]["sha256"] != second["source"]["sha256"]
    for data in (first, second):
        for key in ("request_id", "diagnostics", "result_hash"):
            del data[key]
        del data["source"]["sha256"], data["source"]["size_bytes"]
    assert canonical_json(first) == canonical_json(second)


def test_names_and_targets_cannot_carry_code(tmp_path: Path, settings: Settings) -> None:
    (tmp_path / "evil.cpp").write_text('class K : public Base<decltype(system("rm -rf /"))> { void f() {} };\n')
    (tmp_path / "evil.ts").write_text("import x from 'a b; rm -rf /';\nimport y from \"$(touch /tmp/pwned)\";\n")
    (tmp_path / "evil.py").write_text("class A(__import__('os').system('id')):\n    pass\n")
    found = discover(
        str(tmp_path), ignored_directories=(), languages=("cpp", "typescript", "python"), max_file_bytes=4096, deadline=Deadline(5)
    )
    results = parse_files(str(tmp_path), found.files, settings, Deadline(5))
    cpp, ts, py = (results[name].structure_dict() for name in ("evil.cpp", "evil.ts", "evil.py"))
    assert cpp is not None and ts is not None and py is not None
    assert cpp["types"][0]["bases"] == [None]
    assert [item["target"] for item in ts["imports"]] == [None, None]
    assert py["types"][0]["bases"] == [None]
    assert name_text(b"a;b", _Span(0, 3)) is None  # type: ignore[arg-type]
    assert target_text(b"a b", _Span(0, 3)) is None  # type: ignore[arg-type]
    assert name_text(b"x" * 201, _Span(0, 201)) is None  # type: ignore[arg-type]
    assert name_text(b"\xff\xfe", _Span(0, 2)) is None  # type: ignore[arg-type]


class _Span:
    def __init__(self, start: int, end: int) -> None:
        self.start_byte = start
        self.end_byte = end


def test_files_are_read_without_following_symlinks(tmp_path: Path) -> None:
    (tmp_path / "target.py").write_text("x = 1\n")
    os.symlink(tmp_path / "target.py", tmp_path / "link.py")
    with pytest.raises(OSError):
        parser_module._read(str(tmp_path / "link.py"), 1024)


@pytest.mark.parametrize("name", sorted(EXPECTED))
def test_random_and_mutated_inputs_never_crash_the_parser(tmp_path: Path, settings: Settings, name: str) -> None:
    rng = random.Random(hashlib.sha256(name.encode()).digest())
    original = (FIXTURES / name).read_bytes()
    suffix = Path(name).suffix
    for index in range(40):
        data = bytearray(original)
        for _ in range(rng.randint(1, 12)):
            operation = rng.randrange(3)
            position = rng.randrange(len(data) + 1)
            if operation == 0 and data:
                del data[min(position, len(data) - 1)]
            elif operation == 1:
                data.insert(position, rng.choice(b"{}()[];:,.\"'`<>/\\*#$@!?=+-&|^~\n\t "))
            else:
                data[position:position] = bytes(rng.randrange(1, 256) for _ in range(rng.randrange(1, 8)))
        data = data.replace(b"\x00", b"")
        (tmp_path / f"m{index:02d}{suffix}").write_bytes(bytes(data))
    found = discover(
        str(tmp_path), ignored_directories=(), languages=tuple(sorted(EXPECTED_LANGUAGES)), max_file_bytes=65536, deadline=Deadline(30)
    )
    results = parse_files(str(tmp_path), found.files, settings, Deadline(30))
    assert len(results) == 40
    assert all(result.status in STATUSES for result in results.values())


EXPECTED_LANGUAGES = {"c", "cpp", "csharp", "go", "java", "javascript", "php", "python", "rust", "typescript"}


def test_a_malformed_file_does_not_fail_the_run(settings: Settings) -> None:
    archive = zip_bytes(
        {"ok.py": "x = 1\n", "bad.py": "def (:\n", "bin.py": b"\x7fELF\x00\x01", "huge.go": "package a\n" + "// x\n" * 300_000}
    )
    data = post(settings, archive)
    statuses = {f["path"]: (f["skip_reason"], f["parse"] and f["parse"]["status"]) for f in data["ir"]["files"]}
    assert statuses == {
        "bad.py": (None, "PARSE_ERROR"),
        "bin.py": ("binary", None),
        "huge.go": ("too_large", None),
        "ok.py": (None, "PARSED"),
    }
