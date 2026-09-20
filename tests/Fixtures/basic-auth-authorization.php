<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute the real middleware and transition helper against isolated SQL tables.
$root = $argv[1];
$scenario = $argv[2];
$mode = $argv[3];
require $root . '/lib/auth.php';
define('CACTI_VERSION', 'test');
define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 1);
define('POLLER_VERBOSITY_MEDIUM', 3);
$config = array('cacti_db_version' => 'test', 'url_path' => '/', 'base_path' => $root);
$user_auth_realm_filenames = array('user_admin.php' => 7);
$auth_text = true;
$events = array();
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE user_auth (id INTEGER, username TEXT, realm INTEGER, enabled TEXT, locked TEXT, password TEXT DEFAULT '', password_history TEXT DEFAULT '', reset_perms INTEGER)");
$db->exec("INSERT INTO user_auth (id, username, realm, enabled, locked) VALUES (42, 'fixture', 2, 'on', '')");
$db->sqliteCreateFunction('NOW', function () { return '2026-09-20'; });
$db->sqliteCreateFunction('RAND', function () { return 0.5; });
$db->sqliteCreateFunction('FLOOR', 'floor');
$db->exec('CREATE TABLE user_auth_cache (user_id INTEGER, hostname TEXT, last_update TEXT, token TEXT)');
$db->exec('CREATE TABLE user_auth_row_cache (user_id INTEGER)');
$db->exec('CREATE TABLE sessions (user_id INTEGER)');
$db->exec('CREATE TABLE user_domains (user_id INTEGER)');
foreach (array(42, 43) as $id) {
    $query = $db->prepare('INSERT INTO user_auth_cache VALUES (?, ?, ?, ?)');
    $query->execute(array($id, '127.0.0.1', 'fixture', hash('sha512', 'fixture-token')));
    $db->exec('INSERT INTO user_auth_row_cache VALUES (' . $id . ')');
    $db->exec('INSERT INTO sessions VALUES (' . $id . ')');
}
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
$db->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
if (in_array($scenario, array('disabled', 'existing_disabled'), true)) {
    $db->exec("UPDATE user_auth SET enabled = ''");
} elseif (in_array($scenario, array('locked', 'existing_locked'), true)) {
    $db->exec("UPDATE user_auth SET locked = 'on'");
} elseif (in_array($scenario, array('missing', 'existing_missing'), true)) {
    $db->exec('DELETE FROM user_auth');
}
if (strpos($scenario, 'guest') !== false) {
    $db->exec("UPDATE user_auth SET enabled = ''");
    if (str_ends_with($scenario, 'guest_locked')) $db->exec("UPDATE user_auth SET locked = 'on'");
    if (str_ends_with($scenario, 'guest_missing')) $db->exec('DELETE FROM user_auth');
    $guest_account = true;
}
if ($scenario === 'allowed') {
    $db->exec('INSERT INTO user_auth_realm VALUES (42, 7)');
} elseif (in_array($scenario, array('group', 'disabled_group'), true)) {
    $db->exec("INSERT INTO user_auth_group VALUES (1, 'on')");
    $db->exec('INSERT INTO user_auth_group_members VALUES (1, 42)');
    $db->exec('INSERT INTO user_auth_group_realm VALUES (1, 7)');
    if ($scenario === 'disabled_group') {
        $db->exec("UPDATE user_auth_group SET enabled = ''");
    }
}
function read_config_option($key) {
    return array('auth_method' => '2', 'auth_cache_enabled' => 'on', 'admin_user' => 1)[$key] ?? '';
}
function get_current_page() { return $GLOBALS['mode'] === 'logout' ? 'logout.php' : 'user_admin.php'; }
function get_guest_account() { return strpos($GLOBALS['scenario'], 'guest') !== false ? 42 : 0; }
function get_template_account($id) { return 0; }
function get_client_addr() { return '127.0.0.1'; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function kill_session_var($key) { unset($_SESSION[$key]); }
function cacti_log(...$args) {}
function cacti_cookie_logout() { $GLOBALS['events'][] = 'CLEAR_COOKIES'; }
function cacti_session_destroy() { $_SESSION = array(); $GLOBALS['events'][] = 'DESTROY_SESSION'; }
function cacti_cookie_session_logout() { $GLOBALS['events'][] = 'CLEAR_REMEMBER'; }
function cacti_cookie_session_set(...$args) { $GLOBALS['events'][] = 'SET_REMEMBER'; }
function input_validate_input_number($value) { if (!is_numeric($value)) throw new RuntimeException('Invalid test ID'); }
function __($value) { return $value; }
function get_nfilter_request_var($name, $default = '') { return $_REQUEST[$name] ?? $default; }
function get_filter_request_var($name) { return get_nfilter_request_var($name); }
function get_request_var($name) { return get_nfilter_request_var($name); }
function isset_request_var($name) { return isset($_REQUEST[$name]); }
function set_default_action() {}
// This persistence fixture mocks request helpers. The real method/token guard
// is exercised separately by AdminMutationCsrfTest against the real controller.
function cacti_require_post_actions(array $actions) {
    if (in_array($_REQUEST['action'] ?? '', $actions, true)
        && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['__csrf_magic'] ?? '') !== 'auth-fixture-token')) {
        throw new RuntimeException('Authentication fixture requires an intentional POST');
    }
}
function form_input_validate($value, ...$args) { return $value; }
function is_error_message() { return str_ends_with($GLOBALS['scenario'], 'validation_error'); }
function raise_message($id) { $GLOBALS['events'][] = 'MESSAGE:' . $id; }
function sql_save($save, $table) {
    if (str_ends_with($GLOBALS['scenario'], 'save_failed')) return false;
    $query = $GLOBALS['db']->prepare('UPDATE user_auth SET enabled = ? WHERE id = ?');
    $query->execute(array($save['enabled'], $save['id']));
    return $save['id'];
}
function api_plugin_hook_function($hook, ...$args) {
    if ($hook === 'user_admin_setup_sql_save') {
        $save = $args[0];
        if ($GLOBALS['scenario'] === 'plugin_disabled') $save['enabled'] = '';
        if ($GLOBALS['scenario'] === 'plugin_reset') $save['must_change_password'] = 'on';
        return $save;
    }
    if ($hook === 'custom_denied') {
        $GLOBALS['events'][] = 'DENIED';
        return OPER_MODE_RESKIN;
    }
    return OPER_MODE_NATIVE;
}
function db_fetch_row_prepared($sql, $params = array()) {
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($params);
    return $query->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = array()) {
    if (strpos($sql, 'AS authorized') !== false) $GLOBALS['events'][] = 'REALM_CHECK';
    if (strpos($sql, 'FROM user_auth_cache') !== false) $GLOBALS['events'][] = 'COOKIE_CHECK';
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}
function db_table_exists($name) { return true; }
function db_execute_prepared($sql, $params = array()) {
    if (strpos($sql, 'INSERT IGNORE INTO user_log') !== false) {
        $GLOBALS['events'][] = 'LOGIN_LOG';
        return true;
    }
    $query = $GLOBALS['db']->prepare($sql);
    return $query->execute($params);
}
$_SERVER['PHP_AUTH_USER'] = 'fixture';
if (strpos($scenario, 'guest') !== false) unset($_SERVER['PHP_AUTH_USER']);
$_SESSION = array();
if (str_starts_with($scenario, 'existing_')) {
    $_SESSION['sess_user_id'] = 42;
}
register_shutdown_function(function () {
    echo json_encode(array('events' => $GLOBALS['events'], 'user' => $_SESSION['sess_user_id'] ?? null, 'status' => http_response_code() ?: 200));
});
if ($mode === 'transition') {
    $events[] = cacti_auth_transition(42, 'test') ? 'ACCEPT' : 'REJECT';
} elseif ($mode === 'identity') {
    unset($_SERVER['PHP_AUTH_USER']);
    $_SERVER[$scenario] = 'fixture';
    $events[] = get_basic_auth_username() === 'fixture' ? 'IDENTITY' : 'REJECT';
} elseif (str_starts_with($mode, 'cookie')) {
    if ($mode === 'cookie_legacy') {
        $db->exec('UPDATE user_auth SET realm = 0');
        $_COOKIE['cacti_remembers'] = '42,fixture-token';
    } else {
        $_COOKIE['cacti_remembers'] = '42,2,fixture-token';
    }
    $events[] = check_auth_cookie() === 42 ? 'RESTORED' : 'REJECT';
} elseif ($mode === 'save' || $mode === 'bulk_disable') {
    $_SESSION['sess_user_id'] = 1;
    if ($mode === 'bulk_disable') {
        user_disable(42);
    } else {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['__csrf_magic'] = 'auth-fixture-token';
        $_REQUEST = array('action' => 'save', 'save_component_user' => '1', 'id' => 42, 'realm' => 2, 'username' => 'fixture', 'enabled' => in_array($scenario, array('enabled', 'plugin_disabled'), true) ? 'on' : '');
        if (str_starts_with($scenario, 'password_')) {
            $_REQUEST['enabled'] = 'on';
            $_REQUEST['password'] = $_REQUEST['password_confirm'] = 'fixture-new-password';
        }
        if (str_starts_with($scenario, 'reset_') || $scenario === 'plugin_reset') {
            $_REQUEST['enabled'] = 'on';
            $_REQUEST['password_change'] = 'on';
            $_REQUEST['must_change_password'] = $scenario === 'plugin_reset' ? '' : 'on';
        }
        require $root . '/user_admin.php';
    }
    foreach (array(42, 43) as $id) {
        $counts = array();
        foreach (array('user_auth_cache', 'user_auth_row_cache', 'sessions') as $table) {
            $counts[] = $db->query('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id = ' . $id)->fetchColumn();
        }
        $events[] = $id . ':' . implode(',', $counts);
    }
} else {
    require $root . '/include/auth.php';
    $events[] = 'HANDLER';
}
