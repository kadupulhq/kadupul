#!/usr/bin/env bash
# Records MariaDB behaviour for one cell on the database host. Runs as root
# and talks to the server over its Unix socket, so it needs no grants and adds
# no network traffic. run.sh starts it after the snapshot restore and stops it
# once the master and its partitions have finished.
#
# Usage: db-monitor.sh start <cell> <schema> | stop <cell> <schema>
# Output: /var/lib/boost-load-db/<cell>/
set -euo pipefail

action="$1"
cell="$2"
schema="$3"
out="/var/lib/boost-load-db/$cell"
slow_log="$(mariadb -N -e "SELECT @@slow_query_log_file")"

# Innodb_deadlocks must stay 12th: the sampler watches that column.
status_vars=(Questions Com_select Com_insert Com_update Com_delete Com_replace Threads_running Threads_connected
    Innodb_row_lock_waits Innodb_row_lock_time Innodb_row_lock_current_waits Innodb_deadlocks Innodb_buffer_pool_reads
    Innodb_buffer_pool_read_requests Innodb_buffer_pool_pages_dirty Innodb_os_log_written Innodb_data_fsyncs
    Created_tmp_disk_tables Handler_read_rnd_next Aborted_connects Max_used_connections Slow_queries Uptime)

# One line of $status_vars values in that order, or nothing if the server is down.
status_values() {
    local in_list
    in_list="$(printf "'%s'," "${status_vars[@]}")"
    # FIELD() keeps the column order of $status_vars.
    mariadb -N -e "SELECT GROUP_CONCAT(VARIABLE_VALUE ORDER BY FIELD(VARIABLE_NAME, ${in_list%,}) SEPARATOR ',') FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME IN (${in_list%,})" 2>/dev/null || true
}

sample_status() {
    echo "epoch,$(IFS=,; echo "${status_vars[*]}")"
    local last_deadlocks=""
    while true; do
        local now values deadlocks
        now="$(date +%s.%N)"
        values="$(status_values)"
        if [ -n "$values" ] && [ "$values" != NULL ]; then
            echo "$now,$values"
            deadlocks="$(echo "$values" | cut -d, -f12)"
            if [ -n "$last_deadlocks" ] && [ "$deadlocks" != "$last_deadlocks" ]; then
                mariadb -e 'SHOW ENGINE INNODB STATUS\G' > "$out/innodb-status-deadlock-$(date +%s).txt" 2>&1 || true
            fi
            last_deadlocks="$deadlocks"
        fi
        sleep 5
    done
}

sample_tables() {
    local prom_dir=/var/lib/prometheus/node-exporter
    echo 'epoch,table,rows_estimate,data_bytes,index_bytes,rows_exact'
    while true; do
        local now rows
        now="$(date +%s.%N)"
        # TABLE_ROWS is InnoDB's estimate; counting the archives exactly every
        # 5 s would add the very scans Boost itself runs on them.
        rows="$(mariadb -N -B -e "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '$schema' AND (TABLE_NAME LIKE 'poller_output_boost%' OR TABLE_NAME = 'kadupul_messenger_messages')" 2>/dev/null || true)"
        if [ -d "$prom_dir" ]; then
            # Live Grafana panels read these through node-exporter's textfile collector.
            printf '%s\n' "$rows" | awk -F '\t' 'NF == 4 {
                printf "kbl_boost_table_rows{table=\"%s\"} %s\n", $1, $2
                printf "kbl_boost_table_bytes{table=\"%s\"} %s\n", $1, $3 + $4
            }' > "$prom_dir/kbl.prom.tmp" && mv "$prom_dir/kbl.prom.tmp" "$prom_dir/kbl.prom"
        fi
        printf '%s\n' "$rows" | while IFS=$'\t' read -r table count data index; do
            [ -n "$table" ] || continue
            exact=""
            if [ "$table" = kadupul_messenger_messages ]; then
                exact="$(mariadb -N -e "SELECT COUNT(*) FROM \`$schema\`.kadupul_messenger_messages" 2>/dev/null || true)"
            fi
            echo "$now,$table,$count,$data,$index,$exact"
        done
        sleep 5
    done
}

case "$action" in
    start)
        rm -rf "$out"
        install -d "$out"
        mariadb -e "TRUNCATE performance_schema.events_statements_summary_by_digest"
        # Doctrine (the Messenger transport) uses native prepared statements,
        # which the digest table does not summarise; they appear here as
        # statement/com/Prepare, Execute and Close stmt.
        mariadb -e "TRUNCATE performance_schema.events_statements_summary_global_by_event_name"
        mariadb -e "SET GLOBAL slow_query_log = 0"
        : > "$slow_log"
        mariadb -e "SET GLOBAL slow_query_log = 1; FLUSH SLOW LOGS"
        mariadb -e "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('long_query_time', 'slow_query_log', 'performance_schema', 'innodb_buffer_pool_size', 'innodb_flush_log_at_trx_commit', 'innodb_log_file_size', 'max_connections', 'version')" > "$out/variables.tsv"
        cat /etc/mysql/mariadb.conf.d/90-boost-load.cnf > "$out/my.cnf" 2>/dev/null || true
        # Fully detached, or the SSH session that started them never closes.
        sample_status > "$out/status.csv" 2>> "$out/recorder.err" < /dev/null &
        echo $! > "$out/status.pid"
        sample_tables > "$out/tables.csv" 2>> "$out/recorder.err" < /dev/null &
        echo $! > "$out/tables.pid"
        vmstat -n -t 1 > "$out/vmstat-db.log" 2>> "$out/recorder.err" < /dev/null &
        echo $! > "$out/vmstat.pid"
        ;;
    stop)
        for name in status tables vmstat; do
            if [ -f "$out/$name.pid" ]; then
                kill "$(cat "$out/$name.pid")" 2>/dev/null || true
                rm -f "$out/$name.pid"
            fi
        done
        # Close the last interval, which the 5 s loop would otherwise drop.
        values="$(status_values)"
        if [ -n "$values" ] && [ "$values" != NULL ]; then
            echo "$(date +%s.%N),$values" >> "$out/status.csv"
        fi
        mariadb -B -e "SELECT EVENT_NAME, COUNT_STAR, SUM_TIMER_WAIT, SUM_LOCK_TIME, SUM_ROWS_EXAMINED
            FROM performance_schema.events_statements_summary_global_by_event_name
            WHERE COUNT_STAR > 0 ORDER BY SUM_TIMER_WAIT DESC" > "$out/statement-events.tsv"
        mariadb -e 'SHOW ENGINE INNODB STATUS\G' > "$out/innodb-status-end.txt" 2>&1 || true
        # Times are picoseconds; the collector converts them.
        mariadb -B -e "SELECT SCHEMA_NAME, DIGEST, COUNT_STAR, SUM_TIMER_WAIT, MAX_TIMER_WAIT, SUM_LOCK_TIME,
                SUM_ROWS_EXAMINED, SUM_ROWS_SENT, SUM_ROWS_AFFECTED, SUM_CREATED_TMP_DISK_TABLES, SUM_NO_INDEX_USED,
                REPLACE(REPLACE(DIGEST_TEXT, '\n', ' '), '\t', ' ') AS DIGEST_TEXT
            FROM performance_schema.events_statements_summary_by_digest
            ORDER BY SUM_TIMER_WAIT DESC" > "$out/digests.tsv"
        cp "$slow_log" "$out/slow.log" 2>/dev/null || true
        if command -v pt-query-digest >/dev/null; then
            pt-query-digest --limit 20 "$out/slow.log" > "$out/pt-query-digest.txt" 2>&1 || true
        else
            echo 'pt-query-digest is not installed' > "$out/pt-query-digest.txt"
        fi
        zstd -q --rm -f "$out/slow.log" || true
        rm -f /var/lib/prometheus/node-exporter/kbl.prom
        touch "$out/done"
        ;;
    *)
        echo "usage: $0 start|stop <cell> <schema>" >&2
        exit 2
        ;;
esac
