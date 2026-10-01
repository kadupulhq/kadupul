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
$config = array();
$db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY, username TEXT, realm INTEGER, enabled TEXT, locked TEXT, password TEXT, lastfail INTEGER, failed_attempts INTEGER)");
$db->exec("INSERT INTO user_auth VALUES(42,'alice',0,'on','','',0,0),(43,'alice',3,'on','','',0,0),(44,'foreign',0,'on','','',0,0)");
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
if (($scenario['operation'] ?? '') === 'domain') {
    $db->exec('UPDATE user_auth SET realm=1003 WHERE id=43');
    if ($scenario['foreign_realm'] ?? false) {
        $db->exec('UPDATE user_auth SET realm=1004 WHERE id=43');
    }
    $db->exec('CREATE TABLE user_domains(domain_id INTEGER,domain_name TEXT,user_id INTEGER)');
    $db->prepare('INSERT INTO user_domains VALUES(3,?,?)')->execute(array('fixture.example', ($scenario['missing_template'] ?? false) ? 55 : 0));
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
    return $name === 'realm' ? 1003 : 'test-password';
}
$events = array();
$issued = null;
function db_fetch_row_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $row = db_fetch_row_prepared($sql, $params);
    return $row ? reset($row) : false;
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
    return array('auth_cache_enabled' => ($GLOBALS['scenario']['cache_disabled'] ?? false) ? '' : 'on', 'secpass_lockfailed' => 3)[$name] ?? '';
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
if (($scenario['operation'] ?? '') === 'domain') {
    $error = false;
    $error_msg = '';
    $result = domains_login_process('alice');
} else {
    $result = ($scenario['operation'] ?? 'check') === 'clear' ? clear_auth_cookie() : check_auth_cookie();
}
$rows = db_table_exists('user_auth_cache') ? $db->query('SELECT user_id,token FROM user_auth_cache ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC) : array();
fwrite(STDOUT, json_encode(array('result' => $result, 'rows' => $rows, 'events' => $events, 'issued' => $issued, 'old_hash' => $hash, 'error' => $error ?? false, 'failed_attempts' => $db->query('SELECT id,failed_attempts FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR), 'audit' => $db->query('SELECT user_id,result FROM user_log')->fetchAll(PDO::FETCH_ASSOC)), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
