#!/usr/bin/env bash
# Starts the local dry-run pair for `run.sh --docker`: kbl-db (mariadb:10.11
# with my.cnf scaled down) and kbl-app (ubuntu:24.04 prepared by the same
# setup-app.sh the droplet runs). Prometheus and Grafana are not installed
# locally. `local-up.sh down` removes both containers and their network.
set -euo pipefail
# shellcheck source-path=SCRIPTDIR
source "$(dirname "$0")/lib.sh"

if [ "${1:-}" = down ]; then
    # -v also removes MariaDB's anonymous data volume.
    docker rm -f -v kbl-app kbl-db >/dev/null 2>&1 || true
    docker network rm kbl-net >/dev/null 2>&1 || true
    exit 0
fi

work="$STATE_ROOT/local"
install -d -m 700 "$work"
sed -e 's/BIND_ADDRESS/0.0.0.0/' -e 's/DB_BUFFER_POOL/1G/' -e 's/DB_LOG_FILE_SIZE/256M/' \
    -e 's/^innodb_buffer_pool_instances .*/innodb_buffer_pool_instances = 1/' \
    -e 's|/var/log/mysql/slow.log|/var/lib/mysql/slow.log|' "$HERE/remote/my.cnf" > "$work/my.cnf"
[ -f "$work/db-password" ] || openssl rand -hex 16 > "$work/db-password"
[ -f "$work/root-password" ] || openssl rand -hex 16 > "$work/root-password"

docker network create kbl-net >/dev/null 2>&1 || true
docker rm -f -v kbl-app kbl-db >/dev/null 2>&1 || true
docker run -d --name kbl-db --network kbl-net -e "MARIADB_ROOT_PASSWORD=$(cat "$work/root-password")" \
    -v "$work/my.cnf:/etc/mysql/conf.d/90-boost-load.cnf:ro" mariadb:10.11 >/dev/null
docker run -d --name kbl-app --network kbl-net ubuntu:24.04 sleep infinity >/dev/null

pack -C "$HERE" --exclude=results -cf - remote | docker exec -i kbl-app bash -c 'install -d /tmp/kbl && tar -C /tmp/kbl -xf -'
docker exec kbl-app bash /tmp/kbl/remote/setup-app.sh > "$work/setup-app.log" 2>&1

for _ in $(seq 1 30); do
    docker exec kbl-db mariadb-admin ping -uroot "-p$(cat "$work/root-password")" --silent >/dev/null 2>&1 && break
    sleep 2
done
docker exec -i kbl-db bash -c 'umask 077; cat > /root/.my.cnf' <<CNF
[client]
user=root
password=$(cat "$work/root-password")
CNF
docker exec kbl-db bash -c 'apt-get update -q >/dev/null && apt-get install -y -q procps zstd >/dev/null; mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | mariadb mysql'
docker exec -i kbl-db mariadb <<SQL
CREATE USER IF NOT EXISTS 'kadupul'@'%' IDENTIFIED BY '$(cat "$work/db-password")';
GRANT ALL ON \`cacti\`.* TO 'kadupul'@'%';
GRANT SELECT ON mysql.time_zone_name TO 'kadupul'@'%';
SQL
printf 'DB_HOST=kbl-db\nDB_NAME=cacti\nDB_USER=kadupul\nDB_PASS=%s\n' "$(cat "$work/db-password")" \
    | docker exec -i kbl-app bash -c 'umask 077; cat > /etc/boost-load.env; rm -f /etc/boost-load.client.cnf'
log "local dry-run containers are up; run: ./run.sh --docker"
