<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('remote production poller checks its actual queue before continuing collection', function ($connection, $engine) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/remote-queue-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory . '/include', 0700);
    mkdir($directory . '/lib', 0700);
    copy($root . '/poller.php', $directory . '/poller.php');
    foreach (array('poller', 'data_query', 'rrd', 'dsstats', 'dsdebug', 'boost', 'reports', 'rrdcheck') as $library) {
        file_put_contents($directory . '/lib/' . $library . '.php', '<?php');
    }
    file_put_contents($directory . '/lib/rrd_maintenance.php', '<?php require ' . var_export($root . '/lib/rrd_maintenance.php', true) . ';');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $script = '<?php ';
    if ($coverage !== null) {
        $script .= 'define("RRD_TEST_COVERAGE_DIRECTORY",dirname(__DIR__));' .
            'define("RRD_TEST_CLI_COVERAGE_COPY",dirname(__DIR__)."/poller.php");' .
            'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/poller.php', true) . ');' .
            'require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $script .= '$connection=' . var_export($connection, true) . ';$engine=' . var_export($engine, true) . ';';
    $script .= <<<'PROBE'
$config = array('poller_id' => 2, 'connection' => $connection, 'base_path' => dirname(__DIR__), 'rra_path' => '/missing-remote-rrds', 'cacti_server_os' => 'unix');
$remote_db_cnn_id = 'primary-database';
$database_hostname = 'fixture';
$observed = array('probes' => array(), 'logs' => array(), 'continued' => false);
function cacti_sizeof($value) { return count($value); }
function db_column_exists(...$args) { return true; }
function db_fetch_cell_prepared($sql, $params = array(), $column = '', $log = true, $connection = false) {
    if (strpos($sql, 'SELECT ENGINE') !== false) {
        $GLOBALS['observed']['probes'][] = array($params, $connection);
        return $GLOBALS['engine'];
    }
    return 'fixture';
}
function db_fetch_cell(...$args) { return 2; }
function set_config_option(...$args) {}
function read_config_option($key) { return $key === 'poller_enabled' ? 'on' : ''; }
function cacti_log($message, ...$args) { $GLOBALS['observed']['logs'][] = $message; }
function db_table_exists($table) {
    // First operation after preflight: stop before any real collection work.
    $GLOBALS['observed']['continued'] = true;
    exit(42);
}
register_shutdown_function(function () { file_put_contents(dirname(__DIR__).'/observed.json', json_encode($GLOBALS['observed'])); });
PROBE;
    try {
        file_put_contents($directory . '/include/cli_check.php', $script);
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/poller.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $safe = $engine === 'InnoDB';
        $status = proc_close($process);
        expect($error)->toBe('')->and($output)->toBe('')->and($status)->toBe($safe ? 42 : 1);
        $observed = json_decode(file_get_contents($directory . '/observed.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($observed['probes'])->toBe(array(array(array('poller_output'), $connection === 'online' ? 'primary-database' : false)))
            ->and($observed['continued'])->toBe($safe)
            ->and($observed['logs'])->toHaveCount($safe ? 0 : 1);
        if (!$safe) {
            expect($observed['logs'][0])->toContain('including remote collectors')->toContain('must not be discarded');
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (array('/include', '/lib', '') as $suffix) {
            foreach (glob($directory . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory . $suffix);
        }
    }
})->with(array(array('online', 'MEMORY'), array('online', false), array('online', 'InnoDB'), array('offline', 'MEMORY'), array('offline', false), array('offline', 'InnoDB')));
