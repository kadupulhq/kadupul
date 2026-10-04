<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

test('maintenance CLI entrypoints validate arguments and return status for supported actions', function ($script, $arguments, $expectedExit, $message, $scenario = '') {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/maintenance-cli-' . bin2hex(random_bytes(8));
    foreach (array('', '/bin', '/cli', '/include', '/lib', '/docs') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }

    copy($root . '/cli/' . $script, $dir . '/cli/' . $script);
    foreach (array('utility.php', 'api_data_source.php', 'api_graph.php', 'api_automation_tools.php', 'poller.php', 'snmp.php', 'data_query.php', 'reapply_names.php') as $library) {
        $libraryStub = '<?php ';
        if ($library === 'api_automation_tools.php') {
            $libraryStub .= 'function getHosts() { return array(); } function getGraphTemplates() { return array(); }'
                . 'function displayUsers() { print "LIST_OK"; } function displayTrees() { print "LIST_OK"; }'
                . 'function displayHosts($rows, $quiet = false) { print "LIST_OK"; }'
                . 'function displayHostGraphs($host, $quiet = false) { print "LIST_OK"; }'
                . 'function displayGraphTemplates($rows, $quiet = false) { print "LIST_OK"; }';
        }
        file_put_contents($dir . '/lib/' . $library, $libraryStub);
    }
    copy($root . '/lib/reapply_names.php', $dir . '/lib/reapply_names.php');
    if (in_array($scenario, array('audit-report', 'audit-column-drift', 'audit-column-order', 'audit-drift', 'audit-index-missing', 'audit-index-key-mismatch'), true)) {
        copy($root . '/docs/audit_schema.sql', $dir . '/docs/audit_schema.sql');
        file_put_contents($dir . '/bin/mysql', "#!/bin/sh\nif [ \"\$1\" = \"--version\" ]; then echo 'mysql Ver 8.0.36'; exit 0; fi\ncat >/dev/null\nexit 0\n");
        chmod($dir . '/bin/mysql', 0700);
    }

    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($dir . '/cli/' . $script, true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/cli/' . $script, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }

    file_put_contents($dir . '/include/cli_check.php', '<?php ' . $prelude
        . '$config = array("base_path" => dirname(__DIR__), "poller_id" => 1);'
        . 'if (is_file(dirname(__DIR__) . "/bin/mysql")) putenv("CACTI_MYSQL_CLIENT=" . dirname(__DIR__) . "/bin/mysql");'
        . 'define("CACTI_VERSION", "fixture"); define("COPYRIGHT_YEARS", "2026");'
        . '$database_default = "fixture"; $database_username = "fixture"; $database_password = "fixture"; $database_hostname = "fixture"; $database_port = "3306"; $database_ssl = false;'
        . 'function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }'
        . 'function get_cacti_cli_version() { return "fixture"; }'
        . 'function read_config_option($name) { return 5; }'
        . 'function db_fetch_assoc($sql) { if (getenv("MAINTENANCE_CLI_SCENARIO") === "reapply-zero") { print "SELECTED_QUERY:" . $sql; return array(); } if (strpos($sql, "SHOW TABLES") === 0) return array(array("Tables_in_fixture" => "fixture_table")); if (strpos($sql, "SHOW COLUMNS") === 0) { $rows = array(array("Field" => "id", "Type" => "int", "Null" => "NO", "Key" => "PRI", "Default" => null, "Extra" => "")); if (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-column-drift", "audit-index-missing", "audit-index-key-mismatch"), true)) $rows[] = array("Field" => "name", "Type" => getenv("MAINTENANCE_CLI_SCENARIO") === "audit-column-drift" ? "varchar(10)" : "varchar(20)", "Null" => "YES", "Key" => "", "Default" => null, "Extra" => ""); if (getenv("MAINTENANCE_CLI_SCENARIO") === "audit-column-order") $rows[] = array("Field" => "label", "Type" => "varchar(50)", "Null" => "YES", "Key" => "", "Default" => null, "Extra" => ""); return $rows; } if (strpos($sql, "SHOW INDEXES") === 0 && getenv("MAINTENANCE_CLI_SCENARIO") === "audit-index-key-mismatch") return array(array("Table" => "fixture_table", "Non_unique" => 0, "Key_name" => "idx_name", "Seq_in_index" => 1, "Column_name" => "name", "Packed" => null, "Comment" => "", "Index_type" => "BTREE")); if (strpos($sql, "SHOW INDEXES") === 0) return array(); throw new LogicException("Unexpected database access: " . $sql); }'
        . 'function db_fetch_row($sql) { if (strpos($sql, "SHOW TABLE STATUS") === 0) return array("Collation" => "utf8mb4_unicode_ci"); throw new LogicException("Unexpected database access: " . $sql); }'
        . 'function db_fetch_cell($sql) { return strpos($sql, "COUNT(*)") !== false ? 1 : "fixture"; }'
        . 'function db_table_exists($name) { if (preg_match("/^audit_(complete|columns|indexes|old_columns|old_indexes)_/", $name)) return true; return in_array("--load", $_SERVER["argv"], true) || (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-report", "audit-column-drift", "audit-column-order", "audit-drift", "audit-index-missing", "audit-index-key-mismatch", "audit-client-discovery"), true) && in_array($name, array("table_columns", "table_indexes"), true)); }'
        . 'function db_fetch_cell_prepared($sql, $params = array()) { if (strpos($sql, "COUNT(*)") !== false) return 1; if (strpos($sql, "SELECT table_sequence") !== false) return isset($params[1]) && $params[1] === "name" ? 2 : 3; if (strpos($sql, "SELECT table_field") !== false) return "id"; throw new LogicException("Unexpected prepared cell query: " . $sql); }'
        . 'function db_fetch_row_prepared($sql, $params = array()) { if (strpos($sql, "information_schema.tables") !== false) return array("ENGINE" => "InnoDB", "COLLATION" => "utf8mb4"); if (strpos($sql, "FROM table_columns") !== false) { $field = $params[1] ?? "id"; if ($field === "name") return array("table_field" => "name", "table_type" => "varchar(20)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); if ($field === "label") return array("table_field" => "label", "table_type" => "varchar(50)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); return array("table_field" => "id", "table_type" => "int", "table_null" => "NO", "table_key" => "PRI", "table_default" => null, "table_extra" => ""); } if (strpos($sql, "FROM table_indexes") !== false && getenv("MAINTENANCE_CLI_SCENARIO") === "audit-index-key-mismatch") return array("idx_non_unique" => 1, "idx_key_name" => "idx_name", "idx_seq_in_index" => 1, "idx_column_name" => "name", "idx_packed" => null, "idx_comment" => "", "idx_index_type" => "BTREE"); return array(); }'
        . 'function db_fetch_assoc_prepared($sql, $params = array()) { if (strpos($sql, "FROM table_columns") !== false) { $rows = array(array("table_field" => "id", "table_type" => "int", "table_null" => "NO", "table_key" => "PRI", "table_default" => null, "table_extra" => "")); if (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-drift", "audit-column-drift", "audit-index-missing", "audit-index-key-mismatch"), true)) $rows[] = array("table_field" => "name", "table_type" => "varchar(20)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); if (getenv("MAINTENANCE_CLI_SCENARIO") === "audit-column-order") { $rows[] = array("table_field" => "name", "table_type" => "varchar(20)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); $rows[] = array("table_field" => "label", "table_type" => "varchar(50)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); } return $rows; } if (strpos($sql, "FROM table_indexes") !== false && in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-index-missing", "audit-index-key-mismatch"), true)) return array(array("idx_table_name" => "fixture_table", "idx_key_name" => "idx_name", "idx_seq_in_index" => 1, "idx_column_name" => "name", "idx_non_unique" => 1, "idx_index_type" => "BTREE")); return array(); }'
        . 'function db_column_exists($table, $column) { return $table === "fixture_table" && ($column === "id" || (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-column-drift", "audit-index-missing", "audit-index-key-mismatch"), true) && $column === "name") || (getenv("MAINTENANCE_CLI_SCENARIO") === "audit-column-order" && $column === "label")); } function db_index_exists($table, $index) { return getenv("MAINTENANCE_CLI_SCENARIO") === "audit-index-key-mismatch" && $index === "idx_name"; }'
        . 'function db_client_ssl_option($enabled, $version) { return ""; }'
        . 'function db_execute($sql) { return true; } function db_execute_prepared($sql, $params) { return true; }'
        . 'function db_dump_data(...$args) { return 0; }'
        . 'function cacti_escapeshellarg($value) { return escapeshellarg($value); }');

    try {
        $process = proc_open(
            array_merge(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/cli/' . $script), $arguments),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            $scenario === '' ? null : array_merge(getenv(), array('MAINTENANCE_CLI_SCENARIO' => $scenario))
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== $expectedExit) {
            throw new RuntimeException('Exit ' . $exitCode . ': ' . $output);
        }
        expect($output)->toContain($message);

        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (array('/cli', '/include', '/lib', '/docs', '/bin', '') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with(array(
    'rebuild rejects zero host ID' => array('rebuild_poller_cache.php', array('--host-id=0'), 1, '--host-id must be a positive integer'),
    'rebuild rejects zero thread count' => array('rebuild_poller_cache.php', array('--threads=0'), 1, 'valid Number of Treads'),
    'rebuild rejects negative thread count' => array('rebuild_poller_cache.php', array('--threads=-1'), 1, 'valid Number of Treads'),
    'rebuild rejects fractional thread count' => array('rebuild_poller_cache.php', array('--threads=1.5'), 1, 'valid Number of Treads'),
    'reorder rejects zero host ID' => array('reorder_data_query.php', array('--host-id=0', '--qid=all'), 1, '--host-id must be a positive integer'),
    'reorder rejects missing query ID' => array('reorder_data_query.php', array('--host-id=all'), 1, '--qid must be a positive query ID'),
    'data-source reapply retains the deviceless selector' => array('poller_data_sources_reapply_names.php', array('--host-id=0'), 0, 'AND data_local.host_id=0', 'reapply-zero'),
    'graph reapply retains the deviceless selector' => array('poller_graphs_reapply_names.php', array('--host-id=0'), 0, 'AND graph_local.host_id=0', 'reapply-zero'),
    'graph reapply rejects malformed host IDs' => array('poller_graphs_reapply_names.php', array('--host-id=1,nope'), 1, 'positive device IDs'),
    'add permissions list mode exits successfully' => array('add_perms.php', array('--list-users'), 0, 'LIST_OK'),
    'audit report returns failure when its baseline table is unavailable' => array('audit_database.php', array('--report'), 1, 'FATAL: Failed to find or read Audit Schema'),
    'audit repair stops when its baseline table is unavailable' => array('audit_database.php', array('--repair'), 1, 'FATAL: Failed to find or read Audit Schema'),
    'audit alters preview stops when its baseline table is unavailable' => array('audit_database.php', array('--alters'), 1, 'FATAL: Failed to find or read Audit Schema'),
    'audit load exports the loaded baseline' => array('audit_database.php', array('--load'), 0, 'Finished Creating Audit Schema'),
    'audit report scans baseline columns and table metadata' => array('audit_database.php', array('--report'), 0, 'Audit was clean', 'audit-report'),
    'audit report identifies and proposes a real column mismatch' => array('audit_database.php', array('--report'), 0, "Attribute 'Type' invalid. Should be: 'varchar(20)', Is: 'varchar(10)'", 'audit-column-drift'),
    'audit alters preserve the previous-column sequence for a missing baseline column' => array('audit_database.php', array('--alters'), 0, "ALTER TABLE `fixture_table`\n   ADD COLUMN `name` varchar(20) AFTER `id`,\n   ROW_FORMAT=Dynamic CHARSET=utf8mb4;", 'audit-column-order'),
    'audit alters propose a missing baseline index without executing it' => array('audit_database.php', array('--alters'), 0, "ALTER TABLE `fixture_table`\n   ADD INDEX `idx_name` (`name`) USING BTREE,\n   ROW_FORMAT=Dynamic CHARSET=utf8mb4;", 'audit-index-missing'),
    'audit alters rebuild a mismatched baseline index and checks its sequence' => array('audit_database.php', array('--alters'), 0, "ALTER TABLE `fixture_table`\n   DROP INDEX `idx_name`,\n   ADD INDEX `idx_name` (`name`) USING BTREE,\n   ROW_FORMAT=Dynamic CHARSET=utf8mb4;", 'audit-index-key-mismatch'),
    'audit report resolves the installed database client without a test override' => array('audit_database.php', array('--report'), 1, 'FATAL: Failed to find or read Audit Schema', 'audit-client-discovery'),
    'audit repair applies a safe missing-column alteration' => array('audit_database.php', array('--repair'), 0, 'Repair Completed!  All 1 Alters succeeded!', 'audit-drift'),
    'audit alters previews a missing-column alteration' => array('audit_database.php', array('--alters'), 0, '-- Proposed Alter for Table : fixture_table', 'audit-drift'),
));
