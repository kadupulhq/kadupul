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

test('native maintenance CLI coordinates before touching an RRD', function ($scriptName) {
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
        file_put_contents($dir . '/lib/rrd.php', '<?php function rrdtool_function_fetch(...$args) {}');
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
        $args = array(PHP_BINARY, '-d', 'sys_temp_dir=' . $dir, $dir . '/cli/' . $scriptName);
        if ($scriptName === 'update_heartbeat.php') {
            $args = array_merge($args, array('--prev-heartbeat=600', '--new-heartbeat=900', '--force'));
            $lock = rrd_maintenance_acquire(true);
        } elseif ($scriptName === 'float_rrdfiles.php') {
            $args = array_merge($args, array('--type=child', '--child=1', '--force', '--start=1700000000', '--end=1700000060'));
            $lock = rrd_maintenance_acquire();
        } else {
            $args = array_merge($args, array('--oldrrd=' . $rrd, '--newrrd=' . $rrd, '--finrrd=' . $dir . '/finished.rrd'));
            $lock = rrd_maintenance_acquire();
        }
        $process = proc_open($args, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $deadline = microtime(true) + 10;
        while (!file_exists($dir . '/started') && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($dir . '/started'))->toBeTrue();
        if ($scriptName !== 'splice_rrd.php') {
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
        if ($scriptName === 'splice_rrd.php') {
            expect($status)->toBe(1)->and($stderr)->toContain('storage is busy')
                ->and(file_exists($dir . '/rrd-command'))->toBeFalse()
                ->and(file_get_contents($rrd))->toBe($before);
        } else {
            expect($status)->toBe(0)->and($stderr)->toBe('')->and(file_exists($dir . '/rrd-command'))->toBeTrue();
            if ($scriptName === 'update_heartbeat.php') {
                expect(shell_exec(escapeshellarg($binary) . ' info ' . escapeshellarg($rrd)))->toContain('minimal_heartbeat = 900');
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
        rrd_cli_fixture_remove($dir);
    }
})->with(array('update_heartbeat.php', 'float_rrdfiles.php', 'splice_rrd.php'));
