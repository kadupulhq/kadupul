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
copy($root . '/lib/api_automation.php', $directory . '/api_automation.php');
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/api_automation.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/lib/api_automation.php');
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$definitions = array(
    'graph_tree' => 'id INTEGER PRIMARY KEY, sort_type INTEGER',
    'graph_tree_items' => 'id INTEGER PRIMARY KEY, graph_tree_id INTEGER, title TEXT, parent INTEGER, local_graph_id INTEGER DEFAULT 0, host_id INTEGER DEFAULT 0, site_id INTEGER DEFAULT 0, host_grouping_type INTEGER DEFAULT 0, sort_children_type INTEGER DEFAULT 1, position INTEGER DEFAULT 1',
    'sites' => 'id INTEGER PRIMARY KEY, name TEXT',
    'host' => 'id INTEGER PRIMARY KEY, hostname TEXT, description TEXT, disabled TEXT, status INTEGER, host_template_id INTEGER, deleted TEXT',
    'host_template' => 'id INTEGER PRIMARY KEY, name TEXT',
    'graph_local' => 'id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER',
    'graph_templates' => 'id INTEGER PRIMARY KEY, name TEXT',
    'graph_templates_graph' => 'local_graph_id INTEGER, graph_template_id INTEGER, title_cache TEXT',
    'automation_tree_rules' => 'id INTEGER PRIMARY KEY, leaf_type INTEGER',
    'automation_match_rule_items' => 'id INTEGER PRIMARY KEY, rule_id INTEGER, rule_type INTEGER, sequence INTEGER, operation INTEGER, field TEXT, operator INTEGER, pattern TEXT',
    'settings' => 'name TEXT PRIMARY KEY, value TEXT',
    'settings_user' => 'name TEXT,user_id INTEGER,value TEXT',
    'user_auth' => 'id INTEGER PRIMARY KEY,username TEXT,reset_perms INTEGER',
);
foreach ($definitions as $table => $columns) {
    $db->exec('CREATE TABLE ' . $table . ' (' . $columns . ')');
}
$db->exec("INSERT INTO user_auth VALUES (7,'fixture-admin',0)");
$db->exec('INSERT INTO graph_tree VALUES (8,1),(9,1)');
$db->exec("INSERT INTO graph_tree_items (id,graph_tree_id,parent,title) VALUES (77,8,0,'Parent'),(88,9,0,'Unrelated')");
$db->exec("INSERT INTO host_template VALUES (9,'Fixture template')");
$stmt = $db->prepare("INSERT INTO host VALUES (7,'127.0.0.1',?,'',3,9,'')");
$stmt->execute(array($scenario['target']));
$calls = array();
function automation_native_statement($sql, $params = array())
{
    $GLOBALS['calls'][] = array($sql, $params);
    // SQLite spells MySQL's null-safe equality operator IS.
    $sql = str_replace('<=>', 'IS', $sql);
    if (str_contains($sql, 'ON DUPLICATE KEY UPDATE') && str_contains($sql, 'INSERT INTO settings')) {
        $sql = 'INSERT OR REPLACE INTO settings (name,value) VALUES (?,?)';
    }
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return automation_native_statement($sql, $params)->fetchColumn();
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_fetch_row_prepared($sql, $params = array())
{
    return automation_native_statement($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    return automation_native_statement($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function sql_save($values, $table)
{
    if (empty($values['id'])) {
        unset($values['id']);
    }
    $columns = array_keys($values);
    automation_native_statement('INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')', array_values($values));
    return (int) $GLOBALS['db']->lastInsertId();
}
function db_qstr($value)
{
    return $GLOBALS['db']->quote($value);
}
function db_column_exists($table, $column)
{
    return in_array($column, array_column($GLOBALS['db']->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
}
function db_execute_prepared($sql, $params = array())
{
    automation_native_statement($sql, $params);
    return true;
}
function db_execute($sql)
{
    automation_native_statement($sql);
    return true;
}
function db_close() {}
function db_table_exists($table)
{
    return isset($GLOBALS['definitions'][$table]);
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
$_SERVER['SCRIPT_NAME'] = '/api_automation.php';
$_SERVER['REQUEST_URI'] = '/api_automation.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = $_POST = array('id' => 8, 'header' => 'false', 'rows' => 10, 'page' => 1, 'host_status' => -1, 'host_template_id' => -1, 'sort_column' => 'description', 'sort_direction' => 'ASC', 'filter' => '');
$_CACTI_REQUEST = array();
$config = array('base_path' => $root, 'cacti_server_os' => 'unix', 'connection' => 'online', 'cacti_db_version' => '1.3.0', 'poller_id' => 1, 'is_web' => false, 'url_path' => '/', 'config_options_array' => array('log_validation' => '', 'selected_theme' => 'classic', 'log_destination' => 1, 'path_cactilog' => $directory . '/native.log', 'selective_debug' => '', 'selective_plugin_debug' => '', 'log_verbosity' => POLLER_VERBOSITY_LOW, 'date' => 'Y-m-d', 'time' => 'H:i:s', 'auth_method' => 1, 'default_graphs_per_page' => 10, 'num_rows_table' => 10));
$no_session_write = array('api_automation.php');
$no_http_header_files = array();
require $root . '/lib/auth.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_settings.php';
require $root . '/include/global_form.php';
$config['base_path'] = $directory;
ob_start();
register_shutdown_function(function () use ($db, $directory) {
    file_put_contents($directory . '/result.json', json_encode(array('html' => ob_get_clean(), 'nodes' => $db->query('SELECT * FROM graph_tree_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'messages' => $_SESSION['sess_messages'] ?? array(), 'log' => is_file($directory . '/native.log') ? file_get_contents($directory . '/native.log') : '', 'calls' => $GLOBALS['calls'], 'result' => $GLOBALS['result'] ?? null), JSON_THROW_ON_ERROR));
});
require $root . '/lib/api_tree.php';
require $directory . '/api_automation.php';
$rule = array('id' => 8, 'tree_id' => 8, 'host_grouping_type' => 1);
$item = array('field' => 'h.description', 'search_pattern' => $scenario['search'], 'replace_pattern' => $scenario['replace'], 'propagate_changes' => '', 'sort_type' => 1);
if ($scenario['mode'] === 'preview') {
    $db->exec('INSERT INTO automation_tree_rules VALUES (8,' . TREE_ITEM_TYPE_HOST . ')');
    $db->exec("INSERT INTO automation_match_rule_items VALUES (1,8," . AUTOMATION_RULE_TYPE_TREE_MATCH . ",1,0,'h.id'," . AUTOMATION_OP_MATCHES . ",'7')");
    display_matching_trees(8, AUTOMATION_RULE_TYPE_TREE_MATCH, $item, 'automation_tree_rules.php?action=item_edit&id=8');
} elseif ($scenario['mode'] === 'handoff') {
    $GLOBALS['result'] = create_multi_header_node($scenario['target'], $rule, $item, 77);
    if (!empty($scenario['repeat'])) {
        $GLOBALS['result'] = create_multi_header_node($scenario['target'], $rule, $item, 77);
    }
} else {
    $GLOBALS['result'] = automation_string_replace($scenario['search'], $scenario['replace'], $scenario['target']);
}
