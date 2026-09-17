<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('repair reports success only for acknowledged empty stderr', function ($result, $success) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/repair-result-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/source.rrd', 'fixture');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$result=' . var_export($result, true) . ';$logs=array();require ' . var_export($root . '/lib/dsdebug.php', true) . ';';
    $bootstrap .= <<<'PROBE'
function db_fetch_row_prepared(...$args) { return array('info' => array('rrd_match_array' => array('tune' => array('fixture --minimum value:0')))); }
function cacti_sizeof($value) { return count($value); }
function cacti_unserialize($value) { return $value; }
function get_data_source_path(...$args) { return __DIR__.'/source.rrd'; }
function read_config_option($key) { return 'rrdtool'; }
function rrdtool_execute(...$args) { return $GLOBALS['result']; }
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
            ->and($observed[1][0])->toContain($success ? 'command succeeded' : 'command failed');
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
})->with(array(array(false, false), array('', true), array('ERROR: rejected', false)));
