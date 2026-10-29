<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('repair reports success only for acknowledged empty stderr', function ($result, $host, $success, $log, $variant = 'inside') {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/repair-result-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/source.rrd', 'fixture');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';$variant=' . var_export($variant, true) . ';$result=' . var_export($result, true) . ';$host=' . var_export($host, true) . ';$logs=array();require ' . var_export($root . '/lib/dsdebug.php', true) . ';';
    $bootstrap .= <<<'PROBE'
$config = array('rra_path' => __DIR__);
require $GLOBALS['root'] . '/tests/Helpers/PhpSource.php';
// Load the checks when lib/rrd.php has them, so a run against the unchecked code reaches the assertions.
$rrd_source = file_get_contents($GLOBALS['root'] . '/lib/rrd.php');
if (str_contains($rrd_source, 'function rrd_check_path(')) {
    foreach (array('rrd_check_path', 'rrd_path_is_contained', 'rrd_resolve_missing_path') as $function) {
        eval(test_php_function_source($rrd_source, $function));
    }
} else {
    function rrd_check_path($path, $rra_base = null, $logopt = null) { return true; }
}
function rrdtool_uses_proxy() { return false; }
function clean_up_path($path) { return $path; }
function db_fetch_row_prepared(...$args) { return array('info' => array('rrd_match_array' => array('tune' => array(__DIR__ . ($GLOBALS['variant'] === 'other-file' ? '/other.rrd' : '/source.rrd') . ' --minimum value:0')))); }
function db_fetch_cell_prepared(...$args) { return $GLOBALS['host']; }
function is_device_allowed($host_id) { return $host_id === 12; }
function cacti_sizeof($value) { return count($value); }
function cacti_unserialize($value) { return $value; }
function get_data_source_path(...$args) { return $GLOBALS['variant'] === 'outside' ? sys_get_temp_dir() . '/elsewhere/source.rrd' : __DIR__ . '/source.rrd'; }
function read_config_option($key) { return 'rrdtool'; }
function rrdtool_execute(...$args) { $GLOBALS['logs'][] = 'executed'; return $GLOBALS['result']; }
function cacti_log($message, ...$args) { $GLOBALS['logs'][] = $message; }
define('RRDTOOL_OUTPUT_RETURN_STDERR',5);
echo json_encode(array(dsdebug_run_repair(8),$logs));
PROBE;
    try {
        file_put_contents($directory . '/probe.php', $bootstrap);
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('');
        $observed = json_decode($output, true);
        expect($observed[0])->toBe($success)
            ->and(implode("\n", $observed[1]))->toContain($log);
        if ($host !== 12) {
            expect($observed[1])->toHaveCount(1);
        }
        if ($variant !== 'inside') {
            expect(implode("\n", $observed[1]))->not->toContain('executed');
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(array(
    array(false, 12, false, 'command failed'),
    array('', 12, true, 'command succeeded'),
    array('ERROR: rejected', 12, false, 'command failed'),
    array('', 13, false, 'Repair denied'),
    array('', 0, false, 'Repair denied'),
    array('', false, false, 'Repair denied'),
    // The stored tune command must name the checked file, and that file must be inside the RRA directory.
    array('', 12, false, 'does not name the Data Source RRDfile', 'other-file'),
    array('', 12, false, 'outside the RRA directory', 'outside'),
));
