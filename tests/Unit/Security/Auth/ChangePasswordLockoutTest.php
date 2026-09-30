<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * A wrong current password on the change password page must count toward
 * account lockout, as a failed login does, and must be checked before the
 * history and reuse rules, which also compare a guess with the stored hash.
 * The page does not load include/auth.php, so it must also refuse a locked
 * account on its own. Guesses there used to be free and unlimited.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function change_password_lockout_run(array $scenario): array
{
    $root = dirname(__DIR__, 4);
    $work = sys_get_temp_dir() . '/kadupul-acpl-' . bin2hex(random_bytes(6));
    $auth = file_get_contents($root . '/lib/auth.php');

    mkdir($work . '/include', 0700, true);

    // The custom_password hook answers RESKIN, which ends the page right after
    // the password change logic and before it draws the form.
    $global = <<<'PHP'
<?php
$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);
define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 1);
define('POLLER_VERBOSITY_LOW', 2);
$config = array('url_path' => '/kadupul/');
$_SESSION = array('sess_user_id' => '42', 'sess_user_credential' => hash('sha256', 'hash:Current1pass'));
$GLOBALS['calls'] = array('redirect' => null, 'executed' => array(), 'messages' => array());
register_shutdown_function(function () {
    print json_encode(array(
        'session' => $_SESSION,
        'calls' => $GLOBALS['calls'],
        'error_message' => $GLOBALS['errorMessage'] ?? null,
    ));
});
function cacti_require_post_actions($actions) {}
function set_default_action($default = '') {}
function get_request_var($name, $default = '') { return $name === 'action' ? 'changepassword' : $default; }
function get_nfilter_request_var($name, $default = '') { return $GLOBALS['scenario']['request'][$name] ?? $default; }
function validate_redirect_url($url = '', $default = 'index.php') { return $default; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function cacti_header($location) { $GLOBALS['calls']['redirect'] = $location; }
function cacti_cookie_logout() {}
function raise_message($id, $message = '', $level = 0) { $GLOBALS['calls']['messages'][] = $id; }
function get_cacti_version() { return '1.2.31'; }
function get_guest_account() { return 0; }
function get_client_addr() { return '192.0.2.10'; }
function cacti_log(...$args) {}
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function cacti_count($array) { return is_array($array) ? count($array) : 0; }
function __($text, ...$args) { return vsprintf($text, $args); }
function read_config_option($name, $force = false) { return $GLOBALS['scenario']['config'][$name] ?? ''; }
function api_plugin_hook_function($name, $parm = null) { return $name === 'custom_password' ? OPER_MODE_RESKIN : $parm; }
function compat_password_verify($password, $hash) { return $hash === 'hash:' . $password; }
function compat_password_hash($password, $algo, $options = array()) { return 'hash:' . $password; }
function db_check_password_length() {}
function db_fetch_row_prepared($sql, $params = array()) {
    return array('id' => 42, 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'password' => 'hash:Current1pass',
        'password_change' => 'on', 'locked' => $GLOBALS['scenario']['locked'], 'password_history' => $GLOBALS['scenario']['history']);
}
function db_fetch_cell_prepared($sql, $params = array()) {
    if (str_contains($sql, '`locked`')) {
        return $GLOBALS['scenario']['locked_after'];
    }
    return $GLOBALS['scenario']['failed_after'];
}
function db_execute_prepared($sql, $params = array()) {
    $GLOBALS['calls']['executed'][] = trim(preg_replace('/\s+/', ' ', $sql));
    return true;
}
PHP;

    foreach (array('auth_session_credential_key', 'auth_session_credentials_valid', 'auth_process_lockout', 'secpass_check_pass', 'secpass_check_history') as $name) {
        $global .= "\n" . test_php_function_source($auth, $name) . "\n";
    }

    file_put_contents($work . '/include/global.php', $global);
    copy($root . '/auth_changepassword.php', $work . '/auth_changepassword.php');

    try {
        $pipes = array();
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', $work . '/auth_changepassword.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $work
        );
        fwrite($pipes[0], json_encode($scenario + array(
            'config' => array('secpass_lockfailed' => 3, 'secpass_minlen' => 8, 'secpass_history' => 2),
            'locked' => '',
            'locked_after' => '',
            'failed_after' => 1,
            'history' => 'hash:Earlier1pass',
        )));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    } finally {
        unlink($work . '/include/global.php');
        unlink($work . '/auth_changepassword.php');
        rmdir($work . '/include');
        rmdir($work);
    }

    expect($stderr)->toBe('');

    return json_decode($stdout, true);
}

function change_password_lockout_counted(array $result): bool
{
    return in_array('UPDATE user_auth SET lastfail = ?, failed_attempts = failed_attempts + 1 WHERE username = ? AND realm = ?', $result['calls']['executed'], true);
}

function change_password_lockout_changed(array $result): bool
{
    return (bool) preg_grep('/^UPDATE user_auth SET must_change_password = \'\', password = \?/', $result['calls']['executed']);
}

test('a wrong current password counts toward lockout', function () {
    $result = change_password_lockout_run(array('request' => array(
        'current_password' => 'guess', 'password' => 'Fresh1pass', 'password_confirm' => 'Fresh1pass',
    )));

    expect(change_password_lockout_counted($result))->toBeTrue()
        ->and(change_password_lockout_changed($result))->toBeFalse()
        ->and($result['error_message'])->toContain('current password is not correct')
        ->and($result['session']['sess_user_id'] ?? null)->toBe('42');
});

test('a wrong current password is reported before the history rule can answer', function () {
    $result = change_password_lockout_run(array('request' => array(
        'current_password' => 'guess', 'password' => 'Earlier1pass', 'password_confirm' => 'Earlier1pass',
    )));

    expect($result['error_message'])->toContain('current password is not correct')
        ->and(change_password_lockout_counted($result))->toBeTrue();
});

test('the guess that locks the account ends the session', function () {
    $result = change_password_lockout_run(array(
        'request' => array('current_password' => 'guess', 'password' => 'Fresh1pass', 'password_confirm' => 'Fresh1pass'),
        'failed_after' => 3,
        'locked_after' => 'on',
    ));

    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['calls']['redirect'])->toBe('index.php');
});

test('a locked account cannot use the page', function () {
    $result = change_password_lockout_run(array(
        'request' => array('current_password' => 'Current1pass', 'password' => 'Fresh1pass', 'password_confirm' => 'Fresh1pass'),
        'locked' => 'on',
    ));

    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['calls']['redirect'])->toBe('index.php')
        ->and($result['calls']['executed'])->toBe(array());
});

test('the right current password changes the password without counting a failure', function () {
    $result = change_password_lockout_run(array('request' => array(
        'current_password' => 'Current1pass', 'password' => 'Fresh1pass', 'password_confirm' => 'Fresh1pass',
    )));

    expect(change_password_lockout_changed($result))->toBeTrue()
        ->and(change_password_lockout_counted($result))->toBeFalse();
});
