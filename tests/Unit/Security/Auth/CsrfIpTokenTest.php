<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * An ip: token is an HMAC of the client address and is tied to no session,
 * so anyone behind the same address could replay it in a cookie-less POST.
 * Every Kadupul page carries a sid: token, so ip: tokens are not accepted.
 */

function csrf_ip_token_run(string $kind): array
{
    $root = dirname(__DIR__, 4);
    $program = <<<'PHP'
$config = array('include_path' => $argv[1] . '/include', 'base_path' => $argv[1], 'url_path' => '/kadupul/', 'is_web' => true);
function read_config_option($name, $force = false) { return $name === 'csrf_secret' ? str_repeat('7f', 32) : ''; }
function set_config_option($name, $value, $remote = false) {}
function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {}
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
$_COOKIE = array();
session_id('kadupul-ip-token-test');
require $argv[1] . '/include/csrf.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
$token = $argv[2] === 'ip' ? 'ip:' . csrf_hash('10.0.0.5') : 'sid:' . csrf_hash(session_id());
echo json_encode(array('valid' => csrf_check_tokens($token)));
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'session.save_handler=files', '-d', 'session.save_path=' . sys_get_temp_dir(), '-r', $program, $root, $kind),
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

test('a cookie-less POST cannot pass with a token bound only to the client address', function () {
    expect(csrf_ip_token_run('ip')['valid'])->toBeFalse();
});

test('a token bound to the session still passes', function () {
    expect(csrf_ip_token_run('sid')['valid'])->toBeTrue();
});
