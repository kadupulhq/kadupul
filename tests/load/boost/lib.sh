#!/usr/bin/env bash
# Shared by provision.sh, run.sh and teardown.sh on the operator's machine.
#
# STATE_ROOT holds per-run secrets and identifiers (SSH key, database and
# Grafana passwords, droplet IPs). It is never inside the repository.
# RESULTS_ROOT holds the measurements; tests/load/boost/results/ is ignored by
# git, and nothing written there carries an IP, hostname or key.

# REPO and PROJECT_TAG are used by the scripts that source this file; remote
# commands are built locally on purpose.
# shellcheck disable=SC2034,SC2029
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
STATE_ROOT="${STATE_ROOT:-${XDG_STATE_HOME:-$HOME/.local/state}/kadupul-boost-load}"
RESULTS_ROOT="${RESULTS_ROOT:-$HERE/results}"
PROJECT_NAME="${PROJECT_NAME:-kadupul-boost-loadtest}"
PROJECT_TAG="kadupul-boost-loadtest"
REGION="${REGION:-sfo3}"
APP_SIZE="${APP_SIZE:-c-8}"
DB_SIZE="${DB_SIZE:-s-8vcpu-16gb}"
IMAGE="${IMAGE:-ubuntu-24-04-x64}"

log() {
    printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

# A tar stream without macOS extended attributes or AppleDouble files.
pack() {
    if tar --version 2>/dev/null | grep -q bsdtar; then
        COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata "$@"
    else
        tar "$@"
    fi
}

state_dir() {
    printf '%s/%s' "$STATE_ROOT" "$1"
}

# Reads KEY=VALUE lines written by this harness; never sources the file.
state_get() {
    local file="$1" key="$2"
    sed -n "s/^${key}=//p" "$file" | tail -1
}

state_set() {
    local file="$1" key="$2" value="$3"
    touch "$file"
    chmod 600 "$file"
    grep -v "^${key}=" "$file" > "$file.tmp" || true
    printf '%s=%s\n' "$key" "$value" >> "$file.tmp"
    mv "$file.tmp" "$file"
}

# Transport. TARGET=do uses SSH to the droplets recorded in the run state;
# TARGET=docker uses the local dry-run containers from local-up.sh.
# Sets SSH_OPTS and SSH_HOST for a role. Plain arrays keep this working on
# the bash 3.2 that macOS ships.
ssh_target() {
    local role="$1"
    # IdentityAgent=none: a local agent can intercept signing for this key and
    # refuse once it locks, which would strand a long run.
    SSH_OPTS=(-i "$RUN_STATE/id_ed25519" -o IdentitiesOnly=yes -o IdentityAgent=none -o BatchMode=yes
        -o StrictHostKeyChecking=accept-new -o "UserKnownHostsFile=$RUN_STATE/known_hosts"
        -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=6 -o LogLevel=ERROR)
    SSH_HOST="root@$(state_get "$RUN_STATE/state.env" "$(echo "$role" | tr '[:lower:]' '[:upper:]')_IP")"
}

host_exec() {
    # host_exec <app|db> <command...>
    local role="$1"
    shift
    if [ "$TARGET" = docker ]; then
        docker exec -i "kbl-$role" bash -c "$*"
    else
        ssh_target "$role"
        ssh "${SSH_OPTS[@]}" "$SSH_HOST" "$*"
    fi
}

host_put() {
    # host_put <app|db> <local path> <remote path>
    local role="$1" src="$2" dest="$3"
    if [ "$TARGET" = docker ]; then
        docker cp "$src" "kbl-$role:$dest"
    else
        ssh_target "$role"
        scp -q -r "${SSH_OPTS[@]}" "$src" "$SSH_HOST:$dest"
    fi
}

host_get() {
    # host_get <app|db> <remote dir> <local parent dir>; copies the directory.
    local role="$1" src="$2" dest="$3"
    install -d "$dest"
    if [ "$TARGET" = docker ]; then
        docker exec "kbl-$role" tar -C "$(dirname "$src")" --exclude=dumps.tar -cf - "$(basename "$src")" | tar -C "$dest" -xf -
    else
        ssh_target "$role"
        ssh "${SSH_OPTS[@]}" "$SSH_HOST" "tar -C '$(dirname "$src")' --exclude=dumps.tar -cf - '$(basename "$src")'" | tar -C "$dest" -xf -
    fi
}
