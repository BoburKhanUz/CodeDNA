"""A minimal JSON Schema validator for the subset used by
packages/api-contracts (type, required, properties, additionalProperties,
items, enum, const, pattern, minLength/maxLength, minimum/maximum,
minItems, uniqueItems, local $ref). It keeps the analyzer free of a
jsonschema dependency; unsupported keywords fail loudly."""

import json
import os
import re
from pathlib import Path
from typing import Any

SUPPORTED = {
    "$schema",
    "$id",
    "$defs",
    "$ref",
    "title",
    "type",
    "required",
    "properties",
    "additionalProperties",
    "items",
    "enum",
    "const",
    "pattern",
    "minLength",
    "maxLength",
    "minimum",
    "maximum",
    "minItems",
    "uniqueItems",
}
TYPES = {
    "object": dict,
    "array": list,
    "string": str,
    "boolean": bool,
    "null": type(None),
}


def contracts_dir() -> Path:
    configured = os.environ.get("CODEDNA_CONTRACTS_DIR")
    for candidate in ([Path(configured)] if configured else []) + [
        Path("/contracts"),
        Path(__file__).resolve().parents[2] / "packages" / "api-contracts",
    ]:
        if (candidate / "analyzer" / "v1").is_dir():
            return candidate / "analyzer" / "v1"
    raise FileNotFoundError("packages/api-contracts is not available")


def load(name: str) -> dict[str, Any]:
    with open(contracts_dir() / name, encoding="utf-8") as handle:
        return json.load(handle)  # type: ignore[no-any-return]


def validate(instance: Any, schema: dict[str, Any], root: dict[str, Any] | None = None, path: str = "$") -> list[str]:
    root = root or schema
    unknown = set(schema) - SUPPORTED
    if unknown:
        raise ValueError(f"unsupported keywords at {path}: {sorted(unknown)}")
    if "$ref" in schema:
        target: Any = root
        for part in schema["$ref"].lstrip("#/").split("/"):
            target = target[part]
        return validate(instance, target, root, path)

    errors: list[str] = []
    if "type" in schema:
        allowed = schema["type"] if isinstance(schema["type"], list) else [schema["type"]]
        if not any(_is_type(instance, t) for t in allowed):
            return [f"{path}: expected {allowed}, got {type(instance).__name__}"]
    if "const" in schema and instance != schema["const"]:
        errors.append(f"{path}: expected constant {schema['const']!r}")
    if "enum" in schema and instance not in schema["enum"]:
        errors.append(f"{path}: {instance!r} not in enum")
    if isinstance(instance, str):
        if "pattern" in schema and not re.search(schema["pattern"], instance):
            errors.append(f"{path}: does not match {schema['pattern']}")
        if len(instance) < schema.get("minLength", 0) or len(instance) > schema.get("maxLength", 10**9):
            errors.append(f"{path}: length out of bounds")
    is_integer = isinstance(instance, int) and not isinstance(instance, bool)
    if is_integer and not schema.get("minimum", -(10**18)) <= instance <= schema.get("maximum", 10**18):
        errors.append(f"{path}: {instance} out of bounds")
    if isinstance(instance, list):
        if len(instance) < schema.get("minItems", 0):
            errors.append(f"{path}: too few items")
        if schema.get("uniqueItems") and len({json.dumps(i, sort_keys=True) for i in instance}) != len(instance):
            errors.append(f"{path}: items not unique")
        if "items" in schema:
            for index, item in enumerate(instance):
                errors += validate(item, schema["items"], root, f"{path}[{index}]")
    if isinstance(instance, dict):
        for name in schema.get("required", []):
            if name not in instance:
                errors.append(f"{path}: missing {name}")
        properties = schema.get("properties", {})
        for name, value in instance.items():
            if name in properties:
                errors += validate(value, properties[name], root, f"{path}.{name}")
            elif schema.get("additionalProperties") is False:
                errors.append(f"{path}: unexpected property {name}")
    return errors


def _is_type(value: Any, name: str) -> bool:
    if name == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if name == "number":
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    return isinstance(value, TYPES[name]) and not (name != "boolean" and isinstance(value, bool))
