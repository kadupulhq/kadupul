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
    if (in_array($scenario, array('audit-report', 'audit-drift'), true)) {
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
        . 'if (is_file(dirname(__DIR__) . "/bin/mysql")) define("CACTI_TEST_DATABASE_CLIENT", dirname(__DIR__) . "/bin/mysql");'
        . 'define("CACTI_VERSION", "fixture"); define("COPYRIGHT_YEARS", "2026");'
        . '$database_default = "fixture"; $database_username = "fixture"; $database_password = "fixture"; $database_hostname = "fixture"; $database_port = "3306"; $database_ssl = false;'
        . 'function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }'
        . 'function get_cacti_cli_version() { return "fixture"; }'
        . 'function read_config_option($name) { return 5; }'
        . 'function db_fetch_assoc($sql) { if (strpos($sql, "SHOW TABLES") === 0) return array(array("Tables_in_fixture" => "fixture_table")); if (strpos($sql, "SHOW COLUMNS") === 0) return array(array("Field" => "id", "Type" => "int", "Null" => "NO", "Key" => "PRI", "Default" => null, "Extra" => "")); if (strpos($sql, "SHOW INDEXES") === 0) return array(); throw new LogicException("Unexpected database access: " . $sql); }'
        . 'function db_fetch_row($sql) { if (strpos($sql, "SHOW TABLE STATUS") === 0) return array("Collation" => "utf8mb4_unicode_ci"); throw new LogicException("Unexpected database access: " . $sql); }'
        . 'function db_fetch_cell($sql) { return "fixture"; }'
        . 'function db_table_exists($name) { return in_array("--load", $_SERVER["argv"], true) || (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-report", "audit-drift", "audit-client-discovery"), true) && in_array($name, array("table_columns", "table_indexes"), true)); }'
        . 'function db_fetch_cell_prepared($sql, $params = array()) { if (strpos($sql, "COUNT(*)") !== false) return 1; if (strpos($sql, "table_sequence") !== false) return isset($params[1]) && $params[1] === "name" ? 2 : 1; if (strpos($sql, "table_field") !== false) return "id"; throw new LogicException("Unexpected prepared cell query: " . $sql); }'
        . 'function db_fetch_row_prepared($sql, $params = array()) { if (strpos($sql, "FROM table_columns") !== false) { $field = $params[1] ?? "id"; return $field === "name" ? array("table_field" => "name", "table_type" => "varchar(20)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => "") : array("table_field" => "id", "table_type" => "int", "table_null" => "NO", "table_key" => "PRI", "table_default" => null, "table_extra" => ""); } return array(); }'
        . 'function db_fetch_assoc_prepared($sql, $params = array()) { if (strpos($sql, "FROM table_columns") !== false) { $rows = array(array("table_field" => "id", "table_type" => "int", "table_null" => "NO", "table_key" => "PRI", "table_default" => null, "table_extra" => "")); if (getenv("MAINTENANCE_CLI_SCENARIO") === "audit-drift") $rows[] = array("table_field" => "name", "table_type" => "varchar(20)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); return $rows; } return array(); }'
        . 'function db_column_exists($table, $column) { return $table === "fixture_table" && $column === "id"; } function db_index_exists($table, $index) { return false; }'
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
    'rebuild rejects fractional thread count' => array('rebuild_poller_cache.php', array('--threads=1.5'), 1, 'valid Number of Treads'),
    'reorder rejects zero host ID' => array('reorder_data_query.php', array('--host-id=0', '--qid=all'), 1, '--host-id must be a positive integer'),
    'reorder rejects missing query ID' => array('reorder_data_query.php', array('--host-id=all'), 1, '--qid must be a positive query ID'),
    'data-source reapply rejects zero host ID' => array('poller_data_sources_reapply_names.php', array('--host-id=0'), 1, 'positive device IDs'),
    'graph reapply rejects malformed host IDs' => array('poller_graphs_reapply_names.php', array('--host-id=1,nope'), 1, 'positive device IDs'),
    'add permissions list mode exits successfully' => array('add_perms.php', array('--list-users'), 0, 'LIST_OK'),
    'audit report returns failure when its baseline table is unavailable' => array('audit_database.php', array('--report'), 1, 'FATAL: Unable to load the audit schema baseline'),
    'audit repair stops when its baseline table is unavailable' => array('audit_database.php', array('--repair'), 1, 'FATAL: Unable to load the audit schema baseline'),
    'audit alters preview stops when its baseline table is unavailable' => array('audit_database.php', array('--alters'), 1, 'FATAL: Unable to load the audit schema baseline'),
    'audit load exports the loaded baseline' => array('audit_database.php', array('--load'), 0, 'Finished Creating Audit Schema'),
    'audit report scans baseline columns and table metadata' => array('audit_database.php', array('--report'), 0, 'Audit was clean', 'audit-report'),
    'audit report resolves the installed database client without a test override' => array('audit_database.php', array('--report'), 1, 'FATAL: Unable to load the audit schema baseline', 'audit-client-discovery'),
    'audit repair applies a safe missing-column alteration' => array('audit_database.php', array('--repair'), 0, 'Repair Completed!  All 1 Alters succeeded!', 'audit-drift'),
    'audit alters previews a missing-column alteration' => array('audit_database.php', array('--alters'), 0, '-- Proposed Alter for Table : fixture_table', 'audit-drift'),
));
