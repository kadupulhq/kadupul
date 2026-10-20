<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('the production wait loop retains remote samples arriving before and after child completion', function (string $mode, int $pollerId) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    expect($source)->not->toBeFalse();
    $loop = test_php_block_source($source, 'while (1)');
    $program = <<<'PHP'
namespace OfflineWaitProbe;
const SQL_NO_CACHE = '';
const MAX_POLLER_RUNTIME = 298;
const POLLER_VERBOSITY_MEDIUM = 3;
$db = new \PDO('sqlite::memory:');
$db->exec('CREATE TABLE poller_output (output TEXT)');
$calls = 0;
$drains = 0;
function db_fetch_cell_prepared(...$args) {
    global $db, $calls;
    $db->exec("INSERT INTO poller_output VALUES ('sample')");
    return $calls++;
}
function db_execute($sql) {
    global $db;
    return $db->exec(str_replace('TRUNCATE ', 'DELETE FROM ', $sql)) !== false;
}
function read_config_option(...$args) { return 0; }
function api_plugin_hook(...$args) {}
function log_cacti_stats(...$args) {}
function sleep(...$args) {}
function process_poller_output_batch(...$args) { $GLOBALS['drains']++; return 0; }
$poller_db_cnn_id = false;
$started_processes = 1;
$poller_finishing_dispatched = false;
$poller_output_deferred = false;
$rrdtool_pipe = false;
$rrds_processed = 0;
$rrd_write_failed = false;
$poller_start = $loop_start = microtime(true);
$method = $concurrent_processes = $max_threads = $total_polling_hosts = $hosts_per_process = $num_polling_items = 1;
$debug = false;
PHP;
    $program .= '$poller_id=' . $pollerId . ';$config=["connection"=>' . var_export($mode, true) . '];' . $loop;
    $program .= 'echo json_encode([$db->query("SELECT count(*) FROM poller_output")->fetchColumn(), $drains, $calls]);';
    $result = test_php_run($program);
    expect($result['status'])->toBe(0, $result['out'] . $result['err'])->and($result['err'])->toBe('');
    expect(json_decode($result['out'], true, flags: JSON_THROW_ON_ERROR))->toBe([2, $pollerId === 1 ? 2 : 0, 2]);
})->with([['offline', 2], ['recovery', 2], ['online', 2], ['online', 1]]);

test('bootstrap enters recovery for either retained local queue and failed reads', function (int $boost, int $output, string $failedQueue) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');
    expect($source)->not->toBeFalse();
    $block = test_php_block_source($source, 'if (is_object($local_db_cnn_id))');
    $program = <<<'PHP'
namespace OfflineBootstrapProbe;
$local_db_cnn_id = new \PDO('sqlite::memory:');
foreach (['poller_output_boost', 'poller_output'] as $table) {
    $local_db_cnn_id->exec("CREATE TABLE $table (output TEXT)");
}
function db_fetch_cell($sql, $column = '', $log = true, $connection = false) {
    if ($GLOBALS['failedQueue'] !== '' && str_contains($sql, $GLOBALS['failedQueue'])) { return false; }
    return $connection->query($sql)->fetchColumn();
}
$config = ['connection' => 'online'];
PHP;
    $program .= '$failedQueue=' . var_export($failedQueue, true) . ';';
    foreach (['poller_output_boost' => $boost, 'poller_output' => $output] as $table => $count) {
        if ($count > 0) {
            $program .= '$local_db_cnn_id->exec("INSERT INTO ' . $table . ' VALUES (\'retained\')");';
        }
    }
    $result = test_php_run($program . $block . 'echo $config["connection"];');
    expect($result['status'])->toBe(0, $result['out'] . $result['err'])->and($result['err'])->toBe('')
        ->and($result['out'])->toBe($boost || $output || $failedQueue !== '' ? 'recovery' : 'online');
})->with([[0, 0, ''], [1, 0, ''], [0, 1, ''], [1, 1, ''], [0, 0, 'FROM poller_output_boost'], [0, 0, 'SELECT 1 FROM poller_output LIMIT']]);

test('each cmd flush reports queue failures and retains successful writes', function (string $flush, bool $redirect, bool $boost, int $failure) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cmd.php');
    expect($source)->not->toBeFalse();
    $body = match ($flush) {
        'host' => test_php_block_source($source, 'if ($output_count > 0)'),
        'batch' => test_php_block_source($source, 'if ($output_count > 2000)'),
        'final' => test_php_block_source($source, 'if ($output_count > 0', '// Flush the items to the output table'),
    };
    $start = strpos($source, '// record the process as having completed');
    $end = strpos($source, '// function to assist in logging', $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $finish = substr($source, $start, $end - $start);
    $program = <<<'PHP'
namespace OfflineCmdProbe;
const POLLER_VERBOSITY_MEDIUM = 3;
$db = new \PDO('sqlite::memory:');
foreach (['poller_output', 'poller_output_boost'] as $table) {
    $db->exec("CREATE TABLE $table (local_data_id INT, rrd_name TEXT, time TEXT, output TEXT)");
}
$calls = 0;
$continued = false;
$logs = [];
$poller_write_failed = false;
$poller_db_cnn_id = $db;
$output_count = 2001;
$output_array = ["(1, 'value', '2026-01-01 00:00:00', 'sample')"];
$host_id = 2;
$last_host = 1;
$print_data_to_stdout = false;
function debug_level(...$args) { return 0; }
function cacti_log($message, ...$args) { $GLOBALS['logs'][] = $message; }
function record_cmdphp_done() { $GLOBALS['done'] = true; }
function db_close() { $GLOBALS['closed'] = true; }
function read_config_option($name) { return $GLOBALS[$name] ? 'on' : ''; }
function db_execute($sql, $log, $connection) {
    if ($connection !== $GLOBALS['db']) { throw new \RuntimeException('Wrong queue connection'); }
    if (str_starts_with($sql, 'SET timestamp')) { return true; }
    if (++$GLOBALS['calls'] === $GLOBALS['failure']) { return false; }
    return $connection->exec(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql)) !== false;
}
register_shutdown_function(function () {
    global $db;
    echo json_encode([$db->query('SELECT count(*) FROM poller_output')->fetchColumn(),
        $db->query('SELECT count(*) FROM poller_output_boost')->fetchColumn(),
        $GLOBALS['calls'], $GLOBALS['done'], $GLOBALS['closed'],
        count(array_filter($GLOBALS['logs'], fn($log) => str_contains($log, 'ERROR:'))), $GLOBALS['continued'], $GLOBALS['host_id']]);
});
PHP;
    $program .= '$boost_redirect=' . var_export($redirect, true) . ';$boost_rrd_update_enable=' . var_export($boost, true) . ';$failure=' . $failure . ';';
    $result = test_php_run($program . 'foreach ([1] as $fixture) {' . $body . '$continued = true;}' . $finish);
    $copies = $redirect && $boost;
    $failed = $failure === 1 || ($failure === 2 && $copies);
    expect($result['err'])->toBe('')->and($result['status'])->toBe($failed ? 1 : 0, $result['out']);
    expect(json_decode($result['out'], true, flags: JSON_THROW_ON_ERROR))->toBe([
        $failure === 1 ? 0 : 1, $copies && $failure !== 2 ? 1 : 0, $copies ? 2 : 1, true, true, $failed ? 1 : 0, !$failed || $flush === 'final', $failed && $flush === 'host' ? 1 : 2,
    ]);
})->with(['host', 'batch', 'final'])->with([[false, false], [true, false], [false, true], [true, true]])->with([0, 1, 2]);

test('the final cmd flush does not retry after an earlier failed queue or clock operation', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cmd.php');
    expect($source)->not->toBeFalse();
    $body = test_php_block_source($source, 'if ($output_count > 0', '// Flush the items to the output table');
    $result = test_php_run('namespace OfflineCmdStoppedProbe; $poller_write_failed=true;$output_count=1;'
        . 'function cacti_log(...$args){throw new \\RuntimeException("Unexpected flush");}'
        . $body . 'echo "retained";');
    expect($result['status'])->toBe(0, $result['out'] . $result['err'])->and($result['err'])->toBe('')->and($result['out'])->toBe('retained');
});
