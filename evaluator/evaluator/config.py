"""Evaluator configuration, from the environment only, with hard caps."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

SLOT_FIRST_UID = 10002


class ConfigError(ValueError):
    pass


@dataclass(frozen=True)
class Slot:
    index: int
    uid: int
    gid: int
    directory: Path


@dataclass(frozen=True)
class Limits:
    wall_seconds: int
    cpu_seconds: int
    memory_bytes: int
    max_processes: int
    max_file_bytes: int
    max_open_files: int
    max_output_bytes: int
    max_source_bytes: int
    max_cases: int


@dataclass(frozen=True)
class Config:
    spool: Path
    slots: tuple[Slot, ...]
    limits: Limits
    poll_seconds: float
    # Runtime isolation contract (Phase 25, evaluator/isolation.py).
    isolation: str = "container"
    production: bool = False


def _int(name: str, default: int, low: int, high: int) -> int:
    raw = os.environ.get(name, str(default))
    try:
        value = int(raw)
    except ValueError as error:
        raise ConfigError(f"{name} must be an integer") from error
    if not low <= value <= high:
        raise ConfigError(f"{name} must be between {low} and {high}")
    return value


def _flag(name: str) -> bool:
    raw = os.environ.get(name, "false")
    if raw not in ("true", "false"):
        raise ConfigError(f"{name} must be true or false")
    return raw == "true"


def load() -> Config:
    timeout = _int("CHALLENGE_EXECUTION_TIMEOUT", 5, 1, 30)
    concurrency = _int("CHALLENGE_MAX_CONCURRENT_EVALUATIONS", 2, 1, 4)
    sandbox_root = Path(os.environ.get("EVALUATOR_SANDBOX_ROOT", "/sandbox"))
    slots = tuple(
        Slot(index=i, uid=SLOT_FIRST_UID + i, gid=SLOT_FIRST_UID + i, directory=sandbox_root / str(i)) for i in range(concurrency)
    )
    limits = Limits(
        wall_seconds=timeout,
        cpu_seconds=timeout,
        memory_bytes=_int("CHALLENGE_MAX_MEMORY_MB", 256, 64, 1024) * 1024 * 1024,
        max_processes=_int("CHALLENGE_MAX_PROCESSES", 16, 1, 64),
        max_file_bytes=_int("CHALLENGE_MAX_FILE_BYTES", 1024 * 1024, 4096, 16 * 1024 * 1024),
        max_open_files=32,
        max_output_bytes=_int("CHALLENGE_MAX_OUTPUT_BYTES", 65536, 4096, 1024 * 1024),
        max_source_bytes=_int("CHALLENGE_MAX_SOURCE_BYTES", 16384, 1024, 65536),
        max_cases=64,
    )
    isolation = os.environ.get("EVALUATOR_ISOLATION", "container")
    if isolation not in ("container", "gvisor"):
        raise ConfigError("EVALUATOR_ISOLATION must be container or gvisor")
    production = _flag("EVALUATOR_PRODUCTION")
    if production and isolation != "gvisor":
        # Fail closed: production never executes code with container isolation only.
        raise ConfigError("EVALUATOR_PRODUCTION=true requires EVALUATOR_ISOLATION=gvisor")
    return Config(
        spool=Path(os.environ.get("EVALUATOR_SPOOL", "/spool")),
        slots=slots,
        limits=limits,
        poll_seconds=0.2,
        isolation=isolation,
        production=production,
    )
