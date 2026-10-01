<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$directory = $argv[2];
$scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
mkdir($directory . '/include');
mkdir($directory . '/lib');
mkdir($directory . '/include/themes/classic', 0700, true);
file_put_contents($directory . '/include/themes/classic/main.css', '');
foreach (array('include/auth.php', 'include/global_session.php', 'include/top_header.php', 'include/bottom_footer.php', 'lib/poller.php', 'lib/utility.php') as $stub) {
    file_put_contents($directory . '/' . $stub, '<?php');
}
copy($root . '/data_source_profiles.php', $directory . '/data_source_profiles.php');
copy($root . '/lib/data_source_profile_integrity.php', $directory . '/lib/data_source_profile_integrity.php');
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/data_source_profiles.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/data_source_profiles.php');
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';
$mysql = getenv('PROFILE_DELETE_MYSQL') === '1';
$db = $mysql ? new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '') : new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$tableMap = array();
if (!empty($scenario['editor_tables'])) {
    $tableMap = $scenario['editor_tables'];
} elseif ($mysql) {
    $ownedPrefix = 'pr_profile_' . bin2hex(random_bytes(8)) . '_';
    foreach (array('data_source_profiles','data_source_profiles_rra','data_source_profiles_cf','data_template_data') as $table) {
        $tableMap[$table] = $ownedPrefix . $table;
    }
}
function profile_native_sql($sql)
{
    foreach ($GLOBALS['tableMap'] as $logical => $physical) {
        $sql = preg_replace('/\b' . preg_quote($logical, '/') . '\b/', $physical, $sql);
    }
    return $sql;
}
function profile_native_cleanup()
{
    if (!empty($GLOBALS['scenario']['editor_tables'])) {
        return;
    }
    foreach ($GLOBALS['tableMap'] as $table) {
        $GLOBALS['db']->exec('DROP TABLE IF EXISTS ' . $table);
    }
}
$snapshotRegistered = false;
register_shutdown_function(function () {
    if (!$GLOBALS['snapshotRegistered']) {
        profile_native_cleanup();
    }
});
$prefix = $mysql ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
$profilePrefix = $tableMap ? 'CREATE TABLE ' : $prefix;
$idColumn = $mysql ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY';
if (empty($scenario['editor_tables'])) {
    $db->exec($profilePrefix . profile_native_sql('data_source_profiles (id INTEGER PRIMARY KEY, name VARCHAR(255), hash VARCHAR(64), step INTEGER, heartbeat INTEGER, x_files_factor DOUBLE, `default` VARCHAR(4))'));
    $db->exec($profilePrefix . profile_native_sql('data_source_profiles_rra (id ' . $idColumn . ', data_source_profile_id INTEGER, name VARCHAR(255), steps INTEGER, `rows` INTEGER, timespan INTEGER)'));
    $db->exec($profilePrefix . profile_native_sql('data_source_profiles_cf (data_source_profile_id INTEGER, consolidation_function_id INTEGER, PRIMARY KEY(data_source_profile_id,consolidation_function_id))'));
    $db->exec($profilePrefix . profile_native_sql('data_template_data (id INTEGER PRIMARY KEY, data_source_profile_id INTEGER, local_data_id INTEGER)'));
    $db->exec(profile_native_sql('CREATE INDEX data_source_profile_id ON data_template_data (data_source_profile_id)'));
}
$db->exec($prefix . 'settings (name VARCHAR(64) PRIMARY KEY, value VARCHAR(255))');
$db->exec($prefix . 'settings_user (name VARCHAR(64),user_id INTEGER,value VARCHAR(255))');
$db->exec($prefix . 'user_auth (id INTEGER PRIMARY KEY,username VARCHAR(64),reset_perms INTEGER)');
$db->exec("INSERT INTO user_auth VALUES (7,'fixture-admin',0)");
if (empty($scenario['editor_tables'])) {
    $db->exec(profile_native_sql("INSERT INTO data_source_profiles VALUES (1,'Template profile','abc',300,600,0.5,''),(2,'Local profile','def',300,600,0.5,''),(3,'Unused profile','ghi',300,600,0.5,'')"));
    $db->exec(profile_native_sql("INSERT INTO data_source_profiles_rra VALUES (11,1,'Hourly',1,100,30000),(12,2,'Hourly',1,100,30000),(13,3,'Hourly',1,100,30000)"));
    $db->exec(profile_native_sql('INSERT INTO data_source_profiles_cf VALUES (1,1),(2,1),(3,1)'));
    $db->exec(profile_native_sql('INSERT INTO data_template_data VALUES (1,1,0),(2,2,42)'));
}
$calls = array();
$rollbacks = 0;
$commits = 0;
$failure = $scenario['failure'] ?? '';
if ($mysql && in_array($failure, ['guard-rra-engine', 'guard-cf-engine'], true)) {
    $table = $failure === 'guard-rra-engine' ? 'data_source_profiles_rra' : 'data_source_profiles_cf';
    $db->exec(profile_native_sql('ALTER TABLE ' . $table . ' ENGINE=MyISAM'));
}
function profile_native_statement($sql, $params = array())
{
    $GLOBALS['calls'][] = array($sql, $params);
    if (!$GLOBALS['mysql']) {
        $sql = str_replace('FOR UPDATE', '', $sql);
    }
    $statement = $GLOBALS['db']->prepare(profile_native_sql($sql));
    $statement->execute($params);
    return $statement;
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return profile_native_statement($sql, $params)->fetchColumn();
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_fetch_row_prepared($sql, $params = array())
{
    return profile_native_statement($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    if (str_contains($sql, 'information_schema.TABLES')) {
        if ($GLOBALS['mysql'] && $GLOBALS['failure'] !== 'guard-engine') {
            $statement = $GLOBALS['db']->prepare($sql);
            $statement->execute(array_map(static fn($table) => $GLOBALS['tableMap'][$table] ?? $table, $params));
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        return array(array('TABLE_NAME' => 'data_source_profiles_rra', 'ENGINE' => $GLOBALS['failure'] === 'guard-rra-engine' ? 'MyISAM' : 'InnoDB'), array('TABLE_NAME' => 'data_source_profiles_cf', 'ENGINE' => $GLOBALS['failure'] === 'guard-cf-engine' ? 'MyISAM' : 'InnoDB'), array('TABLE_NAME' => 'data_source_profiles', 'ENGINE' => 'InnoDB'), array('TABLE_NAME' => 'data_template_data', 'ENGINE' => $GLOBALS['failure'] === 'guard-engine' ? 'MyISAM' : 'InnoDB'));
    }
    if (str_contains($sql, 'information_schema.TRIGGERS')) {
        if ($GLOBALS['failure'] === 'guard-missing') {
            return array();
        }
        $rows = array();
        foreach (data_source_profile_reference_triggers() as $name => $definition) {
            $rows[] = array('TRIGGER_NAME' => $name, 'EVENT_OBJECT_TABLE' => $definition['table'], 'ACTION_TIMING' => $definition['timing'], 'EVENT_MANIPULATION' => $definition['event'], 'ACTION_STATEMENT' => $GLOBALS['failure'] === 'guard-modified' ? 'BEGIN END' : $definition['body']);
        }
        return $rows;
    }
    if (str_contains($sql, 'FROM data_template_data') && str_contains($sql, 'FOR UPDATE')) {
        if ($GLOBALS['failure'] === 'lookup-false') {
            return false;
        }
        if ($GLOBALS['failure'] === 'lookup-invalid') {
            return 'invalid';
        }
        if ($GLOBALS['failure'] === 'lookup-invalid-row') {
            return array(array('data_source_profile_id' => 'invalid'));
        }
        if ($GLOBALS['failure'] === 'lookup-aborted') {
            $GLOBALS['db']->rollBack();
            return false;
        }
        if ($GLOBALS['failure'] === 'lookup-throw') {
            throw new RuntimeException('Native lookup failure');
        }
    }
    return profile_native_statement($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function sql_save($values, $table)
{
    $values['id'] = $values['id'] ?: 4;
    $columns = array_keys($values);
    profile_native_statement('REPLACE INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')', array_values($values));
    return $values['id'];
}
function db_execute_prepared($sql, $params = array())
{
    profile_native_statement($sql, $params);
    return true;
}
// The legacy SQL-list helper lives in the database adapter module.
function array_to_sql_or($values, $column)
{
    return $column . ' IN (' . implode(',', array_map('intval', $values)) . ')';
}
function db_execute($sql)
{
    if ($sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
        $GLOBALS['calls'][] = array($sql, array());
        if ($GLOBALS['failure'] === 'isolation') {
            return false;
        }
        if ($GLOBALS['mysql']) {
            $GLOBALS['db']->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $GLOBALS['db']->exec($sql);
        }
        return true;
    }
    $table = $GLOBALS['failure'];
    if (str_starts_with($sql, 'DELETE FROM ') && $table !== '' && str_starts_with($sql, 'DELETE FROM ' . $table . ' WHERE')) {
        $GLOBALS['calls'][] = array($sql, array());
        try {
            $GLOBALS['db']->exec('DELETE FROM ' . $table . ' WHERE unavailable_column=1');
        } catch (PDOException $exception) {
            return false;
        }
        throw new RuntimeException('Expected native delete failure');
    }
    profile_native_statement($sql);
    return true;
}
function db_begin_transaction()
{
    return $GLOBALS['failure'] !== 'begin' && $GLOBALS['db']->beginTransaction();
}
function db_commit_transaction()
{
    $GLOBALS['commits']++;
    return $GLOBALS['failure'] !== 'commit' && $GLOBALS['db']->commit();
}
function db_rollback_transaction()
{
    $GLOBALS['rollbacks']++;
    return $GLOBALS['db']->rollBack();
}
function db_close() {}
function db_table_exists($table)
{
    return in_array($table, array('settings', 'settings_user', 'user_auth', 'data_source_profiles', 'data_source_profiles_rra', 'data_source_profiles_cf', 'data_template_data'), true);
}
function api_plugin_hook($name, ...$arguments) {}
function api_plugin_hook_function($name, $value)
{
    return $value;
}
function number_format_i18n($number, $decimals = null, $baseu = 1024)
{
    return number_format($number, $decimals ?? 2, '.', ',');
}
function __esc($text, ...$values)
{
    return html_escape(__($text, ...$values));
}
function csrf_check($fatal = true)
{
    return true;
}
function get_installed_locales()
{
    return array('en-US' => 'English');
}
function get_new_user_default_language()
{
    return 'en-US';
}
function csrf_check_valid()
{
    return true;
}
function __($text, ...$values)
{
    return $values ? sprintf($text, ...$values) : $text;
}
function __x($context, $text, ...$values)
{
    return __($text, ...$values);
}
function __n($single, $plural, $count)
{
    return $count === 1 ? $single : $plural;
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html.php';
require $root . '/lib/html_validate.php';
require $root . '/lib/html_form.php';
require $root . '/lib/headers_secure.php';
require $root . '/lib/variables.php';
session_save_path($directory);
session_start();
$_SESSION = array('sess_user_id' => 7, 'selected_theme' => 'classic', 'sess_user_perms_key' => 0, 'sess_user_realms' => array_fill_keys(range(1, 1000), true), 'sess_config_array' => array('log_destination' => 1, 'path_cactilog' => $directory . '/native.log', 'log_validation' => '', 'selective_debug' => '', 'selective_plugin_debug' => ''));
$_SERVER['SCRIPT_NAME'] = '/data_source_profiles.php';
$_SERVER['REQUEST_URI'] = '/data_source_profiles.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = $_POST = array_merge(array('action' => 'actions', 'drp_action' => '1', 'selected_items' => serialize($scenario['selected'] ?? array(3)), '__csrf_magic' => 'fixture'), $scenario['request'] ?? array());
$_CACTI_REQUEST = array();
$config = array('base_path' => $root, 'cacti_server_os' => 'unix', 'connection' => 'online', 'cacti_db_version' => '1.3.0', 'poller_id' => 1, 'is_web' => false, 'url_path' => '/', 'config_options_array' => array('log_validation' => '', 'selected_theme' => 'classic', 'log_destination' => 1, 'path_cactilog' => $directory . '/native.log', 'selective_debug' => '', 'selective_plugin_debug' => '', 'log_verbosity' => POLLER_VERBOSITY_LOW, 'date' => 'Y-m-d', 'time' => 'H:i:s', 'auth_method' => 1, 'default_graphs_per_page' => 10));
$no_session_write = array('data_source_profiles.php');
$no_http_header_files = array();
require $root . '/lib/auth.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_settings.php';
require $root . '/include/global_form.php';
$config['base_path'] = $directory;
if (!empty($scenario['editor_tables'])) {
    echo $db->query('SELECT CONNECTION_ID()')->fetchColumn() . "\n";
    flush();
}
ob_start();
register_shutdown_function(function () use ($db, $directory) {
    try {
        $tables = array();
        foreach (array('data_source_profiles', 'data_source_profiles_rra', 'data_source_profiles_cf') as $table) {
            $tables[$table] = $db->query(profile_native_sql('SELECT * FROM ' . $table))->fetchAll(PDO::FETCH_ASSOC);
        }
        file_put_contents($directory . '/result.json', json_encode(array('html' => ob_get_clean(), 'tables' => $tables, 'messages' => $_SESSION['sess_messages'] ?? array(), 'log' => is_file($directory . '/native.log') ? file_get_contents($directory . '/native.log') : '', 'calls' => $GLOBALS['calls'], 'rollbacks' => $GLOBALS['rollbacks'], 'commits' => $GLOBALS['commits']), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    } finally {
        profile_native_cleanup();
    }
});
$snapshotRegistered = true;
chdir($directory);
require $directory . '/data_source_profiles.php';
