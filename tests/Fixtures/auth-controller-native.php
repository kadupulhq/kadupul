<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Real authentication functions/controllers; isolate boot, request and transport.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
$mode = $scenario['mode'] ?? 'login';
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (isset($argv[3])) {
    $coverageSources = array('tests/Unit/Security/Auth/AuthControllerNativeCoverageTest.php', 'composer.lock', 'tests/composer.lock', 'tests/Fixtures/auth-controller-native.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/auth.php', 'auth_login.php', 'auth_changepassword.php', 'logout.php', 'lib/ldap.php', 'include/global_constants.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    $GLOBALS['nativeCoverageEvidence'] = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-controller-native.php', $argv[1], $coverageSources);
}
chdir($directory);
$config = array('base_path' => $root, 'url_path' => '/', 'library_path' => $root . '/lib');
$options = array_merge(array('auth_method' => 1, 'secpass_lockfailed' => 3, 'secpass_unlocktime' => 15, 'secpass_expireaccount' => 1, 'secpass_minlen' => 8), $scenario['options'] ?? array());
$request = array_merge(array('action' => 'login', 'login_username' => 'alice', 'login_password' => 'Correct1!', 'realm' => 0), $scenario['request'] ?? array());
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = array('sess_user_realms' => array(99), 'sess_user_config_array' => array('stale'), 'sess_config_array' => array('stale'));
$events = array();
$messages = array();
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
$db->exec("CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, realm INTEGER DEFAULT 0, enabled TEXT DEFAULT 'on', locked TEXT DEFAULT '', password TEXT, lastfail INTEGER DEFAULT 0, failed_attempts INTEGER DEFAULT 0, lastlogin INTEGER DEFAULT 0, must_change_password TEXT DEFAULT '', password_change TEXT DEFAULT 'on', login_opts INTEGER DEFAULT 3, show_tree TEXT DEFAULT '', show_list TEXT DEFAULT '', show_preview TEXT DEFAULT '', password_history TEXT DEFAULT '', lastchange INTEGER DEFAULT 0)");
$db->exec('CREATE TABLE user_log (username TEXT, user_id INTEGER, result INTEGER, ip TEXT, time TEXT)');
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group_members (user_id INTEGER, group_id INTEGER, show_tree TEXT, show_list TEXT, show_preview TEXT)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_cache (user_id INTEGER)');
$db->exec('CREATE TABLE sessions (user_id INTEGER)');
// Fixed historical test vector for Correct1!: migration must replace an existing
// weak legacy record; new fixture credentials always use password_hash().
$hash = ($scenario['legacy_hash'] ?? false)
    ? '231b340f63aab54c7a81f940a521e8c6'
    : password_hash('Correct1!', PASSWORD_DEFAULT);
$db->prepare('INSERT INTO user_auth (id, username, password) VALUES (42, ?, ?)')->execute(array('alice', $hash));
$db->prepare('INSERT INTO user_auth (id, username, password) VALUES (43, ?, ?)')->execute(array('bob', $hash));
foreach ($scenario['account'] ?? array() as $field => $value) {
    if (!in_array($field, array('enabled', 'locked', 'lastfail', 'failed_attempts', 'must_change_password', 'password_change', 'realm'), true)) {
        throw new InvalidArgumentException('Unknown account fixture field.');
    }
    if ($field === 'lastfail' && $value === 'recent') {
        $value = time();
    }
    $db->prepare('UPDATE user_auth SET ' . $field . ' = ? WHERE id = 42')->execute(array($value));
}
if (!($scenario['no_realm'] ?? false)) {
    $db->exec('INSERT INTO user_auth_realm VALUES (42, 7)');
}
if ($scenario['group_realm'] ?? false) {
    $db->exec("INSERT INTO user_auth_group_members VALUES (42, 5, '', '', '')");
    $db->exec('INSERT INTO user_auth_group_realm VALUES (5, 7)');
}
$db->exec('INSERT INTO user_auth_cache VALUES (42), (43)');
$db->exec('INSERT INTO sessions VALUES (42), (43)');
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
    // SQLite adapter for MySQL's INSERT IGNORE syntax; statements and parameters
    // otherwise execute against real tables rather than returning canned rows.
    $sql = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
    return $GLOBALS['db']->prepare($sql)->execute($params);
}
function db_table_exists($table)
{
    return (bool) db_fetch_cell_prepared('SELECT 1 FROM sqlite_master WHERE type = ? AND name = ?', array('table', $table));
}
function db_column_exists($table, $column)
{
    return in_array($column, array_column($GLOBALS['db']->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
}
function read_config_option($name)
{
    return $GLOBALS['options'][$name] ?? '';
}
function get_nfilter_request_var($name, $default = '')
{
    return $GLOBALS['request'][$name] ?? $default;
}
function get_request_var($name)
{
    return get_nfilter_request_var($name);
}
function isset_request_var($name)
{
    return array_key_exists($name, $GLOBALS['request']);
}
function set_request_var($name, $value)
{
    $GLOBALS['request'][$name] = $value;
}
function set_default_action() {}
function sanitize_search_string($text)
{
    return $text;
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function cacti_count($value)
{
    return count($value);
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function __esc($text, ...$args)
{
    return htmlspecialchars(__($text, ...$args), ENT_QUOTES);
}
function get_cacti_version()
{
    return 'native-fixture';
}
function get_client_addr()
{
    return '127.0.0.1';
}
function get_template_account($username)
{
    return 0;
}
function get_guest_account()
{
    return 0;
}
function user_setting_exists(...$args)
{
    return false;
}
function cacti_log(...$args) {}
function db_check_password_length()
{
    $GLOBALS['events'][] = 'PASSWORD_COLUMN_CHECK';
}
function cacti_session_start($rotate = false)
{
    $GLOBALS['events'][] = $rotate ? 'ROTATE_SESSION' : 'START_SESSION';
}
function cacti_session_destroy()
{
    $_SESSION = array();
    $GLOBALS['events'][] = 'DESTROY_SESSION';
}
function cacti_cookie_logout()
{
    $GLOBALS['events'][] = 'CLEAR_COOKIES';
}
function kill_session_var($key)
{
    unset($_SESSION[$key]);
}
function cacti_header($url)
{
    $GLOBALS['events'][] = 'REDIRECT:' . $url;
}
function raise_message($key, ...$args)
{
    $GLOBALS['messages'][] = $key;
}
function api_plugin_hook($name)
{
    $GLOBALS['events'][] = $name;
}
function api_plugin_hook_function($name, ...$args)
{
    // Stop after controller decisions, before unrelated visual theming.
    return in_array($name, array('custom_login', 'custom_password'), true) ? OPER_MODE_RESKIN : ($args[0] ?? false);
}
function cacti_require_post_actions($actions)
{
    // Real method/token behavior has its own native LoginMutationCsrfTest.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('This decision fixture requires an intentional POST.');
    }
}
function validate_redirect_url($url, $fallback = 'index.php')
{
    return $fallback;
}
function html_common_header($title)
{
    print '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>';
}
final class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return "nonce='fixture'";
    }
}
require $root . '/include/global_constants.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('AUTH_CONTROLLER_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/auth.php';
ob_start();
register_shutdown_function(static function () use ($db, $mode) {
    $html = ob_get_clean();
    $account = db_fetch_row_prepared('SELECT * FROM user_auth WHERE id = 42');
    $state = array('session' => $_SESSION, 'events' => $GLOBALS['events'], 'messages' => $GLOBALS['messages'], 'error' => $GLOBALS['error'] ?? false, 'error_message' => $GLOBALS['error_msg'] ?? '', 'password_error' => $GLOBALS['errorMessage'] ?? '', 'failed_attempts' => $account['failed_attempts'], 'locked' => $account['locked'], 'must_change' => $account['must_change_password'], 'lastlogin' => $account['lastlogin'] > 0, 'password_matches_original' => password_verify('Correct1!', $account['password']), 'password_matches_new' => password_verify('NewCorrect2!', $account['password']), 'legacy_hash_retained' => strlen($account['password']) === 32, 'audit' => $db->query('SELECT result FROM user_log ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN), 'cache_users' => $db->query('SELECT user_id FROM user_auth_cache ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN), 'session_users' => $db->query('SELECT user_id FROM sessions ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN), 'html' => $html);
    $encoded = json_encode($state, JSON_THROW_ON_ERROR);
    // The collector appends serialization after application shutdown handlers.
    define('NATIVE_COVERAGE_COMPLETED', array('auth-controller-observed:' . $mode, 'auth-controller-persisted-state-readback'));
    print $encoded;
});
if ($mode === 'logout') {
    $_SESSION['sess_user_id'] = 42;
    require $root . '/logout.php';
} elseif ($mode === 'password') {
    if (!($scenario['no_session'] ?? false)) {
        $_SESSION['sess_user_id'] = 42;
        $_SESSION['sess_change_password'] = true;
    }
    require $root . '/auth_changepassword.php';
} else {
    require $root . '/auth_login.php';
}
