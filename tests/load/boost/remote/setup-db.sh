#!/usr/bin/env bash
# Prepares the database host: MariaDB 10.11 from Ubuntu 24.04, the Prometheus
# node and mysqld exporters bound to the private IP, percona-toolkit for
# pt-query-digest, and sysstat. provision.sh sends this as cloud-init
# user-data, preceded by the contents of my.cnf written to
# /etc/mysql/mariadb.conf.d/90-boost-load.cnf.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
cnf=/etc/mysql/mariadb.conf.d/90-boost-load.cnf

private_ip="$(curl -fsS http://169.254.169.254/metadata/v1/interfaces/private/0/ipv4/address)"
case "$private_ip" in
    10.*|172.*|192.168.*) ;;
    *) echo "unexpected private address '$private_ip'" >&2; exit 1 ;;
esac
sed -i "s/BIND_ADDRESS/$private_ip/" "$cnf"

apt-get update -q
apt-get install -y -q --no-install-recommends mariadb-server mariadb-client \
    prometheus-node-exporter prometheus-mysqld-exporter percona-toolkit sysstat zstd openssl
install -d -o mysql -g adm -m 2750 /var/log/mysql
systemctl restart mariadb
mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | mariadb mysql

# The exporter account has only what mysqld_exporter needs.
exporter_password="$(openssl rand -hex 24)"
mariadb <<SQL
CREATE USER IF NOT EXISTS 'exporter'@'localhost' IDENTIFIED BY '$exporter_password' WITH MAX_USER_CONNECTIONS 3;
ALTER USER 'exporter'@'localhost' IDENTIFIED BY '$exporter_password';
GRANT PROCESS, REPLICATION CLIENT ON *.* TO 'exporter'@'localhost';
GRANT SELECT ON performance_schema.* TO 'exporter'@'localhost';
SQL
umask 027
printf '[client]\nuser=exporter\npassword=%s\nsocket=/run/mysqld/mysqld.sock\n' "$exporter_password" > /etc/mysql/exporter.cnf
chgrp prometheus /etc/mysql/exporter.cnf
umask 022

install -d -o prometheus -g prometheus /var/lib/prometheus/node-exporter
printf 'ARGS="--config.my-cnf=/etc/mysql/exporter.cnf --web.listen-address=%s:9104 --collect.info_schema.innodb_metrics --collect.info_schema.processlist --collect.perf_schema.eventsstatementssum"\n' "$private_ip" \
    > /etc/default/prometheus-mysqld-exporter
printf 'ARGS="--web.listen-address=%s:9100 --collector.textfile.directory=/var/lib/prometheus/node-exporter"\n' "$private_ip" \
    > /etc/default/prometheus-node-exporter
systemctl restart prometheus-mysqld-exporter prometheus-node-exporter

install -d /var/lib/boost-load-db
touch /var/lib/boost-load-db/setup-db.done
