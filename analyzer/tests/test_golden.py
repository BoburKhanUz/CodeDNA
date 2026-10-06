"""Golden regression: the fixture tree's IR 1.1, metrics and findings, byte for byte.

Any change to parsing, IR, metrics or findings output fails this test until
tests/golden/static-analysis.json is regenerated in the same change, which
must also bump IR_VERSION or METRICS_VERSION when the output meaning
changes (ADR-004). Regenerate inside the analyzer container:

    docker compose exec -T analyzer python -m tests.test_golden > analyzer/tests/golden/static-analysis.json
"""

import json
import shutil
import sys
import tempfile
from pathlib import Path
from typing import Any

from app.canonical import canonical_json
from app.deadline import Deadline
from app.discovery.discover import discover
from app.discovery.languages import SUPPORTED_LANGUAGES
from app.metrics.aggregate import compute_metrics
from app.metrics.findings import compute_findings
from app.parsing.parser import parse_files
from app.services.analysis import ir_file
from tests.support import make_settings

FIXTURES = Path(__file__).parent / "fixtures" / "parsing"
GOLDEN = Path(__file__).parent / "golden" / "static-analysis.json"


def analyze_fixtures() -> dict[str, Any]:
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory) / "tree"
        shutil.copytree(FIXTURES, root)
        settings = make_settings(directory)
        found = discover(
            str(root),
            ignored_directories=settings.ignored_directories,
            languages=tuple(sorted(SUPPORTED_LANGUAGES)),
            max_file_bytes=settings.max_file_bytes,
            deadline=Deadline(60),
        )
        parsed = parse_files(str(root), found.files, settings, Deadline(60))
        return {
            "ir": [ir_file(record.to_dict(), parsed.get(record.path)) for record in found.files],
            "metrics": compute_metrics(found.files, parsed),
            "findings": compute_findings(found.files, parsed),
        }


def test_the_fixture_tree_matches_the_golden_output() -> None:
    expected = json.loads(GOLDEN.read_text(encoding="utf-8"))
    assert canonical_json(analyze_fixtures()) == canonical_json(expected)


if __name__ == "__main__":
    sys.stdout.write(json.dumps(analyze_fixtures(), indent=1, sort_keys=True, ensure_ascii=False) + "\n")
