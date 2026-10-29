#!/usr/bin/env bash
# Unpacks a code tarball (git archive plus include/vendor) as /opt/kadupul/<slot>
# and points it at the test database. The tree is root-owned; the poller
# account owns only the directories the application writes. Slot "app" is the tree Boost runs from;
# run.sh deploys origin/main as "app.main" and cell.sh swaps it in by rename so
# the absolute RRD paths stored in poller_item stay valid.
#
# Usage: deploy.sh <tarball> <slot>
# Reads DB_HOST, DB_NAME, DB_USER and DB_PASS from /etc/boost-load.env.
set -euo pipefail

tarball="$1"
slot="$2"
# shellcheck source=/dev/null
source /etc/boost-load.env

dest="/opt/kadupul/$slot"
rm -rf "$dest"
install -d -o root -g root -m 0755 "$dest"
tar --no-same-owner -xzf "$tarball" -C "$dest"

cp "$dest/include/config.php.dist" "$dest/include/config.php"
# The values come from the root-only env file written by provision.sh; quote
# them for a PHP single-quoted string.
php_quote() { printf "'%s'" "$(printf '%s' "$1" | sed -e "s/\\\\/\\\\\\\\/g" -e "s/'/\\\\'/g")"; }
sed -i \
    -e "s|^\$database_hostname *=.*|\$database_hostname = $(php_quote "$DB_HOST");|" \
    -e "s|^\$database_default *=.*|\$database_default  = $(php_quote "$DB_NAME");|" \
    -e "s|^\$database_username *=.*|\$database_username = $(php_quote "$DB_USER");|" \
    -e "s|^\$database_password *=.*|\$database_password = $(php_quote "$DB_PASS");|" \
    -e "s|^\$url_path *=.*|\$url_path = '/';|" \
    "$dest/include/config.php"
uid="$(id -u kadupul)"
gid="$(id -g kadupul)"
# shellcheck disable=SC2016 # $config is PHP, not a shell variable.
printf '\n$config["rrd_maintenance_trusted_uids"] = array(%s);\n$config["rrd_maintenance_trusted_gids"] = array(%s);\n' "$uid" "$gid" >> "$dest/include/config.php"
chown root:kadupul "$dest/include/config.php"
chmod 0640 "$dest/include/config.php"

# Written by the poller account: RRD files, logs, caches, the Symfony cache,
# and the scripts and resources the installer's template packages add. The
# tree is new, so no poller process can have placed a link here yet.
install -d -m 0755 "$dest/rra" "$dest/log" "$dest/cache" "$dest/cache/boost" "$dest/cache/mibcache" "$dest/cache/realtime" "$dest/cache/spikekill" "$dest/var"
touch "$dest/log/cacti.log" "$dest/log/boost.log"
chown -R kadupul:kadupul "$dest/rra" "$dest/log" "$dest/cache" "$dest/var" "$dest/scripts" "$dest/resource"

# Build the Symfony container once, so eight consumers starting together do
# not race to compile it.
(cd "$dest" && runuser -u kadupul -- /usr/bin/php8.4 bin/console cache:warmup --env=prod --no-debug >/dev/null)
# cell.sh reads this to know which tree currently sits at /opt/kadupul/app.
if [ "$slot" = app ]; then tree=feat; else tree="${slot#app.}"; fi
printf '%s\n' "$tree" > "$dest/.tree"
