<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Real legacy functions and schema-backed SQL. Native authentication and
// unrelated HTML layout/translation are outside this focused read regression.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
$input = json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR);
$db = new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
$schema = 'snmp_description_' . bin2hex(random_bytes(8));
$created = false;
$logRows = [];
$total = null;
$queries = [];
$_REQUEST = array_replace(['filter' => '', 'severity' => '-1', 'receiver' => '-1', 'rows' => '-1', 'page' => '1', 'id' => '1'], $input['request']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];
$item_rows = [1 => 'One', 2 => 'Two'];
function db_fetch_assoc($sql) {
    $GLOBALS['queries'][] = $sql;
    $rows = $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (strpos($sql, 'snl.*') !== false) $GLOBALS['logRows'] = $rows;
    return $rows;
}
function db_fetch_cell($sql) {
    $GLOBALS['queries'][] = $sql;
    return $GLOBALS['total'] = $GLOBALS['db']->query($sql)->fetchColumn();
}
function db_qstr($value) { return $GLOBALS['db']->quote($value); }
function db_execute($sql) { throw new RuntimeException('A read regression attempted a mutation.'); }
function db_execute_prepared($sql, $parameters) { throw new RuntimeException('A read regression attempted a mutation.'); }
function get_request_var($name) { return $_REQUEST[$name] ?? ''; }
function get_filter_request_var($name) { return get_request_var($name); }
function isset_request_var($name) { return isset($_REQUEST[$name]); }
function isempty_request_var($name) { return !isset($_REQUEST[$name]) || $_REQUEST[$name] === ''; }
function set_request_var($name, $value) { $_REQUEST[$name] = $value; }
function validate_store_request_vars($filters, $prefix) {}
function read_config_option($name) { return $name === 'num_rows_table' ? 2 : ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message) { throw new RuntimeException('Unexpected native read warning.'); }
function die_html_input_error() { throw new RuntimeException('Unexpected native validation failure.'); }
function html_start_box(...$arguments) {}
function html_end_box(...$arguments) {}
function html_nav_bar(...$arguments) { return ''; }
function form_start(...$arguments) {}
function html_header(...$arguments) {}
function form_alternate_row(...$arguments) {}
function form_end_row(...$arguments) {}
function html_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function filter_value($value, $filter) { return html_escape($value); }
function __($text, ...$arguments) { return $arguments ? vsprintf($text, $arguments) : $text; }
function __esc($text, ...$arguments) { return html_escape(__($text, ...$arguments)); }
function __esc_x($text, $context) { return html_escape($text); }
class CactiSecureHeaders { public static function getNonceAttribute() { return 'nonce="fixture"'; } }
try {
    $db->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
    $created = true;
    $db->exec('USE `' . $schema . '`');
    $db->exec("SET SESSION sql_mode = ''");
    $source = file_get_contents($root . '/cacti.sql');
    if (!is_string($source)) throw new RuntimeException('Actual schema is unavailable.');
    foreach (['snmpagent_cache', 'snmpagent_managers', 'snmpagent_notifications_log'] as $table) {
        if (preg_match('/CREATE TABLE `?' . $table . '`?\s*\([^;]*;/s', $source, $match) !== 1) throw new RuntimeException('Actual notification schema is unavailable.');
        $db->exec($match[0]);
    }
    $db->exec("INSERT INTO snmpagent_managers (id, hostname, description, max_log_size, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_message_type) VALUES (1, 'fixture.test', 'Fixture', 10, '', '', '', '', '', 1)");
    $db->exec("INSERT INTO snmpagent_cache (oid, name, mib, description) VALUES ('1', 'shared', 'MIB-A', 'Description A'), ('2', 'shared', 'MIB-B', 'Description B'), ('3', 'shared', 'MIB-B', 'Description B alias')");
    $db->exec("INSERT INTO snmpagent_notifications_log (id, time, severity, manager_id, notification, mib, varbinds) VALUES (1, 100, 2, 1, 'shared', 'MIB-B', 'b_payload'), (2, 101, 4, 1, 'uncached', 'MIB-C', 'missing_payload'), (3, 102, 1, 1, 'shared', 'MIB-A', 'a_payload'), (4, 103, 1, 999, 'shared', 'MIB-A', 'orphan_receiver')");
    $file = $input['view'] === 'utilities' ? 'utilities.php' : 'managers.php';
    $function = $input['view'] === 'utilities' ? 'snmpagent_utilities_run_eventlog' : 'manager_logs';
    $source = file_get_contents($root . '/' . $file);
    if (!is_string($source)) throw new RuntimeException('Actual event renderer is unavailable.');
    eval(test_php_function_source($source, $function));
    ob_start();
    try {
        if ($input['view'] === 'utilities') snmpagent_utilities_run_eventlog();
        else manager_logs(1, 'Fixture');
    } finally {
        ob_end_clean();
    }
    print json_encode(['rows' => $logRows, 'total' => (int) $total], JSON_THROW_ON_ERROR);
} finally {
    if ($created) $db->exec('DROP DATABASE `' . $schema . '`');
}
