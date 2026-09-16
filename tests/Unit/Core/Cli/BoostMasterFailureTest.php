<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('Boost master retains archives and retries when any child result fails', function ($failed, $updates, $expectedExit) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller_boost.php');
    $start = strpos($source, 'if ($child == false) {');
    $end = strpos($source, "} else {\n\tcacti_log('INFO: Boost register child process", $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    // Run the actual master control flow; only external effects are replaced.
    $master = substr($source, $start, $end - $start) . '}';
    $bootstrap = <<<'FIXTURE'
<?php
namespace BoostMasterProbe;
$child = false; $forcerun = true; $rrd_updates = -1; $run_failed = false;
$config = array('poller_id' => 1); $options = array(); $effects = array();
function sleep($seconds) {}
function read_config_option($key) { return $key === 'boost_last_run_time' ? 1234 : 1; }
function set_config_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function cacti_sizeof($rows) { return count($rows); }
function cacti_log(...$args) {}
function boost_debug(...$args) {}
function boost_time_to_run(...$args) { return true; }
function register_process_start(...$args) { return true; }
function unregister_process(...$args) { $GLOBALS['effects'][] = 'unregister'; }
function boost_kill_running_processes() {}
function boost_prepare_process_table() { return true; }
function boost_prune_memstats() {}
function boost_launch_children() { return array(1); }
function boost_wait_children(...$args) { return true; }
function boost_processes_running() { return 0; }
function boost_log_statistics(...$args) { $GLOBALS['effects'][] = 'statistics'; }
function boost_get_arch_table_names() { return array('poller_output_boost_arch_test'); }
function boost_archive_is_empty($name) { return true; }
function dsstats_boost_bottom() { $GLOBALS['effects'][] = 'bottom'; }
function rrdcheck_boost_bottom() {}
function api_plugin_hook(...$args) {}
function boost_purge_cached_png_files(...$args) {}
function db_fetch_row(...$args) { return array('pending' => 1); }
function db_fetch_assoc(...$args) { return array(array('name' => 'poller_output_boost_arch_test')); }
function db_execute($sql) { if (strpos($sql, 'DROP TABLE') !== false) { $GLOBALS['effects'][] = 'drop'; } return true; }
function db_fetch_cell($sql) { if ($sql === 'SELECT COUNT(*) FROM poller_output_boost_processes') {return 1;} return strpos($sql, 'status < 0') !== false ? $GLOBALS['failed'] : $GLOBALS['updates']; }
register_shutdown_function(function () { echo json_encode(array($GLOBALS['options'], $GLOBALS['effects'])); });
FIXTURE;
    $file = tempnam(sys_get_temp_dir(), 'boost-master-');
    try {
        file_put_contents($file, $bootstrap . "\n" . '$failed = ' . var_export($failed, true) . '; $updates = ' . var_export($updates, true) . ';' . $master);
        $process = proc_open(array(PHP_BINARY, $file), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        expect($error)->toBe('')->and($status)->toBe($expectedExit);
        list($options, $effects) = json_decode($output, true);
        expect($effects)->toContain('unregister');
        if ($expectedExit === 1) {
            expect($options['boost_last_run_time'])->toBe(1234)
                ->and($options['boost_poller_status'])->toStartWith('failed')
                ->and(isset($options['boost_next_run_time']))->toBeFalse()
                ->and($effects)->not->toContain('drop', 'statistics', 'bottom');
        } else {
            expect($options['boost_last_run_time'])->toBeGreaterThan(1234)
                ->and($options['boost_poller_status'])->toStartWith('complete')
                ->and(isset($options['boost_next_run_time']))->toBeTrue()
                ->and($effects)->toContain('drop', 'statistics', 'bottom');
        }
    } finally {
        unlink($file);
    }
})->with(array(array(1, 9, 1), array(2, -2, 1), array(false, 10, 1), array(null, 10, 1), array(0, null, 1), array('0', '10', 0), array(0, 10, 0)));

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace BoostWorkerSupervision; function unregister_process(...$args) {$GLOBALS["boost_reaped"][]=$args;}' . test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/poller_boost.php'), 'boost_wait_children'));
test('Boost supervision reaps native children that exit before registration or exceed the deadline', function ($mode) {
    $GLOBALS['boost_reaped'] = array();
    if ($mode === 'launch-failure') {
        $children = array(array('process' => false, 'pid' => 0, 'child' => 1));
    } else {
        $code = $mode === 'timeout' ? 'sleep(30);' : 'exit(' . ($mode === 'success' ? 0 : 7) . ');';
        $process = proc_open(array(PHP_BINARY, '-r', $code), array(0 => STDIN, 1 => STDOUT, 2 => STDERR), $pipes);
        $status = proc_get_status($process);
        $children = array(array('process' => $process, 'pid' => $status['pid'], 'child' => 1));
    }
    $start = microtime(true);
    expect(\BoostWorkerSupervision\boost_wait_children($children, 1))->toBe($mode === 'success');
    expect(microtime(true) - $start)->toBeLessThan(5);
    expect($GLOBALS['boost_reaped'])->toHaveCount($mode === 'launch-failure' ? 0 : 1);
})->with(array('success', 'early-crash', 'timeout', 'launch-failure'));
