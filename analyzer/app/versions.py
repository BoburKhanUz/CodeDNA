"""Versions reported in every result (docs/api/internal-analyzer-contract.md)."""

from app import __version__

ANALYZER_VERSION = __version__

# MAJOR.MINOR of the internal contract this analyzer implements.
CONTRACT_VERSION = "1.0"
CONTRACT_MAJOR = 1

# Version of the intermediate representation (the per-file records in `ir`).
# Bump the minor for additive fields, the major for incompatible changes.
# 1.0: file records (Phase 08), the IR of a foundation result.
# 1.1: + per-file parse status and AST-derived structure (Phase 09), the IR
# of a static_analysis result; every 1.0 field is unchanged.
IR_VERSION = "1.0"
IR_VERSION_STATIC_ANALYSIS = "1.1"

# What a successful response contains; the request chooses
# (options.result_type, default "foundation"). "foundation" (Phase 08):
# source inspection and file discovery only, no parsing. "static_analysis"
# (Phase 09): the foundation fields plus parsing, IR 1.1, static metrics and
# structural findings; still no features, DNA or scores. "full" arrives with
# scoring (Phase 11).
RESULT_TYPE_FOUNDATION = "foundation"
RESULT_TYPE_STATIC_ANALYSIS = "static_analysis"
