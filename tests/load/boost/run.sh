#!/usr/bin/env bash
# Runs the Boost load and equivalence matrix and writes
# results/<run id>/summary.md plus the raw files behind it.
#
# Usage:
#   run.sh <run id>     against the droplets provision.sh created; tears them
#                       down when it exits, unless KEEP=1
#   run.sh --docker     against the local dry-run containers (local-up.sh)
#
# Environment: SCALES (default 10000 50000 100000), FAULT_SCALE (50000),
# BASELINE_SCALE (10000), SEED (20261027), RRD_WORKERS (8), MAIN_REF
# (origin/main), FEAT_REF (HEAD), KEEP (0), CELL_FILTER (an extended regular
# expression; only matching cells run, for example '^50000-(A|F4.*)$').
#
# Large scales run in cycle mode (see remote/seed.sh): LARGE_SCALES (default
# none), LARGE_RRD_WORKERS (32), and the cells as name:mode:parallel:consumers
# in LARGE_CELLS_<scale> or LARGE_CELLS. Each large cell is one Boost cycle of
# 12 steps for every source, with boost_rrd_update_max_runtime at 4800 s.
#
# RESUME=1 continues on droplets that already ran: it skips building, shipping
# and deploying (a redeploy would delete the RRD files inside the app tree)
# and the seeding of any large scale whose stash already exists.
set -euo pipefail
# shellcheck source-path=SCRIPTDIR
source "$(dirname "$0")/lib.sh"

SCALES="${SCALES-10000 50000 100000}"
FAULT_SCALE="${FAULT_SCALE:-50000}"
BASELINE_SCALE="${BASELINE_SCALE:-10000}"
SEED="${SEED:-20261027}"
RRD_WORKERS="${RRD_WORKERS:-8}"
MAIN_REF="${MAIN_REF:-origin/main}"
FEAT_REF="${FEAT_REF:-HEAD}"
KEEP="${KEEP:-0}"
RESUME="${RESUME:-0}"
LARGE_SCALES="${LARGE_SCALES:-}"
LARGE_RRD_WORKERS="${LARGE_RRD_WORKERS:-32}"
LARGE_CELLS="${LARGE_CELLS:-A16:children:16:0 B16:queue:16:16}"
CELL_FILTER="${CELL_FILTER:-}"

if [ "${1:-}" = --docker ]; then
    TARGET=docker
    RUN_ID="local-$(date -u +%Y%m%d%H%M)"
    RUN_STATE="$(state_dir "$RUN_ID")"
    install -d -m 700 "$RUN_STATE"
else
    TARGET="do"
    RUN_ID="${1:?usage: run.sh <run id> | --docker}"
    RUN_STATE="$(state_dir "$RUN_ID")"
    [ -f "$RUN_STATE/state.env" ] || die "no state for $RUN_ID; run provision.sh first"
fi
RESULTS="$RESULTS_ROOT/$RUN_ID"
install -d "$RESULTS/cells" "$RESULTS/db" "$RESULTS/equivalence"
exec > >(tee -a "$RESULTS/run.log") 2>&1

finish() {
    local rc=$?
    if [ "$TARGET" = "do" ] && [ "$KEEP" != 1 ]; then
        log "tearing down $RUN_ID (exit status $rc)"
        if "$HERE/teardown.sh" "$RUN_ID" --reason "run.sh exit $rc"; then
            grep -E 'gone:|REMAINS:' "$RUN_STATE/teardown.log" | tail -5 > "$RESULTS/teardown-verification.txt" || true
        fi
    fi
}
trap finish EXIT
trap 'exit 130' INT TERM

# --- build -----------------------------------------------------------------

if [ "$RESUME" != 1 ]; then

composer_cmd() {
    if command -v mise >/dev/null; then
        (cd "$REPO" && mise exec -- composer "$@")
    else
        composer "$@"
    fi
}

build_tree() {
    local ref="$1" name="$2" sha dir
    sha="$(git -C "$REPO" rev-parse "$ref")"
    dir="$STATE_ROOT/build/$sha"
    if [ ! -f "$dir.tgz" ]; then
        log "building $name from $ref ($sha)"
        rm -rf "$dir"
        install -d "$dir"
        git -C "$REPO" archive "$sha" | tar -x -C "$dir"
        # Production dependencies only. Extensions this machine lacks exist on the droplet.
        composer_cmd install --working-dir="$dir" --no-dev --no-interaction --prefer-dist --no-progress \
            --ignore-platform-req='ext-*' > "$dir.composer.log" 2>&1
        pack -C "$dir" -czf "$dir.tgz.tmp" . && mv "$dir.tgz.tmp" "$dir.tgz"
        rm -rf "$dir"
    fi
    printf '%s=%s\n' "$name" "$sha" >> "$RESULTS/code.env"
    BUILT="$dir.tgz"
}

: > "$RESULTS/code.env"
build_tree "$FEAT_REF" feat
feat_tgz="$BUILT"
build_tree "$MAIN_REF" main
main_tgz="$BUILT"
if [ -n "$(git -C "$REPO" status --porcelain -- tests/load/boost)" ]; then
    echo 'harness=working tree (uncommitted changes)' >> "$RESULTS/code.env"
else
    echo "harness=$(git -C "$REPO" rev-parse HEAD)" >> "$RESULTS/code.env"
fi

# --- ship and deploy ---------------------------------------------------------

log "shipping code and harness"
host_exec app 'install -d -m 0755 /var/lib/boost-load && rm -rf /usr/local/lib/boost-load && install -d -m 0755 /usr/local/lib/boost-load'
host_put app "$feat_tgz" /var/lib/boost-load/feat.tgz
host_put app "$main_tgz" /var/lib/boost-load/main.tgz
pack -C "$HERE" --exclude=results -cf - . | host_exec app 'tar --no-same-owner -C /usr/local/lib/boost-load -xf - && chmod -R go-w,a+rX /usr/local/lib/boost-load'
host_put db "$HERE/remote/db-monitor.sh" /usr/local/sbin/boost-load-db-monitor.sh
host_exec app 'bash /usr/local/lib/boost-load/remote/deploy.sh /var/lib/boost-load/feat.tgz app && bash /usr/local/lib/boost-load/remote/deploy.sh /var/lib/boost-load/main.tgz app.main'

if [ "$TARGET" = "do" ]; then
    # The address stays in the state file so run.log, which is kept with the
    # results, never contains it.
    log "live dashboards: ssh -i $RUN_STATE/id_ed25519 -N -L 3000:localhost:3000 root@\"\$(sed -n 's/^APP_IP=//p' $RUN_STATE/state.env)\""
    log "then open http://localhost:3000 as admin with the password in $RUN_STATE/grafana-admin-password"
fi

{
    echo "target=$TARGET"
    echo "app_cpus=$(host_exec app nproc)"
    echo "app_memory=$(host_exec app "free -g | awk '/Mem:/ {print \$2}'")G"
    echo "db_cpus=$(host_exec db nproc)"
    echo "db_memory=$(host_exec db "free -g | awk '/Mem:/ {print \$2}'")G"
    echo "kernel=$(host_exec app 'uname -r')"
    echo "php=$(host_exec app '/usr/bin/php8.4 -r "echo PHP_VERSION;"')"
    echo "rrdtool=$(host_exec app "rrdtool --version | head -1 | awk '{print \$2}'")"
    echo "mariadb=$(host_exec db "mariadb -N -e 'SELECT VERSION()'")"
} > "$RESULTS/environment.env"
host_exec db 'cat /etc/mysql/mariadb.conf.d/90-boost-load.cnf 2>/dev/null || cat /etc/mysql/conf.d/90-boost-load.cnf' > "$RESULTS/my.cnf" || true
fi

# --- cells -----------------------------------------------------------------

wait_for() {
    # wait_for <app file> <seconds>; fails early if the cell exited first.
    local file="$1" limit="$2" waited=0
    until host_exec app "test -f '$file'" 2>/dev/null; do
        if host_exec app "test -f '$(dirname "$file")/exited'" 2>/dev/null \
            && ! host_exec app "test -f '$file'" 2>/dev/null; then
            log "cell exited before $(basename "$file"): $(host_exec app "tail -5 /var/lib/boost-load/$(basename "$(dirname "$file")").out" 2>&1 | tr '\n' ' ')"
            return 1
        fi
        sleep 2
        waited=$((waited + 2))
        [ "$waited" -lt "$limit" ] || return 1
    done
}

restart_db() {
    if [ "$TARGET" = docker ]; then
        docker restart -t 5 kbl-db >/dev/null
    else
        host_exec db 'systemctl restart mariadb'
    fi
}

# run_cell <cell> <scale> <tree> <mode> <parallel> <consumers> <fault> <restore> <dump> [VAR=value ...]
run_cell() {
    local cell="$1" scale="$2" tree="$3" mode="$4" parallel="$5" consumers="$6" fault="$7" restore="$8" dump="$9"
    shift 9
    local dir="/var/lib/boost-load/runs/$cell"
    if [ -n "$CELL_FILTER" ] && ! [[ "$cell" =~ $CELL_FILTER ]]; then
        return 0
    fi
    log "cell $cell: scale $scale, $tree, $mode, boost_parallel=$parallel, consumers=$consumers, fault=$fault"
    host_exec app "rm -rf '$dir'; nohup env WAIT_GO=1 $* bash /usr/local/lib/boost-load/remote/cell.sh $cell $scale $tree $mode $parallel $consumers $fault $restore $dump >> /var/lib/boost-load/$cell.out 2>&1 < /dev/null &"
    wait_for "$dir/ready" 3600 || die "cell $cell never became ready"
    host_exec db "bash /usr/local/sbin/boost-load-db-monitor.sh start $cell cacti"
    host_exec app "touch '$dir/go'"
    if [ "$fault" = dbrestart ]; then
        wait_for "$dir/midrun" 3600 || die "cell $cell never started its children"
        log "cell $cell: restarting MariaDB"
        restart_db
    fi
    wait_for "$dir/measured" 14400 || die "cell $cell did not finish"
    host_exec db "bash /usr/local/sbin/boost-load-db-monitor.sh stop $cell cacti"
    wait_for "$dir/done" 3600 || die "cell $cell did not finish its dump"
    host_get app "$dir" "$RESULTS/cells"
    host_get db "/var/lib/boost-load-db/$cell" "$RESULTS/db"
    [ "$(grep -c '^master_rc=' "$RESULTS/cells/$cell/params.env")" = 1 ] || die "cell $cell recorded more than one master run"
    log "cell $cell: $(grep -E '^(master_rc|wall_seconds)=' "$RESULTS/cells/$cell/params.env" | tr '\n' ' ')"
}

# compare <cell> <reference cell>
compare() {
    local cell="$1" ref="$2"
    local out="$RESULTS/equivalence/$cell-vs-$ref.txt"
    local a="$RESULTS/cells/$ref/dump-hashes.txt" b="$RESULTS/cells/$cell/dump-hashes.txt"
    if [ -n "$CELL_FILTER" ] && ! [[ "$cell" =~ $CELL_FILTER ]]; then
        return 0
    fi
    if [ ! -s "$a" ] || [ ! -s "$b" ]; then
        echo "missing dump hashes" > "$out"
    elif diff -q <(awk '{print $2, $1}' "$a") <(awk '{print $2, $1}' "$b") >/dev/null; then
        echo "identical $(wc -l < "$a") files" > "$out"
    else
        { echo "DIFFERENT"; diff <(awk '{print $2, $1}' "$a") <(awk '{print $2, $1}' "$b") || true; } > "$out"
    fi
    log "equivalence $cell vs $ref: $(head -1 "$out")"
}

wall_of() {
    sed -n 's/^wall_seconds=//p' "$RESULTS/cells/$1/params.env"
}

for scale in $SCALES; do
    log "seeding $scale data sources"
    host_exec app "bash /usr/local/lib/boost-load/remote/seed.sh $scale $SEED $RRD_WORKERS" > "$RESULTS/seed-$scale.log" 2>&1 \
        || die "seeding $scale failed; see $RESULTS/seed-$scale.log"
    tail -1 "$RESULTS/seed-$scale.log"

    run_cell "$scale-A" "$scale" feat children 8 0 none 1 1
    run_cell "$scale-B" "$scale" feat queue 8 8 none 1 1
    compare "$scale-B" "$scale-A"
    run_cell "$scale-C" "$scale" feat queue 16 8 none 1 1
    compare "$scale-C" "$scale-A"

    if [ "$scale" = "$BASELINE_SCALE" ]; then
        run_cell "$scale-A-main" "$scale" main children 8 0 none 1 1
        compare "$scale-A-main" "$scale-A"
    fi

    if [ "$scale" = "$FAULT_SCALE" ]; then
        # Bound the queue master's wait (3 x boost_rrd_update_max_runtime) so a
        # stalled fault cell ends in minutes, while staying well above the
        # time a healthy partition needs at this scale.
        a_wall="$(wall_of "$scale-A")"
        runtime="$(awk -v w="$a_wall" 'BEGIN { r = int(w * 2) + 1; print (r < 60 ? 60 : r) }')"
        delay="$(awk -v w="$a_wall" 'BEGIN { d = (w - 7) / 4; print (d < 1 ? 1 : d) }')"
        log "fault cells: MAX_RUNTIME=$runtime, FAULT_DELAY=$delay"

        run_cell "$scale-F1-noconsumer" "$scale" feat queue 8 8 noconsumer 1 0 "MAX_RUNTIME=$runtime"
        run_cell "$scale-F1-recovery" "$scale" feat queue 8 8 none 0 1
        compare "$scale-F1-recovery" "$scale-A"
        run_cell "$scale-F2-killconsumer" "$scale" feat queue 8 8 killconsumer 1 0 "MAX_RUNTIME=$runtime" "FAULT_DELAY=$delay"
        run_cell "$scale-F2-recovery" "$scale" feat queue 8 8 none 0 1
        compare "$scale-F2-recovery" "$scale-A"
        run_cell "$scale-F3-termmaster" "$scale" feat queue 8 8 termmaster 1 0 "MAX_RUNTIME=$runtime" "FAULT_DELAY=$delay"
        run_cell "$scale-F3-recovery" "$scale" feat queue 8 8 none 0 1
        compare "$scale-F3-recovery" "$scale-A"
        run_cell "$scale-F4-dbrestart" "$scale" feat queue 8 8 dbrestart 1 0 "MAX_RUNTIME=$runtime" "FAULT_DELAY=$delay"
        run_cell "$scale-F4-recovery" "$scale" feat queue 8 8 none 0 1
        compare "$scale-F4-recovery" "$scale-A"
        # The same restart in children mode, for comparison.
        run_cell "$scale-F4A-dbrestart" "$scale" feat children 8 0 dbrestart 1 0 "MAX_RUNTIME=$runtime"
        run_cell "$scale-F4A-recovery" "$scale" feat children 8 0 none 0 1
        compare "$scale-F4A-recovery" "$scale-A"
    fi
done

for scale in $LARGE_SCALES; do
    if [ "$RESUME" = 1 ] && host_exec app "test -f /var/lib/boost-load/snap/$scale/sample-rra.tar"; then
        log "resuming $scale on the existing seed"
    else
        log "seeding $scale data sources in cycle mode"
        host_exec app "bash /usr/local/lib/boost-load/remote/seed.sh $scale $SEED $LARGE_RRD_WORKERS cycle" > "$RESULTS/seed-$scale.log" 2>&1 \
            || die "seeding $scale failed; see $RESULTS/seed-$scale.log"
        grep -E 'rows in|created RRD|done' "$RESULTS/seed-$scale.log" | tail -12
    fi
    cells_var="LARGE_CELLS_$scale"
    first=""
    for spec in ${!cells_var:-$LARGE_CELLS}; do
        IFS=: read -r name mode parallel consumers <<< "$spec"
        run_cell "$scale-$name" "$scale" feat "$mode" "$parallel" "$consumers" none 2 1 MAX_RUNTIME=4800
        if [ -z "$first" ]; then
            first="$scale-$name"
        else
            compare "$scale-$name" "$first"
        fi
    done
done

# --- results -----------------------------------------------------------------

if [ "$TARGET" = "do" ]; then
    # The raw files must carry no addresses: replace any that slipped in.
    for key in APP_IP DB_IP APP_PRIV DB_PRIV; do
        value="$(state_get "$RUN_STATE/state.env" "$key")"
        [ -n "$value" ] || continue
        { grep -rlF "$value" "$RESULTS" 2>/dev/null || true; } | while read -r file; do
            sed -i.bak "s/$(printf '%s' "$value" | sed 's/\./\\./g')/<$key>/g" "$file" && rm -f "$file.bak"
        done
    done
fi

log "writing summary"
if command -v mise >/dev/null; then
    (cd "$REPO" && mise exec -- php "$HERE/collect.php" "$RESULTS")
else
    php "$HERE/collect.php" "$RESULTS"
fi
log "results in $RESULTS/summary.md"
