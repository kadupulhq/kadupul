#!/usr/bin/env bash
# Creates the DigitalOcean resources for one load-test run and prints its run
# id. Everything it creates carries the tags kadupul-boost-loadtest and the
# run id, sits in a new project and a new VPC, and is recorded in
# $STATE_ROOT/<run id>/state.env so teardown.sh can delete exactly that.
#
# A watchdog started before the first billable resource runs teardown.sh after
# WATCHDOG_SECONDS (default 6 hours) whatever happens to this shell. Any
# failure here tears the run down at once.
#
# Usage: provision.sh
set -euo pipefail
# shellcheck source-path=SCRIPTDIR
source "$(dirname "$0")/lib.sh"

WATCHDOG_SECONDS="${WATCHDOG_SECONDS:-21600}"
# Sized for the default s-8vcpu-16gb database host; raise both with DB_SIZE.
DB_BUFFER_POOL="${DB_BUFFER_POOL:-10G}"
DB_LOG_FILE_SIZE="${DB_LOG_FILE_SIZE:-2G}"

command -v doctl >/dev/null || die "doctl is not installed"
command -v jq >/dev/null || die "jq is not installed"
doctl account get -o json | jq -e '(if type == "array" then .[0] else . end).status == "active"' >/dev/null || die "doctl is not authenticated"

RUN_ID="kbl-$(date -u +%Y%m%d%H%M)-$(openssl rand -hex 3)"
RUN_STATE="$(state_dir "$RUN_ID")"
install -d -m 700 "$RUN_STATE"
STATE="$RUN_STATE/state.env"
state_set "$STATE" RUN_ID "$RUN_ID"
state_set "$STATE" CREATED_AT "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "run id $RUN_ID, state in $RUN_STATE"

provisioned=0
on_exit() {
    if [ "$provisioned" != 1 ]; then
        log "provisioning failed; tearing down $RUN_ID"
        "$HERE/teardown.sh" "$RUN_ID" || true
    fi
}
trap on_exit EXIT
trap 'exit 130' INT TERM

# shellcheck disable=SC2016 # positional parameters of the inner shell
nohup bash -c 'sleep "$1"; "$2" "$3" --reason watchdog' _ "$WATCHDOG_SECONDS" "$HERE/teardown.sh" "$RUN_ID" \
    >> "$RUN_STATE/watchdog.log" 2>&1 < /dev/null &
state_set "$STATE" WATCHDOG_PID "$!"
state_set "$STATE" WATCHDOG_DEADLINE "$(( $(date +%s) + WATCHDOG_SECONDS ))"
disown
log "watchdog pid $! deletes the run after $WATCHDOG_SECONDS s"

my_ip="$(curl -fsS https://api.ipify.org)"
[[ "$my_ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || die "could not determine this machine's public IPv4 address"

ssh-keygen -q -t ed25519 -N '' -C "$RUN_ID" -f "$RUN_STATE/id_ed25519"
openssl rand -hex 24 > "$RUN_STATE/db-password"
openssl rand -base64 24 | tr -d '/+=' | cut -c1-24 > "$RUN_STATE/grafana-admin-password"
chmod 600 "$RUN_STATE/db-password" "$RUN_STATE/grafana-admin-password"

key_id="$(doctl compute ssh-key import "$RUN_ID" --public-key-file "$RUN_STATE/id_ed25519.pub" -o json | jq -r '.[0].id')"
[[ "$key_id" =~ ^[0-9]+$ ]] || die "SSH key import failed"
state_set "$STATE" SSH_KEY_ID "$key_id"

doctl compute tag create "$PROJECT_TAG" >/dev/null 2>&1 || true
doctl compute tag create "$RUN_ID" >/dev/null

project_id="$(doctl projects create --name "$PROJECT_NAME" --purpose 'Kadupul Boost load test' \
    --environment Development --description "$RUN_ID" -o json | jq -r '.[0].id')"
[ -n "$project_id" ] && [ "$project_id" != null ] || die "project creation failed"
state_set "$STATE" PROJECT_ID "$project_id"

vpc_id="$(doctl vpcs create --name "$RUN_ID" --region "$REGION" --description "$RUN_ID" -o json | jq -r '.[0].id')"
[ -n "$vpc_id" ] && [ "$vpc_id" != null ] || die "VPC creation failed"
state_set "$STATE" VPC_ID "$vpc_id"

userdata_db="$RUN_STATE/userdata-db.sh"
{
    printf '#!/bin/bash\nset -euo pipefail\nmkdir -p /etc/mysql/mariadb.conf.d\n'
    printf "cat > /etc/mysql/mariadb.conf.d/90-boost-load.cnf <<'KBL_CNF'\n"
    sed -e "s/DB_BUFFER_POOL/$DB_BUFFER_POOL/" -e "s/DB_LOG_FILE_SIZE/$DB_LOG_FILE_SIZE/" "$HERE/remote/my.cnf"
    printf 'KBL_CNF\n'
    sed '1d' "$HERE/remote/setup-db.sh"
} > "$userdata_db"

# Prints "id public-ip private-ip". The create response can lack the network
# addresses, so they are read back until both are assigned.
create_droplet() {
    local role="$1" size="$2" userdata="$3" id addresses
    id="$(doctl compute droplet create "$RUN_ID-$role" --region "$REGION" --size "$size" --image "$IMAGE" \
        --vpc-uuid "$vpc_id" --ssh-keys "$key_id" --tag-names "$PROJECT_TAG,$RUN_ID" \
        --user-data-file "$userdata" --wait -o json | jq -r '.[0].id')"
    [[ "$id" =~ ^[0-9]+$ ]] || die "creating the $role droplet failed"
    for _ in $(seq 1 30); do
        addresses="$(doctl compute droplet get "$id" -o json | jq -r '.[0].networks.v4 // [] |
            [(map(select(.type == "public"))[0].ip_address // ""), (map(select(.type == "private"))[0].ip_address // "")] | join(" ")')"
        if [[ "$addresses" =~ ^[0-9.]+\ [0-9.]+$ ]]; then
            echo "$id $addresses"
            return 0
        fi
        sleep 5
    done
    die "the $role droplet $id has no addresses"
}

log "creating droplets ($APP_SIZE app, $DB_SIZE db) in $REGION"
read -r db_id db_ip db_priv <<< "$(create_droplet db "$DB_SIZE" "$userdata_db")"
[ -n "$db_priv" ] || die "database droplet creation failed"
state_set "$STATE" DB_ID "$db_id"
state_set "$STATE" DB_IP "$db_ip"
state_set "$STATE" DB_PRIV "$db_priv"
read -r app_id app_ip app_priv <<< "$(create_droplet app "$APP_SIZE" "$HERE/remote/setup-app.sh")"
[ -n "$app_priv" ] || die "application droplet creation failed"
state_set "$STATE" APP_ID "$app_id"
state_set "$STATE" APP_IP "$app_ip"
state_set "$STATE" APP_PRIV "$app_priv"
doctl projects resources assign "$project_id" --resource "do:droplet:$db_id" --resource "do:droplet:$app_id" >/dev/null

# SSH only from here; MariaDB and the exporters only from the app droplet's
# private address. Outbound stays open for package installs.
firewall_id="$(doctl compute firewall create --name "$RUN_ID" --tag-names "$RUN_ID" \
    --inbound-rules "protocol:tcp,ports:22,address:$my_ip/32 protocol:tcp,ports:3306,address:$app_priv/32 protocol:tcp,ports:9100,address:$app_priv/32 protocol:tcp,ports:9104,address:$app_priv/32" \
    --outbound-rules "protocol:tcp,ports:all,address:0.0.0.0/0,address:::/0 protocol:udp,ports:all,address:0.0.0.0/0,address:::/0 protocol:icmp,address:0.0.0.0/0,address:::/0" \
    -o json | jq -r '.[0].id')"
state_set "$STATE" FIREWALL_ID "$firewall_id"

TARGET="do"
for role in db app; do
    log "waiting for cloud-init on $role"
    for _ in $(seq 1 60); do
        host_exec "$role" true 2>/dev/null && break
        sleep 5
    done
    host_exec "$role" 'cloud-init status --wait >/dev/null; cloud-init status --long | grep -q "status: done"' \
        || die "cloud-init failed on $role; see /var/log/cloud-init-output.log there"
done
host_exec db test -f /var/lib/boost-load-db/setup-db.done
host_exec app test -f /var/lib/boost-load/setup-app.done

log "granting the poller account to $app_priv"
{
    printf "CREATE USER 'kadupul'@'%s' IDENTIFIED BY '%s';\n" "$app_priv" "$(cat "$RUN_STATE/db-password")"
    printf "GRANT ALL ON \`cacti\`.* TO 'kadupul'@'%s';\n" "$app_priv"
    printf "GRANT SELECT ON mysql.time_zone_name TO 'kadupul'@'%s';\n" "$app_priv"
} | host_exec db mariadb
{
    printf 'DB_HOST=%s\nDB_NAME=cacti\nDB_USER=kadupul\nDB_PASS=%s\n' "$db_priv" "$(cat "$RUN_STATE/db-password")"
} | host_exec app 'umask 077; cat > /etc/boost-load.env'

log "installing Prometheus and Grafana on the app droplet"
host_exec app 'install -d -m 0755 /usr/local/lib/boost-load'
pack -C "$HERE" --exclude=results -cf - . | host_exec app 'tar --no-same-owner -C /usr/local/lib/boost-load -xf - && chmod -R go-w,a+rX /usr/local/lib/boost-load'
host_exec app "bash /usr/local/lib/boost-load/remote/setup-monitoring.sh '$db_priv'" < "$RUN_STATE/grafana-admin-password" \
    > "$RUN_STATE/setup-monitoring.log" 2>&1 || die "monitoring setup failed; see $RUN_STATE/setup-monitoring.log"

provisioned=1
log "provisioned $RUN_ID"
echo "$RUN_ID"
