#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Gives the E2E stack something to draw for theme-contrast.spec.ts: devices in
# every status, graphs, a tree and the spike kill realm. Runs inside the php
# container and is safe to repeat.
#
#   docker compose exec -T php bash tests/e2e/theme-contrast/seed.sh

set -euo pipefail

cd "${CACTI_ROOT:-/var/www/html/cacti}"
php_binary="${PHP_BINARY:-php}"

sql() {
    mariadb --skip-ssl -h"${CACTI_DB_HOST:-mariadb}" -u"${CACTI_DB_USER:-cactiuser}" \
        -p"${CACTI_DB_PASS:-cactipass}" "${CACTI_DB_NAME:-cacti}" -N -e "$1"
}

# Each step checks for its own result, so a run that stopped part way is
# finished by the next one rather than skipped.
template="$(sql "SELECT id FROM host_template WHERE name = 'Local Linux Machine'")"
if [ -z "$template" ]; then
    "$php_binary" cli/import_package.php --filename=install/templates/Local_Linux_Machine.xml.gz >/dev/null
    template="$(sql "SELECT id FROM host_template WHERE name = 'Local Linux Machine'")"
fi

for n in 1 2 3 4 5 6; do
    if [ "$(sql "SELECT COUNT(*) FROM host WHERE description = 'Device $n'")" = "0" ]; then
        "$php_binary" cli/add_device.php --description="Device $n" --ip="10.0.0.$n" --template="$template" \
            --avail=none --version=0 >/dev/null
    fi
done

# Device creation associates templates; graph creation also depends on optional
# automation settings and may be absent after a partial previous seed run.
# Use a normal single-instance template and verify the actual CLI outcome;
# add_graphs can print a whitelist failure while returning success.
host="$(sql "SELECT MIN(id) FROM host WHERE description = 'Device 1'")"
graph_template="$(sql "SELECT MIN(hg.graph_template_id) FROM host_graph AS hg
    JOIN graph_templates AS gt ON gt.id = hg.graph_template_id
    WHERE hg.host_id = $host AND gt.multiple = ''")"
if [ -z "$graph_template" ] || [ "$graph_template" = "NULL" ]; then
    echo 'No single-instance graph template is available for Device 1.' >&2
    exit 1
fi
graph="$(sql "SELECT MIN(id) FROM graph_local WHERE host_id = $host AND graph_template_id = $graph_template")"
if [ -z "$graph" ] || [ "$graph" = "NULL" ]; then
    "$php_binary" cli/add_graphs.php --graph-type=cg --host-id="$host" --graph-template-id="$graph_template"
    graph="$(sql "SELECT MIN(id) FROM graph_local WHERE host_id = $host AND graph_template_id = $graph_template")"
fi
if [ -z "$graph" ] || [ "$graph" = "NULL" ]; then
    echo 'Graph creation did not persist the preview fixture.' >&2
    exit 1
fi

# cacti.sql already holds Default Tree, so the tree is found by its name.
tree="$(sql "SELECT MIN(id) FROM graph_tree WHERE name = 'Network'")"
if [ "$tree" = "NULL" ] || [ -z "$tree" ]; then
    "$php_binary" cli/add_tree.php --type=tree --name=Network --sort-method=manual >/dev/null
    tree="$(sql "SELECT MIN(id) FROM graph_tree WHERE name = 'Network'")"
fi

for host in $(sql "SELECT id FROM host WHERE description IN ('Device 1', 'Device 2', 'Device 3', 'Device 4')"); do
    if [ "$(sql "SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = $tree AND host_id = $host")" = "0" ]; then
        "$php_binary" cli/add_tree.php --type=node --node-type=host --tree-id="$tree" --host-id="$host" >/dev/null
    fi
done

if [ "$(sql "SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = $tree AND local_graph_id = $graph")" = "0" ]; then
    "$php_binary" cli/add_tree.php --type=node --node-type=graph --tree-id="$tree" --graph-id="$graph" >/dev/null
fi
if [ "$(sql "SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = $tree AND local_graph_id = $graph")" = "0" ]; then
    echo 'The preview graph was not attached to the seeded tree.' >&2
    exit 1
fi

# One device per status the list pages colour: up, down, recovering, unknown
# and disabled.
sql "UPDATE host SET status = 3 WHERE description IN ('Device 1', 'Device 4');
    UPDATE host SET status = 1, status_fail_date = NOW() WHERE description = 'Device 2';
    UPDATE host SET status = 2 WHERE description = 'Device 3';
    UPDATE host SET disabled = 'on' WHERE description = 'Device 5';
    UPDATE host SET status = 0 WHERE description = 'Device 6';
    INSERT IGNORE INTO user_auth_realm (realm_id, user_id) VALUES (1043, 1);"
