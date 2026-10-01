<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute native policy helpers against persisted rows, without HTTP bootstrap.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (isset($argv[3])) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], array('lib/auth.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'));
}
$config = ['cacti_db_version' => '1.2.33'];
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE user_auth (id INTEGER, reset_perms INTEGER, show_tree TEXT, show_list TEXT, show_preview TEXT, graph_settings TEXT, policy_hosts INTEGER, policy_graphs INTEGER, policy_graph_templates INTEGER, policy_trees INTEGER DEFAULT 1)');
$db->prepare('INSERT INTO user_auth(id,reset_perms,show_tree,show_list,show_preview,graph_settings,policy_hosts,policy_graphs,policy_graph_templates) VALUES (42, 0, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge(array_fill(0, 4, $scenario['view_default'] ?? ''), array_fill(0, 3, $scenario['policy'] ?? 1)));
$db->exec("INSERT INTO user_auth(id,reset_perms,show_tree,show_list,show_preview,graph_settings,policy_hosts,policy_graphs,policy_graph_templates) VALUES (43, 0, '', '', '', '', 1, 1, 1)");
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT, name TEXT DEFAULT "fixture", show_tree TEXT, show_list TEXT, show_preview TEXT, graph_settings TEXT, policy_hosts INTEGER, policy_graphs INTEGER, policy_graph_templates INTEGER, policy_trees INTEGER DEFAULT 1)');
$db->exec('CREATE TABLE user_auth_group_members (user_id INTEGER, group_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_perms (user_id INTEGER, type INTEGER, item_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group_perms (group_id INTEGER, type INTEGER, item_id INTEGER)');
$db->exec('CREATE TABLE plugin_realms (id INTEGER, file TEXT, display TEXT)');
$db->exec("INSERT INTO plugin_realms VALUES (5, 'first.php,middle.php,last.php', 'Extension realm')");
foreach ($scenario['realms'] ?? [] as $user) {
    $db->prepare('INSERT INTO user_auth_realm VALUES (?, 21)')->execute([$user]);
}
foreach ($scenario['groups'] ?? [] as $index => $group) {
    $id = $index + 1;
    $db->prepare('INSERT INTO user_auth_group(id,enabled,show_tree,show_list,show_preview,graph_settings,policy_hosts,policy_graphs,policy_graph_templates) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1)')->execute(array_merge([$id, $group['enabled'] ?? 'on'], array_fill(0, 4, $group['view'] ?? '')));
    $db->prepare('INSERT INTO user_auth_group_members VALUES (?, ?)')->execute([$group['user'] ?? 42, $id]);
    $db->prepare('INSERT INTO user_auth_group_realm VALUES (?, 21)')->execute([$id]);
    $db->prepare('UPDATE user_auth_group SET policy_trees=? WHERE id=?')->execute([$group['tree_policy'] ?? 1, $id]);
    foreach ($group['exceptions'] ?? [] as $type) {
        $db->prepare('INSERT INTO user_auth_group_perms VALUES (?, ?, 100)')->execute([$id, $type]);
    }
}
foreach ($scenario['exceptions'] ?? [] as $type) {
    $db->prepare('INSERT INTO user_auth_perms VALUES (42, ?, 100)')->execute([$type]);
}
$db->prepare('UPDATE user_auth SET policy_trees=? WHERE id=42')->execute([$scenario['tree_policy'] ?? 1]);
$_SESSION = $scenario['anonymous'] ?? false ? [] : ['sess_user_id' => 42];
$queries = 0;
$logs = [];
function db_fetch_assoc_prepared($sql, $params = [])
{
    $GLOBALS['queries']++;
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_row_prepared($sql, $params = [])
{
    $rows = db_fetch_assoc_prepared($sql, $params);
    return $rows[0] ?? [];
}
function db_fetch_cell_prepared($sql, $params = [])
{
    $rows = db_fetch_assoc_prepared($sql, $params);
    return $rows ? reset($rows[0]) : false;
}
function db_table_exists($name)
{
    return (bool) db_fetch_cell_prepared('SELECT 1 FROM sqlite_master WHERE type = ? AND name = ?', ['table', $name]);
}
function read_config_option($name)
{
    return $name === 'auth_method' ? ($GLOBALS['scenario']['auth_method'] ?? 1) : '';
}
function cacti_version_compare($left, $right, $operator)
{
    return version_compare($left, $right, $operator);
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function cacti_sizeof($rows)
{
    return is_countable($rows) ? count($rows) : 0;
}
function cacti_log($message, ...$args)
{
    $GLOBALS['logs'][] = $message;
}
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    define('AUTH_POLICY_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/auth.php';
$result = null;
$cached = null;
switch ($scenario['operation']) {
    case 'realm':
        $result = is_realm_allowed(21, $scenario['check_user'] ?? false);
        $before = $queries;
        $db->exec('DELETE FROM user_auth_realm');
        $db->exec('DELETE FROM user_auth_group_realm');
        $cached = is_realm_allowed(21, $scenario['check_user'] ?? false);
        break;
    case 'tree':
        $result = is_tree_allowed(100);
        $before = $queries;
        $db->exec('DELETE FROM user_auth_perms');
        $db->exec('DELETE FROM user_auth_group_perms');
        $cached = is_tree_allowed(100);
        break;
    case 'policies':
        $result = get_policies(42);
        break;
    case 'view':
        $result = is_view_allowed($scenario['view'] ?? 'show_tree');
        break;
    case 'roles':
        $user_auth_roles = ['extension' => [7]];
        $user_auth_realm_filenames = [];
        auth_augment_roles('extension', ['first.php', 'middle.php', 'last.php', 'unknown.php']);
        auth_augment_roles_byname('extension', 'Extension realm');
        auth_augment_roles_byname('new', 'Extension realm');
        auth_augment_roles_byname('missing', 'Unknown realm');
        $before = $queries;
        $db->exec('DELETE FROM plugin_realms');
        auth_augment_roles('extension', ['first.php', 'middle.php', 'last.php']);
        auth_augment_roles_byname('extension', 'Extension realm');
        $result = $user_auth_roles;
        break;
    case 'simple':
        $result = [get_simple_device_perms(42), get_simple_graph_perms(42), get_simple_graph_template_perms(42)];
        $before = $queries;
        $db->exec('UPDATE user_auth SET policy_graphs = 2, policy_graph_templates = 2 WHERE id = 42');
        $cached = [get_simple_graph_perms(42), get_simple_graph_template_perms(42)];
        break;
    default:
        throw new InvalidArgumentException('Unknown policy operation.');
}
$nativeChildCoverageMarkers = array('native-policy-operation-returned', 'policy-session-observed');
print json_encode(['result' => $result, 'cached' => $cached, 'session' => $_SESSION, 'extra_queries' => isset($before) ? $queries - $before : null, 'logs' => $logs], JSON_THROW_ON_ERROR);
