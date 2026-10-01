<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$directory = $argv[2];
$source = $root . '/install/upgrades/1_2_31.php';
$copy = $directory . '/upgrade.php';
copy($source, $copy);
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $copy);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $source);
    require __DIR__ . '/rrd-process-coverage.php';
}
$mysql = getenv('PROFILE_DELETE_MYSQL') === '1';
$db = $mysql ? new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '') : new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$prefix = $mysql ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
foreach (array(
    'data_template_data' => 'id INTEGER PRIMARY KEY, data_source_profile_id INTEGER',
    'automation_devices' => 'snmp_priv_protocol VARCHAR(6)',
    'automation_snmp_items' => 'snmp_priv_protocol VARCHAR(6)',
    'snmpagent_managers' => 'snmp_priv_protocol VARCHAR(6)',
    'settings' => 'name VARCHAR(64)',
    'settings_user' => 'name VARCHAR(64)',
    'snmp_query_graph' => 'snmp_query_id INTEGER, graph_template_id INTEGER',
    'user_auth_row_cache' => 'class VARCHAR(64), time INTEGER',
) as $table => $columns) {
    $db->exec($prefix . $table . ' (' . $columns . ')');
}
$calls = array();
function db_index_exists($table, $index)
{
    $rows = $GLOBALS['db']->query($GLOBALS['mysql'] ? 'SHOW INDEX FROM ' . $table : 'PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    return in_array($index, array_column($rows, $GLOBALS['mysql'] ? 'Key_name' : 'name'), true);
}
function db_install_execute($sql)
{
    $GLOBALS['calls'][] = $sql;
    if (!$GLOBALS['mysql']) {
        // SQLite can verify the index migration; unrelated MySQL DDL is isolated.
        if (!preg_match('/^ALTER TABLE (\w+) ADD INDEX (\w+) (\(.+\))$/', $sql, $match)) {
            return true;
        }
        $sql = 'CREATE INDEX ' . $match[2] . ' ON ' . $match[1] . ' ' . $match[3];
    } else {
        // Keep all migration objects owned by this connection.
        $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $sql);
    }
    $GLOBALS['db']->exec($sql);
    return true;
}
require $copy;
upgrade_to_1_2_31();
upgrade_to_1_2_31();
$indexes = $db->query($mysql ? 'SHOW INDEX FROM data_template_data' : 'PRAGMA index_list(data_template_data)')->fetchAll(PDO::FETCH_ASSOC);
file_put_contents($directory . '/result.json', json_encode(array('calls' => $calls, 'runs' => 2, 'indexes' => array_column($indexes, $mysql ? 'Key_name' : 'name')), JSON_THROW_ON_ERROR));
