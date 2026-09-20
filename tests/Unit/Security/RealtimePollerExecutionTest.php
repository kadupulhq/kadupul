<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('realtime controller validates identifiers and uses shell-free poller arguments', function ($id, $step, $hash, $status, $expected, $action = 'init', $stepSource = 'setting') {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/realtime-exec-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    mkdir($dir . '/lib', 0700);
    file_put_contents($dir . '/include/auth.php', '<?php');
    file_put_contents($dir . '/lib/rrd.php', '<?php');
    $program = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/html_utility.php';
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function die_html_input_error(...$args) { http_response_code(400); exit; }
function read_user_setting($name, $default = null) {
    return $name === 'realtime_interval' ? $GLOBALS['step'] : $default;
}
function read_config_option($name) {
    return $name === 'path_php_binary' ? '/php path/php' : '/isolated cache';
}
function db_fetch_row_prepared(...$args) { return array(); }
function db_fetch_cell_prepared(...$args) { return '1'; }
function cacti_log(...$args) {}
function cacti_exec($binary, $args, &$output, $timeout) {
    if ($binary !== '/php path/php' || $timeout !== 300 || $args !== array(
        '-q', '/application path/poller_realtime.php', '--graph=7', '--interval=' . (int) $GLOBALS['step'],
        '--poller_id=' . $_SESSION['sess_realtime_hash'])) {
        throw new RuntimeException('Unexpected poller command');
    }
    $GLOBALS['called'] = true;
    $output = array('poller diagnostic must not leak into response');
    return $GLOBALS['status'];
}
function rrdtool_function_graph(...$args) { $GLOBALS['rendered'] = true; exit; }
$config = array('base_path' => '/application path');
$step = json_decode($argv[3], true);
$status = (int) $argv[5];
$_SESSION = array('sess_user_id' => 42, 'sess_realtime_hash' => json_decode($argv[4], true));
$_REQUEST = array('action' => $argv[6], 'local_graph_id' => json_decode($argv[2], true));
if ($argv[7] === 'request') $_REQUEST['ds_step'] = $step;
if ($argv[7] === 'session') $_SESSION['sess_realtime_ds_step'] = $step;
$called = false;
$rendered = false;
register_shutdown_function(function () {
    while (ob_get_level()) ob_end_clean();
    echo json_encode(array('status' => http_response_code() ?: 200, 'called' => $GLOBALS['called'], 'rendered' => $GLOBALS['rendered']));
});
require $argv[1] . '/graph_realtime.php';
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("REALTIME_EXEC_TEST_COVERAGE", true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root,
                json_encode($id), json_encode($step), json_encode($hash), (string) $status, $action, $stepSource),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start realtime controller probe');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }
        expect(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR))->toBe(array(
            'status' => $expected, 'called' => $expected !== 400, 'rendered' => $expected === 200));
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }
    } finally {
        unlink($dir . '/include/auth.php');
        unlink($dir . '/lib/rrd.php');
        rmdir($dir . '/include');
        rmdir($dir . '/lib');
        foreach (glob($dir . '/*.coverage') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array(
    array('7', '10', 'abc_123-DEF', 0, 200),
    array('7', '1', str_repeat('a', 64), 0, 200),
    array('7', '120', 'abc123', 0, 200),
    array('7', '10', 'abc123', 0, 200, 'init', 'request'),
    array('7', '10', 'abc123', 0, 200, 'init', 'session'),
    array('7', '10; echo injected', 'abc123', 0, 400, 'init', 'request'),
    array('7', '10; echo injected', 'abc123', 0, 400, 'init', 'session'),
    array('7', '10', 'abc123', 0, 200, 'timespan'),
    array('7', '10', 'abc123', 0, 200, 'interval'),
    array('7', '10', 'abc123', 0, 200, 'countdown'),
    array('7', '10', 'abc123', 7, 503),
    array('7', '10', 'abc123', 255, 503),
    array('7', '10', 'abc123', 1, 503),
    array('', '10', 'abc123', 0, 400),
    array(null, '10', 'abc123', 0, 400),
    array('0', '10', 'abc123', 0, 400),
    array('-1', '10', 'abc123', 0, 400),
    array(array('7'), '10', 'abc123', 0, 400),
    array('7; echo injected', '10', 'abc123', 0, 400),
    array('7', '10; echo injected', 'abc123', 0, 400),
    array('7', '$(echo injected)', 'abc123', 0, 400),
    array('7', array('10'), 'abc123', 0, 400),
    array('7', '0', 'abc123', 0, 400),
    array('7', '-1', 'abc123', 0, 400),
    array('7', '10', '../outside', 0, 400),
    array('7', '10', 'abc; echo injected', 0, 400),
    array('7', '10', "abc\n", 0, 400),
    array('7', '10', array('abc'), 0, 400),
    array('7', '10', '', 0, 400),
    array('7', '10', str_repeat('a', 65), 0, 400),
));
