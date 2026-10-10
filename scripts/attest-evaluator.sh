#!/usr/bin/env bash
# Evaluator isolation attestation on a production host (make prod-evaluator-attest).
#
# Resolves the evaluator image and OCI runtime exactly as `docker compose up`
# would (docker-compose.prod.yml + the production env file + this shell's
# environment), then runs the image's own attestation under that runtime:
# `python3 -m evaluator.isolation` prints "gvisor" and exits 0 only inside a
# gVisor sandbox. Every other outcome exits non-zero (fail closed).
#
#   scripts/attest-evaluator.sh                          # /etc/codedna/production.env
#   CODEDNA_ENV_FILE=/path/to/production.env scripts/attest-evaluator.sh
#
# Proves only that this image runs under gVisor with this runtime; the other
# controls are checked by the healthy evaluator service (docs/operations/
# production-deployment.md). Prints no configuration values besides the image
# reference and the runtime name.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
ENV_FILE=${CODEDNA_ENV_FILE:-/etc/codedna/production.env}

fail() { echo "attest: FAIL: $*" >&2; exit 1; }

[[ -r "$ENV_FILE" ]] || fail "cannot read the production env file $ENV_FILE (set CODEDNA_ENV_FILE)"

# Only the evaluator's image and runtime leave the rendered configuration.
resolved=$(docker compose -f "$ROOT/docker-compose.prod.yml" --env-file "$ENV_FILE" config --format json 2>/dev/null |
    python3 -c '
import json, sys
evaluator = json.load(sys.stdin)["services"]["evaluator"]
print(evaluator.get("image", ""), evaluator.get("runtime", ""))
' 2>/dev/null) || fail "docker-compose.prod.yml does not render with $ENV_FILE (run docker compose config to see why)"
read -r image runtime <<< "$resolved"

[[ -n "$image" ]] || fail "the evaluator service has no image"
[[ "$runtime" == runsc* ]] || fail "the evaluator runtime is \"${runtime:-<none>}\", not a gVisor (runsc) runtime"
docker info --format '{{json .Runtimes}}' | grep -q "\"$runtime\"" ||
    fail "Docker has no runtime named \"$runtime\" (install gVisor: sudo runsc install && sudo systemctl restart docker)"
docker image inspect "$image" >/dev/null 2>&1 || fail "image $image is not on this host (build or pull it first)"

echo "attest: image $image, runtime $runtime"
level=$(docker run --rm --pull never --runtime "$runtime" --network none --entrypoint python3 "$image" -m evaluator.isolation) ||
    fail "the evaluator did not attest gVisor isolation under runtime $runtime"
[[ "$level" == gvisor ]] || fail "unexpected attestation output \"$level\""
echo "attest: PASS: $level"
