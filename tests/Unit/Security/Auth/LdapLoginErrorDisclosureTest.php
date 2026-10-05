<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The login page is shown to unauthenticated clients, so every directory
 * failure must produce the same message. The detail belongs in the log, and
 * lockout counting stays as it was: only a rejected password counts.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function ldap_disclosure_run(string $function, array $scenario): array
{
    $root = dirname(__DIR__, 4);
    $body = test_php_function_source(file_get_contents($root . '/lib/auth.php'), $function);
    $scenario += array(
        'username' => 'alice',
        'password' => 'secret',
        'realm' => '1001',
        'search' => array('error_num' => '0', 'error_text' => '', 'dn' => 'uid=alice,dc=example,dc=com'),
        'auth' => array('error_num' => '0', 'error_text' => ''),
    );

    $program = <<<'PHP'
$scenario = json_decode($argv[1], true);
$GLOBALS['logs'] = array();
$GLOBALS['lockout_calls'] = 0;
$error = false;
$error_msg = '';
function get_nfilter_request_var($name, $default = '') {
    return array('login_password' => $GLOBALS['scenario']['password'], 'realm' => $GLOBALS['scenario']['realm'])[$name] ?? $default;
}
function __($text, ...$args) { return vsprintf($text, $args); }
function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') { $GLOBALS['logs'][] = $string; }
function auth_checkclear_lockout($username, $realm) {}
function auth_process_lockout_check($username, $realm) { return false; }
function auth_process_lockout($username, $realm) { $GLOBALS['lockout_calls']++; }
function cacti_ldap_search_dn($username) { return $GLOBALS['scenario']['search']; }
function cacti_ldap_auth($username, $password, $dn) { return $GLOBALS['scenario']['auth']; }
function domains_ldap_search_dn($username, $realm) { return $GLOBALS['scenario']['search']; }
function domains_ldap_auth($username, $password, $dn, $realm) { return $GLOBALS['scenario']['auth']; }
function db_fetch_row_prepared($sql, $params = array()) { return array('id' => 9, 'username' => $params[0], 'realm' => $params[1]); }
function db_fetch_cell_prepared($sql, $params = array()) { return strpos($sql, 'domain_name') !== false ? 'example' : 0; }
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
$GLOBALS['scenario'] = $scenario;
PHP;

    $program .= "\n" . $body . "\n";
    $program .= '$user = ' . $function . '($scenario[\'username\']);';
    $program .= 'print json_encode(array(\'error\' => $error, \'error_msg\' => $error_msg, \'user\' => $user, \'logs\' => $GLOBALS[\'logs\'], \'lockout_calls\' => $GLOBALS[\'lockout_calls\']));';

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

dataset('ldap login failures', array(
    'user not found' => array(array('search' => array('error_num' => 14, 'error_text' => 'Unable to find users DN', 'dn' => '')), 0),
    'several users found' => array(array('search' => array('error_num' => 13, 'error_text' => 'More than one matching user found', 'dn' => '')), 0),
    'wrong password' => array(array('auth' => array('error_num' => 1, 'error_text' => 'Authentication Failure')), 1),
    'not in group' => array(array('auth' => array('error_num' => 8, 'error_text' => 'Insufficient Access to Server (ldap.example.com)')), 0),
    'server unreachable' => array(array('auth' => array('error_num' => 9, 'error_text' => 'Unable to Connect to Server (ldap.example.com)')), 0),
));

test('every directory login failure shows one generic message and logs the detail', function (string $function, array $scenario, int $lockouts) {
    $result = ldap_disclosure_run($function, $scenario);
    $detail = ($scenario['auth'] ?? $scenario['search'])['error_text'];

    expect($result['error'])->toBeTrue()
        ->and($result['error_msg'])->toBe('Access Denied!  Login Failed.')
        ->and($result['user'])->toBe(array())
        ->and(implode("\n", $result['logs']))->toContain($detail)
        ->and($result['lockout_calls'])->toBe($lockouts);
})->with(array('ldap_login_process', 'domains_login_process'))->with('ldap login failures');

test('an empty password keeps its own message', function (string $function) {
    $result = ldap_disclosure_run($function, array('password' => ''));

    expect($result['error'])->toBeTrue()
        ->and($result['error_msg'])->toBe('Access Denied!  No password provided by user.');
})->with(array('ldap_login_process', 'domains_login_process'));

test('a successful directory login still returns the account without an error', function (string $function, string $realm) {
    $result = ldap_disclosure_run($function, array());

    expect($result['error'])->toBeFalse()
        ->and($result['error_msg'])->toBe('')
        ->and($result['user'])->toBe(array('id' => 9, 'username' => 'alice', 'realm' => $realm === '3' ? 3 : $realm))
        ->and($result['lockout_calls'])->toBe(0);
})->with(array(
    'ldap' => array('ldap_login_process', '3'),
    'domains' => array('domains_login_process', '1001'),
));
