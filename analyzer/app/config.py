"""Analyzer settings, read once from the environment and validated at startup.

Every limit lives here; nothing else hard-codes a limit. Names follow
.env.example and the internal contract (section 7). The process refuses to
start with an invalid configuration (e.g. a missing or short HMAC secret).
"""

import ipaddress
import os
import re
from collections.abc import Mapping
from dataclasses import dataclass

MIB = 1024 * 1024

# Per-run workspaces live on a dedicated tmpfs (docker-compose.yml) owned by
# the analyzer user; run directories are 0700 with random names
# (app.workspace), so the usual shared-/tmp risks do not apply.
DEFAULT_WORKSPACE_ROOT = "/tmp/codedna"  # noqa: S108

# Directory names skipped by file discovery: VCS data, dependencies, build
# output, caches and temporary directories (docs/architecture/analyzer.md).
DEFAULT_IGNORED_DIRECTORIES: tuple[str, ...] = (
    ".git",
    ".hg",
    ".svn",
    "node_modules",
    "vendor",
    "bower_components",
    "dist",
    "build",
    "out",
    ".next",
    ".nuxt",
    "coverage",
    ".cache",
    "cache",
    "tmp",
    "temp",
    ".tmp",
    "__pycache__",
    ".venv",
    "venv",
    ".tox",
    ".mypy_cache",
    ".pytest_cache",
    "__MACOSX",
)

_HOSTNAME = re.compile(r"^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$")
_DIRECTORY_NAME = re.compile(r"^[A-Za-z0-9._-]{1,100}$")


class ConfigurationError(RuntimeError):
    """The environment does not describe a valid analyzer configuration."""


@dataclass(frozen=True)
class Settings:
    hmac_secret: str
    hmac_secret_previous: str | None = None
    # Maximum |now - X-CodeDNA-Timestamp| in seconds (contract: 300).
    hmac_max_skew_seconds: int = 300
    # Exact hostnames source URLs may point to (no wildcards, no IPs).
    allowed_source_hosts: frozenset[str] = frozenset()
    # Subset of allowed hosts reachable over plain HTTP and on private
    # addresses: local development only (e.g. the MinIO container).
    local_source_hosts: frozenset[str] = frozenset()
    # Longest accepted pre-signed URL lifetime (X-Amz-Expires).
    max_url_lifetime_seconds: int = 3600
    source_connect_timeout_seconds: float = 5.0
    source_read_timeout_seconds: float = 30.0
    source_download_timeout_seconds: float = 120.0
    hard_timeout_seconds: int = 240
    max_concurrency: int = 2
    max_archive_bytes: int = 50 * MIB
    max_extracted_bytes: int = 200 * MIB
    max_files: int = 20_000
    # Largest single archive entry that is extracted at all.
    max_entry_bytes: int = 25 * MIB
    # Largest file that is analyzed; larger files are skipped as too_large.
    max_file_bytes: int = 1 * MIB
    max_path_length: int = 512
    # Parsing (Phase 09). Wall-clock budget for parsing one file; when it runs
    # out the file is PARSE_TIMEOUT (the only time-dependent status).
    parse_timeout_ms: int = 5000
    # Largest syntax tree (in nodes) that is analyzed; larger trees are
    # LIMIT_EXCEEDED. Bounds the walk's time and memory per file.
    max_ast_nodes: int = 1_000_000
    # Total syntax-tree nodes analyzed per run; later files are LIMIT_EXCEEDED.
    max_total_ast_nodes: int = 10_000_000
    # Most files parsed per run; later files are LIMIT_EXCEEDED.
    max_parsed_files: int = 20_000
    workspace_root: str = DEFAULT_WORKSPACE_ROOT
    ignored_directories: tuple[str, ...] = DEFAULT_IGNORED_DIRECTORIES
    # Bounded in-memory state (per analyzer process).
    replay_cache_entries: int = 100_000
    result_cache_bytes: int = 32 * MIB
    result_cache_seconds: int = 900
    max_request_body_bytes: int = 64 * 1024
    log_level: str = "INFO"

    def __post_init__(self) -> None:
        problems = self.problems()
        if problems:
            raise ConfigurationError("Invalid analyzer configuration: " + "; ".join(problems))

    def problems(self) -> list[str]:
        problems: list[str] = []
        if len(self.hmac_secret) < 32:
            problems.append("ANALYZER_HMAC_SECRET must be at least 32 characters (64 hex characters recommended)")
        if self.hmac_secret_previous is not None and len(self.hmac_secret_previous) < 32:
            problems.append("ANALYZER_HMAC_SECRET_PREVIOUS must be empty or at least 32 characters")
        if not self.allowed_source_hosts:
            problems.append("ANALYZER_ALLOWED_SOURCE_HOSTS must list at least one hostname")
        for host in self.allowed_source_hosts | self.local_source_hosts:
            if _is_ip_literal(host) or not _HOSTNAME.match(host):
                problems.append(f"source host {host!r} must be a lowercase hostname, not an IP address or URL")
        if not self.local_source_hosts <= self.allowed_source_hosts:
            problems.append("ANALYZER_LOCAL_SOURCE_HOSTS must be a subset of ANALYZER_ALLOWED_SOURCE_HOSTS")
        for name in (
            "hmac_max_skew_seconds",
            "max_url_lifetime_seconds",
            "hard_timeout_seconds",
            "max_concurrency",
            "max_archive_bytes",
            "max_extracted_bytes",
            "max_files",
            "max_entry_bytes",
            "max_file_bytes",
            "max_path_length",
            "parse_timeout_ms",
            "max_ast_nodes",
            "max_total_ast_nodes",
            "max_parsed_files",
            "replay_cache_entries",
            "result_cache_seconds",
            "max_request_body_bytes",
        ):
            if getattr(self, name) < 1:
                problems.append(f"{name} must be positive")
        for name in ("source_connect_timeout_seconds", "source_read_timeout_seconds", "source_download_timeout_seconds"):
            if getattr(self, name) <= 0:
                problems.append(f"{name} must be positive")
        if self.result_cache_bytes < 0:
            problems.append("result_cache_bytes must not be negative")
        if self.max_ast_nodes > self.max_total_ast_nodes:
            problems.append("ANALYZER_MAX_AST_NODES must not exceed ANALYZER_MAX_TOTAL_AST_NODES")
        if self.max_entry_bytes > self.max_extracted_bytes:
            problems.append("ANALYZER_MAX_ENTRY_BYTES must not exceed ANALYZER_MAX_EXTRACTED_BYTES")
        if not os.path.isabs(self.workspace_root) or os.path.normpath(self.workspace_root) in ("/", ""):
            problems.append("ANALYZER_WORKSPACE_ROOT must be an absolute path below /")
        for name in self.ignored_directories:
            if not _DIRECTORY_NAME.match(name) or name in (".", ".."):
                problems.append(f"ignored directory {name!r} is not a plain directory name")
        return problems

    @property
    def secrets(self) -> tuple[str, ...]:
        """Current secret first, then the previous one during rotation."""
        return (self.hmac_secret,) if self.hmac_secret_previous is None else (self.hmac_secret, self.hmac_secret_previous)

    def limits(self) -> dict[str, int]:
        """Non-sensitive limits reported by the health endpoint and recorded in results."""
        return {
            "hard_timeout_seconds": self.hard_timeout_seconds,
            "max_archive_bytes": self.max_archive_bytes,
            "max_extracted_bytes": self.max_extracted_bytes,
            "max_files": self.max_files,
            "max_entry_bytes": self.max_entry_bytes,
            "max_file_bytes": self.max_file_bytes,
            "max_path_length": self.max_path_length,
            "max_concurrency": self.max_concurrency,
            "parse_timeout_ms": self.parse_timeout_ms,
            "max_ast_nodes": self.max_ast_nodes,
            "max_total_ast_nodes": self.max_total_ast_nodes,
            "max_parsed_files": self.max_parsed_files,
        }

    @classmethod
    def from_env(cls, env: Mapping[str, str] | None = None) -> "Settings":
        source = os.environ if env is None else env

        def text(name: str, default: str = "") -> str:
            return source.get(name, default).strip()

        def integer(name: str, default: int) -> int:
            raw = text(name)
            if raw == "":
                return default
            try:
                return int(raw)
            except ValueError as exc:
                raise ConfigurationError(f"{name} must be an integer") from exc

        def number(name: str, default: float) -> float:
            raw = text(name)
            if raw == "":
                return default
            try:
                return float(raw)
            except ValueError as exc:
                raise ConfigurationError(f"{name} must be a number") from exc

        def hosts(name: str) -> frozenset[str]:
            return frozenset(h.strip().lower().rstrip(".") for h in text(name).split(",") if h.strip())

        ignored = tuple(d.strip() for d in text("ANALYZER_IGNORED_DIRECTORIES").split(",") if d.strip())

        return cls(
            hmac_secret=text("ANALYZER_HMAC_SECRET"),
            hmac_secret_previous=text("ANALYZER_HMAC_SECRET_PREVIOUS") or None,
            hmac_max_skew_seconds=integer("ANALYZER_HMAC_MAX_SKEW_SECONDS", 300),
            allowed_source_hosts=hosts("ANALYZER_ALLOWED_SOURCE_HOSTS"),
            local_source_hosts=hosts("ANALYZER_LOCAL_SOURCE_HOSTS"),
            max_url_lifetime_seconds=integer("ANALYZER_MAX_URL_LIFETIME_SECONDS", 3600),
            source_connect_timeout_seconds=number("ANALYZER_SOURCE_CONNECT_TIMEOUT_SECONDS", 5.0),
            source_read_timeout_seconds=number("ANALYZER_SOURCE_READ_TIMEOUT_SECONDS", 30.0),
            source_download_timeout_seconds=number("ANALYZER_SOURCE_DOWNLOAD_TIMEOUT_SECONDS", 120.0),
            hard_timeout_seconds=integer("ANALYZER_HARD_TIMEOUT_SECONDS", 240),
            max_concurrency=integer("ANALYZER_MAX_CONCURRENCY", 2),
            max_archive_bytes=integer("ANALYZER_MAX_ARCHIVE_BYTES", 50 * MIB),
            max_extracted_bytes=integer("ANALYZER_MAX_EXTRACTED_BYTES", 200 * MIB),
            max_files=integer("ANALYZER_MAX_FILES", 20_000),
            max_entry_bytes=integer("ANALYZER_MAX_ENTRY_BYTES", 25 * MIB),
            max_file_bytes=integer("ANALYZER_MAX_FILE_BYTES", 1 * MIB),
            max_path_length=integer("ANALYZER_MAX_PATH_LENGTH", 512),
            parse_timeout_ms=integer("ANALYZER_PARSE_TIMEOUT_MS", 5000),
            max_ast_nodes=integer("ANALYZER_MAX_AST_NODES", 1_000_000),
            max_total_ast_nodes=integer("ANALYZER_MAX_TOTAL_AST_NODES", 10_000_000),
            max_parsed_files=integer("ANALYZER_MAX_PARSED_FILES", 20_000),
            workspace_root=text("ANALYZER_WORKSPACE_ROOT", DEFAULT_WORKSPACE_ROOT) or DEFAULT_WORKSPACE_ROOT,
            ignored_directories=ignored or DEFAULT_IGNORED_DIRECTORIES,
            replay_cache_entries=integer("ANALYZER_REPLAY_CACHE_ENTRIES", 100_000),
            result_cache_bytes=integer("ANALYZER_RESULT_CACHE_BYTES", 32 * MIB),
            result_cache_seconds=integer("ANALYZER_RESULT_CACHE_SECONDS", 900),
            log_level=text("LOG_LEVEL", "INFO").upper() or "INFO",
        )


def _is_ip_literal(host: str) -> bool:
    try:
        ipaddress.ip_address(host.strip("[]"))
    except ValueError:
        return False
    return True
