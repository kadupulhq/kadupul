<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('a hung RRD writer is abandoned at the collector deadline and releases its lease', function () {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/poller-writer-deadline-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    // The writer accepts the command and never answers.
    file_put_contents($dir . '/rrd-server', '#!' . PHP_BINARY . "\n<?php fgets(STDIN); sleep(60);");
    chmod($dir . '/rrd-server', 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';';
    $bootstrap .= <<<'SOURCE'
// The maintenance ceiling stays long; only the poller's deadline may shorten it.
$config = array('cacti_server_os'=>'unix', 'rra_path'=>__DIR__, 'is_web'=>false, 'rrd_command_timeout'=>3600);
require $root.'/include/global_constants.php';
require $root.'/tests/Helpers/PhpSource.php';
define('CACTI_LOCALE', 'en-US');
$logs = array();
function read_config_option($key) { return $key === 'path_rrdtool' ? __DIR__.'/rrd-server' : ''; }
function cacti_log($message, ...$args) { $GLOBALS['logs'][] = $message; }
function cacti_session_close() {}
function db_fetch_cell_prepared($sql) { return 1; }
// The drain boundary issues one real acknowledged update through the writer.
function process_poller_output(&$pipe, $remainder, $after, &$acknowledged) {
    $acknowledged = 0;
    return rrdtool_execute('update ' . __DIR__ . '/sample.rrd 1700000000:1', false, RRDTOOL_OUTPUT_BOOLEAN, $pipe) === true ? 1 : false;
}
require $root.'/lib/rrd.php';
eval(test_php_function_source(file_get_contents($root.'/lib/poller.php'), 'process_poller_output_batch'));
$deferred = false;
$proxy = false;
$start = microtime(true);
$result = process_poller_output_batch($deferred, $proxy, true, microtime(true) + 2);
$elapsed = microtime(true) - $start;
$exclusive = rrd_maintenance_acquire(true, false, 0);
echo json_encode(array($result, $deferred, $elapsed, is_resource($exclusive), rrd_command_deadline(), $logs));
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        stream_set_blocking($pipes[1], false);
        $output = '';
        $limit = microtime(true) + 20;
        // Bound the test even if the writer deadline is ignored.
        while (proc_get_status($process)['running'] && microtime(true) < $limit) {
            $output .= stream_get_contents($pipes[1]);
            usleep(50000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        $output .= stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        expect($error)->toBe('');
        list($result, $deferred, $elapsed, $released, $deadline, $logs) = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect($result)->toBe(0)
            ->and($deferred)->toBeTrue()
            ->and($elapsed)->toBeLessThan(10)
            ->and($released)->toBeTrue()
            ->and($deadline)->toBeNull()
            ->and($logs)->toContain('ERROR: RRDtool response was unavailable or timed out; samples retained for retry.');
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
});
