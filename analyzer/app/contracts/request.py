"""POST /internal/v1/analyze request body (contract section 4).

The body is parsed only after the HMAC signature has been verified. Unknown
fields are ignored (contract section 1). Duplicate JSON keys are rejected so
that no two parsers can disagree about what was signed.
"""

import json
import re
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, ValidationError, field_validator

from app.discovery.languages import SUPPORTED_LANGUAGES
from app.errors import AnalyzerError, ErrorCode
from app.versions import CONTRACT_MAJOR

ULID_PATTERN = r"^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$"
_CONTRACT_VERSION = re.compile(r"^([0-9]{1,3})\.([0-9]{1,3})$")


class SourceSpec(BaseModel):
    model_config = ConfigDict(extra="ignore", frozen=True)

    type: Literal["archive"]
    format: Literal["zip"]
    url: str = Field(min_length=10, max_length=4096)
    sha256: str = Field(pattern=r"^[0-9a-f]{64}$")
    size_bytes: int = Field(ge=1, le=2**53)


# What a run produces (contract section 4). "foundation" (Phase 08, the
# default, so requests without the option keep their Phase 08 meaning) or
# "static_analysis" (Phase 09: foundation inventory + parsing, IR 1.1,
# metrics and findings).
ResultType = Literal["foundation", "static_analysis"]


class Options(BaseModel):
    model_config = ConfigDict(extra="ignore", frozen=True)

    languages: tuple[str, ...] | None = Field(default=None, max_length=len(SUPPORTED_LANGUAGES))
    result_type: ResultType = "foundation"

    @field_validator("languages")
    @classmethod
    def known_languages(cls, value: tuple[str, ...] | None) -> tuple[str, ...] | None:
        if value is None:
            return None
        if len(set(value)) != len(value) or not value:
            raise ValueError("languages must be a non-empty list without duplicates")
        unknown = [language for language in value if language not in SUPPORTED_LANGUAGES]
        if unknown:
            raise ValueError("languages contains an unsupported language")
        return tuple(sorted(value))


class AnalyzeRequest(BaseModel):
    model_config = ConfigDict(extra="ignore", frozen=True)

    contract_version: str
    analysis_run_id: str = Field(pattern=ULID_PATTERN)
    attempt: int = Field(ge=1, le=1000)
    source: SourceSpec
    options: Options = Options()

    def requested_languages(self) -> tuple[str, ...]:
        return self.options.languages or tuple(sorted(SUPPORTED_LANGUAGES))

    def result_type(self) -> str:
        return self.options.result_type


def _reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate key")
        result[key] = value
    return result


def parse_request(body: bytes) -> AnalyzeRequest:
    try:
        data = json.loads(body.decode("utf-8"), object_pairs_hook=_reject_duplicate_keys)
    except (UnicodeDecodeError, ValueError) as exc:
        raise AnalyzerError(ErrorCode.INVALID_REQUEST, {"reason": "malformed_json"}) from exc
    if not isinstance(data, dict):
        raise AnalyzerError(ErrorCode.INVALID_REQUEST, {"reason": "not_an_object"})

    # An unknown contract major is its own error (400), checked before the schema.
    version = data.get("contract_version")
    match = _CONTRACT_VERSION.match(version) if isinstance(version, str) else None
    if match is None:
        raise AnalyzerError(ErrorCode.INVALID_REQUEST, {"fields": ["contract_version"]})
    if int(match.group(1)) != CONTRACT_MAJOR:
        raise AnalyzerError(ErrorCode.UNSUPPORTED_CONTRACT_VERSION)

    try:
        # Strict JSON validation: no type coercion (e.g. "1" is not an integer).
        return AnalyzeRequest.model_validate_json(body, strict=True)
    except ValidationError as exc:
        # Field locations only (e.g. "source.url"); never the submitted values.
        fields = sorted({".".join(str(part) for part in error["loc"]) for error in exc.errors()})
        raise AnalyzerError(ErrorCode.INVALID_REQUEST, {"fields": fields}) from exc
