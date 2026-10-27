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

function local_login_timing_run(string $username, string $password, string $state = "enabled", ?string $legacy_hash = null, bool $replaced = false): array
{
    $auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

    $program = <<<'PHP'
$scenario = json_decode($argv[1], true);
define('POLLER_VERBOSITY_DEBUG', 5);
$GLOBALS['users'] = array('alice' => array('id' => 42, 'username' => 'alice', 'enabled' => 'on', 'locked' => '', 'password' => $scenario['legacy_hash'] ?? 'known-hash'));
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
function db_fetch_row_prepared($sql, $params = array()) {
    $row = $GLOBALS['users'][$params[0]] ?? array();
    // A password reset that lands between verification and the full row read.
    if ($row && str_contains($sql, 'SELECT *') && !empty($GLOBALS['scenario']['replaced'])) {
        $row['password'] = 'replaced-hash';
    }
    return $row;
}
function db_fetch_cell_prepared($sql, $params = array()) { return $GLOBALS['users'][$params[0]]['password'] ?? ''; }
function db_execute_prepared($sql, $params = array()) { return true; }
function compat_password_verify($password, $hash) {
    $GLOBALS['hashes'][] = $hash;
    return $hash === 'known-hash' && $password === 'right';
}
function compat_password_needs_rehash($password, $algo, $options = array()) { return false; }
function compat_password_hash($password, $algo, $options = array()) {
    if (str_contains($password, chr(0))) { throw new ValueError('Bcrypt password must not contain NUL'); }
    $GLOBALS['hashes'][] = 'hash:' . $algo;
    return 'new-hash';
}
$GLOBALS['scenario'] = $scenario;
PHP;

    foreach (array('secpass_login_process', 'local_auth_login_process', 'auth_local_login_timing_floor', 'auth_unknown_user_password_verify') as $function) {
        if (strpos($auth, "\nfunction $function(") !== false) {
            $program .= "\n" . test_php_function_source($auth, $function) . "\n";
        }
    }

    if ($legacy_hash !== null) {
        $program = preg_replace('/function compat_password_verify\(\$password, \$hash\) \{.*?\n\}/s', '', $program);
        $program .= test_php_function_source($auth, 'compat_password_verify');
        $program .= test_php_function_source($auth, 'compat_hash_equals');
    }

    $program .= '$user = local_auth_login_process($scenario[\'username\']);';
    $program .= 'print json_encode(array(\'user\' => $user, \'error\' => $error, \'error_msg\' => $error_msg, \'hashes\' => $GLOBALS[\'hashes\']));';

    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, json_encode(array('username' => $username, 'password' => $password, 'state' => $state, 'legacy_hash' => $legacy_hash, 'replaced' => $replaced))),
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
        ->and($known['error_msg'])->toBe($unknown['error_msg'])
        ->and(count($known['hashes']))->toBe($password === '' ? 0 : 1)
        ->and(count($unknown['hashes']))->toBe(count($known['hashes']));
})->with(array('wrong password' => 'guess', 'blank password' => ''));

test('the stand-in work hashes at the PASSWORD_DEFAULT cost', function () {
    $unknown = local_login_timing_run('nobody', 'guess');

    expect($unknown['hashes'])->toBe(array('hash:' . PASSWORD_DEFAULT));
});

test('a correct password still returns the account', function () {
    $result = local_login_timing_run('alice', 'right');

    expect($result['user']['id'] ?? null)->toBe(42)
        ->and($result['error'])->toBeFalse();
});

// GHSA-p3rg-7pc3-2h86, upstream Cacti #8157: a known account used to verify
// twice, once to log in and again before a rehash. Each path now does the work
// of one verification, so the count is fixed rather than only equal.
test('every local login path does the work of exactly one password verification', function (string $username, string $state, string $password, ?string $hash) {
    $result = local_login_timing_run($username, $password, $state, $hash);

    expect(count($result['hashes']))->toBe(trim($password) === '' ? 0 : 1);
})->with(array(
    'known, wrong password' => array('alice', 'enabled', 'guess', null),
    'known, right password' => array('alice', 'enabled', 'right', null),
    'known, blank password' => array('alice', 'enabled', '', null),
    'unknown' => array('nobody', 'enabled', 'guess', null),
    'unknown, blank password' => array('nobody', 'enabled', '', null),
    'disabled' => array('alice', 'disabled', 'guess', null),
    'locked' => array('alice', 'locked', 'guess', null),
    'legacy MD5, wrong password' => array('alice', 'enabled', 'guess', '7c4f29407893c334a6cb7a87bf045c0d'),
    'legacy MD5, right password' => array('alice', 'enabled', 'right', '7c4f29407893c334a6cb7a87bf045c0d'),
    'empty stored hash' => array('alice', 'enabled', 'guess', ''),
));

test('a password replaced after verification refuses the login', function () {
    $result = local_login_timing_run('alice', 'right', 'enabled', null, true);

    expect($result['user'])->toBe(array())
        ->and($result['error'])->toBeTrue()
        ->and($result['error_msg'])->toBe('Access Denied!  Login Failed.')
        ->and($result['hashes'])->toBe(array('known-hash'));
});

test('disabled and locked usernames do the same password work as unknown names', function (string $state, string $password) {
    $known = local_login_timing_run('alice', $password, $state);
    $unknown = local_login_timing_run('nobody', $password, $state);
    expect($known['error'])->toBeTrue()->and(count($known['hashes']))->toBe(count($unknown['hashes']));
})->with(array(array('disabled', 'guess'), array('locked', 'guess'), array('disabled', ''), array('locked', '')));


// Precomputed legacy MD5 for the documented fixture password 'right'.
// This exercises verification only; tests never create or persist MD5 passwords.
test('legacy MD5 and empty hashes run the same fixed-cost password work as an unknown account', function (string $hash, string $password, string $state) {
    $known = local_login_timing_run('alice', $password, $state, $hash);
    $unknown = local_login_timing_run('nobody', $password, $state, $hash);
    expect($known['error'])->toBeTrue()->and($known['hashes'])->toBe($unknown['hashes']);
})->with(array(
    array('7c4f29407893c334a6cb7a87bf045c0d', 'guess', 'enabled'), array('', 'guess', 'enabled'),
    array('7c4f29407893c334a6cb7a87bf045c0d', 'guess', 'disabled'), array('7c4f29407893c334a6cb7a87bf045c0d', 'guess', 'locked'),
    array('7c4f29407893c334a6cb7a87bf045c0d', '', 'enabled'), array('', '', 'enabled'),
    array('7c4f29407893c334a6cb7a87bf045c0d', chr(0), 'enabled'),
));

test('native local login enforces one elapsed floor across real bcrypt costs and unknown accounts', function () {
    require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';
    $durations = array();
    foreach (array(10, 12, null) as $cost) {
        $scenario = array(
            'call' => array('type' => 'local_auth_login_process', 'args' => array($cost === null ? 'unknown' : 'alice')),
            'request' => array('login_password' => 'wrong'),
            'users' => array(),
        );
        if ($cost !== null) {
            // Neither a lower floor nor invalid text may disable the default.
            $scenario['runtime_config'] = array('auth_login_timing_floor_ms' => $cost === 10 ? 0 : 'invalid');
        }
        if ($cost !== null) {
            $scenario['users'][] = array('id' => 42, 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => password_hash('right', PASSWORD_BCRYPT, array('cost' => $cost)), 'lastfail' => 0, 'failed_attempts' => 0);
        }
        $result = auth_entry_probe_run($scenario);
        expect($result['stderr'])->toBe('')->and($result['return'])->toBe(array())
            ->and($result['error'])->toBeTrue()->and($result['elapsed_seconds'])->toBeGreaterThanOrEqual(0.99);
        $durations[] = $result['elapsed_seconds'];
    }
    // These real cost-10/12 paths must fit inside the default floor on this
    // test host, rather than merely count calls to a stubbed verifier.
    expect(max($durations) - min($durations))->toBeLessThan(0.35);
});

test('native successful login preserves real bcrypt rehash and a raised timing floor', function () {
    require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';
    $scenario = array(
        'call' => array('type' => 'local_auth_login_process', 'args' => array('alice')),
        'request' => array('login_password' => 'right'),
        'runtime_config' => array('auth_login_timing_floor_ms' => 1200),
        'credential_database' => true,
        'users' => array(array(
            'id' => 42, 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '',
            'password' => password_hash('right', PASSWORD_BCRYPT, array('cost' => 10)),
            'lastfail' => 0, 'failed_attempts' => 0,
        )),
    );
    $result = auth_entry_probe_run($scenario);
    expect($result['stderr'])->toBe('')->and($result['return']['id'])->toBe(42)
        ->and($result['elapsed_seconds'])->toBeGreaterThanOrEqual(1.19);
    expect(password_verify('right', $result['credential_password']))->toBeTrue()
        ->and(password_needs_rehash($result['credential_password'], PASSWORD_DEFAULT))->toBeFalse()
        ->and($result['credential_password'])->not->toBe($scenario['users'][0]['password']);
});
