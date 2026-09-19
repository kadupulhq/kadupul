<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production Boost retains samples and restores caller state after read failure', function ($failure, $owned) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-read-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/rrd.php', '<?php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $script = '<?php ';
    if ($coverage !== null) {
        $script .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $script .= '$failure=' . var_export($failure, true) . ';$owned=' . var_export($owned, true) . ';require ' . var_export($root . '/lib/boost.php', true) . ';';
    $script .= <<<'PROBE'
$config = array('library_path' => __DIR__);
$writes = array();
$closed = 0;
$opened = 0;
function cacti_system_zone_set() {}
function rrd_init(...$args) { $GLOBALS['opened']++; return fopen('php://temp', 'r+'); }
function rrd_close($pipe) { $GLOBALS['closed']++; fclose($pipe); }
function read_config_option($key) { return 100; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return cacti_sizeof($value); }
function array_rekey($value, ...$args) { return array(); }
function db_fetch_assoc($sql) {
    return $GLOBALS['failure'] === 'archives' ? false : array(array('name' => 'poller_output_boost_arch_fixture'));
}
function db_fetch_assoc_prepared(...$args) { return false; }
function db_fetch_cell(...$args) { return 1700000000; }
function db_execute($sql) { $GLOBALS['writes'][] = $sql; return true; }
function db_execute_prepared($sql, ...$args) { return db_execute($sql); }
define('BOOST_TIMER_START', 0);
define('BOOST_TIMER_END', 1);
$handler = function () { throw new RuntimeException('Unexpected warning'); };
set_error_handler($handler);
error_reporting(E_ALL);
$pipe = $owned ? '' : fopen('php://temp', 'r+');
$result = boost_process_poller_output(42, $pipe);
$restored = set_error_handler($handler) === $handler;
restore_error_handler();
echo json_encode(array($result, $opened, $closed, $owned || is_resource($pipe), $restored, error_reporting() === E_ALL, $writes));
if (!$owned) { fclose($pipe); }
PROBE;
    try {
        file_put_contents($directory . '/probe.php', $script);
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect(array_slice($result, 0, 6))->toBe(array(-1, $owned ? 1 : 0, $owned ? 1 : 0, true, true, true));
        foreach ($result[6] as $sql) {
            expect(preg_match('/\b(?:DELETE|TRUNCATE|UPDATE|REPLACE)\b/i', $sql))->toBe(0);
        }
        expect(count($result[6]))->toBe($failure === 'archives' ? 0 : 3);
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
})->with(array(array('archives', false), array('archives', true), array('samples', false), array('samples', true)));
