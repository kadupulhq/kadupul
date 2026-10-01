<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Exercise real admin handlers and cache-reset SQL after the separately tested
// authorization/CSRF/bootstrap boundary. Controller functions are never copied.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
chdir($directory);
$group = $scenario['group'];
$target = 42;
$config = ['cacti_db_version' => '1.2.33'];
$operation = $scenario['operation'];
$request = ['action' => $operation === 'realm' ? 'save' : 'perm_remove', 'id' => $operation === 'realm' ? $target : 100, 'user_id' => $target, 'group_id' => $target, 'type' => $scenario['type'] ?? 'graph'];
$_POST = [];
if ($operation === 'add') {
    $request['action'] = 'save';
    $request['id'] = $target;
    $request['save_component_graph_perms'] = '1';
    $request['add_' . $scenario['type'] . '_x'] = '1';
    $request['perm_' . $scenario['field']] = $scenario['item'];
    foreach (array('policy_graphs', 'policy_trees', 'policy_hosts', 'policy_graph_templates') as $policy) {
        $request[$policy] = 1;
    }
} elseif ($operation === 'policy') {
    $request['update_policy'] = '1';
    $request['id'] = $target;
    $request += $scenario['policies'];
} elseif ($operation === 'membership' && !isset($scenario['replace'])) {
    $request['action'] = 'fixture';
}
if ($operation === 'realm') {
    $request['save_component_realm_perms'] = '1';
    foreach ($scenario['realms'] as $realm) {
        $_POST['section' . $realm] = 'on';
    }
    $_POST['unrelated_field'] = 'on';
}
if ($operation === 'bulk' || ($operation === 'membership' && isset($scenario['replace']))) {
    $request['action'] = 'actions';
    $request['id'] = $target;
    $request['drp_action'] = $scenario['replace'] ? '1' : '2';
    $request[$operation === 'membership' ? ($group ? 'associate_member' : 'associate_groups') : 'associate_' . $scenario['kind']] = '1';
    $_POST['chk_' . ($operation === 'membership' ? 42 : 100)] = 'on';
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = ['sess_user_id' => ($scenario['self'] ?? false) ? $target : 41, 'sess_user_perms_key' => 0, 'sess_user_realms' => [99], 'sess_user_config_array' => ['stale'], 'sess_config_array' => ['stale'], 'sess_auth_names' => ['stale']];
$initial_session = $_SESSION;
$messages = [];
$db = new PDO('sqlite:' . $directory . '/state.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Native SQL's random reset marker stays a marker rather than a canned UPDATE.
$db->sqliteCreateFunction('RAND', static fn() => random_int(1, 4294967294) / 4294967295);
$db->sqliteCreateFunction('FLOOR', static fn($value) => floor($value));
$db->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, reset_perms INTEGER DEFAULT 0)');
$db->exec('INSERT INTO user_auth (id) VALUES (41), (42), (43), (44)');
$db->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO user_auth_group VALUES (42), (43)');
foreach (array('policy_graphs', 'policy_trees', 'policy_hosts', 'policy_graph_templates') as $policy) {
    $db->exec('ALTER TABLE user_auth ADD COLUMN ' . $policy . ' INTEGER DEFAULT 1');
    $db->exec('ALTER TABLE user_auth_group ADD COLUMN ' . $policy . ' INTEGER DEFAULT 1');
}
$db->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER, UNIQUE(group_id, user_id))');
$db->exec('INSERT INTO user_auth_group_members VALUES (42, 42), (42, 44), (43, 43)');
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER, UNIQUE(user_id, realm_id))');
$db->exec('INSERT INTO user_auth_realm VALUES (42, 7), (43, 9)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER, UNIQUE(group_id, realm_id))');
$db->exec('INSERT INTO user_auth_group_realm VALUES (42, 7), (43, 9)');
$db->exec('CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER, UNIQUE(user_id, item_id, type))');
$db->exec('CREATE TABLE user_auth_group_perms (group_id INTEGER, item_id INTEGER, type INTEGER, UNIQUE(group_id, item_id, type))');
foreach (range(1, 4) as $type) {
    $db->prepare('INSERT INTO user_auth_perms VALUES (42, 100, ?), (42, 101, ?), (43, 100, ?)')->execute([$type, $type, $type]);
    $db->prepare('INSERT INTO user_auth_group_perms VALUES (42, 100, ?), (42, 101, ?), (43, 100, ?)')->execute([$type, $type, $type]);
}
if ($scenario['replace'] ?? false) {
    if ($operation === 'membership') {
        $db->exec('DELETE FROM user_auth_group_members WHERE group_id = 42 AND user_id = 42');
    } else {
        $table = $group ? 'user_auth_group_perms' : 'user_auth_perms';
        $principal = $group ? 'group_id' : 'user_id';
        $db->prepare('DELETE FROM ' . $table . ' WHERE ' . $principal . ' = 42 AND item_id = 100 AND type = ?')->execute([$scenario['type_id']]);
    }
}
function input_validate_input_number($value)
{
    if (!ctype_digit((string) $value)) {
        throw new InvalidArgumentException('Invalid fixture numeric input.');
    }
}
function db_execute_prepared($sql, $params = [])
{
    return $GLOBALS['db']->prepare($sql)->execute($params);
}
function db_execute($sql)
{
    return $GLOBALS['db']->exec($sql);
}
function db_fetch_assoc_prepared($sql, $params = [])
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function api_plugin_hook_function($hook, $value)
{
    // Isolate plugin routing so helper-only cases can load the real controller.
    return true;
}
function cacti_require_post_request()
{
    cacti_require_post_actions(array());
}
function array_rekey($rows, $key, $value)
{
    $result = [];
    foreach ($rows as $row) {
        $result[$row[$key]] = $row[$value];
    }
    return $result;
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function get_request_var($name)
{
    return $GLOBALS['request'][$name] ?? '';
}
function get_nfilter_request_var($name, $default = '')
{
    return $GLOBALS['request'][$name] ?? $default;
}
function get_filter_request_var($name)
{
    return (int) get_request_var($name);
}
function isset_request_var($name)
{
    return array_key_exists($name, $GLOBALS['request']);
}
function db_fetch_cell_prepared($sql, $params = [])
{
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}
function cacti_version_compare($left, $right, $operator)
{
    return version_compare($left, $right, $operator);
}
function set_default_action() {}
function is_error_message()
{
    return $GLOBALS['scenario']['error'] ?? false;
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function raise_message($key, ...$args)
{
    $GLOBALS['messages'][] = $key;
}
function cacti_require_post_actions($actions)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Native persistence fixture requires an intentional POST.');
    }
}
require $root . '/include/global_constants.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('ADMIN_PERMISSION_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/auth.php';
ob_start();
register_shutdown_function(static function () use ($db, $group, $initial_session, $operation, $directory, $root, $scenario) {
    $output = ob_get_clean();
    $session = $_SESSION;
    // The old epoch is held by the existing session; query the actual reset
    // marker through the native validity helper after the controller writes.
    $perms_valid = is_user_perms_valid($session['sess_user_id']);
    $next_valid = null;
    if ($operation === 'bulk' || ($operation === 'membership' && isset($scenario['replace']))) {
        $program = <<<'PHP'
$config = array('cacti_db_version' => '1.2.33');
$_SESSION = array('sess_user_id' => 42, 'sess_user_perms_key' => 0);
$db = new PDO('sqlite:' . $argv[1]);
function db_fetch_cell_prepared($sql, $params = array()) { $q = $GLOBALS['db']->prepare($sql); $q->execute($params); return $q->fetchColumn(); }
function cacti_version_compare($a, $b, $op) { return version_compare($a, $b, $op); }
require $argv[2];
print json_encode(is_user_perms_valid(42));
PHP;
        $process = proc_open([PHP_BINARY, '-r', $program, $directory . '/state.sqlite', $root . '/lib/auth.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $result = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') {
            throw new RuntimeException($errors);
        }
        $next_valid = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    }
    $principal = $group ? 'group_id' : 'user_id';
    $realm_table = $group ? 'user_auth_group_realm' : 'user_auth_realm';
    $perm_table = $group ? 'user_auth_group_perms' : 'user_auth_perms';
    print json_encode(['next_valid' => $next_valid, 'memberships' => $db->query('SELECT * FROM user_auth_group_members ORDER BY group_id, user_id')->fetchAll(PDO::FETCH_ASSOC), 'realms' => $db->query('SELECT * FROM ' . $realm_table . ' ORDER BY ' . $principal . ', realm_id')->fetchAll(PDO::FETCH_ASSOC), 'permissions' => $db->query('SELECT * FROM ' . $perm_table . ' ORDER BY ' . $principal . ', item_id, type')->fetchAll(PDO::FETCH_ASSOC), 'reset' => $db->query('SELECT * FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'session' => $session, 'perms_valid' => $perms_valid, 'initial_session' => $initial_session, 'messages' => $GLOBALS['messages'], 'output' => $output, 'policies' => $db->query('SELECT id, policy_graphs, policy_trees, policy_hosts, policy_graph_templates FROM ' . ($group ? 'user_auth_group' : 'user_auth') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'membership' => $GLOBALS['membership'] ?? null], JSON_THROW_ON_ERROR);
});
require $root . ($group ? '/user_group_admin.php' : '/user_admin.php');

if ($operation === 'membership' && !isset($scenario['replace'])) {
    $membership = array(
        'target_member' => user_group_is_member(42, 42),
        'other_member' => user_group_is_member(44, 42),
        'foreign_member' => user_group_is_member(43, 42),
        'foreign_group' => user_group_is_member(42, 43),
        'target_realm' => is_user_group_realm_allowed(7, 42),
        'foreign_realm' => is_user_group_realm_allowed(9, 42),
        'foreign_realm_owner' => is_user_group_realm_allowed(9, 43),
        'missing_group' => is_user_group_realm_allowed(7, 99),
    );
}
