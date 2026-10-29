<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function logout_cookie_race_probe(bool $revoke): array
{
    $auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');
    $program = '';
    foreach (['clear_auth_cookie', 'set_auth_cookie', 'check_auth_cookie', 'auth_cookie_generation_valid', 'auth_cookie_user_id', 'auth_logout_everywhere', 'auth_session_credential_key', 'auth_session_credential_generation', 'auth_session_bind_credentials', 'auth_session_credentials_valid', 'cacti_auth_transition'] as $name) {
        $program .= test_php_function_source($auth, $name) . "\n";
    }
    $program .= <<<'PHP'
$revoke = $argv[1] === 'revoke';
$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->sqliteCreateFunction('NOW', static fn() => '2026-10-14 00:00:00');
$database_hostname='fixture'; $database_port=0; $database_default='auth';
$database_sessions=['fixture:0:auth'=>$db];
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,realm INTEGER,enabled TEXT,locked TEXT,password TEXT);
    INSERT INTO user_auth VALUES(42,'operator',0,'on','','unchanged-hash');
    CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(user_id,name));
    CREATE TABLE sessions(user_id INTEGER);
    INSERT INTO sessions VALUES(42);
    CREATE TABLE user_auth_cache(user_id INTEGER,hostname TEXT,last_update TEXT,token TEXT);
    CREATE TABLE user_log(username TEXT,user_id INTEGER,result INTEGER,ip TEXT,time TEXT);");
$db->prepare("INSERT INTO user_auth_cache VALUES(42,'127.0.0.1',?,?)")->execute([date('Y-m-d H:i:s'),hash('sha512','old-remembered-token')]);
$_SESSION=[]; $_COOKIE=['cacti_remembers'=>'42,0,old-remembered-token'];
define('POLLER_VERBOSITY_MEDIUM',3);
function cacti_log(...$args) {}
function read_config_option($name) { return $name === 'auth_cache_enabled' ? 'on' : ''; }
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function get_client_addr() { return '127.0.0.1'; }
function get_guest_account() { return 0; }
function auth_process_lockout_check(...$args) { return false; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function cacti_cookie_session_logout() { unset($_COOKIE['cacti_remembers']); }
function cacti_cookie_session_set($user,$realm,$token) { $GLOBALS['emitted_token']=$token; }
function db_table_exists($name) { $q=$GLOBALS['db']->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"); $q->execute([$name]); return $q->fetchColumn() !== false; }
function db_execute_prepared($sql,$params=[]) { return $GLOBALS['db']->prepare(str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql))->execute($params); }
function db_fetch_row_prepared($sql,$params=[]) { $q=$GLOBALS['db']->prepare($sql); $q->execute($params); return $q->fetch(PDO::FETCH_ASSOC) ?: []; }
function db_fetch_cell_prepared($sql,$params=[]) {
    $q=$GLOBALS['db']->prepare($sql); $q->execute($params); $value=$q->fetchColumn(); $q->closeCursor();
    if ($GLOBALS['revoke'] && str_contains($sql,'SELECT user_id') && str_contains($sql,'FROM user_auth_cache')) {
        $GLOBALS['revoke']=false;
        $GLOBALS['revoked']=auth_logout_everywhere(42,auth_session_credential_key('unchanged-hash'),$GLOBALS['db']);
    }
    return $value;
}
$user=check_auth_cookie();
$transition=$user > 0 && cacti_auth_transition((int)$user,'cookie_restore');
if ($transition) { $_SESSION['sess_user_id']=$user; }
$token=$GLOBALS['emitted_token'] ?? null;
$known=$token !== null && db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_cache WHERE token=?',[hash('sha512',$token)]) === 1;
$generation=auth_session_credential_generation(42,'unchanged-hash',$db);
$tokenValid=$known && (!function_exists('auth_cookie_generation_valid') || auth_cookie_generation_valid($token,$generation));
echo json_encode(['revoked'=>$GLOBALS['revoked'] ?? false,'resumed'=>$transition && auth_session_credentials_valid('unchanged-hash'),'token_valid'=>$tokenValid],JSON_THROW_ON_ERROR);
PHP;
    $result = test_php_run([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $revoke ? 'revoke' : 'normal']);
    expect($result['status'])->toBe(0)->and($result['err'])->toBe('');

    return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
}

test('an in-flight remembered login cannot recreate credentials after logout everywhere commits', function () {
    $state = logout_cookie_race_probe(true);
    expect($state['revoked'])->toBeTrue()->and($state['resumed'])->toBeFalse()->and($state['token_valid'])->toBeFalse();
});

test('ordinary remembered login still establishes a usable session and token', function () {
    $state = logout_cookie_race_probe(false);
    expect($state['resumed'])->toBeTrue()->and($state['token_valid'])->toBeTrue();
});
