<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute the production report helpers and authorization against SQL tables.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
$coverageSources = array('tests/Unit/Security/Auth/ReportPersistenceNativeCoverageTest.php', 'composer.lock', 'tests/composer.lock', 'tests/Fixtures/report-persistence-native.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
    'lib/auth.php', 'lib/database.php', 'tests/Helpers/PhpSource.php', 'lib/reports.php', 'lib/html_reports.php', 'include/global_constants.php', 'include/global_arrays.php', 'lib/time.php', 'lib/html.php', 'lib/html_form.php', 'lib/data_query.php', 'lib/sort.php', 'lib/html_tree.php', 'lib/html_utility.php',
    'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
if (isset($argv[3])) {
    $GLOBALS['nativeCoverageEvidence'] = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/report-persistence-native.php', $argv[1], $coverageSources);
}
$config = array('base_path' => $root, 'include_path' => $root . '/include', 'library_path' => $root . '/lib', 'url_path' => '/kadupul/', 'poller_id' => 1, 'connection' => 'online', 'is_web' => false, 'cacti_server_os' => 'unix');
$alignment = array();
$attach_types = array();
$reports_interval = array();
$options = $scenario['options'] ?? array();
$_REQUEST = array('id' => 7, 'tab' => 'items', 'item_id' => 70, 'report_item' => array('line71', 'line70', 'line80'));
$request = & $_REQUEST;
$_SESSION['sess_user_id'] = $scenario['user'] ?? 42;
$_SERVER['REQUEST_METHOD'] = 'POST';
$messages = array();
$messageDetails = array();
// SQLite exercises report outcomes and real transaction ownership here.
// FOR UPDATE lock behavior is separately tested on MySQL/MariaDB by the
// native placement database contracts, rather than simulated in this fixture.
$db = new class ('sqlite::memory:') extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(preg_replace('/\\s+FOR UPDATE\\b/i', '', $query), $options);
    }
};
$database_hostname = 'report-fixture';
$database_port = 0;
$database_default = 'owned';
$database_sessions = ['report-fixture:0:owned' => $db];
require_once $root . '/tests/Helpers/PhpSource.php';
$databaseSource = file_get_contents($root . '/lib/database.php');
if (!is_string($databaseSource)) {
    throw new RuntimeException('Cannot read report transaction helpers');
}
foreach (['db_begin_transaction', 'db_commit_transaction', 'db_rollback_transaction'] as $function) {
    eval(test_php_function_source($databaseSource, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
$db->exec('CREATE TABLE user_auth_group_members (user_id INTEGER, group_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE reports_items (id INTEGER PRIMARY KEY AUTOINCREMENT, report_id INTEGER, item_type INTEGER, host_template_id INTEGER, site_id INTEGER, host_id INTEGER, graph_template_id INTEGER, local_graph_id INTEGER, timespan INTEGER, align INTEGER, sequence INTEGER, item_text TEXT DEFAULT \'\')');
$db->exec('CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT, host_template_id INTEGER, site_id INTEGER)');
$db->exec('CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER)');
$db->exec("INSERT INTO host VALUES (100, 'Router <one>', 3, 4)");
$db->exec('INSERT INTO graph_local VALUES (200, 100, 6)');
$db->exec('INSERT INTO reports_items (id, report_id, sequence) VALUES (70, 7, 1), (71, 7, 2), (80, 8, 1)');
if (isset($scenario['realm'])) {
    $db->prepare('INSERT INTO user_auth_realm VALUES (?, ?)')->execute(array($_SESSION['sess_user_id'], $scenario['realm']));
}
if (isset($scenario['group'])) {
    $db->prepare('INSERT INTO user_auth_group VALUES (5, ?)')->execute(array($scenario['group']));
    $db->prepare('INSERT INTO user_auth_group_members VALUES (?, 5)')->execute(array($_SESSION['sess_user_id']));
    $db->exec('INSERT INTO user_auth_group_realm VALUES (5, 21)');
}
function db_fetch_row_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchColumn();
}
function db_execute_prepared($sql, $params = array())
{
    return $GLOBALS['db']->prepare($sql)->execute($params);
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_fetch_row($sql)
{
    return db_fetch_row_prepared($sql);
}
function read_user_setting($name, ...$ignored)
{
    return $name === 'first_weekdayid' ? 0 : '';
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function get_rrdtool_version()
{
    return '1.8.0';
} // Isolate executable discovery; no device or RRD writes.
function cacti_version_compare($a, $b, $op)
{
    return version_compare($a, $b, $op);
}
function null_out_substitutions($value)
{
    return $value;
}
function api_plugin_hook(...$ignored) {} // No external plugins in the owned fixture.
function db_qstr_rlike($pattern)
{
    return 'REGEXP ' . $GLOBALS['db']->quote($pattern);
}
function db_table_exists($table)
{
    return (bool) db_fetch_cell_prepared('SELECT 1 FROM sqlite_master WHERE type = ? AND name = ?', array('table', $table));
}
function read_config_option($name)
{
    return $GLOBALS['options'][$name] ?? '';
}
function sanitize_search_string($text)
{
    return $text;
}
function input_validate_input_number($value)
{
    if (!ctype_digit((string) $value)) {
        throw new InvalidArgumentException('Invalid fixture item id.');
    }
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function __x($context, $text, ...$args)
{
    return __($text, ...$args);
}
function cacti_log(...$args) {}
function raise_message($key, ...$args)
{
    $GLOBALS['messages'][] = $key;
    $GLOBALS['messageDetails'][] = $args;
}
function sql_save($save, $table)
{
    unset($save['id']);
    $fields = array_keys($save);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')';
    if (!$GLOBALS['db']->prepare($sql)->execute(array_values($save))) {
        return 0;
    }
    return (int) $GLOBALS['db']->lastInsertId();
}
require $root . '/include/global_constants.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('REPORT_PERSISTENCE_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/html_utility.php';
require $root . '/lib/auth.php';
require $root . '/lib/reports.php';
require $root . '/lib/html_reports.php';
// Use the production form metadata for report columns, including the fields
// duplicate_reports copies. All rows are persisted, not canned return values.
$columns = array('id INTEGER PRIMARY KEY AUTOINCREMENT', 'user_id INTEGER');
foreach ($fields_reports_edit as $field => $descriptor) {
    if (!str_starts_with($descriptor['method'], 'hidden') && !str_starts_with($descriptor['method'], 'spacer')) {
        $columns[] = $field . " TEXT DEFAULT ''";
    }
}
$db->exec('CREATE TABLE reports (' . implode(', ', $columns) . ')');
$db->exec("INSERT INTO reports (id, user_id, name, enabled) VALUES (7, 42, 'Weekly network', 'on'), (8, 99, 'Other owner', 'on')");
$db->exec('UPDATE reports SET font_size=10, graph_columns=2, graph_width=400, graph_height=100 WHERE id=7');
$result = null;
$operation = $scenario['operation'];
if (!empty($scenario['reject_write'])) {
    // Real rejected statement, with the original database-helper false outcome.
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    $db->exec("CREATE TRIGGER reject_report_item BEFORE INSERT ON reports_items BEGIN SELECT RAISE(FAIL,'owned failure control'); END");
}
if (str_starts_with($operation, 'legacy-')) {
    require $root . '/lib/html.php';
    require $root . '/lib/html_form.php';
    $alignment = array(1 => 'Left', 2 => 'Center');
    $graph_timespans = array(4 => 'Last four hours', 5 => 'Last six hours');
    $db->exec('INSERT INTO graph_local VALUES (201, 0, 0)');
    foreach (array('tree_id INTEGER DEFAULT 0', 'branch_id INTEGER DEFAULT 0', "tree_cascade TEXT DEFAULT ''", "graph_name_regexp TEXT DEFAULT ''", 'font_size INTEGER DEFAULT 10') as $column) {
        $db->exec('ALTER TABLE reports_items ADD ' . $column);
    }
    $request['selected_items'] = serialize($scenario['graphs'] ?? array(200));
    $request['reports_id'] = $scenario['report'] ?? 7;
    $request['timespan'] = 4;
    $request['alignment'] = 2;
}
// Trusted fixture selection and graph-title boundaries. Core parser validation
// and data-input title expansion are outside these report-helper scenarios.
function sanitize_unserialize_selected_items($selection)
{
    return unserialize($selection, array('allowed_classes' => false));
}
function get_graph_title($id)
{
    return 'Owned graph ' . $id;
}
if (str_starts_with($operation, 'expand-')) {
    $options += array('auth_method' => 1, 'graph_auth_method' => 1);
    foreach (array('policy_graphs', 'policy_hosts', 'policy_graph_templates', 'policy_trees') as $field) {
        $db->exec('ALTER TABLE user_auth_group ADD ' . $field . ' INTEGER DEFAULT 2');
    }
    $db->exec("ALTER TABLE user_auth_group ADD name TEXT DEFAULT ''");
    $db->exec('CREATE TABLE user_auth(id INTEGER PRIMARY KEY, policy_graphs INTEGER, policy_hosts INTEGER, policy_graph_templates INTEGER, policy_trees INTEGER)');
    $db->exec('CREATE TABLE user_auth_perms(user_id INTEGER,item_id INTEGER,type INTEGER)');
    $db->exec('CREATE TABLE user_auth_group_perms(group_id INTEGER,item_id INTEGER,type INTEGER)');
    $db->exec('CREATE TABLE host_template(id INTEGER PRIMARY KEY,name TEXT)');
    $db->exec('INSERT INTO user_auth VALUES(42,2,2,2,2)');
    $db->exec('INSERT INTO user_auth_perms VALUES(42,200,1),(42,201,1),(42,210,1)');
    $db->exec("ALTER TABLE host ADD deleted TEXT DEFAULT ''");
    $db->exec("ALTER TABLE host ADD disabled TEXT DEFAULT ''");
    $db->exec("INSERT INTO host(id,description,host_template_id,site_id) VALUES(101,'Denied host',3,4)");
    $db->exec('ALTER TABLE graph_local ADD snmp_query_id INTEGER DEFAULT 0');
    $db->exec("ALTER TABLE graph_local ADD snmp_index TEXT DEFAULT ''");
    $db->exec('CREATE TABLE graph_templates(id INTEGER PRIMARY KEY,name TEXT)');
    $db->exec('CREATE TABLE snmp_query(id INTEGER PRIMARY KEY,name TEXT)');
    $db->exec("INSERT INTO graph_templates VALUES(6,'Traffic <template>'),(7,'Denied template')");
    $db->exec('INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(201,100,6),(202,100,7),(210,100,6),(211,101,7)');
    $db->exec('CREATE TABLE graph_templates_graph(local_graph_id INTEGER,title_cache TEXT,width INTEGER,height INTEGER)');
    $db->exec("INSERT INTO graph_templates_graph VALUES(200,'Traffic 10',400,100),(201,'Traffic 2',400,100),(202,'Forbidden',400,100),(210,'Nested <graph>',400,100),(211,'Other host',400,100)");
    $db->exec('CREATE TABLE graph_tree(id INTEGER PRIMARY KEY,name TEXT)');
    $db->exec("INSERT INTO graph_tree VALUES(1,'Network <tree>')");
    $db->exec('CREATE TABLE graph_tree_items(id INTEGER PRIMARY KEY,graph_tree_id INTEGER,parent INTEGER,position INTEGER,title TEXT,host_id INTEGER,local_graph_id INTEGER,host_grouping_type INTEGER)');
    $db->exec("INSERT INTO graph_tree_items VALUES(1,1,0,1,'Root <branch>',0,0,0),(2,1,1,1,'',100,0,1),(3,1,1,2,'',0,200,0),(4,1,1,3,'Nested <branch>',0,0,0),(5,1,4,1,'',0,210,0),(6,1,1,4,'Empty branch',0,0,0),(7,1,1,5,'',101,0,1),(8,1,0,2,'',0,200,0)");
    if (isset($scenario['grouping'])) {
        $db->prepare('UPDATE graph_tree_items SET host_grouping_type = ? WHERE id = 2')->execute(array($scenario['grouping']));
    }
    $db->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no, 3);
    $db->sqliteCreateFunction('REGEXP', static fn($pattern, $subject) => preg_match('~' . str_replace('~', '\\~', $pattern) . '~', $subject), 2);
    require $root . '/lib/time.php';
    require $root . '/lib/html.php';
    require $root . '/lib/html_form.php';
    $item = array('timespan' => GT_LAST_HOUR, 'align' => 1, 'font_size' => 10, 'graph_template_id' => $scenario['template'] ?? 6,
        'graph_name_regexp' => $scenario['regexp'] ?? '', 'tree_id' => $scenario['tree'] ?? 1, 'branch_id' => $scenario['branch'] ?? 1, 'tree_cascade' => $scenario['cascade'] ?? '');
    foreach (array('tree_id INTEGER DEFAULT 0', 'branch_id INTEGER DEFAULT 0', "tree_cascade TEXT DEFAULT ''", "graph_name_regexp TEXT DEFAULT ''", 'font_size INTEGER DEFAULT 10') as $column) {
        $db->exec('ALTER TABLE reports_items ADD ' . $column);
    }
    $item['host_id'] = $scenario['device'] ?? 100;
    $fields = array_keys($item);
    $db->prepare('UPDATE reports_items SET ' . implode(',', array_map(static fn($field) => $field . '=?', $fields)) . ' WHERE id=70 AND report_id=7')->execute(array_values($item));
    $item = db_fetch_row_prepared('SELECT * FROM reports_items WHERE id=? AND report_id=?', array(70, 7));
    $report = db_fetch_row_prepared('SELECT * FROM reports WHERE id=?', array($item['report_id']));
    $alignment = array(1 => 'left', 2 => 'center');
    if (!empty($scenario['deny_all'])) {
        $db->exec('DELETE FROM user_auth_perms');
    }
}
switch ($operation) {
    case 'legacy-prepare':
        ob_start();
        $result = reports_graphs_action_prepare(array('drp_action' => $scenario['action'] ?? 'reports', 'graph_list' => '<ul><li>Owned graph 200</li></ul>'));
        $rendered = ob_get_clean();
        break;
    case 'legacy-execute':
        $result = reports_graphs_action_execute($scenario['action'] ?? 'reports');
        break;
    case 'expand-device':
        $result = reports_expand_device($report, $item, $scenario['device'] ?? 100, REPORTS_OUTPUT_STDOUT, $scenario['format'] ?? true);
        break;
    case 'expand-tree':
        $result = reports_expand_tree($report, $item, $scenario['branch'] ?? 1, REPORTS_OUTPUT_STDOUT, $scenario['format'] ?? true, 'classic', $scenario['nested'] ?? false);
        break;
    case 'expand-branch':
        $result = expand_branch($report, $item, $scenario['branch'] ?? 1, REPORTS_OUTPUT_STDOUT, $scenario['format'] ?? true);
        break;
    case 'add-device':
        $result = reports_add_devices(7, $scenario['devices'] ?? array(100), 4, 2);
        break;
    case 'duplicate-device':
        reports_add_devices(7, array(100), 4, 2);
        $result = reports_add_devices(7, array(100), 4, 2);
        break;
    case 'add-graph':
        $result = reports_add_graphs(7, $scenario['graph'] ?? 200, 4, 2);
        break;
    case 'duplicate-graph':
        reports_add_graphs(7, 200, 4, 2);
        $result = reports_add_graphs(7, 200, 4, 2);
        break;
    case 'copy-report':
        duplicate_reports(7, 'Copy of <name>');
        break;
    case 'reorder':
        reports_item_dnd();
        break;
    case 'remove':
        $request['item_id'] = $scenario['item'] ?? 70;
        reports_item_remove();
        break;
    default:
        throw new InvalidArgumentException('Unknown fixture operation.');
}
$state = array('result' => $result, 'rendered' => $rendered ?? '', 'messages' => $messages, 'message_details' => $messageDetails, 'reports' => db_fetch_assoc_prepared('SELECT id, user_id, name, enabled FROM reports ORDER BY id'), 'items' => db_fetch_assoc_prepared('SELECT * FROM reports_items ORDER BY id'));
define('NATIVE_COVERAGE_COMPLETED', array('report-persisted-state-readback'));
print json_encode($state, JSON_THROW_ON_ERROR);
