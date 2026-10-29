<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Upstream Cacti #7196: cmd.php read from the script server's pipes without
 * checking that proc_open() started it, and closed those pipes at the end of
 * the run. The production start helper runs in a child process; a namespace
 * stand-in makes proc_open() fail the way it does when the child cannot be
 * spawned.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function script_server_start_run(string $program): array
{
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $err . $out)->and($err)->toBe('');

    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

function script_server_start_source(string $name): string
{
    return test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), $name);
}

test('a script server that cannot be spawned is reported without pipes', function () {
    $program = 'namespace Probe; function proc_open($command, $descriptors, &$pipes) { $pipes = array("stale"); return false; }'
        . script_server_start_source('poller_script_server_open')
        . '$pipes = null; $process = poller_script_server_open("php script_server.php", $pipes);'
        . 'echo json_encode(array("process" => $process, "pipes" => $pipes));';

    expect(script_server_start_run($program))->toBe(array('process' => false, 'pipes' => array()));
});

test('a started script server answers on its pipes', function () {
    $program = script_server_start_source('poller_script_server_open')
        . '$command = escapeshellarg(PHP_BINARY) . " -r " . escapeshellarg("echo \"PHP Script Server has Started\\n\"; echo strtoupper(fgets(STDIN));");'
        . '$process = poller_script_server_open($command, $pipes);'
        . '$started = fgets($pipes[1]); fwrite($pipes[0], "quit\\n"); fclose($pipes[0]); $reply = fgets($pipes[1]);'
        . 'fclose($pipes[1]); fclose($pipes[2]);'
        . 'echo json_encode(array("resource" => is_resource($process), "started" => trim($started), "reply" => trim($reply), "status" => proc_close($process)));';

    expect(script_server_start_run($program))->toBe(array('resource' => true, 'started' => 'PHP Script Server has Started', 'reply' => 'QUIT', 'status' => 0));
});

test('script server items report no value when the server is not running', function () {
    $program = script_server_start_source('exec_poll_php')
        . '$config = array("cacti_server_os" => "unix");'
        . 'echo json_encode(array("output" => exec_poll_php("ss_fixture.php ss_fixture", true, array(), false)));';

    // The poller turns the empty answer into U instead of running the script through a shell.
    expect(script_server_start_run($program))->toBe(array('output' => ''));
});

test('both collectors start the script server through the guarded helper', function (string $file) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

    expect($source)->toContain('poller_script_server_open(')
        ->and($source)->not->toContain('proc_open(')
        ->and($source)->toContain('if ($cactiphp === false) {');
})->with(array('cmd.php', 'cmd_realtime.php'));

test('cmd.php closes the script server only when it is running', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cmd.php');

    expect($source)->toContain('if ($using_proc_function && $script_server_calls > 0 && is_resource($cactiphp)) {');
});
