"""Versions reported in every result (docs/api/internal-analyzer-contract.md)."""

from app import __version__

ANALYZER_VERSION = __version__

# MAJOR.MINOR of the internal contract this analyzer implements.
CONTRACT_VERSION = "1.0"
CONTRACT_MAJOR = 1

# Version of the intermediate representation (the per-file records in `ir`).
# Bump the minor for additive fields, the major for incompatible changes.
IR_VERSION = "1.0"

# What a successful response contains. "foundation": source inspection and
# file discovery only (Phase 08); no metrics, features, DNA or findings.
# "full" arrives with metrics (Phase 09) and scoring (Phase 11).
RESULT_TYPE_FOUNDATION = "foundation"
