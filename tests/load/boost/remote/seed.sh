#!/usr/bin/env bash
# Builds one scale from scratch and snapshots it: fresh schema, base data
# sources through the product CLI, clones and RRD files through seed.php,
# queued samples through generate.php, then a database dump and an RRD
# tarball that every run at this scale restores.
#
# In cycle mode (for millions of sources, where copying every RRD file per
# cell is out of reach) the clone is lean, nothing is queued yet, and only the
# comparison set's RRD files are stashed; cell.sh then queues one cycle per
# cell on the same RRD set.
#
# Usage: seed.sh <data sources> <seed> <rrd workers> [snapshot|cycle]
set -euo pipefail
# shellcheck source-path=SCRIPTDIR source=common.sh
source "$(dirname "$0")/common.sh"

count="$1"
seed="$2"
workers="$3"
mode="${4:-snapshot}"
snap="$STATE/snap/$count"

# A cell may have left the baseline tree in place; seed from the tree under test.
use_tree feat
log "seed $count: fresh schema"
db -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
db "$DB_NAME" < "$APP/cacti.sql"
find "$APP/rra" -mindepth 1 -delete
reset_app_file "$APP/log/cacti.log"
(cd "$APP" && as_poller "$PHP" cli/install_cacti.php --accept-eula --install --mode=1 \
    --template=Local_Linux_Machine.xml.gz:1 --template=NetSNMP_Device.xml.gz:1 > "$STATE/install-$count.log" 2>&1)
grep -q 'Installation has now completed' "$STATE/install-$count.log"

log "seed $count: base interface source through cli/add_device.php and cli/add_graphs.php"
(
    cd "$APP"
    as_poller "$PHP" cli/add_device.php --description=snmp-base --ip=127.0.0.1 --template=0 \
        --avail=snmp --version=2 --community=public
    host_id="$(db -N "$DB_NAME" -e "SELECT id FROM host WHERE description = 'snmp-base'")"
    as_poller "$PHP" cli/add_data_query.php --host-id="$host_id" --data-query-id=2 --reindex-method=0
    # Graph template 22 with query type 7 is "In/Out Bits (64-bit)" (ifHCInOctets).
    as_poller "$PHP" cli/add_graphs.php --graph-type=ds --graph-template-id=22 --host-id="$host_id" \
        --snmp-query-id=2 --snmp-query-type-id=7 --snmp-field=ifIndex --snmp-value=1
) > "$STATE/base-$count.log" 2>&1

log "seed $count: clone"
if [ "$mode" = cycle ]; then
    as_poller "$PHP" "$HARNESS/seed.php" clone --count="$count" --seed="$seed" --lean
else
    as_poller "$PHP" "$HARNESS/seed.php" clone --count="$count" --seed="$seed"
fi
log "seed $count: RRD files"
as_poller "$PHP" "$HARNESS/seed.php" rrds --workers="$workers" | grep -v '^OK u:'
if [ "$mode" != cycle ]; then
    log "seed $count: samples"
    as_poller "$PHP" "$HARNESS/generate.php"
fi

db "$DB_NAME" -e "
    INSERT INTO settings (name, value) VALUES
        ('boost_rrd_update_enable', 'on'), ('boost_rrd_update_system_enable', 'on')
    ON DUPLICATE KEY UPDATE value = VALUES(value);
    DELETE FROM settings WHERE name IN ('boost_last_run_time', 'boost_next_run_time', 'boost_poller_status');"

rm -rf "$snap"
install -d "$snap"
if [ "$mode" = cycle ]; then
    as_poller "$PHP" "$HARNESS/seed.php" sample-list --size=500 --special=100 > "$snap/sample.txt"
    db "$DB_NAME" -e "INSERT INTO kbl_meta VALUES ('cycle', '0') ON DUPLICATE KEY UPDATE value = '0'"
    sed "s|^$APP/||" "$snap/sample.txt" | tar -C "$APP" -cf "$snap/sample-rra.tar" -T -
    echo "$count" > "$snap/sources"
    log "seed $count: done in cycle mode; $(wc -l < "$snap/sample.txt") RRD files in the comparison set, $(du -sh "$APP/rra" | cut -f1) of RRD files"
    exit 0
fi

log "seed $count: snapshot"
as_poller "$PHP" "$HARNESS/seed.php" sample-list --size=500 > "$snap/sample.txt"
db_dump --single-transaction --triggers --routines --hex-blob "$DB_NAME" | zstd -q -T0 -o "$snap/db.sql.zst"
tar -C "$APP" -cf - rra | zstd -q -T0 -o "$snap/rra.tar.zst"
db -N "$DB_NAME" -e "SELECT COUNT(*) FROM poller_output_boost" > "$snap/queued-rows"
db -N "$DB_NAME" -e "SELECT COUNT(*) FROM kbl_plan" > "$snap/sources"
log "seed $count: done; $(cat "$snap/queued-rows") rows queued, $(wc -l < "$snap/sample.txt") RRD files in the comparison set, $(du -sh "$snap" | cut -f1) snapshot"
