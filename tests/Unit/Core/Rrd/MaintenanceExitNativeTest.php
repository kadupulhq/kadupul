<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('maintenance CLI propagates queue failures after completing other maintenance', function ($mode) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/maintenance-exit-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/include', 0700);
    mkdir($dir . '/lib', 0700);
    foreach (array('api_data_source', 'api_device', 'api_graph', 'poller', 'rrd', 'utility') as $lib) {
        file_put_contents($dir . '/lib/' . $lib . '.php', '<?php');
    }
    file_put_contents($dir . '/lib/rrd_maintenance.php', '<?php require ' . var_export($root . '/lib/rrd_maintenance.php', true) . ';');
    copy($root . '/poller_maintenance.php', $dir . '/poller_maintenance.php');
    $parent = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($parent !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",dirname(__DIR__));define("RRD_TEST_CLI_COVERAGE_COPY",dirname(__DIR__)."/poller_maintenance.php");define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/poller_maintenance.php', true) . ');require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$mode=' . var_export($mode, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('poller_id'=>1, 'cacti_server_os'=>'unix', 'base_path'=>dirname(__DIR__), 'rra_path'=>dirname(__DIR__), 'library_path'=>dirname(__DIR__).'/lib');
$events = $messages = array();
function read_config_option($key, ...$args) { if ($key === 'secpass_expireaccount') { $GLOBALS['events'][] = 'passwords'; } return 0; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message, ...$args) { $GLOBALS['messages'][] = $message; }
function register_process_start(...$args) { return true; }
function unregister_process(...$args) { $GLOBALS['events'][] = 'unregister'; }
function db_fetch_cell($sql) { return $GLOBALS['mode'] === 'count' ? false : ($GLOBALS['mode'] === 'empty' ? 0 : 1); }
function db_fetch_assoc($sql) { return strpos($sql, 'data_source_purge_action') !== false ? false : array(); }
// The purge reads the queue in keyset pages; a failed page read is the 'read' failure.
function db_fetch_assoc_prepared($sql, $params = array(), $log = true, $db_conn = false) { return strpos($sql, 'FROM data_source_purge_action') !== false && count($params) === 3 ? false : array(); }
function db_execute($sql) { $GLOBALS['events'][] = strpos($sql, 'poller_output_realtime') !== false ? 'realtime' : 'authcache'; return true; }
function api_device_purge_deleted_devices() { $GLOBALS['events'][] = 'devices'; }
function cacti_escapeshellcmd($command) { return $command; }
function array_rekey($rows, ...$args) { return $rows; }
register_shutdown_function(function () { file_put_contents(dirname(__DIR__).'/result.json', json_encode(array($GLOBALS['events'], $GLOBALS['messages']))); });
SOURCE;
    file_put_contents($dir . '/include/cli_check.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/poller_maintenance.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame($mode === 'empty' ? 0 : 1, proc_close($process), $output . $error);
        expect($error)->toBe('');
        list($events, $messages) = json_decode(file_get_contents($dir . '/result.json'), true);
        expect($events)->toBe(array('authcache', 'passwords', 'realtime', 'devices', 'unregister'));
        expect(end($messages))->toStartWith('MAINT STATS:');
        if ($mode !== 'empty') {
            expect(implode("\n", $messages))->toContain('requests retained');
        }
        if ($parent !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $parent->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (array('/include', '/lib', '') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with(array('count', 'read', 'empty'));
