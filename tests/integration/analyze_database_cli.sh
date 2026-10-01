#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
cd "$(dirname "$0")/../.."
owned_container=''
cleanup() {
    if [[ -n "$owned_container" ]]; then
        docker rm --force --volumes "$owned_container" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
export ANALYZE_DB_PASSWORD
ANALYZE_DB_PASSWORD=$(php -r 'echo bin2hex(random_bytes(24));')
for mode in off on; do
    owned_container="workflow-analyze-cli-$$-$mode"
    args=(--skip-log-bin)
    if [[ "$mode" == on ]]; then args=(--log-bin=contract-bin --server-id=1); fi
    docker run --detach --name "$owned_container" --tmpfs /var/lib/mysql:rw,size=768m \
        --publish 127.0.0.1::3306 --env MARIADB_ROOT_PASSWORD="$ANALYZE_DB_PASSWORD" \
        mariadb:10.11 "${args[@]}" >/dev/null
    ready=false
    for attempt in {1..60}; do
        if docker exec --env MYSQL_PWD="$ANALYZE_DB_PASSWORD" "$owned_container" \
            mariadb-admin --host=127.0.0.1 --user=root ping --silent >/dev/null 2>&1; then ready=true; break; fi
        sleep 1
    done
    if [[ "$ready" != true ]]; then echo 'FAIL: disposable MariaDB did not become ready' >&2; exit 1; fi
    ANALYZE_DB_PORT=$(docker port "$owned_container" 3306/tcp | awk -F: '{print $NF}')
    export ANALYZE_DB_PORT
    echo "Testing production LTS analysis with binary logging $mode"
    ANALYZE_DB_BINLOG="$mode" php tests/integration/AnalyzeDatabaseCliContract.php
    cleanup
    owned_container=''
done
