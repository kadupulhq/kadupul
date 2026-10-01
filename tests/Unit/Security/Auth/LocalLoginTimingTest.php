<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * A local login for an unknown username must do the same password hashing
 * work as one for a known username, or response time shows which usernames
 * exist. The shipped login functions run in a child process against a
 * counting password verifier and hasher.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function local_login_timing_run(string $username, string $password, string $state = "enabled"): array
{
    $auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

    $program = <<<'PHP'
$scenario = json_decode($argv[1], true);
define('POLLER_VERBOSITY_DEBUG', 5);
$GLOBALS['users'] = array('alice' => array('id' => 42, 'username' => 'alice', 'enabled' => 'on', 'locked' => '', 'password' => 'known-hash'));
$GLOBALS['users']['alice']['enabled'] = $scenario['state'] === 'disabled' ? '' : 'on';
$GLOBALS['hashes'] = array();
$error = false;
$error_msg = '';
function get_nfilter_request_var($name, $default = '') { return $name === 'login_password' ? $GLOBALS['scenario']['password'] : $default; }
function __($text, ...$args) { return vsprintf($text, $args); }
function cacti_log(...$args) {}
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function read_config_option($name, $force = false) { return ''; }
function api_plugin_hook_function($name, $parm = null) { return $parm; }
function auth_checkclear_lockout($username, $realm) {}
function auth_process_lockout_check($username, $realm) { global $error; if ($GLOBALS['scenario']['state'] === 'locked' && $username === 'alice') { $error = true; return true; } return false; }
function auth_process_lockout($username, $realm) {}
function db_column_exists($table, $column) { return true; }
function db_fetch_row_prepared($sql, $params = array()) { return $GLOBALS['users'][$params[0]] ?? array(); }
function db_fetch_cell_prepared($sql, $params = array()) { return $GLOBALS['users'][$params[0]]['password'] ?? ''; }
function db_execute_prepared($sql, $params = array()) { return true; }
function compat_password_verify($password, $hash) {
    $GLOBALS['hashes'][] = $hash;
    return $hash === 'known-hash' && $password === 'right';
}
function compat_password_needs_rehash($password, $algo, $options = array()) { return false; }
function compat_password_hash($password, $algo, $options = array()) {
    $GLOBALS['hashes'][] = 'hash:' . $algo;
    return 'new-hash';
}
$GLOBALS['scenario'] = $scenario;
PHP;

    foreach (array('secpass_login_process', 'local_auth_login_process', 'auth_unknown_user_password_verify') as $function) {
        if (strpos($auth, "\nfunction $function(") !== false) {
            $program .= "\n" . test_php_function_source($auth, $function) . "\n";
        }
    }

    $program .= '$user = local_auth_login_process($scenario[\'username\']);';
    $program .= 'print json_encode(array(\'user\' => $user, \'error\' => $error, \'hashes\' => $GLOBALS[\'hashes\']));';

    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, json_encode(array('username' => $username, 'password' => $password, 'state' => $state))),
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

test('an unknown username runs as many password verifications as a known one', function (string $password) {
    $known = local_login_timing_run('alice', $password);
    $unknown = local_login_timing_run('nobody', $password);

    expect($known['user'])->toBe(array())
        ->and($unknown['user'])->toBe(array())
        ->and($known['error'])->toBeTrue()
        ->and($unknown['error'])->toBeTrue()
        ->and($known['hashes'])->not->toBe(array())
        ->and(count($unknown['hashes']))->toBe(count($known['hashes']));
})->with(array('wrong password' => 'guess', 'blank password' => ''));

test('the stand-in work hashes at the PASSWORD_DEFAULT cost', function () {
    $unknown = local_login_timing_run('nobody', 'guess');

    expect($unknown['hashes'])->toBe(array('hash:' . PASSWORD_DEFAULT, 'hash:' . PASSWORD_DEFAULT));
});

test('a correct password still returns the account', function () {
    $result = local_login_timing_run('alice', 'right');

    expect($result['user']['id'] ?? null)->toBe(42)
        ->and($result['error'])->toBeFalse();
});

test('disabled and locked usernames do the same password work as unknown names', function (string $state, string $password) {
    $known = local_login_timing_run('alice', $password, $state);
    $unknown = local_login_timing_run('nobody', $password, $state);
    expect($known['error'])->toBeTrue()->and(count($known['hashes']))->toBe(count($unknown['hashes']));
})->with(array(array('disabled', 'guess'), array('locked', 'guess'), array('disabled', ''), array('locked', '')));
