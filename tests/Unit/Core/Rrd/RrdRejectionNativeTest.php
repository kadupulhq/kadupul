<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('a stale timestamp is consumed while a schema refusal is reported for dead-lettering', function () {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-rejection-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    touch($dir . '/stale.rrd');
    touch($dir . '/schema.rrd');
    // The writer answers each update as RRDtool would for that file.
    $server = <<<'SERVER'
while (($command = fgets(STDIN)) !== false) {
    if (strpos($command, 'stale.rrd') !== false) {
        echo "ERROR: stale.rrd: illegal attempt to update using time 1699999800 when last update time is 1700000000 (minimum one second step)\n";
    } else {
        echo "ERROR: unknown DS name 'value'\n";
    }
    fflush(STDOUT);
}
SERVER;
    file_put_contents($dir . '/rrd-server', '#!' . PHP_BINARY . "\n<?php " . $server);
    chmod($dir . '/rrd-server', 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('cacti_server_os'=>'unix', 'rra_path'=>__DIR__, 'is_web'=>false, 'rrd_command_timeout'=>10);
require $root.'/include/global_constants.php';
define('CACTI_LOCALE', 'en-US');
$logs = array();
function read_config_option($key) { return $key === 'path_rrdtool' ? __DIR__.'/rrd-server' : ''; }
function cacti_log($message, ...$args) { $GLOBALS['logs'][] = $message; }
function cacti_session_close() {}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function get_rrdtool_version() { return '1.7'; }
function cacti_version_compare($a, $b, $operator) { return version_compare($a, $b, $operator); }
require $root.'/lib/rrd.php';
$pipe = rrd_init(false, false, true);
$updates = array();
foreach (array('stale', 'schema') as $name) {
    $updates[__DIR__ . "/$name.rrd"] = array('local_data_id' => 1, 'data_template_id' => 0, 'times' => array(1699999800 => array('value' => '1')));
}
$result = rrdtool_function_update($updates, $pipe, $completed, $rejected);
rrd_close($pipe);
$base = fn($paths) => array_combine(array_map('basename', array_keys($paths)), array_values($paths));
echo json_encode(array($result, $base($completed), $base($rejected), $logs));
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        list($result, $completed, $rejected, $logs) = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        // The stale sample is acknowledged as consumed; the refused one is retained with its reason.
        expect($result)->toBeFalse()
            ->and($completed)->toBe(array('stale.rrd' => array(1699999800 => false)))
            ->and($rejected)->toBe(array('schema.rrd' => "unknown DS name 'value'"))
            ->and($logs[0])->toStartWith('ERROR: RRDtool rejected sample (not written): ')
            ->and($logs[1])->toStartWith('ERROR: RRD pending sample retained for retry: ');
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
