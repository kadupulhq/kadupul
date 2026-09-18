<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('native cleanup preserves failed requests and releases its batch lease', function ($mode, $action) {
    if (!function_exists('posix_geteuid') || ($mode === 'filesystem' && posix_geteuid() === 0)) {
        $this->markTestSkipped('Requires POSIX identity and non-root filesystem permissions.');
    }
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/native-purge-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    foreach (array('include', 'bad', 'archive', 'archive/bad') as $path) {
        mkdir($directory . '/' . $path, 0700);
    }
    file_put_contents($directory . '/bad/sample.rrd', 'retained original');
    file_put_contents($directory . '/good.rrd', 'valid sibling');
    copy($root . '/poller_maintenance.php', $directory . '/poller_maintenance.php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",dirname(__DIR__));' .
            'define("RRD_TEST_CLI_COVERAGE_COPY",dirname(__DIR__)."/poller_maintenance.php");' .
            'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/poller_maintenance.php', true) . ');' .
            'require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/lib/rrd_maintenance.php', true) . ';';
    $bootstrap .= 'require ' . var_export($root . '/lib/rrd.php', true) . ';';
    $bootstrap .= '$mode=' . var_export($mode, true) . ';$action=' . var_export($action, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('cacti_server_os'=>'unix', 'rra_path'=>dirname(__DIR__), 'base_path'=>dirname(__DIR__));
$debug = false;
$archived = $purged = $reads = 0;
$poller_start = microtime(true);
$queue = array(array('id'=>1, 'name'=>'bad/sample.rrd', 'local_data_id'=>0, 'action'=>$action),
    array('id'=>2, 'name'=>'good.rrd', 'local_data_id'=>0, 'action'=>$action));
if ($mode === 'empty') { $queue = array(); }
$messages = $warnings = $deleted = array();
function read_config_option($key, ...$args) { return $key === 'rrd_archive' ? dirname(__DIR__).'/archive' : 0; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message, ...$args) { $GLOBALS['messages'][] = $message; }
function db_fetch_cell($sql) { return $GLOBALS['mode'] === 'count' ? false : count($GLOBALS['queue']); }
function db_fetch_assoc($sql) {
    if (++$GLOBALS['reads'] > 2) { throw new RuntimeException('Cleanup retried a failed batch indefinitely'); }
    return $GLOBALS['mode'] === 'read' ? false : $GLOBALS['queue'];
}
function db_fetch_assoc_prepared(...$args) { return array(); }
function db_execute_prepared($sql, $params) {
    $GLOBALS['deleted'][] = $params[0];
    $GLOBALS['queue'] = array_values(array_filter($GLOBALS['queue'], fn($row) => $row['name'] !== $params[0]));
    return true;
}
function set_config_option(...$args) {}
set_error_handler(function ($number, $message) { $GLOBALS['warnings'][] = $message; return true; });
$writer = $mode === 'lease' ? rrd_maintenance_acquire(false) : null;
if ($mode === 'lease' && !is_resource($writer)) { throw new RuntimeException('Fixture could not acquire writer lease'); }
if ($mode === 'filesystem') { chmod(dirname(__DIR__).'/bad', 0500); }
try {
    $result = rrdfile_purge(false);
} finally {
    rrd_maintenance_release($writer);
    chmod(dirname(__DIR__).'/bad', 0700);
}
$probe = rrd_maintenance_acquire(true, false);
if (!is_resource($probe)) { throw new RuntimeException('Cleanup leaked its batch lease'); }
rrd_maintenance_release($probe);
echo json_encode(array('result'=>$result, 'queue'=>$queue, 'deleted'=>$deleted, 'messages'=>$messages,
    'warnings'=>$warnings, 'purged'=>$purged, 'archived'=>$archived), JSON_THROW_ON_ERROR);
exit;
SOURCE;
    file_put_contents($directory . '/include/cli_check.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/poller_maintenance.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $completed = $mode === 'success' ? 2 : ($mode === 'filesystem' ? 1 : 0);
        expect($result['deleted'])->toHaveCount($completed)
            ->and($result['queue'])->toHaveCount($mode === 'empty' ? 0 : 2 - $completed)
            ->and($result[$action === '1' ? 'purged' : 'archived'])->toBe($completed);
        if ($mode === 'success') {
            expect($result['result'])->toBeTrue()->and($result['warnings'])->toBe(array());
            expect(file_exists($directory . '/bad/sample.rrd'))->toBeFalse();
            if ($action === '3') {
                expect(file_get_contents($directory . '/archive/bad/sample.rrd'))->toBe('retained original');
            }
        } elseif ($mode === 'empty') {
            expect($result['result'])->toBeTrue()->and($result['warnings'])->toBe(array());
            expect(file_get_contents($directory . '/bad/sample.rrd'))->toBe('retained original');
        } else {
            expect($result['result'])->toBeFalse()
                ->and(file_get_contents($directory . '/bad/sample.rrd'))->toBe('retained original');
            if ($mode === 'filesystem') {
                expect($result['deleted'])->toBe(array('good.rrd'))->and($result['warnings'])->toHaveCount(1);
            } else {
                expect($result['warnings'])->toBe(array());
            }
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        chmod($directory . '/bad', 0700);
        $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
    }
})->with(array('count', 'read', 'lease', 'filesystem', 'success', 'empty'))->with(array('1', '3'));
