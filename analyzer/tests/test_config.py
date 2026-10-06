import pytest

from app.config import ConfigurationError, Settings


def env(**overrides: str) -> dict[str, str]:
    values = {
        "ANALYZER_HMAC_SECRET": "a" * 64,
        "ANALYZER_ALLOWED_SOURCE_HOSTS": "minio",
        "ANALYZER_LOCAL_SOURCE_HOSTS": "minio",
    }
    values.update(overrides)
    return values


def test_reads_the_documented_environment_variables() -> None:
    settings = Settings.from_env(env(ANALYZER_MAX_FILES="10", ANALYZER_IGNORED_DIRECTORIES="node_modules, .git"))

    assert settings.max_files == 10
    assert settings.ignored_directories == ("node_modules", ".git")
    assert settings.allowed_source_hosts == frozenset({"minio"})
    # Defaults match the Laravel upload limits (Phase 07).
    assert (settings.max_archive_bytes, settings.max_extracted_bytes, settings.max_entry_bytes) == (52428800, 209715200, 26214400)


def test_reads_the_parser_limits() -> None:
    defaults = Settings.from_env(env())
    assert (defaults.parse_timeout_ms, defaults.max_ast_nodes, defaults.max_total_ast_nodes, defaults.max_parsed_files) == (
        5000,
        1_000_000,
        10_000_000,
        20_000,
    )
    custom = Settings.from_env(
        env(
            ANALYZER_PARSE_TIMEOUT_MS="250",
            ANALYZER_MAX_AST_NODES="1000",
            ANALYZER_MAX_TOTAL_AST_NODES="5000",
            ANALYZER_MAX_PARSED_FILES="7",
        )
    )
    assert (custom.parse_timeout_ms, custom.max_ast_nodes, custom.max_total_ast_nodes, custom.max_parsed_files) == (250, 1000, 5000, 7)


@pytest.mark.parametrize(
    "overrides",
    [
        {"ANALYZER_HMAC_SECRET": ""},
        {"ANALYZER_HMAC_SECRET": "too-short"},
        {"ANALYZER_HMAC_SECRET_PREVIOUS": "short"},
        {"ANALYZER_ALLOWED_SOURCE_HOSTS": ""},
        {"ANALYZER_ALLOWED_SOURCE_HOSTS": "127.0.0.1", "ANALYZER_LOCAL_SOURCE_HOSTS": ""},
        {"ANALYZER_ALLOWED_SOURCE_HOSTS": "http://minio", "ANALYZER_LOCAL_SOURCE_HOSTS": ""},
        {"ANALYZER_ALLOWED_SOURCE_HOSTS": "*.r2.example.com", "ANALYZER_LOCAL_SOURCE_HOSTS": ""},
        {"ANALYZER_LOCAL_SOURCE_HOSTS": "other"},
        {"ANALYZER_MAX_FILES": "0"},
        {"ANALYZER_MAX_FILES": "many"},
        {"ANALYZER_MAX_ENTRY_BYTES": "999999999999"},
        {"ANALYZER_WORKSPACE_ROOT": "relative/dir"},
        {"ANALYZER_WORKSPACE_ROOT": "/"},
        {"ANALYZER_IGNORED_DIRECTORIES": "../etc"},
        {"ANALYZER_PARSE_TIMEOUT_MS": "0"},
        {"ANALYZER_MAX_AST_NODES": "-1"},
        {"ANALYZER_MAX_AST_NODES": "200", "ANALYZER_MAX_TOTAL_AST_NODES": "100"},
        {"ANALYZER_MAX_PARSED_FILES": "lots"},
    ],
)
def test_refuses_to_start_with_an_invalid_configuration(overrides: dict[str, str]) -> None:
    with pytest.raises(ConfigurationError):
        Settings.from_env(env(**overrides))


def test_errors_never_echo_the_secret() -> None:
    with pytest.raises(ConfigurationError) as raised:
        Settings.from_env(env(ANALYZER_HMAC_SECRET="short-secret-value"))
    assert "short-secret-value" not in str(raised.value)
