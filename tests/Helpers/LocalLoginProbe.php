<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the shipped secpass_login_process(), secpass_check_pass() and
 * local_auth_login_process() in a child process against stubbed database
 * helpers. The scenario names the username, password, settings and user_auth
 * rows; the result lists the returned user, the SQL writes, raised messages
 * and any header sent.
 */

require_once __DIR__ . '/PhpSource.php';

function local_login_policy_run(array $scenario): array
{
    $auth = file_get_contents(dirname(__DIR__, 2) . '/lib/auth.php');

    $program = <<<'PHP'
$scenario = json_decode($argv[1], true);
define('POLLER_VERBOSITY_DEBUG', 5);
define('MESSAGE_LEVEL_INFO', 1);
$GLOBALS['scenario'] = $scenario;
$GLOBALS['executed'] = array();
$GLOBALS['messages'] = array();
$error = false;
$error_msg = '';
register_shutdown_function(function () {
    print json_encode(array(
        'user' => $GLOBALS['returned'] ?? null,
        'error' => $GLOBALS['error'],
        'error_msg' => $GLOBALS['error_msg'],
        'executed' => $GLOBALS['executed'],
        'messages' => $GLOBALS['messages'],
        'headers' => $GLOBALS['sent_headers'] ?? array(),
    ));
});
function probe_header($value) { $GLOBALS['sent_headers'][] = $value; }
function get_nfilter_request_var($name, $default = '') { return $name === 'login_password' ? $GLOBALS['scenario']['password'] : $default; }
function __($text, ...$args) { return vsprintf($text, $args); }
function cacti_log(...$args) {}
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function cacti_count($array) { return is_array($array) ? count($array) : 0; }
function read_config_option($name, $force = false) { return $GLOBALS['scenario']['config'][$name] ?? ''; }
function api_plugin_hook_function($name, $parm = null) { return $parm; }
function raise_message($id, $message = '', $level = 0) { $GLOBALS['messages'][] = $id; }
function auth_checkclear_lockout($username, $realm) {}
function auth_process_lockout_check($username, $realm) { return false; }
function auth_process_lockout($username, $realm) {}
function db_check_password_length() {}
function auth_rehash_password_preserving_sessions($id, $verified, $replacement) { return db_execute_prepared('UPDATE user_auth SET password = ? WHERE id = ? AND realm = 0 AND password = ?', array($replacement, $id, $verified)); }
function db_column_exists($table, $column) { return true; }
function user_rows($username) {
    return array_values(array_filter($GLOBALS['scenario']['users'], function ($row) use ($username) { return $row['username'] === $username; }));
}
function local_row($username) {
    foreach (user_rows($username) as $row) {
        if ((int) $row['realm'] === 0) {
            return $row;
        }
    }
    return array();
}
function db_fetch_row_prepared($sql, $params = array()) { return local_row($params[0]); }
function db_fetch_cell_prepared($sql, $params = array()) { return local_row($params[0])['password'] ?? ''; }
function db_execute_prepared($sql, $params = array()) {
    $GLOBALS['executed'][] = array('sql' => trim(preg_replace('/\s+/', ' ', $sql)), 'params' => $params);
    return true;
}
function compat_password_verify($password, $hash) { return $hash === 'hash:' . $password; }
function compat_password_hash($password, $algo, $options = array()) { return 'rehash:' . $password; }
function compat_password_needs_rehash($hash, $algo, $options = array()) { return $GLOBALS['scenario']['rehash'] ?? false; }
function auth_unknown_user_password_verify($password) { return false; }
PHP;

    foreach (array('secpass_login_process', 'secpass_check_pass', 'local_auth_login_process') as $function) {
        // header() is built in, so the copied source calls a recorder instead.
        $program .= "\n" . preg_replace('/\bheader\(/', 'probe_header(', test_php_function_source($auth, $function)) . "\n";
    }

    $program .= '$GLOBALS[\'returned\'] = local_auth_login_process($scenario[\'username\']);';

    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, json_encode($scenario)),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stderr)->toBe('');

    return json_decode($stdout, true);
}

function local_login_policy_scenario(string $username, string $password, array $extra = array()): array
{
    return $extra + array(
        'username' => $username,
        'password' => $password,
        'config' => array('secpass_forceold' => 'on', 'secpass_minlen' => 8, 'secpass_reqnum' => 'on'),
        'users' => array(
            array('id' => 42, 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'hash:weak', 'password_change' => 'on', 'lastfail' => 0, 'failed_attempts' => 0),
            array('id' => 77, 'username' => 'alice', 'realm' => 3, 'enabled' => 'on', 'locked' => '', 'password' => '', 'password_change' => 'on'),
        ),
    );
}

function local_login_policy_writes(array $result, string $pattern): array
{
    return array_values(array_filter($result['executed'], function ($row) use ($pattern) {
        return preg_match($pattern, $row['sql']) === 1;
    }));
}
