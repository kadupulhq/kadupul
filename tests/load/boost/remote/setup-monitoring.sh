#!/usr/bin/env bash
# Live monitoring on the application host: node-exporter, Prometheus and
# Grafana, all listening on 127.0.0.1 only. Prometheus scrapes the database
# host's exporters over the private network every 5 s. Reach Grafana through
# the SSH tunnel run.sh prints.
#
# Usage: setup-monitoring.sh <database private IP>
# Reads the Grafana admin password from standard input.
set -euo pipefail

db_ip="$1"
IFS= read -r grafana_password
[ -n "$grafana_password" ] || { echo "no Grafana password on stdin" >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive
GRAFANA_VERSION=13.2.4
declare -A GRAFANA_SHA256=(
    [amd64]=c48765988fd88572607e4ea670251af8a8e64ab3e360d0f3cf67a6f3587b92ab
    [arm64]=9517a0d21e2706b54521fbd720803698bba42859ca179e9905cc6b8fb05d67ab
)

apt-get install -y -q --no-install-recommends prometheus prometheus-node-exporter curl ca-certificates

printf 'ARGS="--web.listen-address=127.0.0.1:9100"\n' > /etc/default/prometheus-node-exporter
systemctl restart prometheus-node-exporter

cat > /etc/prometheus/prometheus.yml <<YAML
global:
  scrape_interval: 5s
  evaluation_interval: 5s
scrape_configs:
  - job_name: node
    static_configs:
      - targets: ['127.0.0.1:9100']
        labels: {instance: app}
      - targets: ['$db_ip:9100']
        labels: {instance: db}
  - job_name: mysql
    static_configs:
      - targets: ['$db_ip:9104']
        labels: {instance: db}
YAML
printf 'ARGS="--web.listen-address=127.0.0.1:9090 --storage.tsdb.retention.time=3d"\n' > /etc/default/prometheus
promtool check config /etc/prometheus/prometheus.yml
systemctl restart prometheus

arch="$(dpkg --print-architecture)"
tarball="/var/tmp/grafana-$GRAFANA_VERSION.$arch.tar.gz"
if [ ! -d "/opt/grafana-$GRAFANA_VERSION" ]; then
    curl -fsSL -o "$tarball" "https://dl.grafana.com/oss/release/grafana-$GRAFANA_VERSION.linux-$arch.tar.gz"
    echo "${GRAFANA_SHA256[$arch]}  $tarball" | sha256sum -c -
    install -d "/opt/grafana-$GRAFANA_VERSION"
    tar -xzf "$tarball" -C "/opt/grafana-$GRAFANA_VERSION" --strip-components=1
    rm -f "$tarball"
fi
ln -sfn "/opt/grafana-$GRAFANA_VERSION" /opt/grafana
id grafana >/dev/null 2>&1 || useradd --system --home-dir /var/lib/grafana --shell /usr/sbin/nologin grafana
install -d -o grafana -g grafana /var/lib/grafana /var/lib/grafana/dashboards /var/log/grafana
install -d /etc/grafana/provisioning/datasources /etc/grafana/provisioning/dashboards
cp /usr/local/lib/boost-load/grafana/*.json /var/lib/grafana/dashboards/
chown grafana:grafana /var/lib/grafana/dashboards/*.json

umask 027
cat > /etc/grafana/grafana.ini <<INI
[server]
http_addr = 127.0.0.1
http_port = 3000
[paths]
data = /var/lib/grafana
logs = /var/log/grafana
provisioning = /etc/grafana/provisioning
[security]
admin_user = admin
admin_password = $grafana_password
[analytics]
reporting_enabled = false
check_for_updates = false
check_for_plugin_updates = false
[users]
allow_sign_up = false
INI
chgrp grafana /etc/grafana/grafana.ini
umask 022

cat > /etc/grafana/provisioning/datasources/prometheus.yaml <<'YAML'
apiVersion: 1
datasources:
  - name: Prometheus
    type: prometheus
    uid: prometheus
    access: proxy
    url: http://127.0.0.1:9090
    isDefault: true
    jsonData: {timeInterval: 5s}
YAML
cat > /etc/grafana/provisioning/dashboards/boost-load.yaml <<'YAML'
apiVersion: 1
providers:
  - name: boost-load
    type: file
    options: {path: /var/lib/grafana/dashboards}
YAML

cat > /etc/systemd/system/grafana.service <<'UNIT'
[Unit]
Description=Grafana for the Boost load test
After=network.target prometheus.service

[Service]
User=grafana
Group=grafana
ExecStart=/opt/grafana/bin/grafana server --homepath=/opt/grafana --config=/etc/grafana/grafana.ini
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now grafana

for _ in $(seq 1 60); do
    curl -fsS http://127.0.0.1:3000/api/health >/dev/null 2>&1 && break
    sleep 2
done
curl -fsS http://127.0.0.1:3000/api/health
# Every scrape target must be up before the first measured run.
for _ in $(seq 1 30); do
    up="$(curl -fsS 'http://127.0.0.1:9090/api/v1/query?query=sum(up)' | sed -n 's/.*"value":\[[^,]*,"\([0-9]*\)"\].*/\1/p')"
    [ "$up" = 3 ] && break
    sleep 2
done
[ "$up" = 3 ] || { echo "Prometheus sees $up of 3 targets up" >&2; exit 1; }
touch /var/lib/boost-load/setup-monitoring.done
