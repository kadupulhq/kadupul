#!/usr/bin/env bash
# Prepares the application host: PHP 8.4 from the ondrej PPA (Ubuntu 24.04
# ships 8.3), RRDtool, the MariaDB client and sysstat. Runs as cloud-init
# user-data on the droplet and inside the local dry-run container. Idempotent.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update -q
apt-get install -y -q --no-install-recommends software-properties-common gnupg ca-certificates curl
if ! grep -rqs ondrej /etc/apt/sources.list.d/; then
    add-apt-repository -y ppa:ondrej/php
fi
apt-get update -q
apt-get install -y -q --no-install-recommends \
    php8.4-cli php8.4-mysql php8.4-gd php8.4-gmp php8.4-intl php8.4-mbstring \
    php8.4-xml php8.4-ldap php8.4-snmp php8.4-sqlite3 php8.4-zip php8.4-curl \
    rrdtool mariadb-client sysstat zstd procps time psmisc snmp snmpd

# The base data sources are created through the product's CLI, and the
# interface (COUNTER, 64-bit) templates need an SNMP agent to index.
cat > /etc/snmp/snmpd.conf <<'SNMPD'
agentAddress udp:127.0.0.1:161
rocommunity public 127.0.0.1
SNMPD
if command -v systemctl >/dev/null && [ -d /run/systemd/system ]; then
    systemctl restart snmpd
else
    pkill -x snmpd || true
    /usr/sbin/snmpd -Lsd -u Debian-snmp -g Debian-snmp -I -smux -p /run/snmpd.pid
fi

printf 'date.timezone = UTC\nmemory_limit = 1024M\n' > /etc/php/8.4/cli/conf.d/90-boost-load.ini

# The poller account owns only the directories the application writes (see
# deploy.sh). Everything root runs, the harness and its state stay root-owned.
if ! id kadupul >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/kadupul --create-home --shell /bin/bash kadupul
fi
install -d -o root -g root -m 0755 /opt/kadupul /var/lib/boost-load /usr/local/lib/boost-load
touch /var/lib/boost-load/setup-app.done
