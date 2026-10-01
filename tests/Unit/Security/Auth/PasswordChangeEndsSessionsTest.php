<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Changing or resetting a password must end the account's other sessions.
 * Deleting rows from the sessions table only does that when $cacti_db_session
 * is on; the default is PHP's file storage, where every session opened before
 * the change stayed valid. A session now keeps a digest of the password hash
 * it logged in with and ends once the stored hash differs, wherever the
 * session is stored.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';
require_once dirname(__DIR__, 3) . '/Helpers/UserAdminSaveProbe.php';
require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

function password_change_user(string $password): array
{
    return array('id' => '42', 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => $password, 'must_change_password' => '', 'password_change' => 'on');
}

function password_change_request(string $stored, array $session, array $extra = array()): array
{
    return $extra + array(
        'config' => array('auth_method' => 1, 'guest_user' => 'guest'),
        'users' => array(password_change_user($stored), array('id' => '3', 'username' => 'guest', 'realm' => 0, 'enabled' => '', 'locked' => '', 'password' => '')),
        'session' => $session,
    );
}

test('a session opened before a password change is ended on its next request', function () {
    $result = auth_entry_probe_run(password_change_request('new-hash', array(
        'sess_user_id' => '42',
        'sess_user_credential' => hash('sha256', 'old-hash'),
    )));

    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['events'])->toContain('session_destroy')
        ->and($result['events'])->toContain('login_page')
        ->and($result['page_continued'])->toBeFalse();
});

test('a session opened after the password change continues', function () {
    $result = auth_entry_probe_run(password_change_request('new-hash', array(
        'sess_user_id' => '42',
        'sess_user_credential' => hash('sha256', 'new-hash'),
    )));

    expect($result['session']['sess_user_id'] ?? null)->toBe('42')
        ->and($result['events'])->not->toContain('session_destroy')
        ->and($result['page_continued'])->toBeTrue();
});

test('an unbound file-backed session must reauthenticate after upgrade or password reset', function (string $stored) {
    $result = auth_entry_probe_run(password_change_request($stored, array('sess_user_id' => '42'), array('bind_session' => false)));
    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['session'])->not->toHaveKey('sess_user_credential')
        ->and($result['events'])->toContain('login_page')
        ->and($result['page_continued'])->toBeFalse();
})->with(array('unchanged-password', 'new-hash-after-reset'));

test('a malformed binding ends the session rather than passing', function ($binding) {
    $result = auth_entry_probe_run(password_change_request('new-hash', array(
        'sess_user_id' => '42',
        'sess_user_credential' => $binding,
    )));

    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['page_continued'])->toBeFalse();
})->with(array('empty' => '', 'not a string' => array(array('x')), 'null' => null));

test('a stale session on a guest page falls back to the guest account', function () {
    $result = auth_entry_probe_run(password_change_request('new-hash', array(
        'sess_user_id' => '42',
        'sess_user_credential' => hash('sha256', 'old-hash'),
    ), array('guest_account' => true)));

    expect((string) ($result['session']['sess_user_id'] ?? ''))->toBe('3')
        ->and($result['events'])->toContain('session_destroy')
        ->and($result['page_continued'])->toBeTrue();
});

test('a stale session is ended before the change password page shortcut', function () {
    $result = auth_entry_probe_run(password_change_request('new-hash', array(
        'sess_user_id' => '42',
        'sess_user_credential' => hash('sha256', 'old-hash'),
    ), array('page' => 'auth_changepassword.php')));

    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['events'])->toContain('session_destroy');
});

test('a login transition binds the session to the current password', function () {
    $result = auth_entry_probe_run(array(
        'users' => array(password_change_user('new-hash')),
        'call' => array('type' => 'cacti_auth_transition', 'args' => array(42, 'login')),
    ));

    expect($result['return'])->toBeTrue()
        ->and($result['session']['sess_user_credential'] ?? null)->toBe(hash('sha256', 'new-hash'));
});

test('a refused transition does not bind the session', function () {
    $result = auth_entry_probe_run(array(
        'users' => array(array('enabled' => '') + password_change_user('new-hash')),
        'call' => array('type' => 'cacti_auth_transition', 'args' => array(42, 'login')),
    ));

    expect($result['return'])->toBeFalse()
        ->and($result['session'])->not->toHaveKey('sess_user_credential');
});

/* auth_changepassword.php includes global.php, not include/auth.php, so it checks the binding itself */
function password_change_page_run(array $session, int $realm = 0): array
{
    $root = dirname(__DIR__, 4);
    $work = sys_get_temp_dir() . '/kadupul-acp-' . bin2hex(random_bytes(6));
    $auth = file_get_contents($root . '/lib/auth.php');

    mkdir($work . '/include', 0700, true);

    $global = <<<'PHP'
<?php
$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);
$config = array('url_path' => '/kadupul/');
$_SESSION = $GLOBALS['scenario']['session'];
$GLOBALS['calls'] = array('redirect' => null);
register_shutdown_function(function () {
    print json_encode(array('session' => $_SESSION, 'calls' => $GLOBALS['calls']));
});
function cacti_require_post_actions($actions) {}
function set_default_action($default = '') {}
function get_request_var($name, $default = '') { return $default; }
function validate_redirect_url($url = '', $default = 'index.php') { return $default; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function cacti_header($location) { $GLOBALS['calls']['redirect'] = $location; }
function raise_message($id, $message = '', $level = 0) {}
function get_cacti_version() { return '1.2.31'; }
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function db_fetch_row_prepared($sql, $params = array()) {
    return array('id' => 42, 'username' => 'alice', 'realm' => $GLOBALS['scenario']['realm'], 'enabled' => 'on', 'password' => 'new-hash', 'password_change' => 'on', 'locked' => '');
}
PHP;

    foreach (array('auth_session_credential_key', 'auth_session_credentials_valid') as $name) {
        $global .= "\n" . test_php_function_source($auth, $name) . "\n";
    }

    file_put_contents($work . '/include/global.php', $global);

    try {
        $pipes = array();
        $process = proc_open(
            child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'require ' . var_export($root . '/auth_changepassword.php', true) . ';'), $coverage_dir),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $work
        );
        fwrite($pipes[0], json_encode(array('session' => $session, 'realm' => $realm)));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        child_coverage_collect($coverage_dir);
    } finally {
        unlink($work . '/include/global.php');
        rmdir($work . '/include');
        rmdir($work);
    }

    expect($stderr)->toBe('');

    return json_decode($stdout, true);
}

test('the change password page treats a session from before the change as logged out', function () {
    $result = password_change_page_run(array(
        'sess_user_id' => '42',
        'sess_change_password' => true,
        'sess_user_credential' => hash('sha256', 'old-hash'),
    ));

    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['session'])->not->toHaveKey('sess_change_password')
        ->and($result['calls']['redirect'])->toBe('index.php');
});

test('the change password page keeps a current session', function () {
    // A domain account stops at the realm check, after the binding check.
    $result = password_change_page_run(array(
        'sess_user_id' => '42',
        'sess_user_credential' => hash('sha256', 'new-hash'),
    ), 3);

    expect($result['session']['sess_user_id'] ?? null)->toBe('42')
        ->and($result['calls']['redirect'])->toBe('/kadupul/index.php');
});

function password_change_admin_save(string $session_user, string $target): array
{
    return user_admin_save_probe_run(array(
        'session' => array('sess_user_id' => $session_user, 'sess_user_credential' => hash('sha256', 'hash:old')),
        'request' => array('save_component_user' => 1, 'id' => $target, 'username' => $target === '42' ? 'alice' : 'bob', 'realm' => 0, 'password' => 'N3w-password!', 'password_confirm' => 'N3w-password!', 'enabled' => 'on'),
        'users' => array(
            array('id' => 42, 'username' => 'alice', 'realm' => 0, 'password' => 'hash:old', 'password_history' => ''),
            array('id' => 43, 'username' => 'bob', 'realm' => 0, 'password' => 'hash:other', 'password_history' => ''),
        ),
        'auth_functions' => array('auth_session_credential_key', 'auth_session_bind_credentials', 'auth_session_credentials_valid', 'cacti_auth_revoke_user_credentials', 'secpass_check_pass', 'secpass_check_history'),
    ));
}

test('an administrator who changes their own password keeps the session they used', function () {
    $result = password_change_admin_save('42', '42');

    expect($result['saved']['password'])->toBe('hash:N3w-password!')
        ->and($result['session']['sess_user_credential'])->toBe(hash('sha256', 'hash:N3w-password!'));
});

test('an administrator who changes another password keeps their own binding', function () {
    $result = password_change_admin_save('42', '43');

    expect($result['saved']['password'])->toBe('hash:N3w-password!')
        ->and($result['session']['sess_user_credential'])->toBe(hash('sha256', 'hash:old'));
});
