<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * A password an administrator sets in User Management must meet the same
 * complexity and history rules as one the user picks, and the form's live
 * check must report the real result. The save used to hash any password, and
 * the check answered 'ok' for every password it was sent.
 */

require_once dirname(__DIR__, 3) . '/Helpers/UserAdminSaveProbe.php';
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function admin_password_save(array $request, array $config = array(), string $history = ''): array
{
    return user_admin_save_probe_run(array(
        'session' => array('sess_user_id' => 1),
        'request' => $request + array('save_component_user' => 1, 'id' => 42, 'username' => 'alice', 'realm' => 0, 'enabled' => 'on'),
        'users' => array(
            array('id' => 1, 'username' => 'admin', 'realm' => 0, 'password' => 'hash:admin', 'password_history' => '', 'enabled' => 'on'),
            array('id' => 42, 'username' => 'alice', 'realm' => 0, 'password' => 'hash:Old-pass1', 'password_history' => $history, 'enabled' => 'on'),
        ),
        'config' => $config + array('secpass_minlen' => 8, 'secpass_reqnum' => 'on', 'secpass_reqmixcase' => 'on'),
        'auth_functions' => array('auth_session_credential_key', 'auth_session_bind_credentials', 'auth_session_credentials_valid', 'cacti_auth_revoke_user_credentials', 'secpass_check_pass', 'secpass_check_history'),
    ));
}

function admin_password_checkpass(string $password): string
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/user_admin.php');
    $start = strpos($source, "case 'checkpass':");
    $body = substr($source, $start + strlen("case 'checkpass':"), strpos($source, 'break;', $start) - $start - strlen("case 'checkpass':"));

    $program = <<<'PHP'
$password = $argv[1];
function get_nfilter_request_var($name, $default = '') { return $name === 'password' ? $GLOBALS['password'] : $default; }
function read_config_option($name, $force = false) { return array('secpass_minlen' => 8, 'secpass_reqnum' => 'on', 'secpass_reqmixcase' => 'on')[$name] ?? ''; }
function __($text, ...$args) { return vsprintf($text, $args); }
PHP;

    $program .= "\n" . test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php'), 'secpass_check_pass') . "\n" . $body;

    $pipes = array();
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $password),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stderr)->toBe('');

    return $stdout;
}

test('a password that breaks the rules is refused and not saved', function (string $password) {
    $result = admin_password_save(array('password' => $password, 'password_confirm' => $password));

    expect($result['saved'])->toBeNull()
        ->and($result['messages'])->toContain('password_policy')
        ->and($result['session']['sess_error_fields'] ?? array())->toBe(array('password' => 'password'));
})->with(array('short' => 'Ab1', 'no number' => 'Abcdefghij', 'one case' => 'abcdefgh1'));

test('a password that meets the rules is saved', function () {
    $result = admin_password_save(array('password' => 'Str0ngpass', 'password_confirm' => 'Str0ngpass'));

    expect($result['saved']['password'] ?? null)->toBe('hash:Str0ngpass')
        ->and($result['messages'])->not->toContain('password_policy');
});

test('a password from the history is refused', function () {
    $result = admin_password_save(
        array('password' => 'Earlier1pass', 'password_confirm' => 'Earlier1pass'),
        array('secpass_history' => 3),
        'hash:Earlier1pass'
    );

    expect($result['saved'])->toBeNull()
        ->and($result['messages'])->toContain('password_history');
});

test('a saved password pushes the old one into the history', function () {
    $result = admin_password_save(
        array('password' => 'Str0ngpass', 'password_confirm' => 'Str0ngpass'),
        array('secpass_history' => 2),
        'hash:Oldest1pass|hash:Older1pass'
    );

    expect($result['saved']['password_history'] ?? null)->toBe('hash:Older1pass|hash:Old-pass1');
});

test('mismatched passwords are not saved', function () {
    $result = admin_password_save(array('password' => 'Str0ngpass', 'password_confirm' => 'Str0ngpas'));

    expect($result['saved'])->toBeNull()
        ->and($result['messages'])->toContain(4);
});

test('leaving the password blank keeps the stored one', function () {
    $result = admin_password_save(array('password' => '', 'password_confirm' => ''));

    expect($result['saved']['password'] ?? null)->toBe('hash:Old-pass1')
        ->and($result['messages'])->not->toContain('password_policy');
});

test('an account outside the local realm is not held to the local rules', function () {
    $result = admin_password_save(array('realm' => 3, 'password' => 'short', 'password_confirm' => 'short'));

    expect($result['saved'])->not->toBeNull()
        ->and($result['messages'])->not->toContain('password_policy');
});

test('the live check reports the rule a password breaks', function () {
    expect(admin_password_checkpass('Str0ngpass'))->toBe('ok')
        ->and(admin_password_checkpass('abc'))->toBe('Password must be at least 8 characters!')
        ->and(admin_password_checkpass('abcdefghij'))->toBe('Your password must contain at least 1 numerical character!');
});
