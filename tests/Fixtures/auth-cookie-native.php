<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute the shipped cookie helpers with real SQL persistence. Only the
// request/configuration, database dialect and outgoing cookie transport differ.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (isset($argv[3])) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-cookie-native.php', $argv[1], array('lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'));
}
$config = array();
$db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY, username TEXT, realm INTEGER, enabled TEXT, locked TEXT, password TEXT, lastfail INTEGER, failed_attempts INTEGER, password_change TEXT DEFAULT '', must_change_password TEXT DEFAULT '')");
$db->exec("INSERT INTO user_auth(id,username,realm,enabled,locked,password,lastfail,failed_attempts) VALUES(42,'alice',0,'on','','',0,0),(43,'alice',3,'on','','',0,0),(44,'foreign',0,'on','','',0,0)");
$db->exec('CREATE TABLE user_auth_cache(user_id INTEGER, hostname TEXT, last_update TEXT, token TEXT)');
$db->exec('CREATE TABLE user_log(username TEXT,user_id INTEGER,result INTEGER,ip TEXT,time TEXT)');
$old = str_repeat('a', 64);
$hash = hash('sha512', $old);
foreach (array(42, 43, 44) as $id) {
    $db->prepare('INSERT INTO user_auth_cache VALUES(?,?,CURRENT_TIMESTAMP,?)')->execute(array($id, '127.0.0.1', $hash));
}
$db->prepare('UPDATE user_auth SET enabled=?,locked=? WHERE id=?')->execute(array($scenario['enabled'] ?? 'on', $scenario['locked'] ?? '', $scenario['principal'] ?? 42));
if ($scenario['wrong_host'] ?? false) {
    $db->exec("UPDATE user_auth_cache SET hostname='192.0.2.1' WHERE user_id=42");
}
$_COOKIE = array('cacti_remembers' => ($scenario['identity'] ?? 'alice') . ',' . ($scenario['realm'] ?? '') . $old);
if ($scenario['wrong_token'] ?? false) {
    $_COOKIE['cacti_remembers'] = 'alice,' . str_repeat('b', 64);
}
if ($scenario['missing_cookie'] ?? false) {
    $_COOKIE = array();
}
if ($scenario['missing_table'] ?? false) {
    $db->exec('DROP TABLE user_auth_cache');
}
if (in_array($scenario['operation'] ?? '', array('domain', 'domain-cn'), true)) {
    $db->exec('UPDATE user_auth SET realm=1003 WHERE id=43');
    if ($scenario['foreign_realm'] ?? false) {
        $db->exec('UPDATE user_auth SET realm=1004 WHERE id=43');
    }
    $db->exec('CREATE TABLE user_domains(domain_id INTEGER,domain_name TEXT,user_id INTEGER,enabled TEXT DEFAULT "on",defdomain INTEGER DEFAULT 0)');
    $db->prepare('INSERT INTO user_domains(domain_id,domain_name,user_id) VALUES(3,?,?)')->execute(array('fixture.example', ($scenario['missing_template'] ?? false) ? 55 : 0));
    $scenario['config']['auth_method'] = 4;
    $db->exec('CREATE TABLE user_domains_ldap(domain_id INTEGER,server TEXT,group_require TEXT)');
    $db->exec("INSERT INTO user_domains_ldap VALUES(3,'fixture.example','')");
}
#[AllowDynamicProperties]
class Ldap
{
    public function Search()
    {
        return ($GLOBALS['scenario']['search_failure'] ?? false)
            ? array('error_num' => 2, 'error_text' => 'directory unavailable')
            : array('error_num' => 0, 'dn' => 'uid=alice,dc=fixture');
    }
    public function Getcn()
    {
        $GLOBALS['directory_calls'][] = array('username' => $this->username,'host' => $this->host,'cn' => $this->cn);
        return array('error_num' => 0, 'cn' => array('cn' => 'Fixture User','mail' => 'fixture@example.invalid'));
    }
    public function Authenticate()
    {
        $GLOBALS['events'][] = 'bind';
        return ($GLOBALS['scenario']['bind_failure'] ?? false)
            ? array('error_num' => 1, 'error_text' => 'invalid credential')
            : array('error_num' => 0, 'error_text' => '');
    }
}
function get_nfilter_request_var($name)
{
    return $name === 'realm' ? 1003 : ($GLOBALS['scenario']['password'] ?? 'test-password');
}
function get_filter_request_var($name)
{
    return filter_var(get_nfilter_request_var($name), FILTER_VALIDATE_INT);
}
$events = array();
$issued = null;
$identity_queries = array();
$directory_calls = array();
function db_fetch_row_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_cell_prepared($sql, $params = array())
{
    if (str_contains($sql, 'WHERE username = ?')) {
        $GLOBALS['identity_queries'][] = array('sql' => $sql, 'params' => $params);
    }
    $row = db_fetch_row_prepared($sql, $params);
    return $row ? reset($row) : false;
}
function db_fetch_assoc($sql)
{
    return $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_execute_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql));
    return $q->execute($params);
}
function db_table_exists($table)
{
    return (bool) db_fetch_cell_prepared('SELECT 1 FROM sqlite_master WHERE type=? AND name=?', array('table', $table));
}
function read_config_option($name)
{
    return $GLOBALS['scenario']['config'][$name] ?? (array('auth_cache_enabled' => ($GLOBALS['scenario']['cache_disabled'] ?? false) ? '' : 'on', 'secpass_lockfailed' => 3)[$name] ?? '');
}
function db_column_exists($table, $column)
{
    return in_array($column, array_column($GLOBALS['db']->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
}
function cacti_count($items)
{
    return count($items);
}
function get_guest_account()
{
    return $GLOBALS['scenario']['guest'] ?? 99;
}
function get_client_addr()
{
    return '127.0.0.1';
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function cacti_log(...$args) {}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function cacti_cookie_session_logout()
{
    $GLOBALS['events'][] = 'clear';
}
function cacti_cookie_session_set($id, $realm, $token)
{
    $GLOBALS['issued'] = array('id' => $id, 'realm' => $realm, 'token' => $token);
    $GLOBALS['events'][] = 'issue';
}
if (isset($argv[3])) {
    define('AUTH_POLICY_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/auth.php';
if (($scenario['operation'] ?? '') === 'basic') {
    $db->exec('UPDATE user_auth SET realm=2 WHERE id=43');
    $result = basic_auth_login_process('alice');
} elseif (($scenario['operation'] ?? '') === 'domain-cn') {
    $result = domains_ldap_search_cn('alice', array('cn', 'mail'), $scenario['directory_realm'] ?? 1003);
} elseif (($scenario['operation'] ?? '') === 'local-password') {
    if (!empty($scenario['legacy_schema'])) {
        $db->exec('ALTER TABLE user_auth DROP COLUMN lastfail');
    }
    $query = $db->prepare('UPDATE user_auth SET password=? WHERE id=42');
    $query->execute(array(password_hash('test-password', PASSWORD_DEFAULT)));
    $error = false;
    $error_msg = '';
    $result = secpass_login_process('alice');
} elseif (($scenario['operation'] ?? '') === 'password-history') {
    $db->exec("ALTER TABLE user_auth ADD COLUMN password_history TEXT DEFAULT ''");
    $query = $db->prepare('UPDATE user_auth SET password=?,password_history=? WHERE id=42');
    $query->execute(array(password_hash('current-test-password', PASSWORD_DEFAULT), implode('|', array(password_hash('expired-test-password', PASSWORD_DEFAULT), password_hash('retained-test-password', PASSWORD_DEFAULT)))));
    $result = secpass_check_history(42, $scenario['password']);
} elseif (($scenario['operation'] ?? '') === 'domain') {
    $error = false;
    $error_msg = '';
    $result = domains_login_process('alice');
} else {
    $result = ($scenario['operation'] ?? 'check') === 'clear' ? clear_auth_cookie() : check_auth_cookie();
}
$rows = db_table_exists('user_auth_cache') ? $db->query('SELECT user_id,token FROM user_auth_cache ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC) : array();
$state = array('directory_calls' => $directory_calls, 'identity_queries' => $identity_queries, 'result' => $result, 'rows' => $rows, 'events' => $events, 'issued' => $issued, 'old_hash' => $hash, 'error' => $error ?? false, 'failed_attempts' => $db->query('SELECT id,failed_attempts FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR), 'audit' => $db->query('SELECT user_id,result FROM user_log')->fetchAll(PDO::FETCH_ASSOC));
$nativeChildCoverageMarkers = array('native-auth-operation-returned', 'credential-and-audit-readback');
fwrite(STDOUT, json_encode($state, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
