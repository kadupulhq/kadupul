<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('RRD check quotes paths on its pipe and reads a refused path as no output', function () {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrdcheck-pipe-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('base_path' => $root, 'cacti_server_os' => 'unix');
$logged = array();
require $root . '/include/global_constants.php';
function cacti_log($message, ...$args) { $GLOBALS['logged'][] = $message; }
function read_config_option($key) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
require $root . '/src/Graphing/Infrastructure/Rrd/UnrepresentableArgument.php';
require $root . '/src/Graphing/Infrastructure/Rrd/PipeEncoder.php';
require $root . '/lib/rrd.php';
require $root . '/lib/rrdcheck.php';
// A deprecation from explode() on a null result would fail the run here.
set_error_handler(function ($level, $message) { throw new ErrorException($message, 0, $level); });
$sent = array();
foreach (array("rra/a\nb.rrd", "rra/a\rb.rrd", "rra/a\0b.rrd", "rra/it's a.rrd") as $path) {
    // The reply is already waiting, so a written command reads back OK.
    $pipes = array(fopen('php://temp', 'r+'), fopen('php://temp', 'r+'));
    fwrite($pipes[1], "OK u:0.00 s:0.00 r:0.00\n");
    rewind($pipes[1]);
    $output = rrdcheck_rrdtool_execute(array('info', $path), $pipes);
    rewind($pipes[0]);
    $sent[] = array('output' => $output, 'lines' => count(explode("\n", $output)), 'written' => stream_get_contents($pipes[0]));
}
echo json_encode(array('sent' => $sent, 'logged' => $logged));
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }

    expect($status)->toBe(0, $stderr);
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    foreach (array_slice($result['sent'], 0, 3) as $refused) {
        expect($refused)->toBe(array('output' => '', 'lines' => 1, 'written' => ''));
    }
    expect($result['sent'][3]['written'])->toBe("info 'rra/it'\"'\"'s a.rrd'\r\n")
        ->and($result['sent'][3]['output'])->toStartWith('OK');
    expect($result['logged'])->toHaveCount(3);
});
