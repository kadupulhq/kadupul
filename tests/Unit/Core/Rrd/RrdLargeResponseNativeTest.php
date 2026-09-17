<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('native acknowledged pipes scan large responses and split terminators without losing output', function ($mode) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-large-response-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $payload = str_repeat($mode === 'lines' ? "payload\n" : 'payload!', 1024 * 1024);
    $ending = $mode === 'error' ? "ERROR: unknown DS name 'missing'\r\n" : "OK u:0 s:0 r:0\r\n";
    $wrapper = $dir . '/rrd-server';
    file_put_contents($dir . '/response', $payload . "\n");
    $server = <<<'SERVER'
while (($command = fgets(STDIN)) !== false) {
    if (trim($command) === 'quit') { break; }
    if (trim($command) === 'next') { echo "OK u:0 s:0 r:0\n"; fflush(STDOUT); continue; }
    $input = fopen(__DIR__.'/response', 'rb');
    stream_copy_to_stream($input, STDOUT);
    fclose($input);
    foreach (str_split($ending) as $character) { fwrite(STDOUT, $character); fflush(STDOUT); usleep(1000); }
}
SERVER;
    file_put_contents($wrapper, '#!' . PHP_BINARY . "\n<?php $" . 'ending=' . var_export($ending, true) . ';' . $server);
    chmod($wrapper, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $fixture = is_file($root . '/tests/Fixtures/rrd-process-coverage.php') ? '/tests/Fixtures/rrd-process-coverage.php' : '/tests/fixtures/rrd-process-coverage.php';
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . $fixture, true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('cacti_server_os'=>'unix', 'rra_path'=>__DIR__, 'is_web'=>false, 'rrd_command_timeout'=>10);
require $root.'/include/global_constants.php';
define('CACTI_LOCALE', 'en-US');
function read_config_option($key) { return $key === 'path_rrdtool' ? __DIR__.'/rrd-server' : ''; }
function cacti_log(...$args) {}
function cacti_session_close() {}
require $root.'/lib/rrd.php';
$pipe = rrd_init(false, false, true);
if (!is_resource($pipe)) { exit(2); }
list($success, $output) = rrd_acknowledged_command($pipe, 'dump fixture.rrd');
list($next, $nextOutput) = rrd_acknowledged_command($pipe, 'next');
rrd_close($pipe);
echo json_encode(array($success, strlen($output), hash('sha256', $output), $next, $nextOutput));
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'memory_limit=256M', '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error . $output);
        expect($error)->toBe('');
        $expected = $payload . "\n" . $ending;
        expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array($mode !== 'error', strlen($expected), hash('sha256', $expected), true, "OK u:0 s:0 r:0\n"));
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array('lines', 'single-line', 'error'));
