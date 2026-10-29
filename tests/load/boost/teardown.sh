#!/usr/bin/env bash
# Deletes the DigitalOcean resources of one run and verifies that none remain.
#
# Droplets are selected by the run-id tag only. The firewall, SSH key, VPC
# and project cannot carry resource tags, so they are deleted by the IDs that
# provision.sh recorded, and only after checking that each one's name (and,
# for the firewall, its target tag) is the run id. Nothing is ever selected by
# a name pattern.
#
# Usage: teardown.sh <run id> [--reason text]
set -uo pipefail
# shellcheck source-path=SCRIPTDIR
source "$(dirname "$0")/lib.sh"

RUN_ID="${1:?usage: teardown.sh <run id>}"
reason="${3:-manual}"
[[ "$RUN_ID" =~ ^kbl-[0-9]{12}-[0-9a-f]{6}$ ]] || die "not a run id: $RUN_ID"
RUN_STATE="$(state_dir "$RUN_ID")"
STATE="$RUN_STATE/state.env"
[ -f "$STATE" ] || die "no state for $RUN_ID in $RUN_STATE"
exec > >(tee -a "$RUN_STATE/teardown.log") 2>&1
log "teardown of $RUN_ID ($reason)"

get() { state_get "$STATE" "$1"; }

# Droplet IDs carrying the run tag, one per line. Fails loudly on API errors.
tagged_droplets() {
    doctl compute droplet list --tag-name "$RUN_ID" -o json | jq -r '(. // [])[] | .id'
}

# 0 if the object exists, 1 if the API says Not Found, 2 on any other error.
exists() {
    local output
    if output="$("$@" -o json 2>&1)"; then
        return 0
    fi
    if echo "$output" | grep -qiE 'not found|404'; then
        return 1
    fi
    log "could not check: $* ($output)"
    return 2
}

# The object's name, retried because a transient API error must not leave a
# resource behind. Empty when the object is gone.
name_of() {
    local attempt output
    for attempt in 1 2 3 4 5; do
        if output="$("$@" -o json 2>&1)"; then
            echo "$output" | jq -r 'if type == "array" then .[0].name else empty end'
            return 0
        fi
        echo "$output" | grep -qiE 'not found|404' && return 0
        log "lookup failed (attempt $attempt): $*"
        sleep 3
    done
}

# Droplets: by tag. --tag-name deletes every droplet carrying the run tag.
if [ -n "$(tagged_droplets)" ]; then
    doctl compute droplet delete --tag-name "$RUN_ID" --force
fi
for _ in $(seq 1 60); do
    [ -z "$(tagged_droplets)" ] && break
    sleep 5
done

firewall_id="$(get FIREWALL_ID)"
if [ -n "$firewall_id" ]; then
    fw="$(doctl compute firewall get "$firewall_id" --output json 2>/dev/null || true)"
    if [ -n "$fw" ]; then
        if [ "$(echo "$fw" | jq -r 'if type == "array" then .[0].name else empty end')" = "$RUN_ID" ] && [ "$(echo "$fw" | jq -c 'if type == "array" then .[0].tags else empty end')" = "[\"$RUN_ID\"]" ]; then
            doctl compute firewall delete "$firewall_id" --force
        else
            log "firewall $firewall_id does not belong to $RUN_ID; left alone"
        fi
    fi
fi

key_id="$(get SSH_KEY_ID)"
if [ -n "$key_id" ]; then
    key_name="$(name_of doctl compute ssh-key get "$key_id")"
    if [ "$key_name" = "$RUN_ID" ]; then
        doctl compute ssh-key delete "$key_id" --force
    elif [ -n "$key_name" ]; then
        log "ssh key $key_id is named '$key_name', not $RUN_ID; left alone"
    fi
fi

vpc_id="$(get VPC_ID)"
if [ -n "$vpc_id" ] && [ "$(name_of doctl vpcs get "$vpc_id")" = "$RUN_ID" ]; then
    # A VPC can be deleted only once its droplets are gone.
    for _ in $(seq 1 24); do
        doctl vpcs delete "$vpc_id" --force 2>/dev/null && break
        sleep 5
    done
fi

project_id="$(get PROJECT_ID)"
if [ -n "$project_id" ]; then
    project="$(doctl projects get "$project_id" --output json 2>/dev/null || true)"
    if [ -n "$project" ] && [ "$(echo "$project" | jq -r 'if type == "array" then .[0].name else empty end')" = "$PROJECT_NAME" ] \
        && [ "$(echo "$project" | jq -r 'if type == "array" then .[0].description else empty end')" = "$RUN_ID" ]; then
        if [ "$(doctl projects resources list "$project_id" -o json | jq '(. // []) | length')" = 0 ]; then
            doctl projects delete "$project_id" --force
        else
            log "project $project_id still lists resources; left for a later teardown"
        fi
    fi
fi

doctl compute tag delete "$RUN_ID" --force >/dev/null 2>&1 || true

log "verification"
remaining=0
verify() {
    local what="$1"
    shift
    local rc=0
    exists "$@" || rc=$?
    case "$rc" in
        1) log "gone: $what" ;;
        0) log "REMAINS: $what"; remaining=1 ;;
        *) log "UNKNOWN: $what"; remaining=1 ;;
    esac
}
droplets_left="$(tagged_droplets)" || droplets_left="error"
if [ -z "$droplets_left" ]; then
    log "gone: droplets tagged $RUN_ID"
else
    log "REMAINS: droplets tagged $RUN_ID: $droplets_left"
    remaining=1
fi
[ -z "$firewall_id" ] || verify "firewall $firewall_id" doctl compute firewall get "$firewall_id"
[ -z "$key_id" ] || verify "ssh key $key_id" doctl compute ssh-key get "$key_id"
[ -z "$vpc_id" ] || verify "vpc $vpc_id" doctl vpcs get "$vpc_id"
[ -z "$project_id" ] || verify "project $project_id" doctl projects get "$project_id"

watchdog="$(get WATCHDOG_PID)"
if [ "$remaining" = 0 ] && [ -n "$watchdog" ] && [ "$reason" != watchdog ]; then
    # The watchdog is a shell waiting on sleep; stop the sleep, then the shell.
    pkill -P "$watchdog" 2>/dev/null || true
    kill "$watchdog" 2>/dev/null || true
    log "watchdog $watchdog stopped"
fi
state_set "$STATE" TEARDOWN_AT "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
state_set "$STATE" TEARDOWN_REMAINING "$remaining"
exit "$remaining"
