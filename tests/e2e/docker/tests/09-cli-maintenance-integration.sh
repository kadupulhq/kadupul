#!/usr/bin/env bash
# Run CLI contracts against the disposable MariaDB-backed Cacti install.
set -euo pipefail
IFS=$'\n\t'

cd "$(dirname "$0")/.."
DC=(docker compose -f docker-compose.yml)

run_cli() {
	local script="$1"
	shift
	"${DC[@]}" exec -T cacti-master php "/var/www/html/cli/$script" "$@"
}

db_query() {
	"${DC[@]}" exec -T cacti-db mariadb -ucactiuser -pcactiuser cacti -Nse "$1"
}

expect_cli_failure() {
	local label="$1"
	shift
	if run_cli "$@"; then
		echo "FAIL: $label unexpectedly succeeded" >&2
		exit 1
	fi
	echo "PASS: $label rejected the invalid request"
}

echo '[09] every CLI entry point accepts --version and --help in the installed Docker app'
"${DC[@]}" exec -T cacti-master mkdir -p /var/www/html/tests/tools
sed 's@^SCRIPTPATH=.*@SCRIPTPATH="/var/www/html/tests/tools"@' ../../../tests/tools/check_cli_version.sh \
	| "${DC[@]}" exec -T cacti-master sh -c 'cd /var/www/html && bash -s'

echo '[09] database audit report runs against the installed MariaDB schema'
if ! audit_output=$(run_cli audit_database.php --report 2>&1); then
	echo "FAIL: database audit reported an error: $audit_output" >&2
	exit 1
fi
if grep -qiE 'TLS/SSL error|Failed to load the audit schema baseline' <<< "$audit_output"; then
	echo "FAIL: database audit could not load its baseline: $audit_output" >&2
	exit 1
fi

echo '[09] database analysis reports a successful run'
run_cli analyze_database.php >/tmp/cacti-cli-analyze.out
grep -q ' Successful' /tmp/cacti-cli-analyze.out

echo '[09] data-query reorder accepts its documented all selector'
run_cli reorder_data_query.php --qid=all >/dev/null
expect_cli_failure 'reorder rejects host ID zero' reorder_data_query.php --host-id=0
expect_cli_failure 'reorder rejects malformed query ID' reorder_data_query.php --host-id=all --qid=invalid

echo '[09] permission list succeeds and invalid grant IDs fail'
run_cli add_perms.php --list-users --quiet >/dev/null
expect_cli_failure 'permission grant rejects item ID zero' add_perms.php --user-id=1 --item-type=host --item-id=0

echo '[09] name-reapply selectors reject invalid IDs before querying'
expect_cli_failure 'data-source rename rejects malformed host list' poller_data_sources_reapply_names.php --host-id=1,invalid
expect_cli_failure 'graph rename rejects out-of-range host ID' poller_graphs_reapply_names.php --host-id=4294967296

echo '[09] seed two data-source-free devices for the poller-cache host-scope check'
db_query "DELETE FROM poller_item WHERE local_data_id IN (4294967201,4294967202)"
db_query "DELETE FROM host WHERE description IN ('cli-scope-other','cli-scope-target')"
db_query "INSERT INTO host (poller_id,site_id,host_template_id,description,hostname,disabled) VALUES (1,1,0,'cli-scope-other','198.51.100.240',''),(1,1,0,'cli-scope-target','198.51.100.241','')"

other_id=$(db_query "SELECT id FROM host WHERE description='cli-scope-other'")
target_id=$(db_query "SELECT id FROM host WHERE description='cli-scope-target'")
if [[ ! "$other_id" =~ ^[0-9]+$ || ! "$target_id" =~ ^[0-9]+$ || "$other_id" -ge "$target_id" ]]; then
	echo "FAIL: device fixture IDs were not created in expected order (other=$other_id target=$target_id)" >&2
	exit 1
fi

# High, unique data IDs avoid colliding with the empty install's real poller rows.
db_query "INSERT INTO poller_item (local_data_id,poller_id,host_id,rrd_name) VALUES (4294967201,1,$other_id,'cli-scope'),(4294967202,1,$target_id,'cli-scope')"
cache_output=$(run_cli rebuild_poller_cache.php --host-id="$target_id" --threads=1 --force)
if ! grep -q 'There are 1 hosts' <<<"$cache_output"; then
	echo "FAIL: cache rebuild did not process the selected host (output: $cache_output)" >&2
	exit 1
fi

other_count=$(db_query 'SELECT COUNT(*) FROM poller_item WHERE local_data_id=4294967201')
target_count=$(db_query 'SELECT COUNT(*) FROM poller_item WHERE local_data_id=4294967202')
if [[ "$other_count" != '1' || "$target_count" != '0' ]]; then
	echo "FAIL: scoped cache rebuild changed unexpected rows (other=$other_count target=$target_count)" >&2
	exit 1
fi

echo '[09] targeted cache rebuild changed only the selected host'
echo 'PASS: Docker CLI maintenance integration coverage'
