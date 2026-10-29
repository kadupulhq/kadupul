#!/usr/bin/env bash
# Shared helpers for the scripts that run on the application host as root.
# The variables below are used by the scripts that source this file.
# shellcheck disable=SC2034
# shellcheck source=/dev/null
source /etc/boost-load.env

APP=/opt/kadupul/app
STATE=/var/lib/boost-load
HARNESS=/usr/local/lib/boost-load
PHP=/usr/bin/php8.4
CLIENT_CNF=/etc/boost-load.client.cnf

if [ ! -f "$CLIENT_CNF" ]; then
    umask 077
    printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASS" > "$CLIENT_CNF"
    umask 022
fi

db() {
    mariadb --defaults-extra-file="$CLIENT_CNF" "$@"
}

db_dump() {
    mariadb-dump --defaults-extra-file="$CLIENT_CNF" "$@"
}

as_poller() {
    runuser -u kadupul -- env KBL_APP="$APP" "$@"
}

# Root never writes through a path the poller account controls: a file there
# may have been replaced by a symlink. These helpers recreate or read such
# files without following one.
reset_app_file() {
    rm -f "$1"
    install -o kadupul -g kadupul -m 0644 /dev/null "$1"
}

copy_app_file() {
    # copy_app_file <file under $APP> <destination owned by root>
    runuser -u kadupul -- cat "$1" > "$2"
}

log() {
    printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"
}

# The tree Boost runs from sits at $APP; the other one waits beside it as
# $APP.<name>. Swapping by rename keeps the absolute RRD paths stored in
# poller_item valid for both.
use_tree() {
    local current
    current="$(cat "$APP/.tree" 2>/dev/null || echo feat)"
    if [ "$current" = "$1" ]; then
        return
    fi
    mv "$APP" "$APP.$current"
    mv "$APP.$1" "$APP"
    log "switched code tree from $current to $1"
}
