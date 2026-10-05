#!/usr/bin/env bash
# One-time local setup for the CodeDNA Docker development environment.
#
#   * creates .env from .env.example if it does not exist;
#   * fills HOST_UID/HOST_GID and generates random LOCAL secrets for every
#     required secret that is still empty.
#
# Idempotent: values that are already set are never changed, so running it
# again is safe. Secrets are written only to .env (git-ignored) and are never
# printed.
set -euo pipefail

cd "$(dirname "$0")/.."

env_file=.env

if [[ ! -f "$env_file" ]]; then
    cp .env.example "$env_file"
    chmod 600 "$env_file"
    echo "setup: created $env_file from .env.example"
fi

# get_value NAME -> current value in .env (empty if unset or missing)
get_value() {
    grep -E "^$1=" "$env_file" | tail -n 1 | cut -d= -f2- || true
}

# set_if_empty NAME VALUE -> sets NAME only when its current value is empty
set_if_empty() {
    local name=$1 value=$2
    if [[ -n "$(get_value "$name")" ]]; then
        return
    fi
    if grep -qE "^$name=" "$env_file"; then
        # Values are hex/base64 (no '|' or '&'), so they are safe in sed.
        sed -i.bak -E "s|^$name=.*$|$name=$value|" "$env_file" && rm -f "$env_file.bak"
    else
        printf '%s=%s\n' "$name" "$value" >>"$env_file"
    fi
    echo "setup: set $name"
}

random_hex() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }
random_base64() { head -c "$1" /dev/urandom | base64 | tr -d '\n'; }

host_uid=$(id -u)
host_gid=$(id -g)
if [[ "$host_uid" == "0" ]]; then
    # Containers never run as root; fall back to the conventional first user.
    host_uid=1000
    host_gid=1000
fi

set_if_empty HOST_UID "$host_uid"
set_if_empty HOST_GID "$host_gid"
set_if_empty APP_KEY "base64:$(random_base64 32)"
set_if_empty DB_PASSWORD "$(random_hex 24)"
set_if_empty MINIO_ROOT_PASSWORD "$(random_hex 24)"
set_if_empty SOURCE_STORAGE_ACCESS_KEY_ID "codedna-app-$(random_hex 4)"
set_if_empty SOURCE_STORAGE_SECRET_ACCESS_KEY "$(random_hex 24)"
set_if_empty ANALYZER_HMAC_SECRET "$(random_hex 32)"

echo "setup: $env_file is ready (secrets are local-only and git-ignored)"
