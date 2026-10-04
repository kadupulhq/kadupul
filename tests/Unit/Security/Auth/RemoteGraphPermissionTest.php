<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * On a remote collector with local storage, graph_image.php hands rendering
 * to the main poller's remote_agent.php graph_json action. The collector only
 * forwarded the session user and left the permission decision to the main
 * poller. It now checks is_graph_allowed() itself first, as lts/1.2 does, so
 * a refused graph never reaches the main poller.
 *
 * graph_image.php runs in a child process with include/auth.php and
 * lib/rrd.php replaced by empty files. remote_agent.php's own checks are
 * covered by RemoteAgentNativeScopeTest.
 */

namespace RemoteGraphPermissionTest;

/**
 * Run graph_image.php as a remote collector or the main poller.
 *
 * @return array{output: string, allowed_checks: list<array{0: int, 1: int}>, remote_urls: list<string>, local_users: list<int>}
 */
function graph_image_run(int $poller_id, bool $allowed): array
{
    $root = dirname(__DIR__, 4);
    $dir  = sys_get_temp_dir() . '/graph-image-' . bin2hex(random_bytes(8));

    mkdir($dir . '/include', 0700, true);
    mkdir($dir . '/lib', 0700);
    file_put_contents($dir . '/include/auth.php', '<?php');
    file_put_contents($dir . '/lib/rrd.php', '<?php');

    $program = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/html_utility.php';
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function die_html_input_error(...$args) { http_response_code(400); exit; }
function read_config_option($name, $force = false) { return $name === 'stats_poller' ? 'done' : ''; }
function api_plugin_hook_function($name, $data = '') { return $data; }
function db_fetch_cell_prepared(...$args) { return '1'; }
function cacti_session_close() {}
function cacti_validate_theme($theme) { return $theme; }
function __($text) { return $text; }
function is_graph_allowed($local_graph_id, $user) {
    $GLOBALS['trace']['allowed_checks'][] = array((int) $local_graph_id, (int) $user);
    return $GLOBALS['allowed'];
}
function call_remote_data_collector($poller_id, $url) {
    $GLOBALS['trace']['remote_urls'][] = $url;
    return "image = remote\nREMOTE_IMAGE";
}
function rrdtool_function_graph($local_graph_id, $rra_id, $graph_data_array, $pipe = '', &$meta = array(), $user = 0) {
    $GLOBALS['trace']['local_users'][] = (int) $user;
    return 'LOCAL_IMAGE';
}
function rrdtool_create_error_image(...$args) { return 'ERROR_IMAGE'; }
$config = array('poller_id' => (int) $argv[2], 'url_path' => '/');
$allowed = $argv[3] === '1';
$trace = array('allowed_checks' => array(), 'remote_urls' => array(), 'local_users' => array());
$_SESSION = array('sess_user_id' => 42);
$_REQUEST = array('local_graph_id' => '7', 'image_format' => 'png');
register_shutdown_function(function () {
    $output = '';
    while (ob_get_level()) {
        $output = ob_get_clean() . $output;
    }
    echo json_encode($GLOBALS['trace'] + array('output' => $output));
});
/* graph_image.php closes its own buffer before printing the image */
ob_start();
require $argv[1] . '/graph_image.php';
PHP;

    try {
        $process = proc_open(
            array(PHP_BINARY, '-r', $program, $root, (string) $poller_id, $allowed ? '1' : '0'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start graph_image.php probe');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || $stderr !== '') {
            throw new \RuntimeException($stderr . $stdout);
        }

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        unlink($dir . '/include/auth.php');
        unlink($dir . '/lib/rrd.php');
        rmdir($dir . '/include');
        rmdir($dir . '/lib');
        rmdir($dir);
    }
}

test('a remote collector refuses a graph the user may not view without asking the main poller', function () {
    $run = graph_image_run(2, false);

    expect($run['allowed_checks'])->toBe(array(array(7, 42)))
        ->and($run['remote_urls'])->toBe(array())
        ->and($run['output'])->toBe('GRAPH ACCESS DENIED');
});

test('a remote collector forwards the session user with an allowed graph', function () {
    $run = graph_image_run(2, true);

    expect($run['allowed_checks'])->toBe(array(array(7, 42)))
        ->and($run['remote_urls'])->toHaveCount(1)
        ->and($run['remote_urls'][0])->toContain('action=graph_json')
        ->and($run['remote_urls'][0])->toContain('&effective_user=42')
        ->and($run['output'])->toBe('REMOTE_IMAGE');
});

test('the main poller still renders locally as the session user', function () {
    $run = graph_image_run(1, true);

    expect($run['allowed_checks'])->toBe(array())
        ->and($run['remote_urls'])->toBe(array())
        ->and($run['local_users'])->toBe(array(42))
        ->and($run['output'])->toBe('LOCAL_IMAGE');
});
