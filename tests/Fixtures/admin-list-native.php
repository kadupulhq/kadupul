<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Load actual controller/list/form code after isolated bootstrap/plugin routing.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (isset($argv[3])) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/admin-list-native.php', $argv[1], array('user_admin.php', 'user_group_admin.php', 'lib/html.php', 'lib/html_form.php', 'lib/html_utility.php', 'lib/functions.php', 'lib/variables.php', 'src/Platform/Infrastructure/Legacy/HostDataSubstitution.php', 'include/global_constants.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionTemplateGrid.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'));
}
$directory = $argv[2];
mkdir($directory . '/include', 0700, true);
file_put_contents($directory . '/include/auth.php', '<?php');
chdir($directory);
$group = $scenario['group'];
$page = $group ? 'user_group_admin.php' : 'user_admin.php';
$_SERVER['PHP_SELF'] = $page;
$_SERVER['SCRIPT_NAME'] = $page;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $page;
$_SESSION = array('sess_user_id' => 99);
$config = array('poller_id' => 1, 'connection' => 'online', 'url_path' => '/', 'is_web' => false, 'config_options_array' => array('num_rows_table' => 2));
$item_rows = array(1 => 'One', 2 => 'Two & more');
$auth_realms = array(0 => 'Local');
$request = array_merge(array('action' => 'fixture', 'rows' => 2, 'page' => 1, 'filter' => '', 'group' => -1, 'realm' => -1, 'login' => 0, 'sort_column' => $group ? 'name' : 'username', 'sort_direction' => 'ASC'), $scenario['request'] ?? array());
$_REQUEST = $request;
$_SERVER['REQUEST_METHOD'] = 'GET';
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($time) => $time === null ? 0 : strtotime($time));
$db->exec("CREATE TABLE user_auth(id INTEGER, username TEXT, full_name TEXT, realm INTEGER, enabled TEXT, policy_graphs INTEGER, policy_hosts INTEGER, policy_graph_templates INTEGER);
CREATE TABLE user_auth_group(id INTEGER, name TEXT, description TEXT, enabled TEXT, policy_graphs INTEGER, policy_hosts INTEGER, policy_graph_templates INTEGER);
CREATE TABLE user_auth_group_members(group_id INTEGER, user_id INTEGER);
CREATE TABLE user_log(user_id INTEGER, time TEXT);
INSERT INTO user_auth VALUES(1, 'Alpha & <script>', 'First', 0, 'on', 1, 2, 1), (2, 'Beta', 'Second', 0, '', 2, 1, 2), (3, 'Gamma', 'Third', 2, 'on', 1, 1, 1);
INSERT INTO user_auth_group VALUES(7, 'Alpha & <script>', 'A & B', 'on', 1, 2, 1), (8, 'Beta', 'Other', '', 2, 1, 2);
INSERT INTO user_auth_group_members VALUES(7,1),(7,2),(8,3);");
$permissions_before = null;
if (!empty($scenario['grid'])) {
    $db->exec('ALTER TABLE user_auth ADD COLUMN policy_trees INTEGER DEFAULT 1');
    $db->exec('ALTER TABLE user_auth_group ADD COLUMN policy_trees INTEGER DEFAULT 1');
    $db->exec("CREATE TABLE graph_templates(id INTEGER, name TEXT);
CREATE TABLE graph_local(id INTEGER, graph_template_id INTEGER);
CREATE TABLE user_auth_perms(user_id INTEGER, item_id INTEGER, type INTEGER);
CREATE TABLE user_auth_group_perms(group_id INTEGER, item_id INTEGER, type INTEGER);
INSERT INTO graph_templates VALUES(10, 'Alpha & <script>'),(20, 'Beta');
INSERT INTO graph_local VALUES(101,10),(102,10),(103,20);
INSERT INTO user_auth_perms VALUES(1,10,4),(2,20,4),(1,20,1);
INSERT INTO user_auth_group_perms VALUES(7,10,4),(8,20,4),(7,20,1);");
    $table = $group ? 'user_auth_group' : 'user_auth';
    $target = $group ? 7 : 1;
    $q = $db->prepare('UPDATE ' . $table . ' SET policy_graph_templates=? WHERE id=?');
    $q->execute(array($scenario['policy'], $target));
    $_REQUEST['id'] = $target;
    $_REQUEST['associated'] = $scenario['associated'];
    $permissions_before = $db->query('SELECT * FROM ' . $table . '_perms')->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($scenario['graph_work'])) {
        $db->exec("INSERT INTO graph_templates VALUES(30, 'Empty');
ALTER TABLE graph_local RENAME TO graph_inventory;");
        $insert = $db->prepare('INSERT INTO graph_inventory VALUES(?,10)');
        for ($id = 1000; $id < 3000; $id++) {
            $insert->execute(array($id));
        }
        // A transparent view records real SQLite reads of the graph join key.
        $GLOBALS['graph_reads'] = 0;
        $db->sqliteCreateFunction('native_graph_read', static function ($value) {
            $GLOBALS['graph_reads']++;
            return $value;
        });
        $db->exec('CREATE VIEW graph_local AS SELECT id, native_graph_read(graph_template_id) AS graph_template_id FROM graph_inventory');
        $permissions_before = $db->query('SELECT * FROM ' . $table . '_perms')->fetchAll(PDO::FETCH_ASSOC);
    }
}
$queries = array();
$query_work = array();
function db_fetch_assoc_prepared($sql, $params = array())
{
    $GLOBALS['queries'][] = array($sql, $params);
    $before = $GLOBALS['graph_reads'] ?? 0;
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    $result = $q->fetchAll(PDO::FETCH_ASSOC);
    $GLOBALS['query_work'][] = array('sql' => $sql, 'graph_reads' => ($GLOBALS['graph_reads'] ?? 0) - $before, 'result' => $result);
    return $result;
}
function db_fetch_row_prepared($sql, $params = array())
{
    $rows = db_fetch_assoc_prepared($sql, $params);
    return $rows[0] ?? array();
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $GLOBALS['queries'][] = array($sql, $params);
    $before = $GLOBALS['graph_reads'] ?? 0;
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    $result = $q->fetchColumn();
    $GLOBALS['query_work'][] = array('sql' => $sql, 'graph_reads' => ($GLOBALS['graph_reads'] ?? 0) - $before, 'result' => $result);
    return $result;
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_qstr($value)
{
    return $GLOBALS['db']->quote($value);
}
function get_total_row_data($user, $sql, $params)
{
    return db_fetch_cell_prepared($sql, $params);
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function __esc($text, ...$args)
{
    return html_escape(__($text, ...$args));
}
function __x($context, $text)
{
    return $context . ':' . $text;
}
function api_plugin_hook_function($hook, $value)
{
    return true;
}
function api_plugin_hook($hook) {}
function is_realm_allowed($realm)
{
    return false;
}
function is_template_account($id)
{
    return false;
}
function number_format_i18n($number, $decimals)
{
    return number_format($number, $decimals);
}
final class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return "nonce='list-fixture'";
    }
}
// Only the hostname bootstrap substitution exists in this list-only fixture.
define('VALID_HOST_FIELDS', '(hostname)');
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html_form.php';
require $root . '/lib/variables.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('ADMIN_LIST_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/' . $page;
ob_start();
if (!empty($scenario['grid'])) {
    $group ? user_group_graph_perms_edit('permste', 'Target') : graph_perms_edit('permste', 'Target');
} else {
    $group ? user_group() : user();
}
$html = ob_get_clean();
$state = array('html' => $html, 'queries' => $queries, 'query_work' => $query_work, 'permissions_before' => $permissions_before, 'permissions_after' => !empty($scenario['grid']) ? $db->query('SELECT * FROM ' . $table . '_perms')->fetchAll(PDO::FETCH_ASSOC) : null);
$nativeChildCoverageMarkers = array('native-list-rendered', 'list-state-readback');
print json_encode($state, JSON_THROW_ON_ERROR);
