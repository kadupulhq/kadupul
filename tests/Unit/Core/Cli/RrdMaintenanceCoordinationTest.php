<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';

function rrd_cli_fixture_remove($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { rrd_cli_fixture_remove($path . '/' . $name); } }
        rmdir($path);
    } else { unlink($path); }
}

/** Collect real subprocess coverage without changing the copied CLI source. */
function rrd_cli_coverage_arguments($test, $dir, $root, $scriptName) {
    if ($test->getTestResultObject()->getCodeCoverage() === null) { return array(); }
    $bootstrap = '<?php define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__);' .
        'define("RRD_TEST_CLI_COVERAGE_COPY", ' . var_export($dir . '/cli/' . $scriptName, true) . ');' .
        'define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export($root . '/cli/' . $scriptName, true) . ');' .
        'require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    file_put_contents($dir . '/coverage.php', $bootstrap);
    return array('-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-d', 'auto_prepend_file=' . $dir . '/coverage.php');
}

function rrd_cli_merge_coverage($test, $dir) {
    $parent = $test->getTestResultObject()->getCodeCoverage();
    if ($parent === null || !file_exists($dir . '/coverage.php')) { return; }
    $reports = glob($dir . '/*.coverage');
    expect($reports)->toHaveCount(1);
    // Only our child can write in this owned 0700 fixture directory.
    $child = unserialize(file_get_contents($reports[0]));
    expect($child)->toBeInstanceOf(SebastianBergmann\CodeCoverage\CodeCoverage::class);
    $parent->merge($child);
}

test('native maintenance CLI coordinates before touching an RRD', function ($scriptName, $cached, $busy = true, $failure = false) {
    $signal = $failure === 'sigterm' ? 15 : ($failure === 'sigint' ? 2 : null);
    if ($signal !== null && (!function_exists('pcntl_signal') || !is_dir('/proc/self/fd'))) {
        $this->markTestSkipped('Native Linux signals and descriptor inspection are required.');
    }
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) { $this->markTestSkipped('Real RRDtool is required; CI provisions it.'); }
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-cli-lock-' . bin2hex(random_bytes(8));
    foreach (array('', '/cli', '/include', '/lib', '/store') as $suffix) { mkdir($dir . $suffix, 0700); }
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
        file_put_contents($dir . '/lib/rrd.php', '<?php function rrdtool_function_fetch(...$args) { touch(dirname(__DIR__) . "/fetch"); return array("data_source_names" => array("value")); }');
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
function unregister_process(...$args) { file_put_contents(dirname(__DIR__) . "/unregistered", json_encode($args)); }
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
        if ($failure === 'storage') {
            $fixture .= "\n" . '$config["rra_path"] = dirname(__DIR__) . "/missing-store";';
        } elseif ($failure === 'rewrite') {
            file_put_contents($wrapper, "#!/bin/sh\nexit 1\n");
        }
        if ($failure === 'fetch-empty' || $failure === 'fetch-throw') {
            $behavior = $failure === 'fetch-empty' ? 'return array();' : 'throw new RuntimeException("fetch failed");';
            file_put_contents($dir . '/lib/rrd.php', '<?php function rrdtool_function_fetch(...$args) { ' . $behavior . ' }');
        }
        if ($failure === 'missing-file') {
            $fixture = str_replace("'rrd' => \$rrd", "'rrd' => \$rrd . '.missing'", $fixture);
        }
        file_put_contents($dir . '/include/cli_check.php', $fixture);
        $args = array_merge(array(PHP_BINARY), rrd_cli_coverage_arguments($this, $dir, $root, $scriptName), array('-d', 'sys_temp_dir=' . $dir, $dir . '/cli/' . $scriptName));
        if ($scriptName === 'update_heartbeat.php') {
            $args = array_merge($args, array('--prev-heartbeat=600', '--new-heartbeat=900', '--force'));
            $lock = rrd_maintenance_acquire();
        } elseif ($scriptName === 'float_rrdfiles.php') {
            $args = array_merge($args, array('--type=child', '--child=1', '--start=1700000000', '--end=1700000060'));
            $lock = rrd_maintenance_acquire();
        } else {
            $args = array_merge($args, array('--oldrrd=' . $rrd, '--newrrd=' . $rrd, '--finrrd=' . $dir . '/finished.rrd'));
            $lock = $busy ? rrd_maintenance_acquire() : null;
        }
        $process = proc_open($args, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array_merge(getenv(), array('RRDCACHED_ADDRESS' => $cached ? 'unix:/unavailable-test-cache' : '')));
        $deadline = microtime(true) + 10;
        while (!file_exists($dir . '/started') && microtime(true) < $deadline) { usleep(10000); }
        expect(file_exists($dir . '/started'))->toBeTrue();
        if (!$cached && !in_array($failure, array('storage', 'fetch-empty', 'fetch-throw'), true) && $scriptName !== 'splice_rrd.php') {
            usleep(100000);
            expect(proc_get_status($process)['running'])->toBeTrue()
                ->and(file_exists($dir . '/rrd-command'))->toBeFalse()
                ->and(file_exists($dir . '/db-write'))->toBeFalse()
                ->and(file_get_contents($rrd))->toBe($before);
            if ($signal !== null) {
                $pid = proc_get_status($process)['pid'];
                $opened = false; $deadline = microtime(true) + 10;
                // Confirm the child's new descriptor is waiting, rather than
                // timing a signal against an assumed lock-acquisition delay.
                while (!$opened && microtime(true) < $deadline) {
                    foreach (glob('/proc/' . $pid . '/fd/*') as $fd) {
                        $target = @readlink($fd);
                        $info = @file_get_contents('/proc/' . $pid . '/fdinfo/' . basename($fd));
                        if ($target === $dir . '/store' && is_string($info) && strpos($info, 'lock:') === false) { $opened = true; break; }
                    }
                    if (!$opened) { usleep(10000); }
                }
                expect($opened)->toBeTrue()->and(proc_terminate($process, $signal))->toBeTrue();
                $deadline = microtime(true) + 10;
                while (!file_exists($dir . '/unregistered') && microtime(true) < $deadline) { usleep(10000); }
                expect(file_exists($dir . '/unregistered'))->toBeTrue();
            } else {
                rrd_maintenance_release($lock);
            }
        }
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        if ($cached) {
            expect($status)->toBe(1)->and($stderr)->toContain('disable RRDCACHED_ADDRESS')
                ->and(file_exists($dir . '/fetch'))->toBeFalse()
                ->and(file_exists($dir . '/rrd-command'))->toBeFalse()
                ->and(file_exists($dir . '/db-write'))->toBeFalse()
                ->and(file_get_contents($rrd))->toBe($before);
        } elseif ($failure) {
            expect($status)->toBe(1)->and(file_exists($dir . '/db-write'))->toBeFalse()
                ->and(file_get_contents($rrd))->toBe($before);
            if ($signal !== null) {
                expect(json_decode(file_get_contents($dir . '/unregistered'), true))->toBe(array('rfloat', 'child', '1', $pid))
                    ->and($stderr)->toBe('')->and(file_exists($dir . '/rrd-command'))->toBeFalse();
            }
            if ($scriptName === 'float_rrdfiles.php') {
                expect(file_exists($dir . '/unregistered'))->toBeTrue();
            }
            if ($failure === 'storage') {
                expect($stderr)->toContain('maintenance lock is unavailable')
                    ->and(file_exists($dir . '/unregistered'))->toBeTrue();
            }
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
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($process);
        }
        $GLOBALS['config'] = $savedConfig;
        try { rrd_cli_merge_coverage($this, $dir); } finally { rrd_cli_fixture_remove($dir); }
    }
})->with(array(array('update_heartbeat.php', false), array('float_rrdfiles.php', false), array('splice_rrd.php', false), array('float_rrdfiles.php', true), array('splice_rrd.php', true), array('update_heartbeat.php', true), array('splice_rrd.php', false, false), array('float_rrdfiles.php', false, true, 'storage'), array('float_rrdfiles.php', false, true, 'rewrite'), array('float_rrdfiles.php', false, true, 'sigterm'), array('float_rrdfiles.php', false, true, 'sigint'), array('float_rrdfiles.php', false, true, 'fetch-empty'), array('float_rrdfiles.php', false, true, 'fetch-throw'), array('update_heartbeat.php', false, true, 'rewrite'), array('update_heartbeat.php', false, true, 'missing-file')));


test('batch gap repair serializes queued files and reports worker outcomes', function ($threads, $failed = false, $cached = false, $childStatus = null, $stale = false) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/batch gap-lock-' . bin2hex(random_bytes(8));
    foreach (array('', '/cli', '/include', '/lib') as $suffix) { mkdir($dir . $suffix, 0700); }
    try {
        copy($root . '/cli/batchgapfix.php', $dir . '/cli/batchgapfix.php');
        file_put_contents($dir . '/cli/removespikes.php', '<?php exit((int) getenv("TEST_REPAIR_STATUS"));');
        symlink($root . '/lib/rrd_maintenance.php', $dir . '/lib/rrd_maintenance.php');
        file_put_contents($dir . '/lib/poller.php', '<?php');
        $fixture = <<<'FIXTURE'
<?php
$config = array('base_path' => dirname(__DIR__), 'rra_path' => dirname(__DIR__), 'poller_id' => 1);
if (in_array('--child=1', $argv, true) && (string) getenv('TEST_REPAIR_STATUS') === '') {
    file_put_contents(dirname(__DIR__) . '/launches', json_encode($argv) . "\n", FILE_APPEND);
    if (getenv('TEST_BATCH_FAILED') === 'crash') { exit(9); }
    if (getenv('TEST_BATCH_FAILED') === 'killed') { posix_kill(getmypid(), SIGKILL); }
    exit(0);
}

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function read_config_option($name) { return $name === 'path_php_binary' ? PHP_BINARY : ''; }
function cacti_escapeshellcmd($value) { return escapeshellcmd($value); }
function cacti_escapeshellarg($value) { return escapeshellarg($value); }
function register_process_start(...$args) { return true; }
function unregister_process(...$args) {}
function db_table_exists(...$args) { return getenv('TEST_BATCH_STALE') === '1'; }
function cacti_process_still_running($pid) { return false; }
function cacti_process_pid_for_log($pid) { return (string) $pid; }
function db_execute($sql, ...$args) { file_put_contents(dirname(__DIR__) . '/mutations', $sql . "\n", FILE_APPEND); return true; }
function db_affected_rows(...$args) { return 12; }
define('COPYRIGHT_YEARS', '2026');
function get_cacti_cli_version() { return '1.3.0'; }
function db_fetch_cell($sql, ...$args) { if (strpos($sql, 'WHERE ended =') !== false) { return file_exists(dirname(__DIR__) . '/reaped') ? 0 : 1; } return strpos($sql, 'exit_code != 0') !== false ? (getenv('TEST_BATCH_FAILED') === 'unknown' ? false : (int) getenv('TEST_BATCH_FAILED')) : 12; }
function db_fetch_cell_prepared(...$args) { return getenv('TEST_BATCH_FAILED') === 'unfinished' ? 1 : 0; }
function db_fetch_assoc_prepared(...$args) { if (strpos($args[0], 'FROM processes') !== false) { return array(array('tasktype'=>'batchgapfix','taskname'=>'child','taskid'=>1,'pid'=>999999)); } return array(array('id' => 1, 'data_source_path' => dirname(__DIR__) . '/source.rrd')); }
function db_execute_prepared($sql, $params) {
    if (strpos($sql, 'SET ended = NOW(), exit_code = 1') !== false) {touch(dirname(__DIR__) . '/reaped');}
    if (strpos($sql, 'exit_code = ?') !== false) { file_put_contents(dirname(__DIR__) . '/child-exit', json_encode($params)); }
    if (strpos($sql, 'SET child = ?') !== false) {
        file_put_contents(dirname(__DIR__) . '/assignments', json_encode(array($sql, $params)) . "\n", FILE_APPEND);
    }
    return true;
}
function exec_background($binary, $args) { file_put_contents(dirname(__DIR__) . '/launches', json_encode(array($binary, $args)) . "\n", FILE_APPEND); }
function cacti_log($message, ...$args) { file_put_contents(dirname(__DIR__) . '/stats', $message); }
FIXTURE;
        file_put_contents($dir . '/include/cli_check.php', $fixture);
        $process = proc_open(array_merge(array(PHP_BINARY), rrd_cli_coverage_arguments($this, $dir, $root, 'batchgapfix.php'), ($threads === 0 ? array($dir . '/cli/batchgapfix.php', '--help') : array($dir . '/cli/batchgapfix.php', '--start=2026-01-01', '--end=2026-01-02', '--threads=' . $threads, '--child=' . ($childStatus === null ? 0 : 1)))), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array_merge(getenv(), array('TEST_BATCH_STALE' => $stale ? '1' : '0', 'TEST_REPAIR_STATUS' => (string) $childStatus, 'TEST_BATCH_FAILED' => is_string($failed) ? $failed : ($failed ? '1' : '0'), 'RRDCACHED_ADDRESS' => $cached ? 'unix:/unavailable-test-cache' : '')));
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        if ($stale) {
            expect(file_exists($dir . '/reaped'))->toBeTrue()->and($status)->toBe(1)
                ->and($stderr)->toContain('queue retained')
                ->and(file_exists($dir . '/mutations'))->toBeFalse()
                ->and(file_exists($dir . '/launches'))->toBeFalse();
            return;
        }
        if ($childStatus !== null) {
            expect($status)->toBe($childStatus ? 1 : 0)->and($stderr)->toBe('')
                ->and($stdout)->toContain($childStatus ? 'FAILED:' : 'SUCCESS:')
                ->and($stdout)->toContain($dir . '/source.rrd')
                ->and(json_decode(file_get_contents($dir . '/child-exit'), true))->toBe(array($childStatus, 1));
            return;
        }
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
        if ($threads > 1) { expect($stdout)->toContain('Serializing gap repair'); }
    } finally {
        try { rrd_cli_merge_coverage($this, $dir); } finally { rrd_cli_fixture_remove($dir); }
    }
})->with(array(array(0), array(1), array(5), array(40), array(1, true), array(1, false, true), array(1, false, false, 0), array(1, false, false, 1), array(1, 'unknown'), array(1, 'unfinished'), array(1, 'crash'), array(1, 'killed'), array(1, false, false, null, true)));


test('float master reports retained queue rows as a failed run', function ($remaining) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-float-master-' . bin2hex(random_bytes(8));
    foreach (array('', '/cli', '/include', '/lib') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    try {
        copy($root . '/cli/float_rrdfiles.php', $dir . '/cli/float_rrdfiles.php');
        symlink($root . '/lib/rrd_maintenance.php', $dir . '/lib/rrd_maintenance.php');
        symlink($root . '/lib/maintenance_cli.php', $dir . '/lib/maintenance_cli.php');
        file_put_contents($dir . '/lib/poller.php', '<?php');
        file_put_contents($dir . '/lib/rrd.php', '<?php');
        $fixture = <<<'FIXTURE'
<?php
$config = array('base_path' => dirname(__DIR__), 'rra_path' => dirname(__DIR__), 'poller_id' => 1, 'cacti_server_os' => 'unix');
define('POLLER_VERBOSITY_MEDIUM', 3);
if (in_array('--type=child', $argv, true)) {
    touch(dirname(__DIR__) . '/launched');
    if (getenv('TEST_FLOAT_REMAINING') === 'crash') { exit(9); }
    if (getenv('TEST_FLOAT_REMAINING') === 'killed') { posix_kill(getmypid(), SIGKILL); }
    exit(0);
}

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function read_config_option($name) { return $name === 'path_php_binary' ? PHP_BINARY : ''; }
function cacti_escapeshellarg($value) { return escapeshellarg($value); }
function cacti_escapeshellcmd($value) { return escapeshellcmd($value); }
function register_process_start(...$args) { return true; }
function unregister_process(...$args) { touch(dirname(__DIR__) . '/unregistered'); }
function db_table_exists(...$args) { return true; }
function db_execute($sql) { file_put_contents(dirname(__DIR__) . '/mutations', $sql . "\n", FILE_APPEND); return true; }
function db_execute_prepared($sql, $args) { return db_execute($sql); }
function db_fetch_cell($sql) {
    static $queueReads = 0;
    if (strpos($sql, 'FROM processes') !== false) { return 0; }
    return $queueReads++ === 0 ? 1 : (getenv('TEST_FLOAT_REMAINING') === 'unknown' ? false : (int) getenv('TEST_FLOAT_REMAINING'));
}
function db_fetch_assoc_prepared(...$args) { return array(); }
function db_fetch_cell_prepared(...$args) { return 1; }
function exec_background(...$args) { touch(dirname(__DIR__) . '/launched'); }
function cacti_log($message, ...$args) { file_put_contents(dirname(__DIR__) . '/messages', $message . "\n", FILE_APPEND); }
FIXTURE;
        file_put_contents($dir . '/include/cli_check.php', $fixture);
        $args = array_merge(array(PHP_BINARY), rrd_cli_coverage_arguments($this, $dir, $root, 'float_rrdfiles.php'), array($dir . '/cli/float_rrdfiles.php', '--resume', '--threads=1', '--start=1700000000', '--end=1700000060'));
        $process = proc_open($args, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array_merge(getenv(), array('RRDCACHED_ADDRESS' => '', 'TEST_FLOAT_REMAINING' => (string) $remaining)));
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe($remaining ? 1 : 0)->and($stderr)->toBe('')
            ->and(file_exists($dir . '/launched'))->toBeTrue()
            ->and(file_exists($dir . '/unregistered'))->toBeTrue()
            ->and(file_get_contents($dir . '/mutations'))->not->toContain('DELETE')->not->toContain('TRUNCATE');
        if ($remaining) {
            expect(file_get_contents($dir . '/messages'))->toContain('left unprocessed files');
        }
    } finally {
        try {
            rrd_cli_merge_coverage($this, $dir);
        } finally {
            rrd_cli_fixture_remove($dir);
        }
    }
})->with(array(0, 1, 'unknown', 'crash', 'killed'));

test('storage probe checks the invoking account and queue without starting an upgrade', function ($engine, $trusted) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-probe-' . bin2hex(random_bytes(8));
    foreach (array('', '/cli', '/include', '/lib', '/install', '/store') as $suffix) { mkdir($dir . $suffix, 0700); }
    try {
        copy($root . '/cli/upgrade_database.php', $dir . '/cli/upgrade_database.php');
        symlink($root . '/lib/rrd_maintenance.php', $dir . '/lib/rrd_maintenance.php');
        foreach (array('lib/data_query.php', 'lib/poller.php', 'lib/utility.php', 'install/functions.php') as $file) { file_put_contents($dir . '/' . $file, '<?php'); }
        if (!$trusted) { chmod($dir . '/store', 0770); }
        $bootstrap = '<?php ini_set("display_errors", "stderr"); $config = ' . var_export(array('base_path' => $dir, 'rra_path' => $dir . '/store', 'cacti_server_os' => 'unix', 'poller_id' => 1), true) . ';' .
            'function __($message) { return $message; } function cacti_sizeof($value) { return count($value); } function read_config_option($key) { return false; }' .
            'function db_fetch_cell_prepared($sql, $params) { if ($params !== array("poller_output")) { throw new RuntimeException("Wrong queue"); } return ' . var_export($engine, true) . '; }' .
            'function db_execute_prepared(...$args) { throw new RuntimeException("Probe attempted a mutation"); }';
        file_put_contents($dir . '/include/cli_check.php', $bootstrap);
        $arguments = rrd_cli_coverage_arguments($this, $dir, $root, 'upgrade_database.php');
        $process = proc_open(array_merge(array(PHP_BINARY), $arguments, array($dir . '/cli/upgrade_database.php', '--check-rrd-storage')), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $accepted = $trusted && $engine === 'InnoDB';
        expect(proc_close($process))->toBe($accepted ? 0 : 1, $error . $output);
        if ($accepted) {
            expect($error)->toBe('')->and($output)->toContain('No upgrade was performed')->toContain('UID ' . posix_geteuid());
        } else {
            expect($error)->toContain($trusted ? 'must use InnoDB' : 'rrd_maintenance_trusted_gids');
        }
        rrd_cli_merge_coverage($this, $dir);
    } finally { rrd_cli_fixture_remove($dir); }
})->with(array(array('InnoDB', true), array('MEMORY', true), array(false, true), array('InnoDB', false)));
