# CodeDNA challenge evaluator

Runs submitted challenge code in an isolated sandbox (Phase 16). Internal
service: no network at all, no HTTP port. It exchanges files with the Laravel
queue worker through a private spool volume.

- Architecture, isolation and protocol:
  [docs/architecture/challenge-evaluator.md](../docs/architecture/challenge-evaluator.md)
- What challenges are and how results are graded (in Laravel, never here):
  [docs/architecture/coding-challenges-v1.md](../docs/architecture/coding-challenges-v1.md)
- Decision: [ADR-008](../docs/decisions/ADR-008-coding-challenges.md)
- It never receives expected outputs and never writes anything but result
  files. Challenge completion ≠ CodeDNA improvement.
- Standard library only; the code is baked into the image (`make build`).
- Isolation contract (Phase 25): `EVALUATOR_ISOLATION` (`container` |
  `gvisor`) and `EVALUATOR_PRODUCTION`. In production the service attests a
  gVisor runtime at start and refuses to run otherwise; the heartbeat
  reports the attested level
  ([production sandbox](../docs/architecture/challenge-evaluator.md#production-sandbox)).
- Tests: `make test-evaluator` runs the protocol, isolation and sandbox
  security tests inside the running evaluator container. Lint:
  `make lint-evaluator` (ruff, mypy).
