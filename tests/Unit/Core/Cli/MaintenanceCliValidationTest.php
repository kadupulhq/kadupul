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
        . 'require_once ' . var_export($root . '/include/vendor/autoload.php', true) . ';'
        . '$config = array("base_path" => dirname(__DIR__), "poller_id" => 1);'
        . 'if (is_file(dirname(__DIR__) . "/bin/mysql")) putenv("CACTI_MYSQL_CLIENT=" . dirname(__DIR__) . "/bin/mysql");'
        . 'define("CACTI_VERSION", "fixture"); define("COPYRIGHT_YEARS", "2026");'
        . '$database_default = "fixture"; $database_username = "fixture"; $database_password = "fixture"; $database_hostname = "fixture"; $database_port = "3306"; $database_ssl = false;'
        . 'function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }'
        . 'function get_cacti_cli_version() { return "fixture"; }'
        . 'function read_config_option($name) { return 5; }'
        . 'function db_fetch_assoc($sql) { if (str_starts_with(getenv("MAINTENANCE_CLI_SCENARIO") ?: "", "reorder-")) return array(array("host_id" => 7, "snmp_query_id" => 11, "snmp_index" => "3", "value" => 4)); if (getenv("MAINTENANCE_CLI_SCENARIO") === "reapply-zero") { print "SELECTED_QUERY:" . $sql; return array(); } if (strpos($sql, "SHOW TABLES") === 0) return array(array("Tables_in_fixture" => "fixture_table")); if (strpos($sql, "SHOW COLUMNS") === 0) { $rows = array(array("Field" => "id", "Type" => "int", "Null" => "NO", "Key" => "PRI", "Default" => null, "Extra" => "")); if (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-column-drift", "audit-index-missing", "audit-index-key-mismatch"), true)) $rows[] = array("Field" => "name", "Type" => getenv("MAINTENANCE_CLI_SCENARIO") === "audit-column-drift" ? "varchar(10)" : "varchar(20)", "Null" => "YES", "Key" => "", "Default" => null, "Extra" => ""); if (getenv("MAINTENANCE_CLI_SCENARIO") === "audit-column-order") $rows[] = array("Field" => "label", "Type" => "varchar(50)", "Null" => "YES", "Key" => "", "Default" => null, "Extra" => ""); return $rows; } if (strpos($sql, "SHOW INDEXES") === 0 && getenv("MAINTENANCE_CLI_SCENARIO") === "audit-index-key-mismatch") return array(array("Table" => "fixture_table", "Non_unique" => 0, "Key_name" => "idx_name", "Seq_in_index" => 1, "Column_name" => "name", "Packed" => null, "Comment" => "", "Index_type" => "BTREE")); if (strpos($sql, "SHOW INDEXES") === 0) return array(); throw new LogicException("Unexpected database access: " . $sql); }'
        . 'function db_fetch_row($sql) { if (strpos($sql, "SHOW TABLE STATUS") === 0) return array("Collation" => "utf8mb4_unicode_ci"); throw new LogicException("Unexpected database access: " . $sql); }'
        . 'function db_fetch_cell($sql) { return strpos($sql, "COUNT(*)") !== false ? 1 : "fixture"; }'
        . 'function db_table_exists($name) { if (preg_match("/^audit_(complete|columns|indexes|old_columns|old_indexes)_/", $name)) return true; return in_array("--load", $_SERVER["argv"], true) || (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-report", "audit-column-drift", "audit-column-order", "audit-drift", "audit-index-missing", "audit-index-key-mismatch", "audit-client-discovery"), true) && in_array($name, array("table_columns", "table_indexes"), true)); }'
        . 'function db_fetch_cell_prepared($sql, $params = array()) { if (str_starts_with(getenv("MAINTENANCE_CLI_SCENARIO") ?: "", "template-")) return 1; if (strpos($sql, "COUNT(*)") !== false) return 1; if (strpos($sql, "SELECT table_sequence") !== false) return isset($params[1]) && $params[1] === "name" ? 2 : 3; if (strpos($sql, "SELECT table_field") !== false) return "id"; throw new LogicException("Unexpected prepared cell query: " . $sql); }'
        . 'function db_fetch_row_prepared($sql, $params = array()) { if (strpos($sql, "information_schema.tables") !== false) return array("ENGINE" => "InnoDB", "COLLATION" => "utf8mb4"); if (strpos($sql, "FROM table_columns") !== false) { $field = $params[1] ?? "id"; if ($field === "name") return array("table_field" => "name", "table_type" => "varchar(20)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); if ($field === "label") return array("table_field" => "label", "table_type" => "varchar(50)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); return array("table_field" => "id", "table_type" => "int", "table_null" => "NO", "table_key" => "PRI", "table_default" => null, "table_extra" => ""); } if (strpos($sql, "FROM table_indexes") !== false && getenv("MAINTENANCE_CLI_SCENARIO") === "audit-index-key-mismatch") return array("idx_non_unique" => 1, "idx_key_name" => "idx_name", "idx_seq_in_index" => 1, "idx_column_name" => "name", "idx_packed" => null, "idx_comment" => "", "idx_index_type" => "BTREE"); return array(); }'
        . 'function db_fetch_assoc_prepared($sql, $params = array()) { if (strpos($sql, "FROM table_columns") !== false) { $rows = array(array("table_field" => "id", "table_type" => "int", "table_null" => "NO", "table_key" => "PRI", "table_default" => null, "table_extra" => "")); if (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-drift", "audit-column-drift", "audit-index-missing", "audit-index-key-mismatch"), true)) $rows[] = array("table_field" => "name", "table_type" => "varchar(20)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); if (getenv("MAINTENANCE_CLI_SCENARIO") === "audit-column-order") { $rows[] = array("table_field" => "name", "table_type" => "varchar(20)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); $rows[] = array("table_field" => "label", "table_type" => "varchar(50)", "table_null" => "YES", "table_key" => "", "table_default" => null, "table_extra" => ""); } return $rows; } if (strpos($sql, "FROM table_indexes") !== false && in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-index-missing", "audit-index-key-mismatch"), true)) return array(array("idx_table_name" => "fixture_table", "idx_key_name" => "idx_name", "idx_seq_in_index" => 1, "idx_column_name" => "name", "idx_non_unique" => 1, "idx_index_type" => "BTREE")); return array(); }'
        . 'function db_column_exists($table, $column) { return $table === "fixture_table" && ($column === "id" || (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("audit-column-drift", "audit-index-missing", "audit-index-key-mismatch"), true) && $column === "name") || (getenv("MAINTENANCE_CLI_SCENARIO") === "audit-column-order" && $column === "label")); } function db_index_exists($table, $index) { return getenv("MAINTENANCE_CLI_SCENARIO") === "audit-index-key-mismatch" && $index === "idx_name"; }'
        . 'function db_client_ssl_option($enabled, $version) { return ""; }'
        . 'function db_execute($sql) { return true; } function db_execute_prepared($sql, $params) { return true; }'
        . 'function db_dump_data(...$args) { return 0; }'
        . 'function cacti_escapeshellarg($value) { return escapeshellarg($value); }');

    if (str_starts_with($scenario, 'reorder-')) {
        file_put_contents($dir . '/lib/data_query.php', '<?php '
            . 'function update_data_query_sort_cache($host, $query) { return "ifName"; }'
            . 'function get_best_data_query_index_type($host, $query) { return match (getenv("MAINTENANCE_CLI_SCENARIO")) { "reorder-false" => false, "reorder-empty" => "", "reorder-zero" => "0", default => "ifName" }; }'
            . 'function update_snmp_index_order($row) { if (in_array(getenv("MAINTENANCE_CLI_SCENARIO"), array("reorder-false", "reorder-empty"), true)) throw new RuntimeException("Reorder reached after a failed read"); print "REORDER_OK:" . $row["snmp_index_on"]; }');
    }

    if (str_starts_with($scenario, 'template-')) {
        require_once $root . '/tests/Helpers/PhpSource.php';
        $source = file_get_contents($root . '/lib/import.php');
        expect($source)->toBeString();
        $renderer = test_php_function_source($source, 'import_display_results');
        $producer = '';
        if (in_array($scenario, array('template-malformed', 'template-empty', 'template-invalid-hash', 'template-trailing-data'), true)) {
            $producer = test_php_function_source($source, 'import_xml_data')
                . ($scenario === 'template-trailing-data'
                    ? 'function parse_xml_hash($hash) { throw new RuntimeException("Malformed XML reached hash processing"); }'
                    : test_php_function_source($source, 'parse_xml_hash'));
            copy($root . '/lib/xml.php', $dir . '/lib/xml.php');
        }
        $ports = <<<'PHP'
if (!function_exists('import_xml_data')) {
function import_xml_data($xml, ...$options) {
    if ($xml !== '<cacti/>') throw new RuntimeException('CLI did not pass the template bytes');
    $scenario = getenv('MAINTENANCE_CLI_SCENARIO');
    if ($scenario === 'template-noop') return array();
    if ($scenario === 'template-false' || $scenario === 'template-preview-false') return false;
    if ($scenario === 'template-message') { $GLOBALS['import_messages'][] = 7; return array(); }
    if ($scenario === 'template-partial') return array('host_template' => array(
        array('title' => 'Fixture device', 'type' => 'updated', 'result' => 'success'),
        array('title' => 'Rejected template', 'type' => 'new', 'result' => 'fail')),
        'files' => array('fixture.xml' => 'unchanged'));
    if ($scenario === 'template-unchanged') return array('host_template' => array(array('title' => 'Fixture device', 'type' => 'unchanged', 'result' => 'success')));
    return array('host_template' => array(array('title' => 'Fixture device', 'type' => 'updated', 'result' => $GLOBALS['preview_only'] ? 'preview' : 'success')));
}
}
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function cacti_count($value) { return count($value); }
function cacti_log(...$args) {}
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function html_start_box($title, ...$options) { print '<table><tr><th>' . $title . '</th></tr>'; }
function html_end_box() { print '</table>'; }
$hash_type_names = array('host_template' => 'Device Templates');
$ignorable_hashes = array();
$messages = array(7 => array('message' => 'XML parse error.'));
$config['library_path'] = __DIR__;
define('POLLER_VERBOSITY_LOW', 2);
PHP;
        file_put_contents($dir . '/lib/import.php', '<?php ' . $renderer . $producer . $ports);
        $xml = match ($scenario) {
            'template-malformed' => '<cacti>',
            'template-empty' => '',
            'template-invalid-hash' => '<cacti><invalid_hash><name>Invalid</name></invalid_hash></cacti>',
            'template-trailing-data' => '<cacti><hash_0000010123456789abcdef0123456789abcdef><name>Fixture</name></hash_0000010123456789abcdef0123456789abcdef></cacti>junk',
            default => '<cacti/>',
        };
        file_put_contents($dir . '/docs/template.xml', $xml);
        $arguments[] = '--filename=' . $dir . '/docs/template.xml';
    }

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
        if (in_array($scenario, array('template-preview', 'template-import'), true)) {
            expect($output)->not->toContain('<table', '<tr', '<span', '</', 'class=')
                ->and($output)->toContain('Fixture device', '[updated]');
            if ($scenario === 'template-preview') {
                expect($output)->toContain('Import Preview Results', '[preview]')
                    ->not->toContain('has imported', '[success]');
            } else {
                expect($output)->toContain('Import Results', '[success]')
                    ->not->toContain('[preview]', 'would make');
            }
        }
        if (str_starts_with($scenario, 'template-')) {
            expect($output)->not->toContain('Warning:', 'Fatal error:', '<table', '<span');
            if ($scenario === 'template-partial') {
                expect($output)->toContain('[success]', 'Fixture device', '[fail]', 'Rejected template');
            }
        }

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
    'malformed template XML reports failure' => array('import_template.php', array(), 1, 'XML parse error.', 'template-malformed'),
    'trailing XML content fails before hash processing' => array('import_template.php', array(), 1, 'XML parse error.', 'template-trailing-data'),
    'empty template XML reports failure' => array('import_template.php', array(), 1, 'XML parse error.', 'template-empty'),
    'invalid template hash reports failure without PHP warnings' => array('import_template.php', array(), 1, 'XML parse error.', 'template-invalid-hash'),
    'false import outcome reports failure' => array('import_template.php', array(), 1, 'ERROR: Template import or preview failed', 'template-false'),
    'false preview outcome reports failure' => array('import_template.php', array('--preview'), 1, 'ERROR: Template import or preview failed', 'template-preview-false'),
    'message-only import failure reports diagnostics' => array('import_template.php', array(), 1, 'XML parse error.', 'template-message'),
    'partial import failure preserves per-object results' => array('import_template.php', array(), 1, 'ERROR: Template import or preview failed', 'template-partial'),
    'unchanged template import remains successful' => array('import_template.php', array(), 0, '[unchanged]', 'template-unchanged'),
    'benign empty import result remains successful' => array('import_template.php', array(), 0, 'Read 8 bytes of XML data', 'template-noop'),
    'template preview uses plain text and proposed-change wording' => array('import_template.php', array('--preview'), 0, 'would make the following changes', 'template-preview'),
    'template import retains plain text and completed-change wording' => array('import_template.php', array(), 0, 'has imported the following items', 'template-import'),
    'rebuild rejects zero host ID' => array('rebuild_poller_cache.php', array('--host-id=0'), 1, '--host-id must be a positive integer'),
    'rebuild rejects zero thread count' => array('rebuild_poller_cache.php', array('--threads=0'), 1, 'valid Number of Treads'),
    'rebuild rejects negative thread count' => array('rebuild_poller_cache.php', array('--threads=-1'), 1, 'valid Number of Treads'),
    'rebuild rejects fractional thread count' => array('rebuild_poller_cache.php', array('--threads=1.5'), 1, 'valid Number of Treads'),
    'reorder refuses failed sort-field reads' => array('reorder_data_query.php', array('--host-id=7', '--qid=11'), 1, 'Unable to determine sort field', 'reorder-false'),
    'reorder refuses empty sort fields' => array('reorder_data_query.php', array('--host-id=7', '--qid=11'), 1, 'Unable to determine sort field', 'reorder-empty'),
    'reorder preserves zero field names' => array('reorder_data_query.php', array('--host-id=7', '--qid=11'), 0, 'REORDER_OK:0', 'reorder-zero'),
    'reorder runs with a resolved field' => array('reorder_data_query.php', array('--host-id=7', '--qid=11'), 0, 'REORDER_OK:ifName', 'reorder-valid'),
    'reorder rejects zero host ID' => array('reorder_data_query.php', array('--host-id=0', '--qid=all'), 1, '--host-id must be a positive integer'),
    'reorder rejects missing query ID' => array('reorder_data_query.php', array('--host-id=all'), 1, '--qid must be a positive query ID'),
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
