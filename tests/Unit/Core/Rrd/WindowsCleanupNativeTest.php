<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('native data-source API retains Windows files without queuing unsupported cleanup', function ($platform, $remote) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/windows-cleanup-api-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $bootstrap = '<?php ';
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$config=' . var_export(array('cacti_server_os' => $platform), true) . ';';
    $bootstrap .= '$options=' . var_export(array('storage_location' => $remote, 'rrd_autoclean' => 'on', 'rrd_autoclean_method' => '1'), true) . ';';
    $bootstrap .= <<<'SOURCE'
$queries = $messages = array();
function read_config_option($key) { return $GLOBALS['options'][$key] ?? ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function debounce_run_notification(...$args) { return true; }
function cacti_log($message, ...$args) { $GLOBALS['messages'][] = $message; }
function api_plugin_hook_function(...$args) {}
function poller_push_to_remote_db_connect(...$args) { return false; }
function get_remote_poller_ids_from_data_sources(...$args) { return array(); }
function db_fetch_cell_prepared(...$args) { return 0; }
function db_fetch_assoc(...$args) { return array(); }
function db_fetch_row_prepared(...$args) { return array('local_data_id' => 1, 'data_source_path' => '<path_rra>/sample.rrd'); }
function db_execute_prepared($sql, ...$args) { $GLOBALS['queries'][] = $sql; return true; }
function db_execute($sql, ...$args) { return db_execute_prepared($sql); }
SOURCE;
    $bootstrap .= 'require ' . var_export($root . '/lib/api_data_source.php', true) . ';';
    $bootstrap .= 'api_data_source_remove(1); api_data_source_remove_multi(array(2, 3)); echo json_encode(array($queries, $messages));';
    file_put_contents($directory . '/run.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/run.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        list($queries, $messages) = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $supported = $platform !== 'win32' || $remote;
        expect(count(array_filter($queries, fn($q) => str_contains($q, 'INSERT INTO data_source_purge_action'))))->toBe($supported ? 2 : 0);
        expect(count(array_filter($queries, fn($q) => str_contains($q, 'DELETE FROM data_local'))))->toBe(2);
        if (!$supported) {
            expect(implode(' ', $messages))->toContain('retained for manual cleanup');
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
})->with(array(array('win32', 0), array('unix', 0), array('win32', 1)));
