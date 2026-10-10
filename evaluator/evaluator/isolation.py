"""The evaluator's runtime isolation contract (Phase 25).

Two isolation levels exist (docs/architecture/challenge-evaluator.md#production-sandbox):

``container``
    The Phase 16 layers only: a hardened container on the host kernel
    (namespaces, seccomp, dropped capabilities, cgroups) plus per-slot
    unprivileged users and resource limits. Development and tests only: a
    kernel vulnerability reachable from the slot user would reach the host.

``gvisor``
    The same layers inside a gVisor sandbox (the ``runsc`` OCI runtime):
    submitted code talks to gVisor's user-space kernel, never to the host
    kernel. Required in production.

The isolation is not taken on trust from configuration: at start the service
attests it from inside the container, and in production anything but an
attested gVisor sandbox stops the service (fail closed; there is no fallback
to running code with less isolation). The attested level is published in the
heartbeat, and Laravel refuses to submit to an evaluator whose heartbeat
reports less than the level it requires.
"""

from __future__ import annotations

import re
import sys
from dataclasses import dataclass
from pathlib import Path

LEVELS = ("container", "gvisor")

# gVisor's user-space kernel reports a fixed, synthetic version string (the
# host's is never visible inside the sandbox). An upgrade that changes it makes
# attestation fail, which stops the service: fail closed, never open.
GVISOR_VERSION = re.compile(r"^Linux version \S+ #1 SMP Sun Jan 10 15:06:54 PST 2016\b")


class IsolationError(RuntimeError):
    """The runtime does not provide the isolation the configuration requires."""


@dataclass(frozen=True)
class Attestation:
    level: str
    production: bool


def detect(proc_version: Path = Path("/proc/version")) -> str:
    """The isolation level this process actually runs under."""
    try:
        version = proc_version.read_text(encoding="ascii", errors="replace")
    except OSError:
        return "container"
    return "gvisor" if GVISOR_VERSION.match(version) else "container"


def attest(required: str, production: bool, proc_version: Path = Path("/proc/version")) -> Attestation:
    """Checks the configured isolation against the runtime; raises instead of degrading."""
    if required not in LEVELS:
        raise IsolationError(f"unknown isolation level {required!r}")
    if production and required != "gvisor":
        raise IsolationError("production requires EVALUATOR_ISOLATION=gvisor; refusing to execute code with container isolation only")
    actual = detect(proc_version)
    if LEVELS.index(actual) < LEVELS.index(required):
        raise IsolationError(f"EVALUATOR_ISOLATION={required} but the runtime provides {actual}; refusing to execute code")
    return Attestation(level=actual, production=production)


def main(proc_version: Path = Path("/proc/version")) -> int:
    """`python3 -m evaluator.isolation`: the host attestation (`make prod-evaluator-attest`).

    Prints the attested level and exits 0 only when the runtime is gVisor, the
    level production requires; anything else prints the reason and exits 1.
    """
    try:
        attested = attest("gvisor", True, proc_version)
    except IsolationError as error:
        sys.stderr.write(f"attestation failed: {error}\n")
        return 1
    sys.stdout.write(f"{attested.level}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
