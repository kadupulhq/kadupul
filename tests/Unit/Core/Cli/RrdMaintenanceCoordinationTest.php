<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';

function rrd_cli_fixture_remove($path)
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                rrd_cli_fixture_remove($path . '/' . $name);
            }
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

/** Collect real subprocess coverage without changing the copied CLI source. */
function rrd_cli_coverage_arguments($test, $dir, $root, $scriptName)
{
    if ($test->getTestResultObject()->getCodeCoverage() === null) {
        return array();
    }
    $bootstrap = '<?php define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__);' .
        'define("RRD_TEST_CLI_COVERAGE_COPY", ' . var_export($dir . '/cli/' . $scriptName, true) . ');' .
        'define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export($root . '/cli/' . $scriptName, true) . ');' .
        'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    file_put_contents($dir . '/coverage.php', $bootstrap);
    return array('-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-d', 'auto_prepend_file=' . $dir . '/coverage.php');
}

function rrd_cli_merge_coverage($test, $dir)
{
    $parent = $test->getTestResultObject()->getCodeCoverage();
    if ($parent === null || !file_exists($dir . '/coverage.php')) {
        return;
    }
    $reports = glob($dir . '/*.coverage');
    expect($reports)->toHaveCount(1);
    // Only our child can write in this owned 0700 fixture directory.
    $child = unserialize(file_get_contents($reports[0]));
    expect($child)->toBeInstanceOf(SebastianBergmann\CodeCoverage\CodeCoverage::class);
    $parent->merge($child);
}

test('native maintenance CLI coordinates before touching an RRD', function ($scriptName, $cached, $busy = true) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required; CI provisions it.');
    }
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-cli-lock-' . bin2hex(random_bytes(8));
    foreach (array('', '/cli', '/include', '/lib', '/store') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    $savedConfig = $GLOBALS['config'] ?? null;
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => $dir . '/store');
    $rrd = $dir . '/store/' . basename($dir) . '.rrd';
    $lock = null;
    try {
        exec(escapeshellarg($binary) . ' create ' . escapeshellarg($rrd) . ' --start 1700000000 --step 60 DS:value:GAUGE:600:U:U RRA:AVERAGE:0.5:1:20', $output, $status);
        expect($status)->toBe(0);
        exec(escapeshellarg($binary) . ' update ' . escapeshellarg($rrd) . ' 1700000060:42', $output, $status);
        expect($status)->toBe(0);
        $before = file_get_contents($rrd);
        copy($root . '/cli/' . $scriptName, $dir . '/cli/' . $scriptName);
        symlink($root . '/lib/rrd_maintenance.php', $dir . '/lib/rrd_maintenance.php');
        symlink($root . '/lib/maintenance_cli.php', $dir . '/lib/maintenance_cli.php');
        file_put_contents($dir . '/lib/poller.php', '<?php');
        file_put_contents($dir . '/lib/rrd.php', '<?php function rrdtool_function_fetch(...$args) { touch(dirname(__DIR__) . "/fetch"); }');
        $wrapper = $dir . '/rrdtool';
        file_put_contents($wrapper, "#!/bin/sh\ntouch " . escapeshellarg($dir . '/rrd-command') . "\nexec " . escapeshellarg($binary) . " \"\$@\"\n");
        chmod($wrapper, 0700);
        $fixture = <<<'FIXTURE'
<?php
$config = array('base_path' => dirname(__DIR__), 'library_path' => dirname(__DIR__) . '/lib', 'rra_path' => dirname(__DIR__) . '/store', 'poller_id' => 1, 'cacti_server_os' => 'unix');
$heartbeats = array(600 => 'ten minutes', 900 => 'fifteen minutes');
foreach (array('POLLER_VERBOSITY_NONE' => 1, 'POLLER_VERBOSITY_LOW' => 2, 'POLLER_VERBOSITY_MEDIUM' => 3, 'POLLER_VERBOSITY_HIGH' => 4, 'POLLER_VERBOSITY_DEBUG' => 5) as $key => $value) { define($key, $value); }
function read_config_option($name) { return $name === 'path_rrdtool' ? dirname(__DIR__) . '/rrdtool' : ($name === 'poller_interval' ? 300 : ''); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_escapeshellarg($value) { return escapeshellarg($value); }
function cacti_escapeshellcmd($value) { return escapeshellcmd($value); }
function cacti_log(...$args) {}
function register_process_start(...$args) { return true; }
function unregister_process(...$args) {}
function db_fetch_cell(...$args) { return '1.3.0'; }
function db_execute_prepared(...$args) { touch(dirname(__DIR__) . '/db-write'); return true; }
function array_rekey($rows, $key, $value) { $out = array(); foreach ($rows as $row) { $out[$row[$key]] = $row[$value]; } return $out; }
function db_fetch_assoc_prepared($sql, $params = array()) {
    $rrd = glob(dirname(__DIR__) . '/store/*.rrd')[0];
    if (strpos($sql, 'SELECT DISTINCT dsp.id') !== false) { return array(array('id' => 1, 'heartbeat' => 900, 'name' => 'fixture')); }
    if (strpos($sql, 'poller_float_rrdfiles_not_done') !== false) { return array(array('local_data_id' => 1, 'rrd_path' => $rrd)); }
    return array(array('local_data_id' => 1, 'rrd' => $rrd, 'rrd_heartbeat' => 600, 'data_sources' => 'value', 'name' => 'fixture', 'name_cache' => 'fixture'));
}
touch(dirname(__DIR__) . '/started');
FIXTURE;
        file_put_contents($dir . '/include/cli_check.php', $fixture);
        $args = array_merge(array(PHP_BINARY), rrd_cli_coverage_arguments($this, $dir, $root, $scriptName), array('-d', 'sys_temp_dir=' . $dir, $dir . '/cli/' . $scriptName));
        if ($scriptName === 'update_heartbeat.php') {
            $args = array_merge($args, array('--prev-heartbeat=600', '--new-heartbeat=900', '--force'));
            $lock = rrd_maintenance_acquire(true);
        } elseif ($scriptName === 'float_rrdfiles.php') {
            $args = array_merge($args, array('--type=child', '--child=1', '--force', '--start=1700000000', '--end=1700000060'));
            $lock = rrd_maintenance_acquire();
        } else {
            $args = array_merge($args, array('--oldrrd=' . $rrd, '--newrrd=' . $rrd, '--finrrd=' . $dir . '/finished.rrd'));
            $lock = $busy ? rrd_maintenance_acquire() : null;
        }
        $process = proc_open($args, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array_merge(getenv(), array('RRDCACHED_ADDRESS' => $cached ? 'unix:/unavailable-test-cache' : '')));
        $deadline = microtime(true) + 10;
        while (!file_exists($dir . '/started') && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($dir . '/started'))->toBeTrue();
        if (!$cached && $scriptName !== 'splice_rrd.php') {
            usleep(100000);
            expect(proc_get_status($process)['running'])->toBeTrue()
                ->and(file_exists($dir . '/rrd-command'))->toBeFalse()
                ->and(file_exists($dir . '/db-write'))->toBeFalse()
                ->and(file_get_contents($rrd))->toBe($before);
            rrd_maintenance_release($lock);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($cached) {
            expect($status)->toBe(1)->and($stderr)->toContain('disable RRDCACHED_ADDRESS')
                ->and(file_exists($dir . '/fetch'))->toBeFalse()
                ->and(file_exists($dir . '/rrd-command'))->toBeFalse()
                ->and(file_exists($dir . '/db-write'))->toBeFalse()
                ->and(file_get_contents($rrd))->toBe($before);
        } elseif ($scriptName === 'splice_rrd.php' && $busy) {
            expect($status)->toBe(1)->and($stderr)->toContain('storage is busy')
                ->and(file_exists($dir . '/rrd-command'))->toBeFalse()
                ->and(file_get_contents($rrd))->toBe($before);
        } else {
            expect($status)->toBe(0)->and($stderr)->toBe('')->and(file_exists($dir . '/rrd-command'))->toBeTrue();
            if ($scriptName === 'update_heartbeat.php') {
                expect(shell_exec(escapeshellarg($binary) . ' info ' . escapeshellarg($rrd)))->toContain('minimal_heartbeat = 900');
            } elseif ($scriptName === 'splice_rrd.php') {
                expect(file_exists($dir . '/finished.rrd'))->toBeTrue();
                $lastUpdate = shell_exec(escapeshellarg($binary) . ' lastupdate ' . escapeshellarg($dir . '/finished.rrd'));
                expect($lastUpdate)->toContain('value')->toMatch('/1700000060:\s+42(?:\.0+)?(?:e[+]0+)?\s/i');
                expect(file_get_contents($rrd))->toBe($before);
            }
        }
    } finally {
        rrd_maintenance_release($lock);
        if (isset($process) && is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
        $GLOBALS['config'] = $savedConfig;
        try {
            rrd_cli_merge_coverage($this, $dir);
        } finally {
            rrd_cli_fixture_remove($dir);
        }
    }
})->with(array(array('update_heartbeat.php', false), array('float_rrdfiles.php', false), array('splice_rrd.php', false), array('float_rrdfiles.php', true), array('splice_rrd.php', true), array('update_heartbeat.php', true), array('splice_rrd.php', false, false)));


test('batch gap repair assigns every queued file to one maintenance worker', function ($threads, $failed = false, $cached = false) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/batch-gap-lock-' . bin2hex(random_bytes(8));
    foreach (array('', '/cli', '/include', '/lib') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    try {
        copy($root . '/cli/batchgapfix.php', $dir . '/cli/batchgapfix.php');
        symlink($root . '/lib/rrd_maintenance.php', $dir . '/lib/rrd_maintenance.php');
        file_put_contents($dir . '/lib/poller.php', '<?php');
        $fixture = <<<'FIXTURE'
<?php
$config = array('base_path' => dirname(__DIR__), 'rra_path' => dirname(__DIR__), 'poller_id' => 1);
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function read_config_option($name) { return $name === 'path_php_binary' ? PHP_BINARY : ''; }
function cacti_escapeshellcmd($value) { return escapeshellcmd($value); }
function cacti_escapeshellarg($value) { return escapeshellarg($value); }
function register_process_start(...$args) { return true; }
function unregister_process(...$args) {}
function db_table_exists(...$args) { return false; }
function db_execute($sql, ...$args) { file_put_contents(dirname(__DIR__) . '/mutations', $sql . "\n", FILE_APPEND); return true; }
function db_affected_rows(...$args) { return 12; }
define('COPYRIGHT_YEARS', '2026');
function get_cacti_cli_version() { return '1.3.0'; }
function db_fetch_cell($sql, ...$args) { return strpos($sql, 'exit_code != 0') !== false ? (int) getenv('TEST_BATCH_FAILED') : 12; }
function db_fetch_cell_prepared(...$args) { return 0; }
function db_execute_prepared($sql, $params) {
    if (strpos($sql, 'SET child = ?') !== false) {
        file_put_contents(dirname(__DIR__) . '/assignments', json_encode(array($sql, $params)) . "\n", FILE_APPEND);
    }
    return true;
}
function exec_background($binary, $args) { file_put_contents(dirname(__DIR__) . '/launches', json_encode(array($binary, $args)) . "\n", FILE_APPEND); }
function cacti_log($message, ...$args) { file_put_contents(dirname(__DIR__) . '/stats', $message); }
FIXTURE;
        file_put_contents($dir . '/include/cli_check.php', $fixture);
        $process = proc_open(array_merge(array(PHP_BINARY), rrd_cli_coverage_arguments($this, $dir, $root, 'batchgapfix.php'), ($threads === 0 ? array($dir . '/cli/batchgapfix.php', '--help') : array($dir . '/cli/batchgapfix.php', '--start=2026-01-01', '--end=2026-01-02', '--threads=' . $threads))), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array_merge(getenv(), array('TEST_BATCH_FAILED' => $failed ? '1' : '0', 'RRDCACHED_ADDRESS' => $cached ? 'unix:/unavailable-test-cache' : '')));
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($cached) {
            expect($status)->toBe(1)->and($stderr)->toContain('disable RRDCACHED_ADDRESS')
                ->and(file_exists($dir . '/mutations'))->toBeFalse()
                ->and(file_exists($dir . '/launches'))->toBeFalse();
            return;
        }
        expect($status)->toBe($failed ? 1 : 0);
        if ($failed) {
            expect($stderr)->toContain('queue results retained')
                ->and(file_get_contents($dir . '/mutations'))->not->toContain('TRUNCATE');
        } else {
            expect($stderr)->toBe('');
            if ($threads !== 0) {
                expect(file_get_contents($dir . '/mutations'))->toContain('TRUNCATE');
            }
        }
        if ($threads === 0) {
            expect($stdout)->toContain('maintenance currently uses one worker')->toContain('for command-line compatibility.');
            return;
        }
        $assignments = file($dir . '/assignments', FILE_IGNORE_NEW_LINES);
        $launches = file($dir . '/launches', FILE_IGNORE_NEW_LINES);
        expect($assignments)->toHaveCount(1)->and($launches)->toHaveCount(1);
        $assignment = json_decode($assignments[0], true);
        expect($assignment[0])->toContain('LIMIT 12')->and($assignment[1])->toBe(array(1))
            ->and(file_get_contents($dir . '/stats'))->toContain('Threads:1');
        if ($threads > 1) {
            expect($stdout)->toContain('Serializing gap repair');
        }
    } finally {
        try {
            rrd_cli_merge_coverage($this, $dir);
        } finally {
            rrd_cli_fixture_remove($dir);
        }
    }
})->with(array(array(0), array(1), array(5), array(40), array(1, true), array(1, false, true)));
