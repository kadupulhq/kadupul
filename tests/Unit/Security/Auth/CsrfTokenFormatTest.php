<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

/*
 * csrf-magic adds the token time to the expiry without checking it is a
 * number, so a malformed token must be refused before csrf-magic reads it.
 */

function csrf_token_format_run(string $token): array
{
    $root = dirname(__DIR__, 4);
    $program = <<<'PHP'
$config = array('include_path' => $argv[1] . '/include', 'is_web' => false);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['__csrf_magic'] = $_REQUEST['__csrf_magic'] = $argv[2];
$_REQUEST['action'] = 'save';
register_shutdown_function(function () { echo http_response_code() ?: 200; $GLOBALS['nativeChildCoverageMarkers'][] = 'response-status-readback'; });
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/csrf.php';
cacti_require_post_request();
echo 'DISPATCHED:';
PHP;

    $process = proc_open(
        child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $program, $root, $token), $coverage_dir, child_coverage_registration(__FILE__, 'token-format', array($token), array('response-status-readback'), array('include/csrf.php'), array())),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    child_coverage_collect($coverage_dir);

    return array('stdout' => $stdout, 'stderr' => $stderr);
}

test('a token with a non-numeric time is refused without a PHP error', function (string $token) {
    $result = csrf_token_format_run($token);

    expect($result['stderr'])->toBe('')
        ->and($result['stdout'])->toBe('403');
})->with(array(
    'letters' => 'sid:abc,later',
    'implicit sid with letters' => 'abc,later',
    'implicit sid with empty time' => 'abc,',
    'empty time' => 'sid:abc,',
    'signed time' => 'sid:abc,-1',
    'second of two tokens' => 'sid:abc,1700000000;ip:def,soon',
    'comma in the type' => 's,id:abc,1700000000',
));

test('a well formed but wrong token is still only an invalid token', function (string $token) {
    $result = csrf_token_format_run($token);

    expect($result['stderr'])->toBe('')
        ->and($result['stdout'])->toBe('403');
})->with(array(
    'numeric time' => 'sid:abc,1700000000',
    'no time' => 'sid:abc',
    'no type' => 'abc',
));
