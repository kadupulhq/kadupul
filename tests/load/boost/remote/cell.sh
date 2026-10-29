#!/usr/bin/env bash
# Runs one cell of the matrix on the application host and leaves its raw
# output in /var/lib/boost-load/runs/<cell>/.
#
# Usage: cell.sh <cell> <scale> <tree> <mode> <parallel> <consumers> <fault> <restore> <dump>
#   tree      feat | main (the code tree Boost runs from)
#   mode      children | queue (boost_processing_mode)
#   fault     none | noconsumer | killconsumer | termmaster | dbrestart
#             (dbrestart is injected by run.sh on the database host once this
#             script writes the midrun marker)
#   restore   1 restores the scale's snapshot first; 0 runs on the current state;
#             2 (cycle mode) restores the comparison set's RRD files from the
#             stash and queues the next cycle for every source
#   dump      1 hashes `rrdtool dump` of the comparison set afterwards
#
# Optional environment:
#   MAX_RUNTIME  sets boost_rrd_update_max_runtime for this cell. The master
#                waits three times this for queue partitions, so the
#                no-consumer cell uses a short value instead of the hour the
#                default of 1200 s implies.
#   FAULT_DELAY  seconds between the first child appearing and the injected
#                fault (default 2)
#   GEN_WORKERS  generate.php workers in cycle mode (default 16)
#   WAIT_GO      1 makes the cell write "ready" after the restore and wait for
#                run.sh to create "go", so the database recorder starts on a
#                restored server. "measured" marks the end of the measured
#                window, before the dump hashing.
set -euo pipefail
# shellcheck source-path=SCRIPTDIR source=common.sh
source "$(dirname "$0")/common.sh"

cell="$1"
scale="$2"
tree="$3"
mode="$4"
parallel="$5"
consumers="$6"
fault="$7"
restore="$8"
dump="$9"
fault_delay="${FAULT_DELAY:-2}"

out="$STATE/runs/$cell"
snap="$STATE/snap/$scale"

# One cell at a time: two would share the database, the RRD files and the
# output directory.
exec 9> "$STATE/cell.lock"
if ! flock -w 120 9; then
    echo "another cell is running; refusing to start $cell" >&2
    exit 3
fi

rm -rf "$out"
install -d "$out"
exec > >(tee -a "$out/cell.log") 2>&1

monitor_pids=()
consumer_pids=()

cleanup() {
    local rc=$?
    for pid in "${monitor_pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    # run.sh stops waiting when it sees this without the marker it expects.
    echo "$rc" > "$out/exited"
}
trap cleanup EXIT

stop_consumers() {
    local pid
    # Stop the supervisors first so they do not start a replacement.
    for pid in "${consumer_pids[@]}"; do
        kill -TERM "$pid" 2>/dev/null || true
    done
    pkill -TERM -f '^/usr/bin/php8.4 bin/console messenger:consume boost' 2>/dev/null || true
    for _ in $(seq 1 60); do
        if ! pgrep -f '^/usr/bin/php8.4 bin/console messenger:consume boost' >/dev/null; then
            break
        fi
        sleep 1
    done
    pkill -KILL -f '^/usr/bin/php8.4 bin/console messenger:consume boost' 2>/dev/null || true
    consumer_pids=()
}

# One supervised consumer slot, restarted like kadupul-boost-worker.service
# (Restart=always, RestartSec=5s).
supervise_consumer() {
    local k="$1" stopping=0
    trap 'stopping=1' TERM
    while [ "$stopping" = 0 ]; do
        (cd "$APP" && exec runuser -u kadupul -- "$PHP" bin/console messenger:consume boost \
            --time-limit=3600 --memory-limit=256M -vv) >> "$out/consumer-$k.log" 2>&1 &
        wait $! || true
        [ "$stopping" = 0 ] || break
        printf '%s consumer %s exited; restarting in 5 s\n' "$(date +%s)" "$k" >> "$out/consumer-restarts.log"
        sleep 5
    done
}

boost_procs() {
    # Every Boost process except the consumers: master, children, partition wrappers.
    pgrep -af 'poller_boost\.php|legacy-boost-partition\.php' | grep -v -e pgrep -e runuser || true
}


# Boost's own process registry and any worker left from an earlier cell must
# not leak into this one.
pkill -KILL -f '^/usr/bin/php8.4 bin/console messenger:consume boost' 2>/dev/null || true
pkill -KILL -f 'poller_boost\.php|legacy-boost-partition\.php' 2>/dev/null || true

use_tree "$tree"
printf '%s\n' "$tree" > "$APP/.tree"

if [ "$restore" = 1 ]; then
    log "restoring snapshot $scale"
    t=$(date +%s)
    db -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    zstd -dcq "$snap/db.sql.zst" | db "$DB_NAME"
    find "$APP/rra" -mindepth 1 -delete
    zstd -dcq "$snap/rra.tar.zst" | runuser -u kadupul -- tar -C "$APP" -xf -
    log "restore took $(( $(date +%s) - t )) s"
fi

cycle=""
if [ "$restore" = 2 ]; then
    left="$(db -N "$DB_NAME" -e "SELECT COUNT(*) FROM poller_output_boost")"
    archives="$(db -N "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = SCHEMA() AND TABLE_NAME LIKE 'poller_output_boost\\_arch\\_%'")"
    [ "$left" = 0 ] && [ "$archives" = 0 ] || { log "the previous cycle left $left rows and $archives archive tables"; exit 1; }
    runuser -u kadupul -- tar -C "$APP" -xf "$snap/sample-rra.tar"
    cycle=$(( $(db -N "$DB_NAME" -e "SELECT value FROM kbl_meta WHERE name = 'cycle'") + 1 ))
    log "queueing cycle $cycle"
    t=$(date +%s)
    as_poller "$PHP" "$HARNESS/generate.php" --cycle="$cycle" --workers="${GEN_WORKERS:-16}"
    db "$DB_NAME" -e "UPDATE kbl_meta SET value = '$cycle' WHERE name = 'cycle'"
    printf 'cycle=%s\ngenerate_seconds=%s\n' "$cycle" "$(( $(date +%s) - t ))" >> "$out/cycle.env"
fi

db "$DB_NAME" -e "
    INSERT INTO settings (name, value) VALUES ('boost_processing_mode', '$mode'), ('boost_parallel', '$parallel')
    ON DUPLICATE KEY UPDATE value = VALUES(value);"
if [ -n "${MAX_RUNTIME:-}" ]; then
    db "$DB_NAME" -e "INSERT INTO settings (name, value) VALUES ('boost_rrd_update_max_runtime', '$MAX_RUNTIME') ON DUPLICATE KEY UPDATE value = VALUES(value)"
else
    db "$DB_NAME" -e "DELETE FROM settings WHERE name = 'boost_rrd_update_max_runtime'"
fi
reset_app_file "$APP/log/cacti.log"

sync
if [ -w /proc/sys/vm/drop_caches ]; then
    echo 3 > /proc/sys/vm/drop_caches
fi

queued="$(db -N "$DB_NAME" -e "SELECT COUNT(*) FROM poller_output_boost")"
archived="$(db -N "$DB_NAME" -e "SELECT COALESCE(SUM(TABLE_ROWS), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = SCHEMA() AND TABLE_NAME LIKE 'poller_output_boost\\_arch\\_%'")"
if [ -n "$cycle" ]; then
    sources="$(cat "$snap/sources")"
else
    sources="$(db -N "$DB_NAME" -e "SELECT COUNT(DISTINCT local_data_id) FROM poller_output_boost")"
fi
printf 'max_runtime=%s\nfault_delay=%s\n' "${MAX_RUNTIME:-default}" "$fault_delay" > "$out/params.env"
printf 'cell=%s\nscale=%s\ntree=%s\nmode=%s\nparallel=%s\nconsumers=%s\nfault=%s\nrestore=%s\nqueued_rows=%s\narchived_rows_estimate=%s\nqueued_sources=%s\n' \
    "$cell" "$scale" "$tree" "$mode" "$parallel" "$consumers" "$fault" "$restore" "$queued" "$archived" "$sources" >> "$out/params.env"
if [ -n "$cycle" ]; then
    cat "$out/cycle.env" >> "$out/params.env"
fi

touch "$out/ready"
if [ "${WAIT_GO:-0}" = 1 ]; then
    for _ in $(seq 1 1200); do
        [ -f "$out/go" ] && break
        sleep 0.5
    done
    [ -f "$out/go" ] || { log "no go signal"; exit 1; }
fi

# Host and process monitors, 1 s resolution.
vmstat -n -t 1 > "$out/vmstat.log" &
monitor_pids+=($!)
LC_ALL=C sar -u 1 > "$out/sar-cpu.log" 2>&1 &
monitor_pids+=($!)
bash "$HARNESS/remote/procs.sh" > "$out/procs.csv" &
monitor_pids+=($!)

if [ "$mode" = queue ] && [ "$fault" != noconsumer ]; then
    for k in $(seq 1 "$consumers"); do
        supervise_consumer "$k" &
        consumer_pids+=($!)
    done
    sleep 2
fi

log "starting master: $mode, boost_parallel=$parallel, consumers=$consumers, fault=$fault, $queued rows queued"
start=$(date +%s.%N)
(cd "$APP" && exec runuser -u kadupul -- "$PHP" poller_boost.php --force) > "$out/master.log" 2>&1 &
master_wrapper=$!

master_php=""
midrun=0
injected=0
while kill -0 "$master_wrapper" 2>/dev/null; do
    if [ -z "$master_php" ]; then
        master_php="$(pgrep -f -n 'poller_boost\.php --force' -P "$master_wrapper" || pgrep -f -n '^/usr/bin/php8.4 poller_boost\.php --force' || true)"
    fi
    if [ "$midrun" = 0 ] && pgrep -f 'poller_boost\.php --child=' >/dev/null; then
        midrun=1
        printf '%s\n' "$(date +%s.%N)" > "$out/midrun"
        log "children running"
    fi
    if [ "$midrun" = 1 ] && [ "$injected" = 0 ]; then
        case "$fault" in
            killconsumer)
                sleep "$fault_delay"
                victim=""
                for php_pid in $(pgrep -f '^/usr/bin/php8.4 bin/console messenger:consume boost'); do
                    if pgrep -P "$php_pid" >/dev/null; then
                        victim="$php_pid"
                        break
                    fi
                done
                if [ -n "$victim" ]; then
                    kill -KILL "$victim"
                    log "fault: SIGKILL consumer pid $victim while it ran a partition"
                    printf 'killed_consumer=%s\n' "$victim" >> "$out/params.env"
                    injected=1
                fi
                ;;
            termmaster)
                sleep "$fault_delay"
                if [ -n "$master_php" ] && kill -0 "$master_php" 2>/dev/null; then
                    kill -TERM "$master_php"
                    log "fault: SIGTERM master pid $master_php while it waited on partitions"
                    injected=1
                fi
                ;;
            *)
                injected=1
                ;;
        esac
    fi
    sleep 0.2
done
master_rc=0
wait "$master_wrapper" || master_rc=$?
end=$(date +%s.%N)
wall="$(awk -v a="$start" -v b="$end" 'BEGIN { printf "%.2f", b - a }')"
printf 'master_rc=%s\nstart=%s\nend=%s\nwall_seconds=%s\n' "$master_rc" "$start" "$end" "$wall" >> "$out/params.env"
log "master exited $master_rc after $wall s"

boost_procs > "$out/procs-at-master-exit.txt"
# Partitions already running finish on their own; give them time, then record
# what is still alive.
for _ in $(seq 1 600); do
    if [ -z "$(boost_procs)" ]; then
        break
    fi
    sleep 1
done
boost_procs > "$out/leftover-procs.txt"
pgrep -af '^/usr/bin/php8.4 bin/console messenger:consume boost' > "$out/consumers-at-end.txt" || true
stop_consumers

for pid in "${monitor_pids[@]}"; do
    kill "$pid" 2>/dev/null || true
done
monitor_pids=()
touch "$out/measured"

db "$DB_NAME" -e "
    SELECT name, value FROM settings WHERE name IN ('boost_poller_status', 'boost_active_run', 'boost_processing_mode', 'boost_parallel', 'stats_boost', 'boost_last_run_time', 'boost_next_run_time') ORDER BY name;" > "$out/settings.tsv" 2>&1 || true
db "$DB_NAME" -e "
    SELECT 'poller_output_boost' AS tbl, COUNT(*) AS rows_left FROM poller_output_boost;" > "$out/leftover-rows.tsv" 2>&1 || true
for table in $(db -N "$DB_NAME" -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = SCHEMA() AND TABLE_NAME LIKE 'poller_output_boost\\_arch\\_%'"); do
    db -N "$DB_NAME" -e "SELECT '$table', COUNT(*) FROM \`$table\`" >> "$out/leftover-rows.tsv" 2>&1 || true
done
db "$DB_NAME" -e "SELECT id, queue_name, created_at, available_at, delivered_at FROM kadupul_messenger_messages" > "$out/messenger-left.tsv" 2>&1 || true
db "$DB_NAME" -e "SELECT pid, tasktype, taskname, taskid, started FROM processes WHERE tasktype = 'boost'" > "$out/processes-table.tsv" 2>&1 || true
db "$DB_NAME" -e "SELECT run_id, process_handler, status FROM poller_output_boost_processes ORDER BY process_handler" > "$out/boost-processes.tsv" 2>&1 || true
db "$DB_NAME" -e "SELECT COUNT(*) AS rejected FROM poller_output_rejected" > "$out/rejected.tsv" 2>/dev/null || true
copy_app_file "$APP/log/cacti.log" "$out/cacti.log"

# Every queued source ends at t0 + 1800; any RRD file that does not lost samples.
t0="$(db -N "$DB_NAME" -e "SELECT value FROM kbl_meta WHERE name = 't0'" 2>/dev/null || true)"
if [ -n "$t0" ]; then
    log "checking the last update of every RRD file"
    find "$APP/rra" -name 'kbl_*.rrd' | sort | sed 's/^/last /' | runuser -u kadupul -- rrdtool - 2>/dev/null \
        | grep -v '^OK' | paste -d' ' <(find "$APP/rra" -name 'kbl_*.rrd' | sort) - > "$out/rrd-last.txt" || true
    if [ -n "$cycle" ]; then
        # The comparison set replays cycle 1; everything else is at this cycle.
        awk -v sample="$snap/sample.txt" -v first=$((t0 + 3600)) -v want=$((t0 + cycle * 3600)) '
            BEGIN { while ((getline f < sample) > 0) s[f] = 1 }
            $2 != ((($1) in s) ? first : want)' "$out/rrd-last.txt" > "$out/rrd-not-final.txt"
    else
        awk -v want=$((t0 + 1800)) '$2 != want' "$out/rrd-last.txt" > "$out/rrd-not-final.txt"
    fi
    printf 'rrd_files=%s\nrrd_not_final=%s\n' "$(wc -l < "$out/rrd-last.txt")" "$(wc -l < "$out/rrd-not-final.txt")" >> "$out/params.env"
    rm -f "$out/rrd-last.txt"
fi

if [ "$dump" = 1 ]; then
    log "hashing rrdtool dump of $(wc -l < "$snap/sample.txt") RRD files"
    install -d "$out/dumps"
    # shellcheck disable=SC2016
    xargs -P 8 -I{} bash -c 'f="$1"; d="$2/dumps/$(basename "$f").xml.zst"; runuser -u kadupul -- rrdtool dump "$f" | tee >(zstd -q -o "$d") | sha256sum | sed "s|-\$|$f|"' _ {} "$out" \
        < "$snap/sample.txt" | sort -k2 > "$out/dump-hashes.txt"
    tar -C "$out" -cf "$out/dumps.tar" dumps && rm -rf "$out/dumps"
fi

log "cell $cell done"
# Background children (tee among them) inherit fd 9; unlock explicitly so the
# next cell does not wait for them to exit.
flock -u 9
touch "$out/done"
