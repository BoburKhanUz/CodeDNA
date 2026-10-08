#!/usr/bin/env python3
"""Deterministic synthetic source archives for benchmarks (Phase 26).

Writes ZIP archives of generated Python projects: modules with classes,
functions, branches, docstrings and a tests/ directory. Nothing in them is
real source code; the same seed always yields byte-identical archives
(fixed timestamps, sorted entries), so benchmark runs are comparable.

  scripts/benchmark/make_sources.py <out-dir>                      # every profile
  scripts/benchmark/make_sources.py <out-dir> --profile medium     # one profile

Profiles (files ≈ modules + tests):
  small   ~12 files,   ~25 KB uncompressed
  medium  ~120 files,  ~0.35 MB uncompressed
  large   ~1,200 files, ~3.6 MB uncompressed (0.7 MB archive)
  xlarge  ~6,000 files, ~18 MB uncompressed (3.4 MB archive): towards the
          configured limits (20,000 files, 200 MiB extracted, 50 MiB archive)
  template-v1, template-v2: two revisions of one project, used by the
                          benchmark seeder to create a real growth history.
"""

from __future__ import annotations

import argparse
import random
import zipfile
from pathlib import Path

PROFILES = {
    "small": (10, 2, 8),
    "medium": (100, 20, 12),
    "large": (1000, 200, 14),
    "xlarge": (5000, 1000, 14),
    "template-v1": (14, 2, 6),
    "template-v2": (16, 5, 9),
}
FIXED_TIME = (2026, 1, 1, 0, 0, 0)


def function(rng: random.Random, name: str, depth: int) -> list[str]:
    args = ", ".join(f"arg{i}: int" for i in range(rng.randint(1, 4)))
    lines = [f"def {name}({args}) -> int:"]
    if rng.random() < 0.7:
        lines.append(f'    """Compute {name.replace("_", " ")} for the given values."""')
    lines.append("    total = 0")
    for i in range(rng.randint(2, depth)):
        kind = rng.random()
        if kind < 0.4:
            lines += [f"    if arg0 > {i}:", f"        total += arg0 * {i + 1}", "    else:", f"        total -= {i}"]
        elif kind < 0.7:
            lines += [
                f"    for item in range({i + 2}):",
                "        if item % 2:",
                "            total += item",
                "        else:",
                "            continue",
            ]
        elif kind < 0.85:
            lines += ["    try:", f"        total //= max(arg0, {i + 1})", "    except ZeroDivisionError:", "        total = 0"]
        else:
            lines += [f"    while total > {100 * (i + 1)}:", "        total -= 7"]
    lines += ["    return total", ""]
    return lines


def module(rng: random.Random, index: int, depth: int) -> str:
    lines = [f'"""Synthetic module {index} (generated benchmark fixture)."""', "", "from __future__ import annotations", ""]
    for c in range(rng.randint(0, 2)):
        lines += [
            f"class Service{index}x{c}:",
            f'    """Service {index}.{c}."""',
            "",
            "    def __init__(self, limit: int) -> None:",
            "        self.limit = limit",
            "",
        ]
        for m in range(rng.randint(1, 3)):
            lines += [
                f"    def method_{m}(self, value: int) -> int:",
                "        if value > self.limit:",
                "            return self.limit",
                "        return value",
                "",
            ]
        lines.append("")
    for f in range(rng.randint(2, 6)):
        lines += function(rng, f"compute_{index}_{f}", depth)
        lines.append("")
    return "\n".join(lines) + "\n"


def test_module(rng: random.Random, index: int) -> str:
    lines = [f"from pkg.module_{index} import compute_{index}_0", ""]
    for t in range(rng.randint(1, 3)):
        lines += [f"def test_case_{t}() -> None:", f"    assert compute_{index}_0({t}) is not None", ""]
    return "\n".join(lines) + "\n"


def build(profile: str, out: Path) -> Path:
    modules, tests, depth = PROFILES[profile]
    rng = random.Random(f"codedna-benchmark-{profile}")
    entries: dict[str, str] = {
        "README.md": f"# Synthetic benchmark project ({profile})\n\nGenerated fixture; not real source code.\n",
        "pyproject.toml": '[project]\nname = "synthetic-benchmark"\nversion = "0.0.0"\n',
        "pkg/__init__.py": '"""Synthetic package."""\n',
    }
    for i in range(modules):
        entries[f"pkg/module_{i}.py"] = module(rng, i, depth)
    for i in range(tests):
        entries[f"tests/test_module_{i}.py"] = test_module(rng, i)
    path = out / f"{profile}.zip"
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name in sorted(entries):
            info = zipfile.ZipInfo(name, date_time=FIXED_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            archive.writestr(info, entries[name])
    return path


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("out", type=Path)
    parser.add_argument("--profile", choices=sorted(PROFILES), action="append")
    args = parser.parse_args()
    args.out.mkdir(parents=True, exist_ok=True)
    for profile in args.profile or sorted(PROFILES):
        path = build(profile, args.out)
        print(f"{path} {path.stat().st_size} bytes")


if __name__ == "__main__":
    main()
